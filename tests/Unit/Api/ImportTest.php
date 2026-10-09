<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportTest extends ApiTestCase
{
  /**
   * German Excel export: semicolon, decimal comma, d.m.Y dates, Windows-1252, BOM-less.
   */
  public function testAnalyzeGermanCsv(): void
  {
    $csv = mb_convert_encoding("Artikelnummer;Bezeichnung;Preis;Gültig ab;Aktiv;Land;Menge\nA-1;Kaffeetasse;12,50;29.10.2025;ja;DE;3\nA-2;Müslischale;1.234,99;01.11.2025;nein;AT;\n", 'Windows-1252', 'UTF-8');
    $result = $this->upload('/imports', $csv, 'Artikel Liste.csv', $this->login());

    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    $data = $result['body']['data'];
    $this->assertSame(';', $data['delimiter']);
    $this->assertSame(2, $data['row_count']);
    $this->assertSame('artikel_liste', $data['entity']['slug']);

    $suggestions = array_column(array_column($data['columns'], 'suggestion'), null, 'field');
    $this->assertSame('string', $suggestions['artikelnummer']['type']);
    $this->assertTrue($suggestions['artikelnummer']['unique']);
    $this->assertSame('Müslischale', $data['columns'][1]['samples'][1]);
    $this->assertSame('decimal', $suggestions['preis']['type']);
    $this->assertSame('date', $suggestions['gueltig_ab']['type']);
    $this->assertSame('boolean', $suggestions['aktiv']['type']);
    $this->assertSame('integer', $suggestions['menge']['type']);
    $this->assertFalse($suggestions['menge']['required']);
    // Plain numbers are no reference to countries (number 3 exists there)
    $this->assertNull($suggestions['menge']['reference']);
  }

  public function testReferenceSuggestions(): void
  {
    $admin = $this->login();
    $csv = "name;country;nationality\nGermany;Germany;DEU\nAustria;Austria;AUT\n";
    $suggestions = array_column(array_column($this->upload('/imports', $csv, 'refs.csv', $admin)['body']['data']['columns'], 'suggestion'), null, 'field');

    // Values of a unique field (countries.alpha3code) -> reference found by it
    $this->assertSame(['countries', 'alpha3code'], [$suggestions['nationality']['reference'], $suggestions['nationality']['match']]);
    // Values of countries.name under another column name -> reference
    $this->assertSame(['countries', 'name'], [$suggestions['country']['reference'], $suggestions['country']['match']]);
    // Same name as the target field: the same data, no reference
    $this->assertNull($suggestions['name']['reference']);
  }

  public function testColumnsBecomeEntitiesOrAddMissingRecords(): void
  {
    $admin = $this->login();
    $genres = $this->uniqueSlug('genres');
    $books = $this->uniqueSlug('books');
    // Tag values of this run only (other tests leave entities behind)
    [$old, $new] = ['Alt '.bin2hex(random_bytes(3)), 'Neu '.bin2hex(random_bytes(3))];
    $csv = "title;genre;tag\nEins;Krimi | Fantasy;".mb_strtolower($old)."\nZwei;krimi;{$new}\nDrei;Roman;\n";
    $tags = $this->createEntity(['slug' => $this->uniqueSlug('tags'), 'name' => 'Tags', 'label_field' => 'name', 'fields' => [['name' => 'name', 'type' => 'string', 'required' => true, 'unique' => true]]], $admin);
    $this->createRecord($tags['slug'], ['name' => $old], $admin);

    $upload = $this->upload('/imports', $csv, 'books.csv', $admin)['body']['data'];
    // The tag column fits "Tags" for half of the values: offered, not suggested
    $tagColumn = array_column($upload['columns'], null, 'column')['tag'];
    $this->assertSame([$tags['slug'], 'name', 1, 2], [$tagColumn['references'][0]['entity'], $tagColumn['references'][0]['match'], $tagColumn['references'][0]['found'], $tagColumn['references'][0]['total']]);

    $plan = ['sheet' => $upload['sheet'], 'delimiter' => $upload['delimiter'], 'mode' => 'new', 'entity' => ['slug' => $books, 'name' => 'Bücher', 'access' => 'public', 'label_field' => 'title'], 'columns' => [
      ['column' => 'title', 'field' => 'title', 'type' => 'string', 'required' => true],
      // "genre" becomes an entity of its own, one record per different value
      ['column' => 'genre', 'field' => 'genre', 'type' => 'reference', 'repeatable' => true, 'new_reference' => ['slug' => $genres, 'name' => 'Genres']],
      // "tag" points to the existing tags, values that are missing are added (upper/lower case does not matter)
      ['column' => 'tag', 'field' => 'tag', 'type' => 'reference', 'reference' => $tags['slug'], 'match' => 'name', 'create_missing' => true],
    ]];

    // Preview: nothing is created yet
    $preview = $this->api('POST', "/imports/{$upload['import_id']}/preview", $plan, $admin);
    $this->assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['create' => 3, 'update' => 0, 'error' => 0], $preview['body']['data']['summary']);
    $this->assertSame([true, 3, ['Krimi', 'Fantasy', 'Roman']], [$preview['body']['data']['new_references']['genre']['new'], $preview['body']['data']['new_references']['genre']['count'], $preview['body']['data']['new_references']['genre']['values']]);
    $this->assertSame([false, [$new]], [$preview['body']['data']['new_references']['tag']['new'], $preview['body']['data']['new_references']['tag']['values']]);
    $this->assertSame(404, $this->api('GET', "/entities/{$genres}/records", token: $admin)['status']);
    $this->assertCount(1, $this->api('GET', "/entities/{$tags['slug']}/records", token: $admin)['body']['data']);

    // Run: genres and the missing tag exist, the books point to them
    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", $plan, $admin);
    $this->assertSame(200, $run['status'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['Fantasy', 'Krimi', 'Roman'], array_column($this->api('GET', "/entities/{$genres}/records?sort=name", token: $admin)['body']['data'], 'name'));
    $this->assertSame([$old, $new], array_column($this->api('GET', "/entities/{$tags['slug']}/records?sort=name", token: $admin)['body']['data'], 'name'));
    $records = array_column($this->api('GET', "/entities/{$books}/records", token: $admin)['body']['data'], null, 'title');
    $this->assertSame(['Krimi', 'Fantasy'], array_column($records['Eins']['_refs']['genre'], 'label'));
    $this->assertSame(['Krimi'], array_column($records['Zwei']['_refs']['genre'], 'label'));
    $this->assertSame([$old, $new], [$records['Eins']['_refs']['tag']['label'], $records['Zwei']['_refs']['tag']['label']]);

    // Missing records cannot be added when the target needs more than the match field
    $strict = $this->createEntity(['slug' => $this->uniqueSlug('strict'), 'name' => 'Streng', 'fields' => [['name' => 'name', 'type' => 'string', 'unique' => true], ['name' => 'code', 'type' => 'string', 'required' => true]]], $admin);
    $plan['entity']['slug'] = $this->uniqueSlug('books');
    $plan['columns'] = [$plan['columns'][0], ['column' => 'tag', 'field' => 'tag', 'type' => 'reference', 'reference' => $strict['slug'], 'match' => 'name', 'create_missing' => true]];
    $again = $this->upload('/imports', $csv, 'books.csv', $admin)['body']['data'];
    $refused = $this->api('POST', "/imports/{$again['import_id']}/preview", $plan, $admin);
    $this->assertSame(422, $refused['status']);
    $this->assertArrayHasKey('columns.tag.create_missing', $refused['body']['error_data']);
  }

  public function testImportNewEntityWithReferenceAndErrors(): void
  {
    $slug = $this->uniqueSlug('people');
    $csv = "uuid,name,email,country,born\n"
      ."p1,Anna,anna@example.com,DE,1990-05-01\n"
      ."p2,Ben,ben@example.com,XX,1985-12-24\n"   // unknown country
      ."p3,Cem,kein-mail,AT,1970-01-01\n"         // invalid e-mail
      ."p1,Dora,dora@example.com,CH,2000-02-29\n"; // duplicate uuid
    $import = $this->importNew($csv, 'people.csv', ['slug' => $slug, 'name' => 'Personen', 'access' => 'oauth'], [
      'uuid' => ['unique' => true],
      'email' => ['type' => 'email'],
      'country' => ['type' => 'reference', 'reference' => 'countries', 'match' => 'alpha2code'],
    ]);

    $result = $import['result'];
    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['create' => 1, 'update' => 0, 'error' => 3], $result['body']['data']['summary']);
    $errors = array_column($result['body']['data']['errors'], 'message', 'line');
    $this->assertStringContainsString('"XX" was not found in "countries"', $errors[3]);
    $this->assertStringContainsString('e-mail address', $errors[4]);
    $this->assertStringContainsString('more than once (row 2)', $errors[5]);

    $records = $this->api('GET', "/entities/{$slug}/records", token: $this->login())['body']['data'];
    $this->assertSame('Anna', $records[0]['name']);
    $this->assertSame('Germany', $records[0]['_refs']['country']['label']);
  }

  public function testYesNoValuesCanBeChosen(): void
  {
    $slug = $this->uniqueSlug('shops');
    $csv = "name,active\nA,ja\nB,Nein\nC,1\nD,0\nE,\nF,vielleicht\n";
    $import = $this->importNew($csv, 'shops.csv', ['slug' => $slug, 'name' => 'Läden'], [
      // Text, not a reference to records of other tests that are called A, B ...
      'name' => ['type' => 'string', 'reference' => null, 'match' => null],
      // 1 / ja = yes, 0 / nein = no, empty = no
      'active' => ['type' => 'boolean', 'true_values' => ['ja', '1'], 'false_values' => ['nein', '0'], 'empty_false' => true],
    ]);
    // The analysis lists the values of small columns
    $column = array_column($import['analysis']['columns'], null, 'column')['active'];
    $this->assertSame(['ja', 'Nein', '1', '0', 'vielleicht'], $column['values']);

    $result = $import['result'];
    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['create' => 5, 'update' => 0, 'error' => 1], $result['body']['data']['summary']);
    $this->assertStringContainsString('"vielleicht" is neither yes (ja, 1) nor no (nein, 0)', $result['body']['data']['errors'][0]['message']);
    $name = array_column($import['analysis']['columns'], null, 'column')['name']['suggestion']['field'];
    $records = $this->api('GET', "/entities/{$slug}/records?sort={$name}", token: $this->login())['body']['data'];
    $this->assertSame(['A' => true, 'B' => false, 'C' => true, 'D' => false, 'E' => false], array_column($records, 'active', $name));
  }

  public function testYesNoSuggestion(): void
  {
    $upload = $this->upload('/imports', "name,newsletter\nA,yes\nB,no\nC,\n", 'list.csv', $this->login());
    $column = array_column($upload['body']['data']['columns'], null, 'column')['newsletter'];
    $this->assertSame('boolean', $column['suggestion']['type']);
    $this->assertSame([['yes'], ['no'], false], [$column['suggestion']['true_values'], $column['suggestion']['false_values'], $column['suggestion']['empty_false']]);
  }

  public function testPreviewWritesNothingAndRunUpdatesByKey(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('stock');
    $first = $this->importNew("sku;qty\nA;1\nB;2\n", 'stock.csv', ['slug' => $slug, 'name' => 'Bestand'], ['sku' => ['unique' => true]], $admin);
    $this->assertSame(2, $first['result']['body']['data']['summary']['create']);

    $upload = $this->upload('/imports', "SKU;Qty;Notiz\nA;10;neu\nC;5;\n", 'stock2.csv', $admin)['body']['data'];
    // The file fits the existing entity: suggested with key
    $match = array_values(array_filter($upload['existing'], static fn(array $m): bool => $m['entity'] === $slug))[0];
    $this->assertSame(0.67, $match['score']);
    $this->assertSame(['SKU' => 'sku', 'Qty' => 'qty', 'Notiz' => null], $match['columns']);
    $this->assertSame('sku', $match['key']);

    $plan = ['mode' => 'existing', 'target' => $slug, 'key' => 'sku', 'columns' => [
      ['column' => 'SKU', 'field' => 'sku'],
      ['column' => 'Qty', 'field' => 'qty'],
      ['column' => 'Notiz', 'field' => 'note', 'new' => true, 'type' => 'string', 'label' => 'Notiz'],
    ]];
    $preview = $this->api('POST', "/imports/{$upload['import_id']}/preview", $plan, $admin)['body']['data'];
    $this->assertSame(['create' => 1, 'update' => 1, 'error' => 0], $preview['summary']);
    $this->assertSame(2, $this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['meta']['total_items']);

    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", $plan, $admin);
    $this->assertSame(200, $run['status'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
    $records = array_column($this->api('GET', "/entities/{$slug}/records?sort=sku", token: $admin)['body']['data'], null, 'sku');
    $this->assertSame(10, $records['A']['qty']);
    $this->assertSame('neu', $records['A']['note']);
    $this->assertSame(2, $records['B']['qty']);
    $this->assertSame(5, $records['C']['qty']);

    // The upload is gone after the run
    $this->assertSame(410, $this->api('POST', "/imports/{$upload['import_id']}/preview", $plan, $admin)['status']);
  }

  public function testExcelWithDatesAndSheets(): void
  {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Leer');
    $sheet = $spreadsheet->createSheet()->setTitle('Termine');
    $sheet->fromArray([['Titel', 'Beginn', 'Preis']]);
    $sheet->setCellValue('A2', 'Workshop');
    $sheet->setCellValue('B2', Date::PHPToExcel(new \DateTime('2025-10-29 18:30:00')));
    $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('dd.mm.yyyy hh:mm');
    $sheet->setCellValue('C2', 19.9);
    $file = tempnam(sys_get_temp_dir(), 'xlsx');
    (new Xlsx($spreadsheet))->save($file);
    $content = (string)file_get_contents($file);
    unlink($file);

    $admin = $this->login();
    $upload = $this->upload('/imports', $content, 'termine.xlsx', $admin)['body']['data'];
    $this->assertSame(['Leer', 'Termine'], $upload['sheets']);

    $analysis = $this->api('POST', "/imports/{$upload['import_id']}/analyze", ['sheet' => 'Termine'], $admin)['body']['data'];
    $suggestions = array_column(array_column($analysis['columns'], 'suggestion'), null, 'field');
    $this->assertSame('datetime', $suggestions['beginn']['type']);
    $this->assertSame('decimal', $suggestions['preis']['type']);

    $slug = $this->uniqueSlug('termine');
    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", [
      'sheet' => 'Termine', 'mode' => 'new', 'entity' => ['slug' => $slug, 'name' => 'Termine'],
      'columns' => array_map(static fn(array $c): array => ['column' => $c['column']] + $c['suggestion'], $analysis['columns']),
    ], $admin);
    $this->assertSame(1, $run['body']['data']['summary']['create'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
    $record = $this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['data'][0];
    $this->assertSame('2025-10-29 18:30:00', $record['beginn']);
    $this->assertSame(19.9, $record['preis']);
  }

  public function testImportPermissions(): void
  {
    // The editor may update countries, but import nothing
    $this->assertSame(403, $this->upload('/imports', "name\nX\n", 'x.csv', $this->editorToken())['status']);

    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('imp'), 'name' => 'Import', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin);
    $email = $this->uniqueSlug('importer').'@example.com';
    $this->api('POST', '/admin/users', ['name' => 'Importeur', 'email' => $email, 'password' => 'passwort123', 'permissions' => [$entity['id'] => ['import' => true]]], $admin);
    $importer = $this->login($email, 'passwort123');

    $upload = $this->upload('/imports', "name\nX\n", 'x.csv', $importer);
    $this->assertSame(200, $upload['status'], json_encode($upload['body'], JSON_UNESCAPED_UNICODE));
    $id = $upload['body']['data']['import_id'];
    $this->assertFalse($upload['body']['data']['may_create']);

    // No new entities and no new fields for non-admins
    $new = ['mode' => 'new', 'entity' => ['slug' => $this->uniqueSlug('x'), 'name' => 'X'], 'columns' => [['column' => 'name', 'field' => 'name', 'type' => 'string']]];
    $this->assertSame(403, $this->api('POST', "/imports/{$id}/preview", $new, $importer)['status']);
    $newField = ['mode' => 'existing', 'target' => $entity['slug'], 'columns' => [['column' => 'name', 'field' => 'extra', 'new' => true, 'type' => 'string']]];
    $this->assertSame(422, $this->api('POST', "/imports/{$id}/preview", $newField, $importer)['status']);
    // Countries: no import permission
    $this->assertSame(403, $this->api('POST', "/imports/{$id}/preview", ['mode' => 'existing', 'target' => 'countries', 'columns' => [['column' => 'name', 'field' => 'name']]], $importer)['status']);
    // Somebody else's upload
    $existing = ['mode' => 'existing', 'target' => $entity['slug'], 'columns' => [['column' => 'name', 'field' => 'name']]];
    $this->assertSame(403, $this->api('POST', "/imports/{$id}/preview", $existing, $admin)['status']);

    $run = $this->api('POST', "/imports/{$id}/run", $existing, $importer);
    $this->assertSame(1, $run['body']['data']['summary']['create'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
  }

  public function testMappingErrorsPointToColumns(): void
  {
    $admin = $this->login();
    $upload = $this->upload('/imports', "a;b\n1;2\n", 'ab.csv', $admin)['body']['data'];
    $result = $this->api('POST', "/imports/{$upload['import_id']}/preview", ['mode' => 'new', 'entity' => ['slug' => 'countries', 'name' => 'Doppelt'], 'columns' => [
      ['column' => 'a', 'field' => 'Ungültig!', 'type' => 'string'],
      ['column' => 'b', 'field' => 'b', 'type' => 'reference'],
    ]], $admin);

    $this->assertSame(422, $result['status']);
    $this->assertArrayHasKey('columns.a.name', $result['body']['error_data']);
    $this->assertArrayHasKey('columns.b.reference', $result['body']['error_data']);
    $this->assertArrayHasKey('entity.slug', $result['body']['error_data']);
  }
}
