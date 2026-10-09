<?php

declare(strict_types=1);

// Do not edit. Content will be replaced.
return [
    '/' => [
        'di' => [
            'yiisoft/aliases' => [
                'config/di.php',
            ],
            'yiisoft/cache' => [
                'config/di.php',
            ],
            'yiisoft/log-target-file' => [
                'config/di.php',
            ],
            'yiisoft/router-fastroute' => [
                'config/di.php',
            ],
            'yiisoft/db' => [
                'config/di.php',
            ],
            'yiisoft/queue' => [
                'config/di.php',
            ],
            'yiisoft/rbac' => [
                'config/di.php',
            ],
            'yiisoft/router' => [
                'config/di.php',
            ],
            'yiisoft/hydrator' => [
                'config/di.php',
            ],
            'yiisoft/validator' => [
                'config/di.php',
            ],
            'yiisoft/yii-event' => [
                'config/di.php',
            ],
            'yiisoft/translator' => [
                'config/di.php',
            ],
            '/' => [
                'common/di/*.php',
            ],
        ],
        'params' => [
            'yiisoft/aliases' => [
                'config/params.php',
            ],
            'yiisoft/data-response' => [
                'config/params.php',
            ],
            'yiisoft/log-target-file' => [
                'config/params.php',
            ],
            'yiisoft/router-fastroute' => [
                'config/params.php',
            ],
            'yiisoft/db' => [
                'config/params.php',
            ],
            'yiisoft/db-migration' => [
                'config/params.php',
            ],
            'yiisoft/queue' => [
                'config/params.php',
            ],
            'yiisoft/router' => [
                'config/params.php',
            ],
            'yiisoft/validator' => [
                'config/params.php',
            ],
            'yiisoft/translator' => [
                'config/params.php',
            ],
            '/' => [
                'common/params.php',
            ],
        ],
        'di-web' => [
            'yiisoft/data-response' => [
                'config/di-web.php',
            ],
            'yiisoft/input-http' => [
                'config/di-web.php',
            ],
            'yiisoft/router-fastroute' => [
                'config/di-web.php',
            ],
            'yiisoft/error-handler' => [
                'config/di-web.php',
            ],
            'yiisoft/request-provider' => [
                'config/di-web.php',
            ],
            'yiisoft/yii-event' => [
                'config/di-web.php',
            ],
            '/' => [
                '$di',
                'web/di/*.php',
            ],
        ],
        'params-web' => [
            'yiisoft/input-http' => [
                'config/params-web.php',
            ],
            'yiisoft/yii-event' => [
                'config/params-web.php',
            ],
            '/' => [
                '$params',
                'web/params.php',
            ],
        ],
        'di-console' => [
            'yiisoft/db-migration' => [
                'config/di-console.php',
            ],
            'yiisoft/yii-console' => [
                'config/di-console.php',
            ],
            'yiisoft/yii-event' => [
                'config/di-console.php',
            ],
            '/' => [
                '$di',
                'console/di/*.php',
            ],
        ],
        'events-web' => [
            'yiisoft/middleware-dispatcher' => [
                'config/events-web.php',
            ],
            'yiisoft/request-provider' => [
                'config/events-web.php',
            ],
            'yiisoft/log' => [
                'config/events-web.php',
            ],
            '/' => [
                '$events',
            ],
        ],
        'events-console' => [
            'yiisoft/log' => [
                'config/events-console.php',
            ],
            'yiisoft/yii-console' => [
                'config/events-console.php',
            ],
            '/' => [
                '$events',
            ],
        ],
        'params-console' => [
            'yiisoft/yii-console' => [
                'config/params-console.php',
            ],
            'yiisoft/yii-event' => [
                'config/params-console.php',
            ],
            '/' => [
                '$params',
                'console/params.php',
            ],
        ],
        'di-delegates' => [
            '/' => [],
        ],
        'di-delegates-console' => [
            '/' => [
                '$di-delegates',
            ],
        ],
        'di-delegates-web' => [
            '/' => [
                '$di-delegates',
            ],
        ],
        'di-providers' => [
            '/' => [],
        ],
        'di-providers-web' => [
            '/' => [
                '$di-providers',
            ],
        ],
        'di-providers-console' => [
            '/' => [
                '$di-providers',
            ],
        ],
        'events' => [
            '/' => [],
        ],
        'routes' => [
            '/' => [
                'common/routes.php',
            ],
        ],
        'bootstrap' => [
            '/' => [
                'common/bootstrap.php',
            ],
        ],
        'bootstrap-web' => [
            '/' => [
                '$bootstrap',
            ],
        ],
        'bootstrap-console' => [
            '/' => [
                '$bootstrap',
            ],
        ],
    ],
    'dev' => [
        'params' => [
            '/' => [
                'environments/dev/params.php',
            ],
        ],
    ],
    'prod' => [
        'params' => [
            '/' => [
                'environments/prod/params.php',
            ],
        ],
    ],
    'test' => [
        'params' => [
            '/' => [
                'environments/test/params.php',
            ],
        ],
    ],
];
