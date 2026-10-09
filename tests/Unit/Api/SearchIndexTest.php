<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Search index per project: normalized terms, stop words, rebuilding and the global search.
 */
class SearchIndexTest extends ApiTestCase
{
  public function testSearchUsesTheIndex(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('events');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Veranstaltungen', 'access' => 'public', 'label_field' => 'title', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'search_weight' => 3],
      ['name' => 'body', 'type' => 'markdown'],
      ['name' => 'code', 'type' => 'string', 'searchable' => false],
    ]], $admin);
    $titles = fn(string $search): array => array_column($this->api('GET', "/entities/{$slug}/records?s=".rawurlencode($search), token: $admin)['body']['data'], 'title');

    // A new entity is indexed right away
    $status = array_column($this->api('GET', '/admin/search-index', token: $admin)['body']['data']['entities'], null, 'slug')[$slug];
    $this->assertSame('ready', $status['status']);

    $fest = $this->createRecord($slug, ['title' => 'Sommerfest in Köln', 'body' => 'Mit **Musik** und Essen', 'code' => 'X-1234'], $admin);
    $this->createRecord($slug, ['title' => 'Lesung', 'body' => 'Nach dem Sommerfest im Garten', 'code' => 'Y-9'], $admin);

    // Every word has to occur, the last one also as the start of a word; umlauts are merged
    $this->assertSame(['Sommerfest in Köln'], $titles('sommerfest koln'));
    $this->assertSame(['Sommerfest in Köln'], $titles('Koln somm'));
    $this->assertSame(['Sommerfest in Köln'], $titles('musik'), 'Markdown is plain text');
    // Matches in heavier fields come first (title 3×, body 1×); the weight counts at once, no rebuild
    $this->assertSame(['Sommerfest in Köln', 'Lesung'], $titles('sommerfest'));
    $fields = array_column($entity['fields'], 'id', 'name');
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['title']}", ['search_weight' => 1], $admin);
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['body']}", ['search_weight' => 5], $admin);
    $this->assertSame(['Lesung', 'Sommerfest in Köln'], $titles('sommerfest'));
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['body']}", ['search_weight' => 11], $admin)['status']);
    // Fields left out of the search are not in the index
    $this->assertSame([], $titles('x-1234'));
    $postings = $this->db()->createQuery()->from('search_posting')->where(['record_id' => $fest['id']])->count();
    $this->assertGreaterThan(0, $postings);

    // Changes and deletions reach the index
    $this->api('PUT', "/entities/{$slug}/records/{$fest['id']}", ['title' => 'Winterfest in Köln'], $admin);
    $this->assertSame(['Lesung'], $titles('sommerfest'));
    $this->assertSame(['Winterfest in Köln'], $titles('winter'));

    // Stop words: the index has to be rebuilt; until then the columns are searched
    $stop = $this->api('PUT', '/admin/search-index/stopwords', ['stopwords' => "dem\nim\nnach"], $admin)['body']['data'];
    $this->assertContains('dem', $stop['stopwords']['']);
    $this->assertSame('stale', array_column($stop['entities'], 'status', 'slug')[$slug]);
    $this->assertSame(['Lesung'], $titles('garten'), 'searched in the columns meanwhile');
    $rebuilt = $this->api('POST', '/admin/search-index/rebuild', ['entity' => $slug], $admin)['body']['data'];
    $this->assertSame(['ready', 2], [array_column($rebuilt['entities'], 'status', 'slug')[$slug], array_column($rebuilt['entities'], 'indexed', 'slug')[$slug]]);
    $this->assertFalse($this->db()->createQuery()->from('search_term')->where(['term' => 'dem', 'project_id' => $entity['project_id'] ?? $this->db()->createQuery()->from('entity')->select('project_id')->where(['id' => $entity['id']])->scalar()])->andWhere(['in', 'id', $this->db()->createQuery()->from('search_posting')->select('term_id')->where(['entity_id' => $entity['id']])])->exists(), 'stop words are not indexed');
    $this->assertSame(['Lesung'], $titles('nach dem garten'), 'stop words in the search are ignored');

    // Cleared: searched in the columns; rebuilt: in the index again
    $this->api('POST', '/admin/search-index/clear', ['entity' => $slug], $admin);
    $this->assertSame(0, (int)$this->db()->createQuery()->from('search_posting')->where(['entity_id' => $entity['id']])->count());
    $this->assertSame(['Lesung'], $titles('garten'));
    $this->api('POST', '/admin/search-index/rebuild', [], $admin);
    $this->assertSame(['Lesung'], $titles('gart'));

    // Deleted records leave the index
    $this->api('DELETE', "/entities/{$slug}/records/{$fest['id']}", token: $admin);
    $this->assertFalse($this->db()->createQuery()->from('search_posting')->where(['record_id' => $fest['id']])->exists());
  }

  public function testStopwordsPerLanguage(): void
  {
    $admin = $this->login();
    $slug = 'w'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Sprachen', 'slug' => $slug, 'table_prefix' => $slug.'_', 'languages' => ['de', 'en']], $admin);
    $here = ['X-Project' => $slug];
    $this->api('POST', '/admin/entities', ['slug' => 'pages', 'name' => 'Seiten', 'label_field' => 'title', 'fields' => [['name' => 'title', 'type' => 'string', 'translatable' => true]]], $admin, $here);
    $this->api('POST', '/entities/pages/records', ['title' => 'Die Stadt und the river', '_i18n' => ['title' => ['en' => 'The city and the river']]], $admin, $here);

    // German: "und" is a stop word, English: "and" and "the" - per language, only languages of the project
    // ... and for all languages: "river"
    $saved = $this->api('PUT', '/admin/search-index/stopwords', ['stopwords' => ['*' => 'river', '' => 'und die', 'en' => ['and', 'the'], 'fr' => 'le']], $admin, $here)['body']['data'];
    $this->assertSame(['*' => ['river'], '' => ['die', 'und'], 'en' => ['and', 'the']], $saved['stopwords']);
    $this->api('POST', '/admin/search-index/rebuild', [], $admin, $here);
    $terms = fn(string $language): array => $this->db()->createQuery()->from(['p' => 'search_posting'])->innerJoin(['t' => 'search_term'], 't.id = p.term_id')
      ->select('t.term')->where(['p.language' => $language])->andWhere(['in', 'p.entity_id', $this->db()->createQuery()->from('entity')->select('id')->where(['slug' => 'pages', 'project_id' => $this->db()->createQuery()->from('project')->select('id')->where(['slug' => $slug])])])->column();
    $de = $terms('');
    $en = $terms('en');
    sort($de);
    sort($en);
    $this->assertSame(['stadt', 'the'], $de, '"the" is no German stop word, "river" one for all');
    $this->assertSame(['city'], $en);
  }

  public function testGlobalSearch(): void
  {
    $admin = $this->login();
    [$pages, $posts] = [$this->uniqueSlug('pages'), $this->uniqueSlug('posts')];
    $this->createEntity(['slug' => $pages, 'name' => 'Seiten', 'label_field' => 'title', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $this->createEntity(['slug' => $posts, 'name' => 'Beiträge', 'label_field' => 'title', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $word = 'quasar'.bin2hex(random_bytes(3));
    foreach (range(1, 7) as $n) {
      $this->createRecord($pages, ['title' => "Seite {$n} {$word}"], $admin);
    }
    $this->createRecord($posts, ['title' => "Beitrag {$word}"], $admin);

    $result = $this->api('GET', '/search?s='.$word, token: $admin)['body']['data'];
    $groups = array_column($result['groups'], null, 'total');
    $this->assertSame([$pages, $posts], array_column(array_column($result['groups'], 'entity'), 'slug'), 'most matches first');
    $this->assertCount(5, $groups[7]['records'], 'five per entity');
    $this->assertSame("Beitrag {$word}", $groups[1]['records'][0]['label']);

    // Only one entity, more records
    $only = $this->api('GET', '/search?s='.rawurlencode("in:{$pages} {$word}"), token: $admin)['body']['data'];
    $this->assertSame([$pages, 7, 7], [$only['in'], $only['groups'][0]['total'], count($only['groups'][0]['records'])]);
    $this->assertCount(1, $only['groups']);

    // Users only find entities they may read
    $this->assertSame([], $this->api('GET', '/search?s='.$word, token: $this->editorToken())['body']['data']['groups']);
  }

  private function db(): ConnectionInterface
  {
    return $this->container()->get(ConnectionInterface::class);
  }
}
