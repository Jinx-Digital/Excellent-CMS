<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Media library › File types: admins choose which types may be uploaded - SVG only when switched on,
 * served in a sandbox (no scripts).
 */
class MediaTypesTest extends ApiTestCase
{
  private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><rect width="10" height="10"/></svg>';

  public function testAdminsChooseTheFileTypes(): void
  {
    $admin = $this->login();
    $before = $this->api('GET', '/admin/settings/media-types', token: $admin)['body']['data'];
    $svg = array_column($before['types'], null, 'type')['image/svg+xml'];
    $this->assertSame(['svg', 'image', true], [$svg['extension'], $svg['group'], $svg['optional']]);
    $this->assertNotContains('image/svg+xml', $before['allowed'], 'off by default');
    $this->assertContains('application/pdf', $before['allowed']);
    try {
      // Off: refused (also without the XML declaration, as file detection may see text)
      $refused = $this->upload('/media', self::SVG, 'logo.svg', $admin);
      $this->assertSame([422, 'media_type'], [$refused['status'], $refused['body']['error_code']]);

      // On: an image without transformations, served without scripts
      $this->api('PUT', '/admin/settings/media-types', ['allowed' => [...$before['allowed'], 'image/svg+xml']], $admin);
      $file = $this->upload('/media', self::SVG, 'logo.svg', $admin)['body']['data'];
      $this->assertSame(['image/svg+xml', true, null], [$file['mime_type'], $file['is_image'], $file['transform_url']]);
      $this->assertStringEndsWith('.svg', parse_url($file['url'], PHP_URL_PATH));
      $served = $this->fetch($file['url']);
      $this->assertSame(200, $served['status']);
      $this->assertStringContainsString('sandbox', $served['headers']['Content-Security-Policy'] ?? '');
      // Media fields with "image/*" take it too
      $this->assertContains('logo.svg', array_column($this->api('GET', '/media?s=logo&kind=image', token: $admin)['body']['data'], 'name'));

      // Only images: a PDF is refused; none at all cannot be saved
      $this->api('PUT', '/admin/settings/media-types', ['allowed' => ['image/png', 'image/jpeg']], $admin);
      $pdf = $this->upload('/media', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'doc.pdf', $admin);
      $this->assertSame(422, $pdf['status']);
      $this->assertStringContainsString('PNG', $pdf['body']['error_message']);
      $this->assertArrayHasKey('allowed', $this->api('PUT', '/admin/settings/media-types', ['allowed' => []], $admin)['body']['error_data']);
      // Editors may not change it
      $this->assertSame(403, $this->api('PUT', '/admin/settings/media-types', ['allowed' => ['image/png']], $this->editorToken())['status']);
    } finally {
      $this->api('PUT', '/admin/settings/media-types', ['allowed' => $before['allowed']], $admin);
    }
  }
}
