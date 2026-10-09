<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use Codeception\Test\Unit;
use Fixtures\Fixture;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/**
 * Sends real requests through the whole application (routing, middleware, error handling), as in
 * Fixoo. Every request gets a fresh container, just like a real PHP request.
 *
 * Works against the test database with the "test" fixture set (`make test-db-reset`): admin,
 * editor and the public entity "countries". Do not change those - create your own entities with
 * uniqueSlug() for that (nothing is rolled back).
 */
abstract class ApiTestCase extends Unit
{
  /**
   * @return array{status: int, body: array, headers: array<string, string>}
   */
  protected function api(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array
  {
    $headers += ['Accept' => 'application/json'];
    if (null !== $body) {
      $headers['Content-Type'] ??= 'application/json';
    }
    if (null !== $token) {
      $headers['Authorization'] = 'Bearer '.$token;
    }
    $content = null === $body ? '' : (str_contains($headers['Content-Type'], 'json') ? (string)json_encode($body) : http_build_query($body));

    return $this->dispatch(new ServerRequest(
      serverParams: ['REMOTE_ADDR' => '10.0.0.'.random_int(1, 250)],
      method: $method,
      uri: 'http://localhost/api/v1'.$path,
      headers: $headers,
      body: (new StreamFactory())->createStream($content),
    ));
  }

  /**
   * Multipart upload of an import file.
   *
   * @return array{status: int, body: array, headers: array<string, string>}
   */
  protected function upload(string $path, string $content, string $filename, string $token, array $fields = [], array $headers = []): array
  {
    $file = new UploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, $filename, 'application/octet-stream');
    $request = (new ServerRequest(
      serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
      method: 'POST',
      uri: 'http://localhost/api/v1'.$path,
      headers: ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=x', 'Authorization' => 'Bearer '.$token] + $headers,
    ))->withParsedBody($fields)->withUploadedFiles(['file' => $file]);

    return $this->dispatch($request);
  }

  /**
   * GET of an address outside the API, e.g. a file under /media.
   *
   * @return array{status: int, body: array, headers: array<string, string>, content: string}
   */
  protected function fetch(string $url, array $headers = []): array
  {
    return $this->dispatch(new ServerRequest(serverParams: ['REMOTE_ADDR' => '10.0.0.1'], method: 'GET', uri: $url, headers: $headers));
  }

