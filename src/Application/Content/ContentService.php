<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Service\CurrentClient;
use App\Application\Service\CurrentProject;
use App\Domain\Schema\Access;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldType;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Http\Status;

/**
 * Read access of the headless API (/api/v1/content/…). Public entities are open to everybody,
 * protected ones (access "oauth") need a bearer token of a client that was given the entity.
 *
 *   GET /content                         entities the caller may read, with their fields
 *   GET /content/{slug}                  records: ?page, limit, s, filter[…], sort, fields, include
 *   GET /content/{slug}/{id}             one record: ?fields, include
 *
 *   GET /content/{slug}?tree=1           all records nested by the parent field (tree entities)
 *   ?lang=en                             translatable fields in this language (empty: default language), lang=all: all of them
 *
 * `include=country` replaces the id in the reference field by the referenced record (if the caller
 * may read that entity). Records pointing to a record are found with a filter instead:
 * /content/authors?filter[country]=<id>.
 */
final class ContentService
{
  public function __construct(
    private EntityRepository $entityRepository,
    private RecordRepository $records,
    private RecordPresenter $presenter,
    private CurrentClient $currentClient,
    private RecordService $recordService,
    private RecordQuery $recordQuery,
    private TreeService $tree,
    private CurrentProject $currentProject,
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
    private ?PreviewService $previews = null,
    private ?\App\Repository\WorkingCopyRepository $workingCopies = null,
    private ?BlockTemplates $templates = null,
  ) {
  }

  /** ?render=html: the items of blocks fields get "_html" (their templates) */
  private bool $html = false;

  public function renderHtml(bool $html, string $apiUrl = ''): void
  {
    $this->html = $html;
    if ('' !== $apiUrl) {
      $this->templates?->setApiUrl($apiUrl);
    }
  }

  /**
   * HTML of unsaved blocks (live editing in the preview): the items of a blocks field with "_html".
   *
   * @param list<mixed> $blocks
   * @return list<mixed>
   */
  public function renderBlocks(EntityDefinition $entity, string $fieldName, array $blocks): array
  {
    $field = $entity->field($fieldName);
    if (null === $field || !$field->isBlocks() || null === $this->templates) {
      throw UserFacingException::notFound(I18n::t('"{field}" is no blocks field.', ['field' => $fieldName]));
    }
    return $this->templates->items($field, $blocks);
  }

  /** Preview token of the request (see PreviewService): drafts and working copies are delivered */
  private bool $preview = false;

  /**
   * ?preview=<token> or X-Preview-Token: checks the token and switches to the preview.
   */
  public function preview(string $token): void
  {
    $token = trim($token);
    if ('' === $token || null === $this->previews) {
      return;
    }
    $this->previews->verify($token);
    $this->preview = true;
  }

  public function isPreview(): bool
  {
    return $this->preview;
  }

  /**
   * @return list<array>
   */
  public function entities(): array
  {
    $result = [];
    foreach ($this->entityRepository->all() as $entity) {
      if ($this->currentClient->canRead($entity)) {
        $data = $entity->toArray();
        unset($data['id'], $data['sort_order'], $data['created_at'], $data['trash']);
        // Fields limited to roles the caller does not have are not listed; the roles stay internal
        $readable = array_flip(array_map(static fn($field): string => $field->name, null !== $this->fieldAccess ? $this->fieldAccess->readableFields($entity) : $entity->fields));
        $data['fields'] = array_values(array_map(static function (array $field): array {
          unset($field['id'], $field['sort_order'], $field['read_roles'], $field['write_roles']);
          // Fields of plugins: only what they are, not the component of the admin app
          if (is_array($field['custom'] ?? null)) {
            $field['custom'] = ['type' => $field['custom']['type'], 'plugin' => $field['custom']['plugin'], 'label' => $field['custom']['label']];
          }
          return $field;
        }, array_filter($data['fields'], static fn(array $field): bool => isset($readable[$field['name']]))));
        $result[] = $data;
      }
    }
    return $result;
  }

  public function entity(string $slug): EntityDefinition
  {
    $entity = $this->entityRepository->findBySlug($slug) ?? throw UserFacingException::notFound(I18n::t('The entity "{entity}" does not exist.', ['entity' => $slug]));
    if (!$this->currentClient->canRead($entity)) {
      throw $this->currentClient->isAuthenticated()
        ? UserFacingException::forbidden(I18n::t('This client may not read "{entity}".', ['entity' => $slug]))
        : new UserFacingException(I18n::t('"{entity}" is protected - please request it with an OAuth token.', ['entity' => $slug]), Status::UNAUTHORIZED, 'unauthorized');
    }
    return $entity;
  }

