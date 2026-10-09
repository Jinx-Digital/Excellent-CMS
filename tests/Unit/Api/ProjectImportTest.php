<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * ./yii project:import <folder>: a project from an export of another installation - variables, entities,
 * files and records with new ids, translations and references kept.
 */
class ProjectImportTest extends ApiTestCase
{
  public function testImportOfAnExport(): void
  {
    $slug = 'imp'.bin2hex(random_bytes(3));
    $dir = sys_get_temp_dir().'/export-'.$slug;
    @mkdir($dir.'/records', 0775, true);
    @mkdir($dir.'/media', 0775, true);
    $image = imagecreatetruecolor(4, 3);
    imagepng($image, $dir.'/media/OLDFILE00000000000001.png');
    $put = static fn(string $file, mixed $data) => file_put_contents($dir.'/'.$file, json_encode($data, JSON_UNESCAPED_UNICODE));
    $put('project.json', ['slug' => 'ignored', 'name' => 'Importiert', 'table_prefix' => $slug.'_', 'languages' => ['de', 'en'], 'default_language' => 'de']);
    $put('variables.json', [['name' => 'country', 'label' => 'Land', 'translatable' => true, 'value' => 'Polen', 'translations' => ['en' => 'Poland']]]);
    $put('entities.json', [
      ['slug' => 'about', 'name' => 'Über uns', 'access' => 'public', 'label_field' => 'headline', 'fields' => [
        ['name' => 'headline', 'type' => 'string', 'translatable' => true],
        ['name' => 'image', 'type' => 'media', 'media_accept' => ['image/*']],
        ['name' => 'skills', 'type' => 'reference', 'reference' => 'skills', 'repeatable' => true],
      ]],
      ['slug' => 'skills', 'name' => 'Skills', 'access' => 'public', 'drafts' => true, 'label_field' => 'label', 'fields' => [
        ['name' => 'label', 'type' => 'string', 'translatable' => true], ['name' => 'percent', 'type' => 'integer'],
      ]],
    ]);
    $put('records/skills.json', [
      ['id' => 'OLDSKILL0000000000001', 'label' => 'PHP', 'percent' => 95, 'draft' => false, '_i18n' => ['label' => ['en' => 'PHP (en)']]],
      ['id' => 'OLDSKILL0000000000002', 'label' => 'Entwurf', 'percent' => 10, 'draft' => true],
    ]);
    $put('records/about.json', [['id' => 'OLDABOUT0000000000001', 'headline' => 'Wer wir sind', 'image' => ['id' => 'OLDFILE00000000000001', 'url' => 'https://old/x.png', 'mime_type' => 'image/png'],
      'skills' => [['id' => 'OLDSKILL0000000000001', 'label' => 'PHP'], ['id' => 'OLDSKILL0000000000002', 'label' => 'Entwurf']], '_i18n' => ['headline' => ['en' => 'Who we are']]]]);
    $put('media.json', ['OLDFILE00000000000001' => ['file' => 'media/OLDFILE00000000000001.png', 'name' => 'team.png', 'mime_type' => 'image/png', 'focal_point' => ['x' => 0.2, 'y' => 0.8]]]);

    exec(sprintf('cd %s && APP_ENV=test JWT_SECRET=%s DB_NAME=%s php yii project:import %s --project=%s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg((string)$_ENV['JWT_SECRET']), escapeshellarg($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'excellent_cms_test'), escapeshellarg($dir), $slug), $out, $code);
    $this->assertSame(0, $code, implode("\n", $out));
    $this->assertContains('  about: 1 records', $out);

    $admin = $this->login();
    $here = ['X-Project' => $slug];
    $skills = array_column($this->api('GET', '/entities/skills/records', token: $admin, headers: $here)['body']['data'], null, 'label');
    $this->assertSame([95, false, true], [$skills['PHP']['percent'], (bool)$skills['PHP']['draft'], (bool)$skills['Entwurf']['draft']]);
    $about = $this->api('GET', '/entities/about/records', token: $admin, headers: $here)['body']['data'][0];
    $about = $this->api('GET', "/entities/about/records/{$about['id']}", token: $admin, headers: $here)['body']['data'];
    $this->assertSame('Who we are', $about['_i18n']['headline']['en']);
    $this->assertSame([$skills['PHP']['id'], $skills['Entwurf']['id']], array_map(static fn($ref) => is_array($ref) ? $ref['id'] : $ref, $about['skills']), 'references point to the new records');
    $this->assertSame(['team.png', ['x' => 0.2, 'y' => 0.8]], [$about['image']['name'], $about['image']['focal_point']]);
    $this->assertNotSame('OLDFILE00000000000001', $about['image']['id']);
    $country = array_column($this->api('GET', '/admin/variables', token: $admin, headers: $here)['body']['data'], null, 'name')['country'];
    $this->assertSame(['Polen', 'Poland'], [$country['value'], $country['translations']['en']]);

    // Not twice
    exec(sprintf('cd %s && APP_ENV=test JWT_SECRET=%s DB_NAME=%s php yii project:import %s --project=%s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg((string)$_ENV['JWT_SECRET']), escapeshellarg($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'excellent_cms_test'), escapeshellarg($dir), $slug), $again, $code);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('has entities already', implode("\n", $again));
  }
}
