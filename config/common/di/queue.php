<?php

declare(strict_types=1);

use App\Application\Event\EventQueue;
use App\Infrastructure\Queue\YiiEventQueue;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Mutex\MutexFactoryInterface;
use Yiisoft\Mutex\Mysql\MysqlMutexFactory;
use Yiisoft\Queue\Adapter\AdapterInterface;
use Yiisoft\Queue\AsyncQueueProducer;
use Yiisoft\Queue\Provider\QueueConsumerProviderInterface;
use Yiisoft\Queue\Provider\QueueFactoryProvider;
use Yiisoft\Queue\Provider\QueueProducerProviderInterface;
use Yiisoft\Queue\QueueConsumer;
use Yiisoft\Queue\Db\Adapter as DbAdapter;

/**
 * yiisoft/queue with the database adapter (table `queue`) - runs of events in queue mode.
 * Locks through MySQL (GET_LOCK), so no folder needs to be writable.
 */
return [
  EventQueue::class => YiiEventQueue::class,
  MutexFactoryInterface::class => static fn(ConnectionInterface $db): MutexFactoryInterface => new MysqlMutexFactory($db->getActivePdo()),
  AdapterInterface::class => [
    'class' => DbAdapter::class,
    '__construct()' => ['channel' => YiiEventQueue::QUEUE],
  ],
  QueueFactoryProvider::class => [
    '__construct()' => [
      'definitions' => [
        YiiEventQueue::QUEUE => [
          'producer' => [
            'class' => AsyncQueueProducer::class,
            '__construct()' => ['adapter' => Reference::to(AdapterInterface::class), 'queueName' => YiiEventQueue::QUEUE],
          ],
          'consumer' => [
            'class' => QueueConsumer::class,
            '__construct()' => ['adapter' => Reference::to(AdapterInterface::class), 'queueName' => YiiEventQueue::QUEUE],
          ],
        ],
      ],
    ],
  ],
  QueueProducerProviderInterface::class => Reference::to(QueueFactoryProvider::class),
  QueueConsumerProviderInterface::class => Reference::to(QueueFactoryProvider::class),
];
