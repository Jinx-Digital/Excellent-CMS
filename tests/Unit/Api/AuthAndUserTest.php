<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use Fixtures\Fixture;

class AuthAndUserTest extends ApiTestCase
{
  public function testLoginAndMe(): void
  {
    $token = $this->login();
    $me = $this->api('GET', '/auth/me', token: $token);

    $this->assertSame(200, $me['status']);
    $this->assertTrue($me['body']['data']['user']['is_admin']);
    $this->assertContains('countries', array_column($me['body']['data']['entities'], 'slug'));
  }

  public function testWrongPasswordAndMissingToken(): void
  {
    $result = $this->api('POST', '/auth/login', ['email' => Fixture::ADMIN, 'password' => 'falsch']);
    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_credentials', $result['body']['error_code']);

    $this->assertSame(401, $this->api('GET', '/auth/me')['status']);
    $this->assertSame(401, $this->api('GET', '/auth/me', token: 'kaputt')['status']);
  }

  public function testEditorSeesOnlyPermittedEntitiesAndNoAdminArea(): void
  {
    $token = $this->editorToken();
    $me = $this->api('GET', '/auth/me', token: $token)['body']['data'];

    $this->assertFalse($me['user']['is_admin']);
    $countries = array_values(array_filter($me['entities'], static fn(array $e): bool => 'countries' === $e['slug']))[0];
    $this->assertTrue($countries['permissions']['read']);
    $this->assertTrue($countries['permissions']['update']);
    $this->assertFalse($countries['permissions']['delete']);

    $this->assertSame(403, $this->api('GET', '/admin/users', token: $token)['status']);
    $this->assertSame(403, $this->api('POST', '/admin/entities', ['slug' => 'x'], $token)['status']);
  }

  public function testPermissionsPerEntity(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('notes'), 'name' => 'Notizen', 'fields' => [['name' => 'title', 'type' => 'string', 'required' => true]]], $admin);
    $email = $this->uniqueSlug('user').'@example.com';
    $user = $this->api('POST', '/admin/users', [
      'name' => 'Leserin',
      'email' => $email,
      'password' => 'passwort123',
      'permissions' => [$entity['id'] => ['read' => true]],
    ], $admin);
    $this->assertSame(200, $user['status'], json_encode($user['body']));

    $record = $this->createRecord($entity['slug'], ['title' => 'Hallo'], $admin);
    $reader = $this->login($email, 'passwort123');

    $this->assertSame(200, $this->api('GET', "/entities/{$entity['slug']}/records", token: $reader)['status']);
    $this->assertSame(403, $this->api('POST', "/entities/{$entity['slug']}/records", ['title' => 'Neu'], $reader)['status']);
    $this->assertSame(403, $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}", ['title' => 'X'], $reader)['status']);
    $this->assertSame(403, $this->api('DELETE', "/entities/{$entity['slug']}/records/{$record['id']}", token: $reader)['status']);
    // Other entities are not visible
    $this->assertSame(403, $this->api('GET', '/entities/countries/records', token: $reader)['status']);

    // Granting "update" takes effect immediately
    $this->api('PUT', "/admin/users/{$user['body']['data']['id']}", ['permissions' => [$entity['id'] => ['read' => true, 'update' => true]]], $admin);
    $this->assertSame(200, $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}", ['title' => 'Geändert'], $reader)['status']);
  }

  public function testDeactivationAndPasswordChangeEndSessions(): void
  {
    $admin = $this->login();
    $email = $this->uniqueSlug('temp').'@example.com';
    $id = $this->api('POST', '/admin/users', ['name' => 'Temp', 'email' => $email, 'password' => 'passwort123'], $admin)['body']['data']['id'];

    $token = $this->login($email, 'passwort123');
    $changed = $this->api('POST', '/auth/change-password', ['current_password' => 'passwort123', 'new_password' => 'neuesPasswort1', 'new_password_confirmation' => 'neuesPasswort1'], $token);
    $this->assertSame(200, $changed['status']);
    $this->assertSame(401, $this->api('GET', '/auth/me', token: $token)['status']);

    $newToken = $changed['body']['data']['token'];
    $this->assertSame(200, $this->api('GET', '/auth/me', token: $newToken)['status']);
    $this->api('PUT', "/admin/users/{$id}", ['is_active' => false], $admin);
    $this->assertSame(401, $this->api('GET', '/auth/me', token: $newToken)['status']);
    $this->assertSame(403, $this->api('POST', '/auth/login', ['email' => $email, 'password' => 'neuesPasswort1'])['status']);
  }

  public function testUserValidationAndSelfProtection(): void
  {
    $admin = $this->login();
    $result = $this->api('POST', '/admin/users', ['name' => '', 'email' => Fixture::EDITOR, 'password' => 'kurz', 'permissions' => ['gibtsnicht' => ['read' => true]]], $admin);
    $this->assertSame(422, $result['status']);
    foreach (['name', 'email', 'password', 'permissions'] as $field) {
      $this->assertArrayHasKey($field, $result['body']['error_data']);
    }

    $me = $this->api('GET', '/auth/me', token: $admin)['body']['data']['user']['id'];
    $this->assertSame(422, $this->api('PUT', "/admin/users/{$me}", ['is_admin' => false], $admin)['status']);
    $this->assertSame(400, $this->api('DELETE', "/admin/users/{$me}", token: $admin)['status']);
  }
}
