<?php

declare(strict_types=1);

namespace App\Api\Input;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;

final class ApiRequestParametersResolver implements ParametersResolverInterface
{
  /**
   * @param ReflectionParameter[] $parameters
   * @return array<string, object>
   */
  public function resolve(array $parameters, ServerRequestInterface $request): array
  {
    $result = [];
    foreach ($parameters as $parameter) {
      $type = $parameter->getType();
      if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
        continue;
      }

      $class = $type->getName();
      if (class_exists($class) && method_exists($class, 'from')) {
        $result[$parameter->getName()] = $class::from($request);
      }
    }
    return $result;
  }
}
