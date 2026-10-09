<?php

declare(strict_types=1);

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\JsonRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\Target\File\FileTarget;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(__DIR__, 2);

require_once $root.'/src/bootstrap.php';

/**
 * @psalm-var string $_SERVER['REQUEST_URI']
 */
// PHP built-in server routing.
if (PHP_SAPI === 'cli-server') {
  // Serve static files (admin app) as is - relative to the document root public/. Uploaded files
  // (/media/...) always go through the application: protected ones need a signed address.
  /** @var string $path */
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (!str_starts_with($path, '/media/') && is_file(dirname(__DIR__).$path)) {
    return false;
  }

  // Explicitly set for URLs with dot.
  $_SERVER['SCRIPT_NAME'] = '/index.php';
}

// Run HTTP application runner
$runner = new HttpApplicationRunner(
  rootPath: $root,
  debug: Environment::appDebug(),
  checkEvents: Environment::appDebug(),
  environment: Environment::appEnv(),
  temporaryErrorHandler: new ErrorHandler(
    new Logger(
      [
        (new FileTarget($root.'/runtime/logs/app.log'))->setLevels([
          LogLevel::EMERGENCY,
          LogLevel::ERROR,
          LogLevel::WARNING,
        ]),
      ],
    ),
    new JsonRenderer(),
  ),
);
$runner->run();
