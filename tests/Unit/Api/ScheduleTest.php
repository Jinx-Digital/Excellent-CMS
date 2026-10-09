<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Application\Content\RecordSchedules;
use App\Tests\Support\ApiTestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Scheduled publishing and unpublishing, carried out by `./yii schedule:run`.
 */
class ScheduleTest extends ApiTestCase
{
  public function testRecordsArePublishedAndUnpublishedOnSchedule(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('offers');
    $this->createEntity(['slug' => $slug, 'name' => 'Angebote', 'access' => 'public', 'drafts' => true, 'revisions' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string', 'required' => true],
      ['name' => 'text', 'type' => 'text', 'required' => true],
    ]], $admin);
    $draft = $this->createRecord($slug, ['title' => 'Sommer', 'text' => 'Ab Juni', 'draft' => true], $admin);
    $url = "/entities/{$slug}/records/{$draft['id']}";
    $tomorrow = date(DATE_ATOM, time() + 86400);

    // Times in the past and unpublishing before publishing are refused
    $this->assertSame(422, $this->api('PUT', "$url/schedule", ['publish_at' => date(DATE_ATOM, time() - 60)], $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "$url/schedule", ['publish_at' => $tomorrow, 'unpublish_at' => date(DATE_ATOM, time() + 3600)], $admin)['status']);

    $schedule = $this->api('PUT', "$url/schedule", ['publish_at' => $tomorrow, 'unpublish_at' => date(DATE_ATOM, time() + 2 * 86400)], $admin);
    $this->assertSame(200, $schedule['status'], json_encode($schedule['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(strtotime($tomorrow), strtotime($schedule['body']['data']['publish_at']));
    $this->assertNotNull($this->api('GET', $url, token: $admin)['body']['data']['_schedule']['unpublish_at']);
    $this->assertNotNull($this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['data'][0]['_schedule']);

    // Not due yet: nothing happens
    $this->assertSame(['done' => 0, 'failed' => 0], $this->runSchedules());
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$draft['id']}")['status']);

    // Due: published, in the name of whoever scheduled it
    $this->due($draft['id'], 'publish');
    $this->assertSame(['done' => 1, 'failed' => 0], $this->runSchedules());
    $this->assertSame('Sommer', $this->api('GET', "/main/content/{$slug}/{$draft['id']}")['body']['data']['title']);
    $this->assertSame('Administrator', $this->api('GET', "$url/revisions", token: $admin)['body']['data'][0]['created_by']['name']);

    // A published record with a working copy: the working copy goes live
    $this->api('PUT', "$url/working-copy", ['title' => 'Sommer – verlängert'], $admin);
    $this->api('PUT', "$url/schedule", ['publish_at' => $tomorrow], $admin);
    $this->due($draft['id'], 'publish');
    $this->runSchedules();
    $record = $this->api('GET', $url, token: $admin)['body']['data'];
    $this->assertSame(['Sommer – verlängert', null], [$record['title'], $record['_working_copy']]);

    // Unpublished on schedule: gone from the content API
    $this->due($draft['id'], 'unpublish');
    $this->assertSame(['done' => 1, 'failed' => 0], $this->runSchedules());
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$draft['id']}")['status']);
    $this->assertNull($this->api('GET', $url, token: $admin)['body']['data']['_schedule']);

    // A draft that cannot be published (required field empty) stays a draft, the error stays visible
    $incomplete = $this->createRecord($slug, ['title' => 'Ohne Text', 'draft' => true], $admin);
    $this->api('PUT', "/entities/{$slug}/records/{$incomplete['id']}/schedule", ['publish_at' => $tomorrow], $admin);
    $this->due($incomplete['id'], 'publish');
    $this->assertSame(['done' => 0, 'failed' => 1], $this->runSchedules());
    $failed = $this->api('GET', "/entities/{$slug}/records/{$incomplete['id']}", token: $admin)['body']['data'];
    $this->assertTrue($failed['draft']);
    $this->assertStringContainsString('text', $failed['_schedule']['errors']['publish']);
    // Not tried again every minute
    $this->assertSame(['done' => 0, 'failed' => 0], $this->runSchedules());

    // Removing a time; deleting the record removes its schedule
    $this->assertNull($this->api('PUT', "/entities/{$slug}/records/{$incomplete['id']}/schedule", ['publish_at' => null], $admin)['body']['data']);
    $this->api('PUT', "/entities/{$slug}/records/{$incomplete['id']}/schedule", ['publish_at' => $tomorrow], $admin);
    $this->api('DELETE', "/entities/{$slug}/records/{$incomplete['id']}", token: $admin);
    $this->assertFalse($this->db()->createQuery()->from('record_schedule')->where(['record_id' => $incomplete['id']])->exists());
  }

  public function testOnlyEntitiesWithDrafts(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('plain'), 'name' => 'Ohne Entwürfe', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $record = $this->createRecord($entity['slug'], ['title' => 'X'], $admin);
    $this->assertSame(400, $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}/schedule", ['publish_at' => date(DATE_ATOM, time() + 3600)], $admin)['status']);
  }

  private function due(string $recordId, string $action): void
  {
    $this->db()->createCommand()->update('record_schedule', ['run_at' => date('Y-m-d H:i:s', time() - 1)], ['record_id' => $recordId, 'action' => $action])->execute();
  }

  /**
   * @return array{done: int, failed: int}
   */
  private function runSchedules(): array
  {
    return $this->container()->get(RecordSchedules::class)->run();
  }

  private function db(): ConnectionInterface
  {
    return $this->container()->get(ConnectionInterface::class);
  }
}
