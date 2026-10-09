<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Yiisoft\Log\Logger;
use Yiisoft\Log\Target\File\FileRotator;
use Yiisoft\Log\Target\File\FileTarget;

return [
  // runtime/logs/app.log, rotated at LOG_MAX_SIZE MB (default 10) - the newest LOG_MAX_FILES (default 5) old
  // ones are kept, compressed (app.log.1.gz …)
  LoggerInterface::class => static function (): LoggerInterface {
    $rotator = new FileRotator(
      maxFileSize: max(1, (int)($_ENV['LOG_MAX_SIZE'] ?? 10)) * 1024,
      maxFiles: max(1, (int)($_ENV['LOG_MAX_FILES'] ?? 5)),
      compressRotatedFiles: extension_loaded('zlib'),
    );
    $target = new FileTarget(dirname(__DIR__, 3).'/runtime/logs/app.log', $rotator);
    $target->setLevels([LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE]);
    return new Logger([$target]);
  },
];
