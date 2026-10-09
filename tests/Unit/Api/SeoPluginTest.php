<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use ZipArchive;

/**
 * The plugin "seo" (excellent-plugins/seo): the field group SEO, redirects (exact and "…/*", 410,
 * counted) and a sitemap.xml of the published records of the entities in the entity "sitemap".
 */
class SeoPluginTest extends ApiTestCase
{
  public function testFieldGroupRedirectsAndSitemap(): void
  {
    $admin = $this->login();
    if (($this->api('GET', '/admin/plugins/seo', token: $admin)['body']['data']['installed'] ?? false) === true) {
      $this->api('DELETE', '/admin/plugins/seo', token: $admin);
    }
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $this->zipOf('seo'), 'seo.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/seo/install', token: $admin);

    $project = 's'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Website', 'slug' => $project, 'table_prefix' => $project.'_'], $admin)['body']['data'];
    $here = ['X-Project' => $project];
    $this->assertSame(200, $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'url', 'value' => 'https://www.example.com']]], $admin, $here)['status']);
    // Pages with the field group SEO - before activating: the sitemap gets an entry for them
    $activation = $this->api('POST', '/admin/plugins/seo/activate', token: $admin, headers: $here);
    $this->assertSame(200, $activation['status'], json_encode($activation['body'], JSON_UNESCAPED_UNICODE));

    $seo = $this->api('GET', '/admin/groups/seo', token: $admin, headers: $here)['body']['data'];
    $this->assertSame(['group', 'seo'], [$seo['kind'], $seo['managed_by']]);
    $this->assertSame(['title', 'description', 'image', 'canonical', 'noindex'], array_column($seo['fields'], 'name'));
    $entities = array_column($this->api('GET', '/admin/entities', token: $admin, headers: $here)['body']['data'], null, 'slug');
    $this->assertSame('seo', $entities['redirects']['managed_by']);
    $this->assertSame('seo', $entities['sitemap']['managed_by']);

    // Redirects: exact (with or without slash, query ignored), "…/*" passes the rest on, 410 has no target
    foreach ([['path' => '/alte-seite', 'target' => '/neue-seite', 'status' => '301'], ['path' => '/blog/*', 'target' => 'https://news.example.com/*', 'status' => '302'], ['path' => '/blog/archiv/*', 'target' => '/archiv/*', 'status' => '301'], ['path' => '/weg', 'status' => '410']] as $redirect) {
      $this->assertSame(200, $this->api('POST', '/entities/redirects/records', $redirect, $admin, $here)['status']);
    }
    $find = fn(string $path): array => $this->api('GET', "/{$project}/plugins/seo/redirect?path=".rawurlencode($path));
    $this->assertSame(['path' => '/alte-seite', 'target' => '/neue-seite', 'status' => 301], $find('/alte-seite/?utm=x')['body']['data']);
    $this->assertSame(['https://news.example.com/2026/hallo', 302], [$find('/blog/2026/hallo')['body']['data']['target'], $find('/blog/2026/hallo')['body']['data']['status']]);
    $this->assertSame('/archiv/alt', $find('https://www.example.com/blog/archiv/alt')['body']['data']['target'], 'the longest match wins');
    $this->assertSame([null, 410], [$find('/weg')['body']['data']['target'], $find('/weg')['body']['data']['status']]);
    $this->assertSame(404, $find('/gibt-es-nicht')['status']);
    $counted = array_column($this->api('GET', '/entities/redirects/records', token: $admin, headers: $here)['body']['data'], null, 'path');
    $this->assertSame(1, $counted['/alte-seite']['hits']);
    $this->assertNotNull($counted['/alte-seite']['last_hit']);
    $this->assertCount(4, $this->api('GET', "/{$project}/plugins/seo/redirects")['body']['data']);

    // Sitemap: published pages only - no drafts, nothing in the trash, no noindex, no canonical of another page
    $blog = $this->api('POST', '/admin/entities', ['slug' => 'blog', 'name' => 'Blog', 'drafts' => true, 'trash' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string'], ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title'], ['name' => 'seo', 'type' => 'group', 'group' => 'seo'],
    ]], $admin, $here);
    $this->assertSame(200, $blog['status'], json_encode($blog['body'], JSON_UNESCAPED_UNICODE));
    $source = $this->api('POST', '/entities/sitemap/records', ['entity' => 'blog', 'url' => '{{url}}/blog/{slug}', 'changefreq' => 'weekly', 'priority' => 0.6], $admin, $here);
    $this->assertSame(200, $source['status'], json_encode($source['body'], JSON_UNESCAPED_UNICODE));
    $post = function (array $data) use ($admin, $here): array {
      $result = $this->api('POST', '/entities/blog/records', $data, $admin, $here);
      $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
      return $result['body']['data'];
    };
    $post(['title' => 'Erster Beitrag', 'draft' => false]);
    $post(['title' => 'Entwurf', 'draft' => true]);
    $post(['title' => 'Versteckt', 'draft' => false, 'seo' => ['noindex' => true]]);
    $post(['title' => 'Doppelt', 'draft' => false, 'seo' => ['canonical' => 'https://www.example.com/blog/erster-beitrag']]);
    $trashed = $post(['title' => 'Geloescht', 'draft' => false]);
    $this->api('DELETE', "/entities/blog/records/{$trashed['id']}", token: $admin, headers: $here);

    $sitemap = $this->fetch("http://localhost/api/v1/{$project}/plugins/seo/sitemap.xml");
    $this->assertSame(200, $sitemap['status']);
    $this->assertStringStartsWith('application/xml', $sitemap['headers']['Content-Type'] ?? '');
    $xml = simplexml_load_string($sitemap['content']);
    $this->assertNotFalse($xml);
    $locs = [];
    foreach ($xml->url as $url) {
      $locs[] = (string)$url->loc;
    }
    $this->assertSame(['https://www.example.com/blog/erster-beitrag'], $locs, $sitemap['content']);
    $this->assertSame(['weekly', '0.6'], [(string)$xml->url[0]->changefreq, (string)$xml->url[0]->priority]);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)$xml->url[0]->lastmod);
  }

  private function zipOf(string $name): string
  {
    $source = $this->pluginSource($name);
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
      $zip->addFromString($name.'/'.substr($file->getPathname(), strlen($source) + 1), (string)file_get_contents($file->getPathname()));
    }
    $zip->close();
    $content = (string)file_get_contents($path);
    unlink($path);
    return $content;
  }
}
