<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Api\Input\UserInput;
use App\Domain\Access\EntityPermission;
use App\Domain\Entity\User;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;

final class UserService
{
  public function __construct(
    private UserRepository $userRepository,
    private EntityRepository $entityRepository,
    private CurrentUser $currentUser,
    private CurrentProject $currentProject,
    private ProjectRepository $projects,
    private \App\Application\Access\AccessControl $access,
  ) {
  }

  public function get(string $id): User
  {
    return $this->userRepository->get($id) ?? throw UserFacingException::notFound(I18n::t('This user does not exist.'));
  }

  public function create(UserInput $input): User
  {
    $errors = $this->validate($input, null);
    if (null === $input->password) {
      $errors['password'][] = I18n::t('The password must have at least {count} characters.', ['count' => AuthService::MIN_PASSWORD_LENGTH]);
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $user = new User(
      id: Id::new(),
      name: (string)$input->name,
      email: (string)$input->email,
      passwordHash: '',
      isAdmin: $input->isAdmin ?? false,
      isActive: $input->isActive ?? true,
    );
    $user->setRoles($input->roles ?? []);
    $user->setPassword((string)$input->password);
    $user->setPermissions($input->permissions ?? []);
    // Created inside a project: works there unless other projects are given
    $user->setProjectIds($input->projects ?? array_filter([$this->currentProject->id()]));
    $this->userRepository->save($user);

    return $this->get($user->getId());
  }

  public function update(string $id, UserInput $input): User
  {
    $user = $this->get($id);
    $errors = $this->validate($input, $user);
    $isSelf = $user->getId() === $this->currentUser->getId();

    // Nobody locks themselves out, and there is always an active admin left
    if ($isSelf && false === $input->isActive) {
      $errors['is_active'][] = I18n::t('You cannot deactivate yourself.');
    }
    if ($isSelf && false === $input->isAdmin) {
      $errors['is_admin'][] = I18n::t('You cannot take away your own administrator rights.');
    }
    if ($user->isAdmin() && (false === $input->isAdmin || false === $input->isActive) && 0 === $this->userRepository->countActiveAdmins($user->getId())) {
      $errors['is_admin'][] = I18n::t('At least one active administrator must remain.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    if (null !== $input->name) {
      $user->setName($input->name);
    }
    if (null !== $input->email) {
      $user->setEmail($input->email);
    }
    if (null !== $input->password) {
      $user->setPassword($input->password);
    }
    if (null !== $input->isAdmin) {
      $user->setAdmin($input->isAdmin);
    }
    if (null !== $input->roles) {
      $user->setRoles($input->roles);
    }
    if (null !== $input->isActive) {
      $user->setActive($input->isActive);
    }
    if (null !== $input->permissions) {
      // The admin app edits the permissions of one project - those of other projects stay
      $current = array_flip(array_map(static fn($entity): string => $entity->id, $this->entityRepository->all()));
      $kept = $this->currentProject->isScoped() ? array_diff_key($user->getPermissions(), $current) : [];
      $user->setPermissions($kept + $input->permissions);
    }
    if (null !== $input->projects) {
      $user->setProjectIds($input->projects);
    }
    $this->userRepository->save($user);

    return $this->get($user->getId());
  }

  public function delete(string $id): void
  {
    $user = $this->get($id);
    if ($user->getId() === $this->currentUser->getId()) {
      throw new UserFacingException(I18n::t('You cannot delete yourself.'));
    }
    if ($user->isAdmin() && 0 === $this->userRepository->countActiveAdmins($user->getId())) {
      throw new UserFacingException('Es muss mindestens ein aktiver Administrator bleiben.');
    }
    $this->userRepository->delete($id);
  }

  /**
   * @return array<string, string[]>
   */
  private function validate(UserInput $input, ?User $user): array
  {
    $errors = [];
    $isNew = null === $user;

    foreach ($input->roles ?? [] as $role) {
      if (!$this->access->roleExists($role)) {
        $errors['roles'][] = I18n::t('This role does not exist: {role}', ['role' => $role]);
      }
    }

    if ($isNew || null !== $input->name) {
      if ('' === (string)$input->name) {
        $errors['name'][] = I18n::t('Please enter a name.');
      } elseif (mb_strlen((string)$input->name) > 100) {
        $errors['name'][] = I18n::t('The name is too long.');
      }
    }
    if ($isNew || null !== $input->email) {
      $email = (string)$input->email;
      if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'][] = I18n::t('Please enter a valid e-mail address.');
      } elseif ($this->userRepository->emailExists($email, $user?->getId())) {
        $errors['email'][] = I18n::t('This e-mail address is already in use.');
      }
    }
    if (null !== $input->password && mb_strlen($input->password) < AuthService::MIN_PASSWORD_LENGTH) {
      $errors['password'][] = I18n::t('The password must have at least {count} characters.', ['count' => AuthService::MIN_PASSWORD_LENGTH]);
    }
    foreach ($input->projects ?? [] as $projectId) {
      if (null === $this->projects->find($projectId)) {
        $errors['projects'][] = I18n::t('Unknown project.');
        break;
      }
    }
    foreach ($input->permissions ?? [] as $entityId => $permissions) {
      if (null === $this->entityRepository->findById($entityId)) {
        $errors['permissions'][] = I18n::t('Unknown entity in the permissions.');
        break;
      }
      foreach (array_keys($permissions) as $permission) {
        if (null === EntityPermission::tryFrom((string)$permission)) {
          $errors['permissions'][] = I18n::t('Unknown permission "{permission}".', ['permission' => $permission]);
          break 2;
        }
      }
    }
    return $errors;
  }
}