  public function query(EntityDefinition $entity, string $search, array $filter, ?string $sort, ?string $language = null): QueryInterface
  {
    return $this->recordQuery->apply(
      $this->preview ? $this->records->query($entity) : $this->records->publishedQuery($entity),
      $entity,
      $search,
      $filter,
      $sort,
      'created_at',
      RecordPresenter::ALL_LANGUAGES === $language ? null : $language,
      // Fields of referenced entities only if this client may read them
      fn(EntityDefinition $target): bool => $this->currentClient->canRead($target),
    );
  }

  /**
   * Presents a page of rows (typed values, selected fields, included references).
   *
   * @param list<array> $rows
   * @return list<array>
   */
  public function present(EntityDefinition $entity, array $rows, ?array $fields, array $include, ?string $language = null): array
  {
    if ($this->preview && null !== $this->workingCopies) {
      // Published records with saved changes: as they will be
      $rows = array_map(fn(array $row): array => array_merge($row, $this->workingCopies->find($entity, (string)$row['id'])['values'] ?? []), $rows);
    }
    $records = $this->presenter->withMedia($entity, array_map(fn(array $row): array => $this->presenter->present($entity, $row, $fields, $language, true), $rows));
    $records = $this->withSlugPaths($entity, $records, $language);
    if ($this->html && null !== $this->templates) {
      $records = $this->templates->decorate($entity, $records);
    }
    return $this->presenter->expand($entity, $records, $include, fn(EntityDefinition $target): bool => $this->currentClient->canRead($target), $language);
  }

  /**
   * Trees: slugs as the whole path ("world/europe/western-europe"), filter[slug] takes it too.
   *
   * @param list<array> $records
   * @return list<array>
   */
  private function withSlugPaths(EntityDefinition $entity, array $records, ?string $language): array
  {
    if (null === $entity->treeField() || [] === $records) {
      return $records;
    }
    foreach ($entity->fields as $field) {
      if (FieldType::Slug !== $field->type || !array_key_exists($field->name, $records[0])) {
        continue;
      }
      $all = RecordPresenter::ALL_LANGUAGES === $language && $field->translatable;
      $languages = $all ? $entity->languages : [$language ?? (string)$entity->defaultLanguage()];
      $paths = $this->tree->slugPaths($entity, $field, $languages);
      foreach ($records as &$record) {
        $path = $paths[(string)$record['id']] ?? [];
        if ($all) {
          foreach ($languages as $code) {
            if (isset($path[$code]) && is_array($record[$field->name])) {
              $record[$field->name][$code] = $path[$code];
            }
          }
        } elseif (null !== $record[$field->name]) {
          $record[$field->name] = $path[$languages[0]] ?? $record[$field->name];
        }
      }
      unset($record);
    }
    return $records;
  }

  /**
   * ?tree=1: all matching records nested by their parent field, siblings in the requested order.
   *
   * @return list<array> records with `children`
   */
  public function tree(EntityDefinition $entity, string $search, array $filter, ?string $sort, ?array $fields, array $include, ?string $language = null): array
  {
    $field = $entity->treeField() ?? throw ValidationException::field('tree', I18n::t('"{entity}" is no tree - the schema has no parent field.', ['entity' => $entity->slug]));
    $query = $this->query($entity, $search, $filter, $sort, $language);
    if ((int)(clone $query)->count() > TreeService::MAX_NESTED) {
      throw ValidationException::field('tree', I18n::t('The tree is too large (more than {count} records). Please filter it or load it page by page.', ['count' => TreeService::MAX_NESTED]));
    }
    $rows = $query->all();
    $parents = array_map(static fn($parent): ?string => null !== $parent ? (string)$parent : null, array_column($rows, $field->name, 'id'));
    return TreeService::nest($this->present($entity, $rows, $fields, $include, $language), $parents);
  }

  public function get(EntityDefinition $entity, string $id, ?array $fields, array $include, ?string $language = null): array
  {
    $row = $this->published($entity, $id);
    return $this->present($entity, [$row], $fields, $include, $language)[0];
  }

  /**
   * POST /content/{slug}: a new record - with the same checks as in the admin app.
   */
  public function create(EntityDefinition $entity, array $data, ?string $language): array
  {
    $this->assertCan('create', $entity);
    // Drafts are an affair of the admin app: records from the API are published
    unset($data[EntityDefinition::DRAFT]);
    $record = $this->recordService->create($entity, self::intoLanguage($entity, $data, $language));
    return $this->get($entity, (string)$record['id'], null, [], $language);
  }

  /**
   * PUT/PATCH /content/{slug}/{id}: only the sent fields change.
   */
  public function update(EntityDefinition $entity, string $id, array $data, ?string $language): array
  {
    $this->assertCan('update', $entity, $this->published($entity, $id));
    unset($data[EntityDefinition::DRAFT]);
    $this->recordService->update($entity, $id, self::intoLanguage($entity, $data, $language));
    return $this->get($entity, $id, null, [], $language);
  }

