<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Service\CurrentProject;
use App\Application\Service\EnvVariables;
use App\Domain\Schema\EntityDefinition;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use RuntimeException;
use Yiisoft\Http\Status;

/**
 * Preview of records on the website, which runs elsewhere: the admin app asks for a short-lived
 * token (POST /entities/{slug}/records/{id}/preview) and opens the preview address of the entity
 * with it, e.g. https://example.com/api/preview?id={{id}}&token={{token}}. Requests of the content
 * API with that token (?preview=… or header X-Preview-Token) get drafts and the working copies of
 * published records instead of the live state - only of that project, only reading, only until it
 * expires. The website renders them with its own design.
 *
 * Placeholders of the address: {{id}}, {{entity}}, {{token}}, {{lang}}, {{record.<field>}} (URL
 * encoded) and $NAME .env variables (e.g. the secret of a draft-mode endpoint).
 */
final class PreviewService
{
  private const PLACEHOLDER = '/\{\{\s*([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)?)\s*\}\}/i';

  public function __construct(
    private string $secret,
    private int $ttl,
    private CurrentProject $currentProject,
    private EnvVariables $env,
  ) {
  }

  /**
   * A token for the project, and the preview address of the record if the entity has one.
   *
   * @param array<string, mixed> $record presented record (default language)
   * @return array{token: string, expires_at: string, url: ?string}
   */
  public function issue(EntityDefinition $entity, array $record, ?string $language): array
  {
    $expires = time() + $this->ttl;
    $token = $this->sign(['p' => $entity->projectId, 'e' => $entity->slug, 'r' => (string)$record['id'], 'x' => $expires]);
    $url = null;
    if (null !== $entity->previewUrl) {
      $context = ['id' => (string)$record['id'], 'entity' => $entity->slug, 'token' => $token, 'lang' => $language ?? (string)$entity->defaultLanguage(), 'record' => $record];
      $url = (string)preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($context): string {
        $value = $context;
        foreach (explode('.', $match[1]) as $key) {
          $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
        }
        return is_scalar($value) ? rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) : '';
      }, $entity->previewUrl);
      $url = $this->env->interpolate($url, true);
    }
    return ['token' => $token, 'expires_at' => date('c', $expires), 'url' => $url];
  }

  /**
   * Checks a token of a request to the content API.
   *
   * @throws UserFacingException 401 if it is invalid, expired or of another project
   */
  public function verify(string $token): void
  {
    $parts = explode('.', $token);
    $payload = 2 === count($parts) ? json_decode((string)self::decode($parts[0]), true) : null;
    if (!is_array($payload) || !hash_equals($this->signature($parts[0]), $parts[1])) {
      throw new UserFacingException(I18n::t('The preview token is invalid.'), Status::UNAUTHORIZED, 'invalid_preview_token');
    }
    if ((int)($payload['x'] ?? 0) < time()) {
      throw new UserFacingException(I18n::t('The preview has expired - please open it again in the CMS.'), Status::UNAUTHORIZED, 'preview_expired');
    }
    if (($payload['p'] ?? null) !== $this->currentProject->id()) {
      throw new UserFacingException(I18n::t('The preview token belongs to another project.'), Status::UNAUTHORIZED, 'invalid_preview_token');
    }
  }

  private function sign(array $payload): string
  {
    $encoded = self::encode((string)json_encode($payload));
    return $encoded.'.'.$this->signature($encoded);
  }

  private function signature(string $encoded): string
  {
    if (strlen($this->secret) < 32) {
      throw new RuntimeException('JWT_SECRET must be set to at least 32 characters.');
    }
    // Own key, derived from the secret: a preview token can never be used as a login and vice versa
    return self::encode(hash_hmac('sha256', $encoded, hash_hmac('sha256', 'preview', $this->secret), true));
  }

  private static function encode(string $value): string
  {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private static function decode(string $value): string|false
  {
    return base64_decode(strtr($value, '-_', '+/'), true);
  }
}
