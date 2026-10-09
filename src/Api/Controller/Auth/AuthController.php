<?php

declare(strict_types=1);

namespace App\Api\Controller\Auth;

use App\Api\Input\JsonInput;
use App\Api\Input\LoginRequest;
use App\Api\Middleware\AuthMiddleware;
use App\Api\SessionCookie;
use App\Application\Service\AccountService;
use App\Application\Service\AuthService;
use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Entity\User;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private AuthService $authService,
    private CurrentUser $currentUser,
    private EntityRepository $entityRepository,
    private CurrentProject $currentProject,
    private ProjectRepository $projects,
    private AccountService $account,
  ) {
  }

  /**
   * POST /v1/auth/login - {email, password}
   */
  public function login(LoginRequest $input, ServerRequestInterface $request): ResponseInterface
  {
    $result = $this->authService->login($input->email, $input->password, AuthMiddleware::clientIp($request));
    // The session starts in the first project of the user
    $user = $result['user'];
    $this->currentProject->set(array_values(array_filter($this->projects->all(), static fn($project): bool => $user->inProject($project->id) && !$project->isGlobal))[0] ?? null);
    // The admin app keeps the login in the httpOnly cookie; scripts use the token of the answer
    return SessionCookie::set($this->responseFactory->success(['token' => $result['token']] + $this->session($result['user'])), $request, $result['token']);
  }

  /**
   * POST /v1/auth/logout - removes the login cookie of the admin app (tokens of scripts stay valid until they expire).
   */
  public function logout(ServerRequestInterface $request): ResponseInterface
  {
    return SessionCookie::clear($this->responseFactory->success(['signed_out' => true]), $request);
  }

  /**
   * GET /v1/auth/me - the user and what they may do with which entity (the app builds its
   * navigation from it; the API checks again on every request).
   */
  public function me(): ResponseInterface
  {
    return $this->responseFactory->success($this->session($this->currentUser->getUser()));
  }

  /**
   * POST /v1/auth/change-password - {current_password, new_password, new_password_confirmation};
   * returns a new token.
   */
  public function changePassword(JsonInput $input, ServerRequestInterface $request): ResponseInterface
  {
    $token = $this->authService->changePassword(
      $this->currentUser->getUser(),
      $input->getString('current_password'),
      $input->getString('new_password'),
      $input->getString('new_password_confirmation'),
    );
    // Older logins end with the new password - this one goes on
    return SessionCookie::set($this->responseFactory->success(['token' => $token]), $request, $token);
  }

  private function session(User $user): array
  {
    $entities = [];
    foreach ($this->entityRepository->all() as $entity) {
      $permissions = $user->permissionsFor($entity->id);
      if ($permissions[EntityPermission::Read->value] || $permissions[EntityPermission::Import->value]) {
        $entities[] = ['id' => $entity->id, 'slug' => $entity->slug, 'name' => $entity->name, 'access' => $entity->access->value, 'global' => $entity->global, 'permissions' => $permissions];
      }
    }
    return [
      // pending_email: new address waiting for the link in the confirmation mail
      'user' => ['id' => $user->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(), 'pending_email' => $this->account->pendingEmail($user), 'is_admin' => $user->isAdmin(), 'roles' => $user->getRoles(), 'take_over' => $user->canTakeOverLocks()],
      // Projects the user works in and the one of this request (header X-Project)
      'projects' => array_values(array_map(static fn($project): array => $project->toArray(), array_filter($this->projects->all(), static fn($project): bool => $user->inProject($project->id)))),
      'project' => $this->currentProject->find()?->toArray(),
      'entities' => $entities,
    ];
  }
}
