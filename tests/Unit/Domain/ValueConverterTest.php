<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Application\Import\TypeDetector;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\ValueConverter;
use App\Shared\Naming;
use Codeception\Test\Unit;

class ValueConverterTest extends Unit
{
  public function testConvertsInputFormats(): void
  {
    foreach (self::validValues() as [$type, $input, $expected]) {
      $this->assertSame($expected, ValueConverter::convert($type, $input), "{$type->value}: {$input}");
    }
  }

  private static function validValues(): array
  {
    return [
      [FieldType::Integer, '1.234', '1234'],
      [FieldType::Integer, '12,00', '12'],
      [FieldType::Integer, 12.0, '12'],
      [FieldType::Decimal, '12,5', '12.50'],
      [FieldType::Decimal, '1.234,56', '1234.56'],
      [FieldType::Decimal, '1,234.56', '1234.56'],
      [FieldType::Decimal, '0.125', '0.13'],
      [FieldType::Boolean, 'ja', true],
      [FieldType::Boolean, 'Nein', false],
      [FieldType::Boolean, '0', false],
      [FieldType::Date, '29.10.2025', '2025-10-29'],
      [FieldType::Date, '29.10.25', '2025-10-29'],
      [FieldType::Date, '2025-10-29 00:00:00', '2025-10-29'],
      [FieldType::DateTime, '2025-10-29T18:00:00+02:00', '2025-10-29 16:00:00'],
      [FieldType::DateTime, '29.10.2025 18:00', '2025-10-29 18:00:00'],
      [FieldType::Time, '8:05', '08:05:00'],
      [FieldType::String, '  Text  ', 'Text'],
      [FieldType::String, '', null],
      [FieldType::Uuid, '550E8400-E29B-41D4-A716-446655440000', '550e8400-e29b-41d4-a716-446655440000'],
      [FieldType::Uuid, '{550e8400e29b41d4a716446655440000}', '550e8400-e29b-41d4-a716-446655440000'],
      [FieldType::AutoIncrement, '1.234', '1234'],
      [FieldType::Slug, 'Größe & Gewicht', 'groesse-gewicht'],
      [FieldType::Markdown, "# Titel\n\nText", "# Titel\n\nText"],
    ];
  }

  public function testRejectsInvalidValues(): void
  {
    foreach (self::invalidValues() as [$type, $input]) {
      try {
        ValueConverter::convert($type, $input, 5);
        $this->fail("{$type->value}: „{$input}“ should be rejected");
      } catch (InvalidValueException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  private static function invalidValues(): array
  {
    return [
      [FieldType::Integer, '1.5'],
      [FieldType::Decimal, 'teuer'],
      [FieldType::Boolean, 'vielleicht'],
      [FieldType::Date, '31.02.2025'],
      [FieldType::Email, 'kein-mail'],
      [FieldType::Url, 'ftp://x.de'],
      [FieldType::String, 'zu lang'],
      [FieldType::Uuid, '550e8400-e29b-41d4-a716-44665544000'],
      [FieldType::AutoIncrement, '0'],
    ];
  }

  public function testTypeDetection(): void
  {
    $this->assertSame('boolean', TypeDetector::detect(['0', '1', '1'])['type']);
    $this->assertSame('integer', TypeDetector::detect(['100', '101', null])['type']);
    $this->assertSame('decimal', TypeDetector::detect(['12,50', '3'])['type']);
    $this->assertSame('string', TypeDetector::detect(['01234', '80331'])['type'], 'ZIP codes keep their leading zero');
    $this->assertSame('date', TypeDetector::detect(['29.10.2025', '2025-11-01'])['type']);
    $this->assertSame('datetime', TypeDetector::detect(['2025-10-29', '2025-10-29 18:00:00'])['type']);
    $this->assertSame('text', TypeDetector::detect([str_repeat('x', 300)])['type']);
    $this->assertSame('string', TypeDetector::detect(['12', 'abc'])['type']);
    $this->assertTrue(TypeDetector::detect(['a', 'b'], 'uuid')['unique']);
    $this->assertFalse(TypeDetector::detect(['a', 'b'], 'name')['unique']);
    $this->assertFalse(TypeDetector::detect(['a', null], 'uuid')['required']);

    $uuids = TypeDetector::detect(['550e8400-e29b-41d4-a716-446655440000', 'c9bf9e57-1685-4c89-bafb-ff5af830be8a'], 'uuid');
    $this->assertSame(['uuid', 4, true], [$uuids['type'], $uuids['uuid_version'], $uuids['unique']]);
    $this->assertSame('string', TypeDetector::detect([md5('a'), md5('b')])['type'], 'Hashes are no UUIDs');
  }

  public function testGeneratesUuidsOfTheChosenVersion(): void
  {
    foreach (array_keys(FieldType::UUID_VERSIONS) as $version) {
      $uuid = FieldType::newUuid($version);
      $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-'.$version.'[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    }
  }

  public function testNaming(): void
  {
    $this->assertSame('tournament_start_date', Naming::snake('TournamentStartDate'));
    $this->assertSame('logo_id', Naming::snake('LogoId'));
    $this->assertSame('iso_2', Naming::snake('ISO-2'));
    $this->assertSame('gueltig_ab', Naming::snake('Gültig ab'));
    $this->assertSame('f_2024', Naming::snake('2024'));
  }
}