  private function dispatch(ServerRequest $request): array
  {
    if ('' !== $request->getUri()->getQuery() && [] === $request->getQueryParams()) {
      parse_str($request->getUri()->getQuery(), $queryParams);
      $request = $request->withQueryParams($queryParams);
    }
    $response = $this->handle($request);

    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode((string)$response->getBody(), true) ?? [],
      'headers' => array_map(static fn(array $values): string => implode(', ', $values), $response->getHeaders()),
      'content' => (string)$response->getBody(),
    ];
  }

  private static ?ConfigInterface $config = null;
  private static ?ErrorHandler $errorHandler = null;
  private static ?ErrorHandler $temporaryErrorHandler = null;

  /**
   * Like a real PHP request: fresh container, DB connection closed afterwards.
   */
  private function handle(ServerRequest $request): ResponseInterface
  {
    $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), debug: false, environment: Environment::TEST);
    self::$config ??= $runner->getConfig();
    self::$temporaryErrorHandler ??= new ErrorHandler(new NullLogger(), new HtmlRenderer());
    $runner = $runner->withConfig(self::$config)->withContainer($this->container())->withTemporaryErrorHandler(self::$temporaryErrorHandler);
    try {
      $response = $runner->runAndGetResponse($request);
      $response->getBody()->rewind();
      return $response;
    } finally {
      $runner->getContainer()->get(\Yiisoft\Db\Connection\ConnectionInterface::class)->close();
      self::$errorHandler?->unregister();
      for ($i = 0; $i < 2; $i++) {
        restore_error_handler();
        restore_exception_handler();
      }
    }
  }

  /**
   * The container as HttpApplicationRunner builds it, but with one shared error handler (see Fixoo).
   */
  /** @var array<string, mixed> definitions that replace the app's in this test */
  protected static array $overrides = [];

  protected function container(): Container
  {
    $config = self::$config;
    $definitions = $config->get('di-web');
    $definitions[ConfigInterface::class] = $config;
    // Services a test replaces (e.g. the webhook sender)
    $definitions = static::$overrides + $definitions;
    if (null !== self::$errorHandler) {
      $definitions[ErrorHandler::class] = self::$errorHandler;
    }
    $containerConfig = ContainerConfig::create()->withDefinitions($definitions);
    foreach (['di-providers-web' => 'withProviders', 'di-delegates-web' => 'withDelegates', 'di-tags-web' => 'withTags'] as $group => $method) {
      if ($config->has($group)) {
        $containerConfig = $containerConfig->{$method}($config->get($group));
      }
    }
    $container = new Container($containerConfig);
    self::$errorHandler ??= $container->get(ErrorHandler::class);
    return $container;
  }

  protected function login(string $email = Fixture::ADMIN, string $password = Fixture::ADMIN_PASSWORD): string
  {
    $result = $this->api('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    $this->assertSame(200, $result['status'], json_encode($result['body']));
    return $result['body']['data']['token'];
  }

  protected function editorToken(): string
  {
    return $this->login(Fixture::EDITOR, Fixture::EDITOR_PASSWORD);
  }

  /**
   * Folder of a plugin in the plugins project (../excellent-plugins next to the CMS, or PLUGINS_SOURCE) -
   * the test is skipped without it.
   */
  protected function pluginSource(string $name): string
  {
    $dir = rtrim(getenv('PLUGINS_SOURCE') ?: dirname(__DIR__, 3).'/excellent-plugins', '/').'/'.$name;
    if (!is_file($dir.'/plugin.json')) {
      $this->markTestSkipped("The plugin \"{$name}\" is not there ({$dir}) - check out excellent-plugins next to the CMS or set PLUGINS_SOURCE.");
    }
    return $dir;
  }

  protected function uniqueSlug(string $prefix): string
  {
    return $prefix.'_'.bin2hex(random_bytes(4));
  }

  /**
   * Creates an entity through the schema API and returns its definition.
   */
  protected function createEntity(array $data, ?string $token = null): array
  {
    $result = $this->api('POST', '/admin/entities', $data, $token ?? $this->login());
    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    return $result['body']['data'];
  }

  protected function createRecord(string $slug, array $data, ?string $token = null): array
  {
    $result = $this->api('POST', "/entities/{$slug}/records", $data, $token ?? $this->login());
    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    return $result['body']['data'];
  }

  protected function countryId(string $alpha2): string
  {
    $result = $this->api('GET', '/main/content/countries?filter[alpha2code]='.$alpha2);
    return $result['body']['data'][0]['id'];
  }

  /**
   * Upload + run in one go with the suggested mapping, adjusted per column.
   *
   * @param array<string, array|null> $columns column => overrides (null = ignore)
   */
  protected function importNew(string $content, string $filename, array $entity, array $columns = [], ?string $token = null): array
  {
    $token ??= $this->login();
    $upload = $this->upload('/imports', $content, $filename, $token);
    $this->assertSame(200, $upload['status'], json_encode($upload['body'], JSON_UNESCAPED_UNICODE));
    $analysis = $upload['body']['data'];

    $plan = ['sheet' => $analysis['sheet'], 'delimiter' => $analysis['delimiter'], 'mode' => 'new', 'entity' => $entity + $analysis['entity'], 'columns' => []];
    foreach ($analysis['columns'] as $column) {
      $override = array_key_exists($column['column'], $columns) ? $columns[$column['column']] : [];
      $plan['columns'][] = null === $override ? ['column' => $column['column'], 'field' => null] : ['column' => $column['column']] + $override + $column['suggestion'];
    }
    return ['analysis' => $analysis, 'plan' => $plan, 'result' => $this->api('POST', "/imports/{$analysis['import_id']}/run", $plan, $token)];
  }
}
