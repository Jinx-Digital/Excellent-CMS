<?php

declare(strict_types=1);

return [
  'application' => [
    // Small limits so the tests can reach them quickly
    'import_max_rows' => 5000,
    // Mails go to a log file the tests read, links point here
    'app_url' => 'http://cms.test',
    'mail' => ['dsn' => '', 'from' => 'cms@test.local', 'from_name' => 'Excellent CMS', 'log_file' => 'runtime/test-mail.log'],
    // Uploads of the tests never land in storage/
    'media' => [
      'max_size' => 1024 * 1024,
      // Relative like the default: the API makes it absolute with the host of the request
      'url' => '/media',
      'local_path' => 'runtime/test-media',
    ],
  ],
];
