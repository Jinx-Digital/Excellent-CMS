<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class CodeFieldTest extends ApiTestCase
{
  public function testCodeAsWrittenWithLanguageAndFile(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('snippets');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Snippets', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'snippet', 'type' => 'code'],
    ]], $admin);
    $this->assertSame('code', array_column($entity['fields'], null, 'name')['snippet']['type']);

    // Stored exactly as written (only Windows line breaks become \n), nothing escaped
    $code = "<?php\r\necho '<b>{{ not a variable }}</b>';";
    $record = $this->createRecord($slug, ['title' => 'A', 'snippet' => ['language' => 'php', 'file' => ' page.php ', 'code' => $code]], $admin);
    $this->assertSame(['language' => 'php', 'file' => 'page.php', 'code' => "<?php\necho '<b>{{ not a variable }}</b>';"], $record['snippet']);
    // Plain text: the default language, no file; empty code: no value
    $this->assertSame(['language' => 'html', 'file' => null, 'code' => '<p>Hi</p>'], $this->createRecord($slug, ['snippet' => '<p>Hi</p>'], $admin)['snippet']);
    $this->assertNull($this->createRecord($slug, ['snippet' => ['language' => 'css', 'code' => "  \n"]], $admin)['snippet']);
    // Unknown languages are refused
    $invalid = $this->api('POST', "/entities/{$slug}/records", ['snippet' => ['language' => 'cobol', 'code' => 'x']], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertStringContainsString('cobol', $invalid['body']['error_data']['snippet'][0]);

    // The content API delivers the object
    $delivered = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data'];
    $this->assertSame('php', $delivered['snippet']['language']);

    // Cannot be unique or repeatable
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'u', 'type' => 'code', 'unique' => true], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'r', 'type' => 'code', 'repeatable' => true], $admin)['status']);

    // In blocks: the object is kept (not mistaken for a file or record)
    $block = $this->api('POST', '/admin/groups', ['name' => $this->uniqueSlug('code'), 'label' => 'Code', 'kind' => 'block', 'fields' => [['name' => 'code', 'type' => 'code']]], $admin)['body']['data'];
    $pages = $this->uniqueSlug('pages');
    $this->createEntity(['slug' => $pages, 'name' => 'Seiten', 'fields' => [['name' => 'content', 'type' => 'group', 'blocks' => [$block['name']]]]], $admin);
    $page = $this->createRecord($pages, ['content' => [['_type' => $block['name'], 'code' => ['language' => 'twig', 'file' => 'x.twig', 'code' => '{{ block.title }}']]]], $admin);
    $this->assertSame(['language' => 'twig', 'file' => 'x.twig', 'code' => '{{ block.title }}'], $page['content'][0]['code']);

    // A text field becomes a code field: its values become code in the default language
    $text = $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'old', 'type' => 'text'], $admin)['body']['data'];
    $withText = $this->createRecord($slug, ['old' => 'body { color: red }'], $admin);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$text['id']}", ['type' => 'code'], $admin)['status']);
    $this->assertSame(['language' => 'html', 'file' => null, 'code' => 'body { color: red }'], $this->api('GET', "/entities/{$slug}/records/{$withText['id']}", token: $admin)['body']['data']['old']);
  }
}