  /**
   * DELETE /content/{slug}/{id}: into the trash if the entity has one.
   */
  public function delete(EntityDefinition $entity, string $id): void
  {
    $this->assertCan('delete', $entity, $this->published($entity, $id));
    $this->recordService->delete($entity, $id);
  }

  /**
   * A record the content API may see - drafts do not exist for it.
   */
  private function published(EntityDefinition $entity, string $id): array
  {
    $row = $this->records->find($entity, $id);
    if (null === $row || (!$this->preview && RecordRepository::isDraft($entity, $row))) {
      throw UserFacingException::notFound(I18n::t('This record does not exist.'));
    }
    return $row;
  }

  /**
   * @param 'create'|'update'|'delete' $permission
   * @param array|null $row the record (update, delete): "update_own" / "delete_own" allow records the client created
   */
  private function assertCan(string $permission, EntityDefinition $entity, ?array $row = null): void
  {
    if (!$this->currentClient->isAuthenticated()) {
      throw new UserFacingException(I18n::t('Please sign in with an OAuth token to write.'), Status::UNAUTHORIZED, 'unauthorized');
    }
    $client = $this->currentClient->getClient();
    $own = null !== $row && null !== $client && $this->currentClient->can($permission.'_own', $entity)
      && \App\Application\Access\OwnRecordRule::owns(\App\Application\Access\AccessControl::client($client->id), $row);
    if (!$own && !$this->currentClient->can($permission, $entity)) {
      throw UserFacingException::forbidden(match ($permission) {
        'create' => I18n::t('This client may not create records in "{entity}".', ['entity' => $entity->slug]),
        'update' => I18n::t('This client may not edit records in "{entity}".', ['entity' => $entity->slug]),
        default => I18n::t('This client may not delete records in "{entity}".', ['entity' => $entity->slug]),
      });
    }
  }

  /**
   * ?lang=de when writing: values of translatable fields are that language's (into _i18n).
   */
  private static function intoLanguage(EntityDefinition $entity, array $data, ?string $language): array
  {
    if (null === $language || RecordPresenter::ALL_LANGUAGES === $language || $language === $entity->defaultLanguage()) {
      return $data;
    }
    foreach ($entity->translatableFields() as $field) {
      if (array_key_exists($field->name, $data)) {
        $data['_i18n'][$field->name][$language] = $data[$field->name];
        unset($data[$field->name]);
      }
    }
    return $data;
  }

  /**
   * ?lang=en: one of the project's languages, "all", or nothing (= default language).
   */
  public function language(EntityDefinition $entity, string $value): ?string
  {
    $value = strtolower(trim($value));
    if ('' === $value) {
      return null;
    }
    // Global entities answer in the languages of the requesting project too (default language if they lack one)
    $projectLanguages = $entity->global ? ($this->currentProject->find()?->languages ?? []) : [];
    if (RecordPresenter::ALL_LANGUAGES !== $value && !in_array($value, $entity->languages, true) && !in_array($value, $projectLanguages, true)) {
      throw ValidationException::field('lang', [] === $entity->languages
        ? I18n::t('This project has no languages.')
        : I18n::t('Unknown language "{language}" (possible: {languages}, all).', ['language' => $value, 'languages' => implode(', ', $entity->languages)]));
    }
    return $value;
  }

  /**
   * ?fields=name,price -> ['name', 'price'] (unknown names are an error, not silently ignored).
   *
   * @return list<string>|null
   */
  public function fields(EntityDefinition $entity, string $value): ?array
  {
    $names = array_values(array_filter(array_map('trim', explode(',', $value))));
    if ([] === $names) {
      return null;
    }
    foreach ($names as $name) {
      $field = $entity->field($name);
      if (null === $field || (null !== $this->fieldAccess && !$this->fieldAccess->canRead($field))) {
        throw ValidationException::field('fields', I18n::t('The field "{field}" does not exist.', ['field' => $name]));
      }
    }
    return $names;
  }

  /**
   * @return list<string>
   */
  public function includes(EntityDefinition $entity, string $value, ?array $fields): array
  {
    $names = array_values(array_filter(array_map('trim', explode(',', $value))));
    foreach ($names as $name) {
      $field = $entity->field($name);
      if (null === $field || FieldType::Reference !== $field->type) {
        throw ValidationException::field('include', I18n::t('"{field}" is no reference field.', ['field' => $name]));
      }
      if (null !== $fields && !in_array($name, $fields, true)) {
        throw ValidationException::field('include', I18n::t('"{field}" must be in fields too.', ['field' => $name]));
      }
    }
    return $names;
  }

  public function isProtected(EntityDefinition $entity): bool
  {
    return Access::OAuth === $entity->access;
  }
}
