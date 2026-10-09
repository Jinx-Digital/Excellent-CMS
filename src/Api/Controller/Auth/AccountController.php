<?php

declare(strict_types=1);

namespace App\Api\Controller\Auth;

use App\Api\Input\JsonInput;
use App\Api\Middleware\AuthMiddleware;
use App\Application\Service\AccountService;
use App\Application\Service\CurrentUser;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Forgotten password and a new e-mail address of the signed-in user.
 */
final class AccountController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private AccountService $account,
    private CurrentUser $currentUser,
  ) {
  }

  /**
   * POST /v1/auth/password/forgot - {email}; the same answer whether the address exists or not
   */
  public function forgotPassword(JsonInput $input, ServerRequestInterface $request): ResponseInterface
  {
    $this->account->forgotPassword($input->getString('email'), AuthMiddleware::clientIp($request));
    return $this->responseFactory->success(['sent' => true]);
  }

  /**
   * POST /v1/auth/password/reset - {token, password, password_confirmation}; returns a login token
   */
  public function resetPassword(JsonInput $input, ServerRequestInterface $request): ResponseInterface
  {
    $result = $this->account->resetPassword($input->getString('token'), $input->getString('password'), $input->getString('password_confirmation'));
    return \App\Api\SessionCookie::set($this->responseFactory->success(['token' => $result['token']]), $request, $result['token']);
  }

  /**
   * POST /v1/auth/email - {email, password}: sends the confirmation link to the new address
   */
  public function requestEmailChange(JsonInput $input): ResponseInterface
  {
    $email = $this->account->requestEmailChange($this->currentUser->getUser(), $input->getString('email'), $input->getString('password'));
    return $this->responseFactory->success(['pending_email' => $email]);
  }

  /**
   * DELETE /v1/auth/email - forget the address waiting for confirmation
   */
  public function cancelEmailChange(): ResponseInterface
  {
    $this->account->cancelEmailChange($this->currentUser->getUser());
    return $this->responseFactory->success(['pending_email' => null]);
  }

  /**
   * POST /v1/auth/email/confirm - {token} from the link in the mail (no login needed)
   */
  public function confirmEmail(JsonInput $input): ResponseInterface
  {
    $user = $this->account->confirmEmail($input->getString('token'));
    return $this->responseFactory->success(['email' => $user->getEmail()]);
  }
}
