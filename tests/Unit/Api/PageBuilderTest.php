<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Page builder: blocks (items of several field groups) and the preview of drafts on the website.
 */
class PageBuilderTest extends ApiTestCase
{
  public function testBlocks(): void
  {
    $admin = $this->login();
    $suffix = bin2hex(random_bytes(3));
    $hero = $this->api('POST', '/admin/groups', ['name' => 'hero_'.$suffix, 'label' => 'Hero', 'kind' => 'block', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'required' => true],
      ['name' => 'image', 'type' => 'media'],
    ]], $admin)['body']['data'];
    $text = $this->api('POST', '/admin/groups', ['name' => 'text_'.$suffix, 'label' => 'Text', 'kind' => 'block', 'fields' => [
      ['name' => 'body', 'type' => 'markdown'],
    ]], $admin)['body']['data'];
    $divider = $this->api('POST', '/admin/groups', ['name' => 'divider_'.$suffix, 'label' => 'Trenner', 'kind' => 'block', 'fields' => [
      ['name' => 'style', 'type' => 'string'],
    ]], $admin)['body']['data'];

    $slug = $this->uniqueSlug('pages');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'content', 'type' => 'group', 'blocks' => [$hero['id'], 'text_'.$suffix, $divider['id']]],
    ]], $admin);
    $field = array_column($entity['fields'], null, 'name')['content'];
    $this->assertTrue($field['repeatable']);
    $this->assertNull($field['group']);
    $this->assertSame(['hero_'.$suffix, 'text_'.$suffix, 'divider_'.$suffix], array_column($field['blocks'], 'name'));
    // A group used as block can not be deleted
    $this->assertSame(409, $this->api('DELETE', "/admin/groups/{$divider['id']}", token: $admin)['status']);

    $image = $this->upload('/media', self::png(), 'hero.png', $admin)['body']['data'];
    $blocks = [
      ['_type' => 'hero_'.$suffix, 'title' => 'Willkommen', 'image' => $image['id']],
      ['_type' => 'text_'.$suffix, '_key' => 'intro', 'body' => 'Wir bauen **Seiten** aus Blöcken.'],
      ['_type' => 'divider_'.$suffix],
      ['_type' => 'text_'.$suffix, '_key' => 'intro', 'body' => 'Doppelter Schlüssel'],
    ];
    // Unknown types and missing required fields name the block
    $bad = $this->api('POST', "/entities/{$slug}/records", ['title' => 'X', 'content' => [['_type' => 'nope'], ['_type' => 'hero_'.$suffix]]], $admin);
    $this->assertSame(422, $bad['status'], json_encode($bad['body'], JSON_UNESCAPED_UNICODE));
    $this->assertStringContainsString('nope', implode(' ', $bad['body']['error_data']['content']));
    $this->assertStringContainsString('Hero', implode(' ', $bad['body']['error_data']['content']));

    $record = $this->createRecord($slug, ['title' => 'Start', 'content' => $blocks], $admin);
    $content = $record['content'];
    $this->assertSame(['hero_'.$suffix, 'text_'.$suffix, 'divider_'.$suffix, 'text_'.$suffix], array_column($content, '_type'));
    $this->assertSame('intro', $content[1]['_key']);
    $this->assertNotSame('intro', $content[3]['_key'], 'keys stay unique');
    $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $content[0]['_key']);
    $this->assertSame(['_type' => 'divider_'.$suffix, '_key' => $content[2]['_key'], 'style' => null], $content[2], 'an empty block stays');

    // Content API: typed blocks with the files in them
    $live = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']['content'];
    $this->assertSame('Willkommen', $live[0]['title']);
    $this->assertSame($image['id'], $live[0]['image']['id']);
    $this->assertStringContainsString('/media/', $live[0]['image']['url']);
    $this->assertSame(['_type', '_key', 'body'], array_keys($live[1]));

    // The texts of blocks are searched
    $this->assertSame(['Start'], array_column($this->api('GET', "/main/content/{$slug}?s=bl%C3%B6cken")['body']['data'], 'title'));

    // Reordered and saved again: keys stay
    $reordered = $this->api('PUT', "/entities/{$slug}/records/{$record['id']}", ['content' => [$content[1], $content[0]]], $admin)['body']['data']['content'];
    $this->assertSame([$content[1]['_key'], $content[0]['_key']], array_column($reordered, '_key'));

    // A block type taken out of the field: its blocks are left out
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$field['id']}", ['blocks' => [$hero['id']]], $admin);
    $this->assertSame(['hero_'.$suffix], array_column($this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']['content'], '_type'));
    // Blocks can not become a plain group field
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$field['id']}", ['blocks' => [], 'group' => $hero['id']], $admin)['status']);
  }

  public function testNestedBlocks(): void
  {
    $admin = $this->login();
    $suffix = bin2hex(random_bytes(3));
    $text = $this->api('POST', '/admin/groups', ['name' => 'ntext_'.$suffix, 'label' => 'Text', 'kind' => 'block', 'fields' => [['name' => 'body', 'type' => 'markdown', 'required' => true]]], $admin)['body']['data'];
    $image = $this->api('POST', '/admin/groups', ['name' => 'nimage_'.$suffix, 'label' => 'Bild', 'kind' => 'block', 'fields' => [['name' => 'file', 'type' => 'media']]], $admin)['body']['data'];
    // A column holds blocks itself, columns hold columns
    $column = $this->api('POST', '/admin/groups', ['name' => 'ncol_'.$suffix, 'label' => 'Spalte', 'fields' => [
      ['name' => 'span', 'type' => 'integer'],
      ['name' => 'content', 'type' => 'group', 'blocks' => [$text['id'], $image['id']]],
    ]], $admin);
    $this->assertSame(200, $column['status'], json_encode($column['body'], JSON_UNESCAPED_UNICODE));
    $column = $column['body']['data'];
    $this->assertSame(['ntext_'.$suffix, 'nimage_'.$suffix], array_column($column['fields'][1]['blocks'], 'name'));
    $columns = $this->api('POST', '/admin/groups', ['name' => 'ncols_'.$suffix, 'label' => 'Spalten', 'kind' => 'block', 'fields' => [
      ['name' => 'columns', 'type' => 'group', 'group' => $column['id'], 'repeatable' => true],
    ]], $admin)['body']['data'];
    // No loops: the column must not offer "columns" as block (it contains the column)
    $this->assertSame(422, $this->api('POST', "/admin/groups/{$column['id']}/fields", ['name' => 'inner', 'type' => 'group', 'blocks' => [$columns['id']]], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/groups/{$text['id']}/fields", ['name' => 'self', 'type' => 'group', 'blocks' => [$text['id']]], $admin)['status']);

    $slug = $this->uniqueSlug('npages');
    $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'content', 'type' => 'group', 'blocks' => [$columns['id'], $text['id']]],
    ]], $admin);
    $file = $this->upload('/media', self::png(), 'n.png', $admin)['body']['data'];
    $content = [
      ['_type' => 'ntext_'.$suffix, '_key' => 'same', 'body' => 'Oben'],
      ['_type' => 'ncols_'.$suffix, 'columns' => [
        ['span' => 8, 'content' => [['_type' => 'ntext_'.$suffix, '_key' => 'same', 'body' => 'Links'], ['_type' => 'nimage_'.$suffix, 'file' => $file['id']]]],
        ['span' => 4, 'content' => [['_type' => 'ntext_'.$suffix, 'body' => 'Rechts']]],
      ]],
    ];
    // Inner blocks are checked by their group too
    $bad = $this->api('POST', "/entities/{$slug}/records", ['content' => [['_type' => 'ncols_'.$suffix, 'columns' => [['content' => [['_type' => 'ncols_'.$suffix], ['_type' => 'ntext_'.$suffix]]]]]]], $admin);
    $this->assertSame(422, $bad['status']);
    $this->assertCount(2, $bad['body']['error_data']['content'], json_encode($bad['body']['error_data'], JSON_UNESCAPED_UNICODE));

    $record = $this->createRecord($slug, ['title' => 'Verschachtelt', 'content' => $content], $admin);
    $inner = $record['content'][1]['columns'][0]['content'];
    $this->assertSame(['ntext_'.$suffix, 'nimage_'.$suffix], array_column($inner, '_type'));
    $this->assertSame('same', $record['content'][0]['_key']);
    $this->assertNotSame('same', $inner[0]['_key'], 'keys are unique in the whole value');
    $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $record['content'][1]['columns'][1]['content'][0]['_key']);

    // Content API: typed, files inside nested blocks, texts in the search
    $live = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']['content'];
    $this->assertSame($file['id'], $live[1]['columns'][0]['content'][1]['file']['id']);
    $this->assertSame(8, $live[1]['columns'][0]['span']);
    $this->assertSame(['Verschachtelt'], array_column($this->api('GET', "/main/content/{$slug}?s=rechts")['body']['data'], 'title'));
    // The file counts as used
    $this->assertSame(409, $this->api('DELETE', "/media/{$file['id']}", token: $admin)['status']);
  }

  public function testPreviewOfDraftsAndWorkingCopies(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('posts');
    $_ENV['PREVIEW_TEST_SECRET'] = 'geheim 1';
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Beiträge', 'preview_url' => 'https://example.com/preview', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin)['status'], 'the address needs {{token}}');
    $this->createEntity(['slug' => $slug, 'name' => 'Beiträge', 'access' => 'public', 'drafts' => true, 'preview_url' => 'https://example.com/api/preview?entity={{entity}}&slug={{record.slug}}&lang={{lang}}&token={{token}}&secret=$PREVIEW_TEST_SECRET', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title'],
    ]], $admin);
    $draft = $this->createRecord($slug, ['title' => 'Noch geheim', 'draft' => true], $admin);
    $live = $this->createRecord($slug, ['title' => 'Online'], $admin);
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$live['id']}/working-copy", ['title' => 'Online, überarbeitet'], $admin)['status']);

    $preview = $this->api('POST', "/entities/{$slug}/records/{$draft['id']}/preview", [], $admin);
    $this->assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
    $token = $preview['body']['data']['token'];
    $this->assertSame("https://example.com/api/preview?entity={$slug}&slug=noch-geheim&lang=&token=".rawurlencode($token).'&secret=geheim%201', $preview['body']['data']['url']);

    // Without the token: the live state; with it: drafts and working copies
    $this->assertSame(['Online'], array_column($this->api('GET', "/main/content/{$slug}?sort=title")['body']['data'], 'title'));
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$draft['id']}")['status']);
    $withToken = $this->api('GET', "/main/content/{$slug}?sort=title&preview=".rawurlencode($token));
    $this->assertSame(['Noch geheim', 'Online, überarbeitet'], array_column($withToken['body']['data'], 'title'));
    $one = $this->api('GET', "/main/content/{$slug}/{$draft['id']}", headers: ['X-Preview-Token' => $token]);
    $this->assertSame('Noch geheim', $one['body']['data']['title']);
    $this->assertSame('no-store', $one['headers']['Cache-Control'] ?? null);

    // Tampered or expired tokens are refused
    $this->assertSame(401, $this->api('GET', "/main/content/{$slug}?preview=".rawurlencode($token.'x'))['status']);
    [$payload] = explode('.', $token);
    $expired = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $expired['x'] = time() - 10;
    $forged = rtrim(strtr(base64_encode(json_encode($expired)), '+/', '-_'), '=').'.'.explode('.', $token)[1];
    $this->assertSame(401, $this->api('GET', "/main/content/{$slug}?preview=".rawurlencode($forged))['status']);
    // Only for users who may read the entity
    $this->assertSame(404, $this->api('POST', "/entities/{$slug}/records/nope/preview", [], $admin)['status']);
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(3, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }

  public function testBlocksOfCategories(): void
  {
    $admin = $this->login();
    $suffix = bin2hex(random_bytes(3));
    $image = $this->api('POST', '/admin/groups', ['name' => 'cimage_'.$suffix, 'label' => 'Bild', 'kind' => 'block', 'category' => 'Medien '.$suffix, 'fields' => [['name' => 'file', 'type' => 'string']]], $admin)['body']['data'];
    $text = $this->api('POST', '/admin/groups', ['name' => 'ctext_'.$suffix, 'label' => 'Text', 'kind' => 'block', 'fields' => [['name' => 'body', 'type' => 'text']]], $admin)['body']['data'];

    // The chosen block and all blocks of the category
    $slug = $this->uniqueSlug('cpages');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'fields' => [
      ['name' => 'content', 'type' => 'group', 'blocks' => [$text['id']], 'block_categories' => ['Medien '.$suffix]],
    ]], $admin);
    $field = $entity['fields'][0];
    $this->assertSame([[$text['id']], ['Medien '.$suffix]], [$field['block_ids'], $field['block_categories']]);
    $this->assertSame(['ctext_'.$suffix, 'cimage_'.$suffix], array_column($field['blocks'], 'name'));

    // A block that comes into the category later is there too - and can be used right away
    $this->api('POST', '/admin/groups', ['name' => 'cvideo_'.$suffix, 'label' => 'Video', 'kind' => 'block', 'category' => 'Medien '.$suffix, 'fields' => [['name' => 'url', 'type' => 'string']]], $admin);
    $fields = $this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['fields'];
    $this->assertSame(['ctext_'.$suffix, 'cimage_'.$suffix, 'cvideo_'.$suffix], array_column($fields[0]['blocks'], 'name'));
    $record = $this->api('POST', "/entities/{$slug}/records", ['content' => [['_type' => 'cvideo_'.$suffix, 'url' => 'https://example.com/v']]], $admin);
    $this->assertSame(200, $record['status'], json_encode($record['body'], JSON_UNESCAPED_UNICODE));

    // Only categories (no single block) is a blocks field too; updating other things keeps them
    $only = $this->createEntity(['slug' => $this->uniqueSlug('conly'), 'name' => 'Nur Kategorie', 'fields' => [['name' => 'content', 'type' => 'group', 'block_categories' => ['Medien '.$suffix]]]], $admin);
    $onlyField = $only['fields'][0];
    $this->assertTrue($onlyField['repeatable']);
    $this->api('PUT', "/admin/entities/{$only['id']}/fields/{$onlyField['id']}", ['label' => 'Inhalt'], $admin);
    $this->assertSame(['Medien '.$suffix], $this->api('GET', "/admin/entities/{$only['id']}", token: $admin)['body']['data']['fields'][0]['block_categories']);
  }

  public function testTemplatesOfBlocks(): void
  {
    $admin = $this->login();
    $suffix = bin2hex(random_bytes(3));
    // A template with Markdown and a nested block list; the sandbox refuses what is not allowed
    $text = $this->api('POST', '/admin/groups', ['name' => 'ttext_'.$suffix, 'label' => 'Text', 'kind' => 'block', 'fields' => [['name' => 'body', 'type' => 'markdown']],
      'template' => '<div class="text">{{ block.body|markdown }}</div>'], $admin)['body']['data'];
    $this->assertSame(422, $this->api('PUT', "/admin/groups/{$text['id']}", ['template' => "{% include 'x.twig' %}"], $admin)['status'], 'include is not allowed');
    $this->assertSame(422, $this->api('PUT', "/admin/groups/{$text['id']}", ['template' => '{{ block.body|upper }'], $admin)['status'], 'syntax');
    $box = $this->api('POST', '/admin/groups', ['name' => 'tbox_'.$suffix, 'label' => 'Box', 'kind' => 'block', 'fields' => [
      ['name' => 'title', 'type' => 'string'], ['name' => 'content', 'type' => 'group', 'blocks' => [$text['id']]],
    ], 'template' => '<section><h2>{{ block.title }}</h2>{{ render_blocks(block.content) }}</section>'], $admin)['body']['data'];
    $this->assertTrue($box['has_template']);

    // Trying it in the admin app: an example of the values, or the sent ones
    $tried = $this->api('POST', "/admin/groups/{$box['id']}/render", ['block' => ['title' => '<Hallo>', 'content' => [['_type' => 'ttext_'.$suffix, '_key' => 'k1', 'body' => '**fett**']]]], $admin)['body']['data'];
    $this->assertSame('<section><h2>&lt;Hallo&gt;</h2><div class="text"><p><strong>fett</strong></p>'."\n".'</div></section>', $tried['html']);

    // The content API delivers "_html" with ?render=html
    $slug = $this->uniqueSlug('tpages');
    $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'drafts' => true, 'preview_url' => 'https://example.com/p?token={{token}}', 'fields' => [['name' => 'title', 'type' => 'string'], ['name' => 'content', 'type' => 'group', 'blocks' => [$box['id'], $text['id']]]]], $admin);
    $record = $this->createRecord($slug, ['title' => 'Start', 'draft' => false, 'content' => [
      ['_type' => 'tbox_'.$suffix, 'title' => 'Box', 'content' => [['_type' => 'ttext_'.$suffix, 'body' => 'innen']]],
      ['_type' => 'ttext_'.$suffix, 'body' => '<script>x</script>'],
    ]], $admin);
    $this->assertArrayNotHasKey('_html', $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']['content'][0], 'only when asked for');
    $content = $this->api('GET', "/main/content/{$slug}/{$record['id']}?render=html")['body']['data']['content'];
    $this->assertSame('<section><h2>Box</h2><div class="text"><p>innen</p>'."\n".'</div></section>', $content[0]['_html']);
    $this->assertStringContainsString('&lt;script&gt;', $content[1]['_html'], 'HTML inside Markdown is escaped');

    // Live editing: unsaved blocks rendered - only with a preview token
    $blocks = ['field' => 'content', 'blocks' => [['_type' => 'ttext_'.$suffix, '_key' => 'n1', 'body' => 'neu']]];
    $this->assertSame(403, $this->api('POST', "/main/content/{$slug}/render", $blocks)['status']);
    $token = $this->api('POST', "/entities/{$slug}/records/{$record['id']}/preview", [], $admin)['body']['data']['token'];
    $rendered = $this->api('POST', "/main/content/{$slug}/render?preview=".rawurlencode($token), $blocks)['body']['data'];
    $this->assertSame('<div class="text"><p>neu</p>'."\n".'</div>', $rendered[0]['_html']);
  }
}
