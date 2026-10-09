<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class ContentApiTest extends ApiTestCase
{
  public function testPublicEntityWithFiltersSortingAndFields(): void
  {
    $index = $this->api('GET', '/main/content');
    $this->assertSame(200, $index['status']);
    $this->assertContains('countries', array_column($index['body']['data'], 'slug'));

    $result = $this->api('GET', '/main/content/countries?filter[alpha2code][in]=DE,AT&sort=-name&fields=name,alpha2code');
    $this->assertSame(200, $result['status']);
    $this->assertSame(['Germany', 'Austria'], array_column($result['body']['data'], 'name'));
    $this->assertSame(['id', 'name', 'alpha2code', 'created_at', 'updated_at'], array_keys($result['body']['data'][0]));

    $page = $this->api('GET', '/main/content/countries?limit=5&page=2');
    $this->assertSame(2, $page['body']['meta']['current_page']);
    $this->assertCount(5, $page['body']['data']);

    $this->assertSame(422, $this->api('GET', '/main/content/countries?filter[nope]=1')['status']);
    $this->assertSame(422, $this->api('GET', '/main/content/countries?sort=nope')['status']);
    $this->assertSame(404, $this->api('GET', '/main/content/gibtsnicht')['status']);
  }

  public function testFilterAndSortByFieldsOfReferencedRecords(): void
  {
    $admin = $this->login();
    $cities = $this->uniqueSlug('cities');
    $this->createEntity(['slug' => $cities, 'name' => 'Städte', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
    ]], $admin);
    $berlin = $this->createRecord($cities, ['name' => 'Berlin', 'country' => $this->countryId('DE')], $admin)['id'];
    $vienna = $this->createRecord($cities, ['name' => 'Wien', 'country' => $this->countryId('AT')], $admin)['id'];
    $this->createRecord($cities, ['name' => 'Aachen', 'country' => $this->countryId('DE')], $admin);
    $this->createRecord($cities, ['name' => 'Paris', 'country' => $this->countryId('FR')], $admin);

    $streets = $this->uniqueSlug('streets');
    $this->createEntity(['slug' => $streets, 'name' => 'Straßen', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'city', 'type' => 'reference', 'reference' => $cities],
      ['name' => 'cities', 'type' => 'reference', 'reference' => $cities, 'repeatable' => true],
    ]], $admin);
    $this->createRecord($streets, ['name' => 'Unter den Linden', 'city' => $berlin, 'cities' => [$berlin]], $admin);
    $this->createRecord($streets, ['name' => 'Ringstraße', 'city' => $vienna, 'cities' => [$vienna, $berlin]], $admin);

    $names = fn(string $query): array => array_column($this->api('GET', "/main/content/{$cities}?{$query}")['body']['data'], 'name');
    $this->assertSame(['Aachen', 'Berlin'], $names('filter[country][alpha2code]=DE&sort=name'));
    $this->assertSame(['Aachen', 'Berlin', 'Wien'], $names('filter[country][alpha2code][in]=DE,AT&sort=name'));
    $this->assertSame(['Aachen', 'Berlin'], $names('filter[country][name]=germany&sort=name'), 'the same comparisons as on the record itself');
    $this->assertSame(['Wien'], $names('filter[country][name][like]=stri&filter[name][like]=i'));
    // Default for references: the display field of the referenced record (Austria, France, Germany)
    $this->assertSame(['Wien', 'Paris', 'Aachen', 'Berlin'], $names('sort=country,name'));
    $this->assertSame(['Wien', 'Aachen', 'Berlin', 'Paris'], $names('sort=country[alpha2code],name'));
    $this->assertSame(['Paris', 'Aachen', 'Berlin', 'Wien'], $names('sort=-country[alpha2code],name'));

    // Two levels, and lists of references (one of them matches)
    $streetNames = function (string $query) use ($streets): array {
      $response = $this->api('GET', "/main/content/{$streets}?{$query}");
      $this->assertSame(200, $response['status'], $query.': '.json_encode($response['body'], JSON_UNESCAPED_UNICODE));
      return array_column($response['body']['data'], 'name');
    };
    $this->assertSame(['Ringstraße'], $streetNames('filter[city][country][alpha2code]=AT'));
    $this->assertSame(['Unter den Linden', 'Ringstraße'], $streetNames('filter[cities][name]=Berlin&sort=-name'));
    $this->assertSame(['Ringstraße'], $streetNames('filter[cities][country][alpha2code]=AT'));
    $this->assertSame(['Unter den Linden', 'Ringstraße'], $streetNames('sort=-city[country][name]'));
    $this->assertSame(['Ringstraße', 'Unter den Linden'], $streetNames('sort=city[country]'));

    foreach (['filter[country][nope]=1', 'filter[name][nope]=1', 'sort=name[x]', 'sort=country[nope]', 'sort=country]'] as $query) {
      $this->assertSame(422, $this->api('GET', "/main/content/{$cities}?{$query}")['status'], $query);
    }
    $this->assertSame(422, $this->api('GET', "/main/content/{$streets}?sort=cities[name]")['status'], 'lists cannot be sorted by');
    $this->assertSame(422, $this->api('GET', "/main/content/{$streets}?filter[city][country][region][x]=1")['status'], 'too deep');

    // The admin app knows the same paths
    $this->assertSame(['Aachen', 'Berlin'], array_column($this->api('GET', "/entities/{$cities}/records?filter[country][alpha2code]=DE&sort=name", token: $admin)['body']['data'], 'name'));
  }

  public function testFieldsOfProtectedReferencesNeedReadAccess(): void
  {
    [$cities, $client] = $this->protectedSetup();
    $admin = $this->login();
    $streets = $this->uniqueSlug('streets');
    $this->createEntity(['slug' => $streets, 'name' => 'Straßen', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'city', 'type' => 'reference', 'reference' => $cities],
    ]], $admin);
    $berlin = $this->api('GET', "/entities/{$cities}/records", token: $admin)['body']['data'][0]['id'];
    $this->createRecord($streets, ['name' => 'Unter den Linden', 'city' => $berlin], $admin);

    // Public streets, protected cities: no peeking into the cities by filter or sort
    $this->assertSame(422, $this->api('GET', "/main/content/{$streets}?filter[city][name]=Berlin")['status']);
    $this->assertSame(422, $this->api('GET', "/main/content/{$streets}?sort=city[name]")['status']);
    $this->assertSame(200, $this->api('GET', "/main/content/{$streets}?sort=city")['status'], 'falls back to the id');

    $token = $this->token($client)['access_token'];
    $this->assertCount(1, $this->api('GET', "/main/content/{$streets}?filter[city][name]=Berlin", token: $token)['body']['data']);
  }

  public function testProtectedEntityNeedsTokenWithScope(): void
  {
    [$cities, $client] = $this->protectedSetup();

    $anonymous = $this->api('GET', "/main/content/{$cities}");
    $this->assertSame(401, $anonymous['status']);
    $this->assertSame('Bearer', $anonymous['headers']['WWW-Authenticate']);
    $this->assertNotContains($cities, array_column($this->api('GET', '/main/content')['body']['data'], 'slug'));

    $token = $this->token($client);
    $this->assertSame('Bearer', $token['token_type']);
    $this->assertSame($cities, $token['scope']);

    $list = $this->api('GET', "/main/content/{$cities}?include=country", token: $token['access_token']);
    $this->assertSame(200, $list['status'], json_encode($list['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('Berlin', $list['body']['data'][0]['name']);
    // Public target entity: the referenced record is embedded
    $this->assertSame('Germany', $list['body']['data'][0]['country']['name']);

    $one = $this->api('GET', "/main/content/{$cities}/{$list['body']['data'][0]['id']}", token: $token['access_token']);
    $this->assertSame('Berlin', $one['body']['data']['name']);

    $this->assertSame(401, $this->api('GET', "/main/content/{$cities}", token: 'falsch')['status']);
  }

  public function testTokenEndpointFollowsOAuthSpec(): void
  {
    [$cities, $client] = $this->protectedSetup();
    $form = ['Content-Type' => 'application/x-www-form-urlencoded'];

    // HTTP Basic authentication of the client
    $basic = $this->api('POST', '/oauth/token', ['grant_type' => 'client_credentials'], headers: $form + ['Authorization' => 'Basic '.base64_encode($client['client_id'].':'.$client['client_secret'])]);
    $this->assertSame(200, $basic['status'], json_encode($basic['body']));
    $this->assertSame('no-store', $basic['headers']['Cache-Control']);

    $wrong = $this->api('POST', '/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => 'falsch'], headers: $form);
    $this->assertSame(401, $wrong['status']);
    $this->assertSame('invalid_client', $wrong['body']['error']);

    $grant = $this->api('POST', '/oauth/token', ['grant_type' => 'password'], headers: $form);
    $this->assertSame('unsupported_grant_type', $grant['body']['error']);

    $scope = $this->api('POST', '/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret'], 'scope' => 'countries '.$cities], headers: $form);
    $this->assertSame('invalid_scope', $scope['body']['error']);
  }

  public function testRevokingAccessTakesEffectImmediately(): void
  {
    [$cities, $client] = $this->protectedSetup();
    $token = $this->token($client)['access_token'];
    $admin = $this->login();

    $this->api('PUT', "/admin/clients/{$client['id']}", ['entities' => []], $admin);
    $this->assertSame(403, $this->api('GET', "/main/content/{$cities}", token: $token)['status']);

    $this->api('PUT', "/admin/clients/{$client['id']}", ['entities' => [$cities], 'is_active' => false], $admin);
    $this->assertSame(401, $this->api('GET', "/main/content/{$cities}", token: $token)['status']);

    $this->api('PUT', "/admin/clients/{$client['id']}", ['is_active' => true], $admin);
    $secret = $this->api('POST', "/admin/clients/{$client['id']}/secret", token: $admin)['body']['data']['client_secret'];
    $this->assertNotSame($client['client_secret'], $secret);
    $this->assertSame(200, $this->api('GET', "/main/content/{$cities}", token: $this->token(['client_secret' => $secret] + $client)['access_token'])['status']);
  }

  public function testRateLimitPerClient(): void
  {
    [$cities, $client] = $this->protectedSetup(['rate_limit' => 2]);
    $token = $this->token($client)['access_token'];

    $first = $this->api('GET', "/main/content/{$cities}", token: $token);
    $this->assertSame('2', $first['headers']['X-RateLimit-Limit']);
    $this->assertSame('1', $first['headers']['X-RateLimit-Remaining']);
    $this->assertSame(200, $this->api('GET', "/main/content/{$cities}", token: $token)['status']);

    $limited = $this->api('GET', "/main/content/{$cities}", token: $token);
    $this->assertSame(429, $limited['status']);
    $this->assertSame('rate_limited', $limited['body']['error_code']);
    $this->assertArrayHasKey('Retry-After', $limited['headers']);
  }

  public function testRateLimitSettings(): void
  {
    $admin = $this->login();
    $this->assertSame(422, $this->api('PUT', '/admin/settings/rate-limit', ['requests' => 0], $admin)['status']);
    $saved = $this->api('PUT', '/admin/settings/rate-limit', ['enabled' => false, 'requests' => 100, 'window' => 60], $admin);
    $this->assertSame(['enabled' => false, 'requests' => 100, 'window' => 60], $saved['body']['data']);
    // Switched off: no headers for public access
    $this->assertArrayNotHasKey('X-RateLimit-Limit', $this->api('GET', '/main/content/countries')['headers']);
  }

  /**
   * A protected entity "cities" with Berlin -> Germany and an OAuth client for it.
   *
   * @return array{0: string, 1: array}
   */
  private function protectedSetup(array $clientData = []): array
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('cities');
    $this->createEntity(['slug' => $slug, 'name' => 'Städte', 'access' => 'oauth', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
    ]], $admin);
    $this->createRecord($slug, ['name' => 'Berlin', 'country' => $this->countryId('DE')], $admin);

    $client = $this->api('POST', '/admin/clients', ['name' => 'Webseite', 'entities' => [$slug]] + $clientData, $admin);
    $this->assertSame(200, $client['status'], json_encode($client['body'], JSON_UNESCAPED_UNICODE));
    return [$slug, $client['body']['data']];
  }

  private function token(array $client): array
  {
    $result = $this->api('POST', '/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']]);
    $this->assertSame(200, $result['status'], json_encode($result['body']));
    return $result['body'];
  }
}
