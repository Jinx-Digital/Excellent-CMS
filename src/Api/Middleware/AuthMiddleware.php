<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Application\Service\JwtService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

/**
 * Authenticates CMS users (admin app) by their JWT, optionally only admins:
 *   ->middleware(fn(AuthMiddleware $m) => $m->adminOnly())
 *
 * Permissions per entity are checked by the controllers/services (CurrentUser::assertCan),
 * since the entity comes from the URL.
 */
final class AuthMiddleware implements MiddlewareInterface
{
  private bool $adminOnly = false;

  public function __construct(
    private ResponseFactory $responseFactory,
    private JwtService $jwtService,
    private UserRepository $userRepository,
    private CurrentUser $currentUser,
    private CurrentProject $currentProject,
    private ProjectRepository $projects,
  ) {
  }

  public function adminOnly(): self
  {
    $new = clone $this;
    $new->adminOnly = true;
    return $new;
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $this->currentUser->setIp(self::clientIp($request));

    // A bearer token (scripts) or the login cookie of the admin app (see SessionCookie)
    $header = $request->getHeaderLine('Authorization');
    $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : \App\Api\SessionCookie::read($request);
    $payload = null !== $token ? $this->jwtService->decode($token) : null;
    $user = null !== $payload ? $this->userRepository->get((string)($payload['sub'] ?? '')) : null;
    if (null === $user || !$user->isActive() || (int)($payload['ver'] ?? 0) !== $user->getTokenVersion()) {
      return $this->responseFactory->fail(I18n::t('Please sign in again.'), httpCode: Status::UNAUTHORIZED, errorCode: 'unauthorized');
    }

    $this->currentUser->set($user);
    if ($this->adminOnly && !$user->isAdmin()) {
      return $this->responseFactory->fail(I18n::t('Only administrators may do this.'), httpCode: Status::FORBIDDEN, errorCode: 'forbidden');
    }

    // Project of the admin app: header X-Project (slug or id), otherwise the first one the user works in
    $wanted = trim($request->getHeaderLine('X-Project'));
    $accessible = array_values(array_filter($this->projects->all(), static fn($project): bool => $user->inProject($project->id)));
    // Not the area "Global" by default - it is chosen on purpose
    $project = array_values(array_filter($accessible, static fn($project): bool => !$project->isGlobal))[0] ?? $accessible[0] ?? null;
    if ('' !== $wanted) {
      $project = null;
      foreach ($accessible as $candidate) {
        if ($candidate->slug === $wanted || $candidate->id === $wanted) {
          $project = $candidate;
        }
      }
      if (null === $project) {
        return $this->responseFactory->fail(I18n::t('You have no access to the project "{project}".', ['project' => $wanted]), httpCode: Status::FORBIDDEN, errorCode: 'project_forbidden');
      }
    }
    $this->currentProject->set($project);

    return $handler->handle($request->withAttribute('userId', $user->getId()));
  }

  public static function clientIp(ServerRequestInterface $request): ?string
  {
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
    return is_string($ip) && '' !== $ip ? $ip : null;
  }
}
