<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Project\Project;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;

/**
 * The project of the current request: from the header X-Project (admin app) or the path
 * /api/v1/<project>/content (content API). Entities, API clients and media are read only from it.
 *
 * Not set at all (console commands): nothing is limited, e.g. the cleanup sees every project.
 * Set to null (a user without projects): nothing can be read.
 */
final class CurrentProject
{
  private ?Project $project = null;
  private bool $scoped = false;

  public function set(?Project $project): void
  {
    $this->project = $project;
    $this->scoped = true;
  }

  public function isScoped(): bool
  {
    return $this->scoped;
  }

  public function find(): ?Project
  {
    return $this->project;
  }

  public function get(): Project
  {
    return $this->project ?? throw new UserFacingException(I18n::t('Please choose a project first.'), 400, 'no_project');
  }

  public function id(): ?string
  {
    return $this->project?->id;
  }
}
