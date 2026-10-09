<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;

/**
 * The Flysystem adapter sends an ACL with every upload. New AWS buckets ("bucket owner enforced")
 * reject that and Cloudflare R2 does not support ACLs - so it is left out, unless the ACL of the storage sets
 * one (e.g. public-read for old buckets that need it).
 */
final class AclFreeS3Client extends S3Client
{
  private ?string $acl = null;

  public function withAcl(?string $acl): self
  {
    $this->acl = '' === (string)$acl ? null : $acl;
    return $this;
  }

  public function putObject($input): PutObjectOutput
  {
    if (is_array($input)) {
      unset($input['ACL']);
      if (null !== $this->acl) {
        $input['ACL'] = $this->acl;
      }
    } elseif ($input instanceof PutObjectRequest) {
      $input->setAcl($this->acl);
    }
    return parent::putObject($input);
  }
}
