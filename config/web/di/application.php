<?php

declare(strict_types=1);

use App\Api\Input\ApiRequestParametersResolver;
use App\Api\Middleware\ApiPrefixMiddleware;
use App\Api\Middleware\CorsMiddleware;
use App\Api\Middleware\LocaleMiddleware;
use App\Api\Middleware\EventMiddleware;
use App\Presentation\Api\Shared\ResponseFactory\ExceptionResponderFactory;
use App\Presentation\Api\Shared\ResponseFactory\NotFoundHandler;
use Yiisoft\DataResponse\Middleware\FormatDataResponseAsJson;
use Yiisoft\DataResponse\Middleware\JsonDataResponseMiddleware;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Yii\Http\Application;

/** @var array $params */

return [
  Application::class => [
    '__construct()' => [
      'dispatcher' => DynamicReference::to([
        'class' => MiddlewareDispatcher::class,
        'withMiddlewares()' => [
          [
            CorsMiddleware::class,
            // Language of messages (Accept-Language) - before anything that may answer with one
            LocaleMiddleware::class,
            ErrorCatcher::class,
            // Events of the request start when it is done
            EventMiddleware::class,
            RequestCatcherMiddleware::class,
            ApiPrefixMiddleware::class,
            JsonDataResponseMiddleware::class,
            FormatDataResponseAsJson::class,
            // Turns any exception thrown below this point into a friendly JSON error.
            static fn(ExceptionResponderFactory $factory) => $factory->create(),
            Router::class,
          ],
        ],
      ]),
      'fallbackHandler' => Reference::to(NotFoundHandler::class),
    ],
  ],

  ParametersResolverInterface::class => [
    'class' => CompositeParametersResolver::class,
    '__construct()' => [
      Reference::to(HydratorAttributeParametersResolver::class),
      Reference::to(RequestInputParametersResolver::class),
      Reference::to(ApiRequestParametersResolver::class),
    ],
  ],
];
