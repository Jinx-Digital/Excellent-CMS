<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class VariableTest extends ApiTestCase
{
  public function testPlaceholdersAreFilledPerLanguage(): void
  {
    $admin = $this->login();
    $slug = 'v'.substr(md5(uniqid('', true)), 0, 8);
    $project = $this->api('POST', '/admin/projects', ['name' => 'Agentur', 'slug' => $slug, 'table_prefix' => $slug.'_', 'languages' => ['de', 'en']], $admin)['body']['data'];
    $site = ['X-Project' => $slug];

    $variables = $this->api('PUT', '/admin/variables', ['variables' => [
      ['name' => 'url', 'translatable' => true, 'value' => 'https://example.de', 'translations' => ['en' => 'https://example.com']],
      ['name' => 'company', 'value' => 'Muster GmbH'],
    ]], $admin, $site);
    $this->assertSame(200, $variables['status'], json_encode($variables['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['url', 'company'], array_column($variables['body']['data'], 'name'));

    // The admin app reads them back (GET /admin/variables, not the content API's /<project>/variables)
    $read = $this->api('GET', '/admin/variables', token: $admin, headers: $site);
    $this->assertSame(200, $read['status'], json_encode($read['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['url', 'company'], array_column($read['body']['data'], 'name'));
    $this->assertSame(['en' => 'https://example.com'], $read['body']['data'][0]['translations']);

    $this->api('POST', '/admin/entities', ['slug' => 'links', 'name' => 'Links', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'translatable' => true],
      ['name' => 'link', 'type' => 'url'],
      ['name' => 'text', 'type' => 'markdown'],
    ]], $admin, $site);

    // A link with a placeholder is valid, stored as typed
    // {{name}} and {{project.name}}, spaces allowed; single braces are plain text
    $record = $this->api('POST', '/entities/links/records', ['title' => 'Impressum von {{company}}', 'link' => '{{url}}/impressum', 'text' => 'Mehr auf {{ project.url }} – {{unbekannt}} und {url} bleiben', '_i18n' => ['title' => ['en' => 'Imprint of {{company}}']]], $admin, $site);
    $this->assertSame(200, $record['status'], json_encode($record['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('{{url}}/impressum', $record['body']['data']['link']);
    $this->assertSame(422, $this->api('POST', '/entities/links/records', ['link' => '{{company}}'], $admin, $site)['status']);
    $this->assertSame(422, $this->api('POST', '/entities/links/records', ['link' => '{url}/impressum'], $admin, $site)['status'], 'no variable any more');

    // The content API fills in the values of the requested language
    $de = $this->api('GET', "/{$slug}/content/links")['body']['data'][0];
    $this->assertSame(['Impressum von Muster GmbH', 'https://example.de/impressum', 'Mehr auf https://example.de – {{unbekannt}} und {url} bleiben'], [$de['title'], $de['link'], $de['text']]);
    $en = $this->api('GET', "/{$slug}/content/links?lang=en")['body']['data'][0];
    $this->assertSame(['Imprint of Muster GmbH', 'https://example.com/impressum'], [$en['title'], $en['link']]);
    $all = $this->api('GET', "/{$slug}/content/links?lang=all")['body']['data'][0];
    $this->assertSame(['de' => 'Impressum von Muster GmbH', 'en' => 'Imprint of Muster GmbH'], $all['title']);

    // The variables themselves
    $this->assertSame(['url' => 'https://example.com', 'company' => 'Muster GmbH'], $this->api('GET', "/{$slug}/variables?lang=en")['body']['data']);
    $this->assertSame(['de' => 'https://example.de', 'en' => 'https://example.com'], $this->api('GET', "/{$slug}/variables?lang=all")['body']['data']['url']);

    // Changing a variable changes every record at once
    $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'url', 'value' => 'https://neu.example'], ['name' => 'company', 'value' => 'Muster GmbH']]], $admin, $site);
    $this->assertSame('https://neu.example/impressum', $this->api('GET', "/{$slug}/content/links?lang=en")['body']['data'][0]['link']);

    $invalid = $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'Mit Leerzeichen'], ['name' => 'x'], ['name' => 'x']]], $admin, $site);
    $this->assertSame(422, $invalid['status']);
    $this->assertSame(['variables.0.name', 'variables.2.name'], array_keys($invalid['body']['error_data']));
    $this->assertSame(403, $this->api('PUT', '/admin/variables', ['variables' => []], $this->editorToken())['status']);
    $this->assertSame($project['id'], $this->api('GET', '/auth/me', token: $admin, headers: $site)['body']['data']['project']['id']);
  }

  public function testMarkdownEscapedUnderscoresAreVariables(): void
  {
    // TipTap writes "_" as "\_" in Markdown: {{company\_name}} is the variable company_name
    $project = new \App\Domain\Project\Project('1', 'p', 'P', 'p_', variables: [['name' => 'company_name', 'translatable' => false, 'value' => 'Muster GmbH', 'translations' => []]]);
    $this->assertTrue($project->hasVariables('**{{company\\_name}}**'));
    $this->assertSame('**Muster GmbH** Muster GmbH {company\\_name}}', $project->replaceVariables('**{{company\\_name}}** {{ project.company\\_name }} {company\\_name}}'));
  }
}
