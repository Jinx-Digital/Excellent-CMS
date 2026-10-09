<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Shared\I18n;
use App\Tests\Support\ApiTestCase;

class LocaleTest extends ApiTestCase
{
  public function testMessagesFollowAcceptLanguage(): void
  {
    $english = $this->api('GET', '/main/content/gibtsnicht');
    $this->assertSame('The entity "gibtsnicht" does not exist.', $english['body']['error']);
    $this->assertSame('en', $english['headers']['Content-Language']);

    $german = $this->api('GET', '/main/content/gibtsnicht', headers: ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);
    $this->assertSame('Die Entity „gibtsnicht“ gibt es nicht.', $german['body']['error']);
    $this->assertSame('de', $german['headers']['Content-Language']);

    // Unsupported languages: English
    $this->assertSame('en', $this->api('GET', '/main/content/gibtsnicht', headers: ['Accept-Language' => 'fr-FR'])['headers']['Content-Language']);
  }

  public function testValidationAndPluralsInGerman(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('tags');
    $this->createEntity(['slug' => $slug, 'name' => 'Tags', 'fields' => [
      ['name' => 'words', 'type' => 'string', 'repeatable' => true, 'repeat_min' => 2],
    ]], $admin);
    $de = ['Accept-Language' => 'de'];

    $invalid = $this->api('POST', "/entities/{$slug}/records", ['words' => ['eins']], $admin, $de);
    $this->assertSame('Bitte prüfe die markierten Felder.', $invalid['body']['error']);
    $this->assertSame('Bitte mindestens 2 Werte angeben.', $invalid['body']['error_data']['words'][0]);
    $this->assertSame('Please enter at least 2 values.', $this->api('POST', "/entities/{$slug}/records", ['words' => ['one']], $admin)['body']['error_data']['words'][0]);
  }

  public function testEveryGermanTranslationIsValid(): void
  {
    $messages = require dirname(__DIR__, 3).'/messages/de/app.php';
    $this->assertGreaterThan(250, count($messages));
    foreach ($messages as $english => $german) {
      // Arguments only, not plural branches like one{once}
      $names = static function (string $text): array {
        preg_match_all('/(?<!\w)\{(\w+)/', $text, $match);
        $unique = array_values(array_unique($match[1]));
        sort($unique);
        return $unique;
      };
      $this->assertSame($names($english), $names($german), "placeholders of: {$english}");
      $this->assertNotFalse(\MessageFormatter::create('de', $german), "ICU syntax of: {$german}");
    }
    $this->assertSame('en', I18n::fromAcceptLanguage(''));
  }
}
