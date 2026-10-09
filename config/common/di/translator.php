<?php

declare(strict_types=1);

use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;

/** @var array $params */

return [
  // Texts of the application: English in the code, translations in messages/<locale>/app.php
  'translation.app' => [
    'definition' => static function () use ($params): CategorySource {
      $source = new MessageSource(dirname(__DIR__, 3).'/messages');
      return new CategorySource($params['yiisoft/translator']['defaultCategory'], $source, new IntlMessageFormatter(), $source);
    },
    'tags' => ['translation.categorySource'],
  ],
];
