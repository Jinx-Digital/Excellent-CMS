<?php

declare(strict_types=1);

use App\Console\Command\FixturesLoadCommand;

/** @var array $params */

return [
  FixturesLoadCommand::class => [
    '__construct()' => [
      'sets' => $params['fixtures']['sets'],
    ],
  ],
];
