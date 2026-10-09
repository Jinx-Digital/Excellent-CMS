<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory;

use App\Environment;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\ErrorHandler\Middleware\ExceptionResponder;
use Yiisoft\Http\Status;
use Yiisoft\Injector\Injector;
use Yiisoft\Input\Http\InputValidationException;

/**
 * Maps exceptions to the standard fail response. Expected errors keep their message;
 * everything else is logged and answered with a generic, non-technical text so staff
 * never see "SQLSTATE[23000]". Technical details are added only when APP_DEBUG is on.
 */
final readonly class ExceptionResponderFactory
{
  public function __construct(
    private ResponseFactoryInterface $psrResponseFactory,
    private ResponseFactory $apiResponseFactory,
    private Injector $injector,
    private LoggerInterface $logger,
  ) {}

  public function create(): ExceptionResponder
  {
    return new ExceptionResponder(
      [
        ValidationException::class => $this->validation(...),
        UserFacingException::class => $this->userFacing(...),
        InputValidationException::class => $this->inputValidation(...),
        Throwable::class => $this->throwable(...),
      ],
      $this->psrResponseFactory,
      $this->injector,
    );
  }

  private function validation(ValidationException $exception): ResponseInterface
  {
    return $this->apiResponseFactory->fail(
      $exception->getMessage(),
      $exception->getErrors(),
      httpCode: Status::UNPROCESSABLE_ENTITY,
      errorCode: 'validation',
    );
  }

  private function userFacing(UserFacingException $exception): ResponseInterface
  {
    return $this->apiResponseFactory->fail(
      $exception->getMessage(),
      httpCode: $exception->getHttpStatus(),
      errorCode: $exception->getErrorCode(),
    );
  }

  private function inputValidation(InputValidationException $exception): ResponseInterface
  {
    return $this->apiResponseFactory->failValidation($exception->getResult());
  }

  private function throwable(Throwable $exception): ResponseInterface
  {
    $this->logger->error($exception->getMessage(), ['exception' => $exception]);

    $debug = Environment::appDebug() ? [
      'debug' => [
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile().':'.$exception->getLine(),
      ],
    ] : null;

    return $this->apiResponseFactory->fail(
      I18n::t('Sorry, something went wrong. Please try again.'),
      $debug,
      httpCode: Status::INTERNAL_SERVER_ERROR,
      errorCode: 'server_error',
    );
  }
}
