<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Reads CSV and spreadsheet files (Excel xlsx/xls, LibreOffice ods) into a header row and value
 * rows of strings. Fixes what went wrong with the old CSV import: the separator is detected
 * (or chosen) for all steps, files in Windows-1252 and with BOM work, quoted line breaks stay in
 * their cell, and Excel dates arrive as dates instead of serial numbers.
 */
final class SpreadsheetReader
{
  public const EXTENSIONS = ['csv', 'txt', 'tsv', 'xlsx', 'xls', 'ods'];
  public const DELIMITERS = [';', ',', "\t", '|'];

  public static function isSpreadsheet(string $extension): bool
  {
    return in_array($extension, ['xlsx', 'xls', 'ods'], true);
  }

  /**
   * @return list<string> worksheet names (CSV: none)
   */
  public function sheets(string $path, string $extension): array
  {
    if (!self::isSpreadsheet($extension)) {
      return [];
    }
    try {
      return array_values(array_map('strval', IOFactory::createReader(self::readerType($extension))->listWorksheetNames($path)));
    } catch (Throwable) {
      throw new UserFacingException(I18n::t('The file cannot be read. Is it a valid Excel file?'));
    }
  }

  /**
   * @return array{columns: list<string>, rows: list<list<string|null>>, sheet: ?string, delimiter: ?string}
   */
  public function read(string $path, string $extension, ?string $sheet, ?string $delimiter, int $maxRows): array
  {
    $result = self::isSpreadsheet($extension)
      ? $this->readSpreadsheet($path, $extension, $sheet, $maxRows) + ['delimiter' => null]
      : $this->readCsv($path, $delimiter, $maxRows) + ['sheet' => null];

    if ([] === $result['columns']) {
      throw new UserFacingException(I18n::t('The file is empty - no header row was found.'));
    }
    return $result;
  }

