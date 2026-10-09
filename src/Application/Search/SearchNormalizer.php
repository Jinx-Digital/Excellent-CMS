<?php

declare(strict_types=1);

namespace App\Application\Search;

use Normalizer;

/**
 * Turns text into search terms - the same for indexing and for searching:
 *
 *   "Das **Sommerfest** in Köln, 2026!"  ->  ["sommerfest", "koln", "2026"]   (stop words "das", "in")
 *
 * Markdown/HTML go, letters are lower case without accents (ä = a, ß = ss), words are split at
 * everything that is no letter or digit, terms shorter than 2 characters and stop words are dropped.
 */
final class SearchNormalizer
{
  public const MIN_LENGTH = 2;
  public const MAX_LENGTH = 64;

  /**
   * @param list<string> $stopwords as entered (they are normalized the same way)
   * @return list<string> terms in their order, repeated ones included (they count for the weight)
   */
  public static function terms(string $text, array $stopwords = []): array
  {
    $text = self::fold(self::plain($text));
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = array_flip(self::stopwords($stopwords));
    $terms = [];
    foreach ($words as $word) {
      if (mb_strlen($word) < self::MIN_LENGTH || isset($stop[$word])) {
        continue;
      }
      $terms[] = mb_substr($word, 0, self::MAX_LENGTH);
    }
    return $terms;
  }

  /**
   * Stop words as terms (normalized, unique).
   *
   * @param list<string> $stopwords
   * @return list<string>
   */
  public static function stopwords(array $stopwords): array
  {
    $result = [];
    foreach ($stopwords as $word) {
      foreach (preg_split('/[^\p{L}\p{N}]+/u', self::fold((string)$word), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $result[$part] = true;
      }
    }
    return array_keys($result);
  }

  /**
   * Lower case without accents: "Fußball Ärger Café" -> "fussball arger cafe"
   */
  public static function fold(string $text): string
  {
    $text = mb_strtolower($text);
    // German first: ß has no decomposition
    $text = strtr($text, ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ł' => 'l', 'đ' => 'd', 'þ' => 'th']);
    if (class_exists(Normalizer::class)) {
      $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
      if (is_string($decomposed)) {
        return (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
      }
    }
    // Without intl: the common accents by hand
    return strtr($text, [
      'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a',
      'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
      'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ć' => 'c',
      'č' => 'c', 'ñ' => 'n', 'ń' => 'n', 'ś' => 's', 'š' => 's', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'ý' => 'y',
    ]);
  }

  /**
   * Text without markup: HTML tags, Markdown links/images (their text stays), emphasis and code marks.
   */
  private static function plain(string $text): string
  {
    $text = strip_tags($text);
    // [text](url) and ![alt](url): keep the text
    $text = (string)preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
}
