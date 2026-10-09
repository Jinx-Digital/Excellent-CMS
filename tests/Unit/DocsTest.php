<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Docs\DocsSync;
use Codeception\Test\Unit;

/**
 * docs/*.md is the documentation and the content of the project "docs" (./yii docs:sync): every page
 * needs its texts, a parent that exists, and links that lead somewhere.
 */
class DocsTest extends Unit
{
  private const DIR = __DIR__.'/../../docs';

  public function testEveryPageIsComplete(): void
  {
    $docs = DocsSync::parse(self::DIR);
    $this->assertGreaterThanOrEqual(20, count($docs));
    foreach ($docs as $slug => $doc) {
      $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
      foreach (['title', 'summary'] as $key) {
        $this->assertNotEmpty($doc[$key], "docs/{$slug}.md: {$key}");
      }
      $this->assertNotEmpty($doc['body'], "docs/{$slug}.md: text");
      $this->assertStringStartsNotWith('# ', $doc['body'], 'the heading is the title');
    }
    $this->assertNull($docs['getting-started']['parent']);
    $this->assertSame('concepts', $docs['media']['parent']);
  }

  public function testLinksBetweenThePagesLeadSomewhere(): void
  {
    foreach (glob(self::DIR.'/*.md') ?: [] as $file) {
      $text = (string)preg_replace('/```.*?```/s', '', (string)file_get_contents($file));
      preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
      foreach ($links[1] as $link) {
        if (preg_match('#^(https?:|mailto:|\.\./)#', $link)) {
          continue;
        }
        [$path, $anchor] = explode('#', $link, 2) + [1 => null];
        $target = '' === $path ? $file : self::DIR.'/'.$path;
        $this->assertFileExists($target, basename($file).': '.$link);
        if (null !== $anchor) {
          preg_match_all('/^#{1,6} (.+)$/m', (string)preg_replace('/```.*?```/s', '', (string)file_get_contents($target)), $heads);
          $slugs = array_map(static fn(string $h): string => str_replace(' ', '-', (string)preg_replace('/[^\w\- ]/u', '', mb_strtolower($h))), $heads[1]);
          $this->assertContains($anchor, $slugs, basename($file).': '.$link);
        }
      }
    }
  }
}
