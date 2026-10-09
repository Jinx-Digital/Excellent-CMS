<?php

declare(strict_types=1);

namespace App\Api\Controller\Auth;

use App\Api\Input\JsonInput;
use App\Application\Service\CurrentUser;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\PreferenceRepository;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * The signed-in user's own settings in the admin app, e.g. columns and page size of a list:
 *   GET /v1/auth/preferences/list:<entity id>    -> {"value": {...}} (null = defaults)
 *   PUT /v1/auth/preferences/list:<entity id>    {"value": {...}} (null = back to the defaults)
 */
final class PreferenceController
{
  private const MAX_SIZE = 10000;

  public function __construct(
    private ResponseFactory $responseFactory,
    private PreferenceRepository $preferences,
    private CurrentUser $currentUser,
  ) {
  }

  public function get(#[RouteArgument('key')] string $key): ResponseInterface
  {
    self::assertKey($key);
    return $this->responseFactory->success(['value' => $this->preferences->get($this->currentUser->getUser()->getId(), $key)]);
  }

  public function set(#[RouteArgument('key')] string $key, JsonInput $input): ResponseInterface
  {
    self::assertKey($key);
    $value = $input->toArray()['value'] ?? null;
    if (null !== $value && (!is_array($value) || strlen((string)json_encode($value)) > self::MAX_SIZE)) {
      throw ValidationException::field('value', I18n::t('Please send an object of at most 10 KB.'));
    }
    $this->preferences->set($this->currentUser->getUser()->getId(), $key, $value);
    return $this->responseFactory->success(['value' => $value]);
  }

  private static function assertKey(string $key): void
  {
    if (1 !== preg_match('/^[a-z][a-z0-9_:.-]{0,99}$/i', $key)) {
      throw ValidationException::field('key', I18n::t('Invalid name of the setting.'));
    }
  }
}
