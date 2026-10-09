<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Translator\TranslatorInterface;

/**
 * Language of messages for this request: the Accept-Language header (the admin app sends the
 * language chosen by the user), English otherwise. Not the language of the content - that is
 * ?lang= of the content API.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
  public function __construct(private TranslatorInterface $translator)
  {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $locale = I18n::fromAcceptLanguage($request->getHeaderLine('Accept-Language'));
    $this->translator->setLocale($locale);
    I18n::setTranslator($this->translator);
    return $handler->handle($request)->withHeader('Content-Language', $locale);
  }
}