  /**
   * @return array{columns: list<string>, rows: list<list<string|null>>, delimiter: string}
   */
  private function readCsv(string $path, ?string $delimiter, int $maxRows): array
  {
    $content = self::toUtf8((string)file_get_contents($path));
    if (null === $delimiter || !in_array($delimiter, self::DELIMITERS, true)) {
      $delimiter = self::detectDelimiter($content);
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $rows = [];
    while (false !== ($row = fgetcsv($handle, null, $delimiter, '"', ''))) {
      $rows[] = array_map(static fn($value): ?string => null === $value ? null : (string)$value, $row);
      if (count($rows) > $maxRows + 1) {
        fclose($handle);
        throw self::tooLarge($maxRows);
      }
    }
    fclose($handle);

    return self::table($rows) + ['delimiter' => $delimiter];
  }

  /**
   * @return array{columns: list<string>, rows: list<list<string|null>>, sheet: string}
   */
  private function readSpreadsheet(string $path, string $extension, ?string $sheet, int $maxRows): array
  {
    $sheets = $this->sheets($path, $extension);
    if ([] === $sheets) {
      throw new UserFacingException(I18n::t('The file has no sheets.'));
    }
    if (!in_array($sheet, $sheets, true)) {
      // Without a choice: the first worksheet that has data
      foreach ($sheets as $candidate) {
        $table = $this->readSpreadsheet($path, $extension, $candidate, $maxRows);
        if ([] !== $table['columns']) {
          return $table;
        }
      }
      return ['columns' => [], 'rows' => [], 'sheet' => $sheets[0]];
    }

    try {
      /** @var BaseReader $reader */
      $reader = IOFactory::createReader(self::readerType($extension));
      $reader->setLoadSheetsOnly([$sheet]);
      $reader->setReadEmptyCells(false);
      $worksheet = $reader->load($path)->getSheetByName($sheet) ?? throw new UserFacingException(I18n::t('This sheet does not exist.'));
    } catch (UserFacingException $e) {
      throw $e;
    } catch (Throwable) {
      throw new UserFacingException(I18n::t('The file cannot be read. Is it a valid Excel file?'));
    }

    if ($worksheet->getHighestDataRow() > $maxRows + 1) {
      throw self::tooLarge($maxRows);
    }
    $highestColumn = $worksheet->getHighestDataColumn();

    $rows = [];
    foreach ($worksheet->getRowIterator() as $row) {
      $cells = $row->getCellIterator('A', $highestColumn);
      $cells->setIterateOnlyExistingCells(false);
      $values = [];
      foreach ($cells as $cell) {
        $values[] = null !== $cell ? self::cellValue($cell) : null;
      }
      $rows[] = $values;
    }

    return self::table($rows) + ['sheet' => $sheet];
  }

  private static function cellValue(Cell $cell): ?string
  {
    try {
      $value = $cell->getCalculatedValue();
    } catch (Throwable) {
      $value = $cell->getValue();
    }
    if (null === $value || '' === $value) {
      return null;
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_numeric($value) && Date::isDateTime($cell)) {
      $date = Date::excelToDateTimeObject((float)$value);
      $number = (float)$value;
      if ($number < 1) {
        return $date->format('H:i:s');
      }
      return floor($number) === $number ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
    }
    if (is_float($value)) {
      // 12.0 -> "12"; otherwise as precise as PHP prints floats
      return floor($value) === $value && abs($value) < 1e15 ? (string)(int)$value : (string)$value;
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      return (string)$value;
    }
    return is_scalar($value) ? (string)$value : null;
  }

  /**
   * First non-empty row = header; empty rows and trailing empty columns are dropped.
   *
   * @param list<list<string|null>> $rows
   * @return array{columns: list<string>, rows: list<list<string|null>>}
   */
  private static function table(array $rows): array
  {
    $isEmpty = static fn(array $row): bool => [] === array_filter($row, static fn($v): bool => null !== $v && '' !== trim($v));
    $rows = array_values(array_filter($rows, static fn(array $row): bool => !$isEmpty($row)));
    if ([] === $rows) {
      return ['columns' => [], 'rows' => []];
    }

    $header = array_shift($rows);
    $width = count($header);
    while ($width > 0 && (null === $header[$width - 1] || '' === trim((string)$header[$width - 1]))) {
      $hasData = false;
      foreach ($rows as $row) {
        if (null !== ($row[$width - 1] ?? null) && '' !== trim((string)$row[$width - 1])) {
          $hasData = true;
          break;
        }
      }
      if ($hasData) {
        break;
      }
      $width--;
    }

    $columns = [];
    $seen = [];
    for ($i = 0; $i < $width; $i++) {
      $name = trim((string)($header[$i] ?? ''));
      $name = '' === $name ? 'Spalte '.($i + 1) : $name;
      // Headers must be unique - they are the keys of the mapping
      $unique = $name;
      for ($n = 2; isset($seen[$unique]); $n++) {
        $unique = "{$name} ({$n})";
      }
      $seen[$unique] = true;
      $columns[] = $unique;
    }

    // Database exports write empty values as NULL or \N
    $normalized = array_map(static fn(array $row): array => array_map(
      static fn(int $i): ?string => isset($row[$i]) && !in_array(trim((string)$row[$i]), ['', 'NULL', '\\N'], true) ? (string)$row[$i] : null,
      range(0, $width - 1)
    ), $rows);

    return ['columns' => $columns, 'rows' => $normalized];
  }

  private static function toUtf8(string $content): string
  {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
      $content = (string)mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    } elseif (!mb_check_encoding($content, 'UTF-8')) {
      $content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    return str_replace(["\r\n", "\r"], "\n", $content);
  }

  /**
   * The separator that splits the first lines into the same, highest number of columns.
   */
  private static function detectDelimiter(string $content): string
  {
    $lines = array_slice(array_filter(explode("\n", $content), static fn(string $l): bool => '' !== trim($l)), 0, 10);
    $best = ',';
    $bestScore = 0;
    foreach (self::DELIMITERS as $delimiter) {
      $counts = array_map(static fn(string $line): int => count(str_getcsv($line, $delimiter, '"', '')), $lines);
      if ([] === $counts || $counts[0] < 2) {
        continue;
      }
      // Consistent column counts count more than many columns in one line
      $consistent = count(array_filter($counts, static fn(int $c): bool => $c === $counts[0]));
      $score = $consistent * 1000 + $counts[0];
      if ($score > $bestScore) {
        [$best, $bestScore] = [$delimiter, $score];
      }
    }
    return $best;
  }

  private static function readerType(string $extension): string
  {
    return match ($extension) {
      'xlsx' => 'Xlsx',
      'xls' => 'Xls',
      'ods' => 'Ods',
      default => 'Csv',
    };
  }

  private static function tooLarge(int $maxRows): UserFacingException
  {
    return new UserFacingException(I18n::t('The file is too large (at most {count} rows).', ['count' => $maxRows]), 413, 'import_too_large');
  }
}
