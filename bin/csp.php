<?php

declare(strict_types=1);

/**
 * Content-Security-Policy of the admin app (run by `make generate`): only the app's own scripts run -
 * the inline scripts of the built pages by their hashes, plus Google Maps (plugin "geo"). Writes
 * public/.htaccess: resources/public.htaccess with the policy between "# CSP start" and "# CSP end";
 * for nginx: php bin/csp.php --print.
 */

$public = dirname(__DIR__).'/public';
$hashes = [];
foreach (glob($public.'/{*.html,*/index.html,*/*/index.html}', GLOB_BRACE) ?: [] as $page) {
  preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>(.*?)</script>#s', (string)file_get_contents($page), $matches);
  foreach ($matches[1] as $script) {
    if ('' !== trim($script)) {
      $hashes["'sha256-".base64_encode(hash('sha256', $script, true))."'"] = true;
    }
  }
}
$policy = implode('; ', [
  "default-src 'self'",
  "script-src 'self' ".implode(' ', array_keys($hashes)).' https://maps.googleapis.com',
  // Nuxt UI sets styles inline
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' data: https://fonts.gstatic.com",
  // Media of other storages, map tiles, previews of websites
  "img-src 'self' data: blob: https: http:",
  "media-src 'self' blob: https: http:",
  "connect-src 'self' https:",
  "frame-src 'self' https: http:",
  "worker-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'self'",
]);

if (in_array('--print', $argv, true)) {
  echo $policy, PHP_EOL;
  exit(0);
}
// public/.htaccess = the template of the repository + the policy (public/ is built, not in git)
$file = $public.'/.htaccess';
$htaccess = (string)file_get_contents(dirname(__DIR__).'/resources/public.htaccess');
$block = "# CSP start (written by bin/csp.php - make generate)\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.html$\">\n    Header set Content-Security-Policy \"".$policy."\"\n    Header set X-Content-Type-Options \"nosniff\"\n    Header set Referrer-Policy \"strict-origin-when-cross-origin\"\n  </FilesMatch>\n</IfModule>\n# CSP end";
$htaccess = preg_match('/# CSP start.*?# CSP end/s', $htaccess)
  ? (string)preg_replace('/# CSP start.*?# CSP end/s', str_replace('\\', '\\\\', $block), $htaccess)
  : rtrim($htaccess)."\n\n".$block."\n";
file_put_contents($file, $htaccess);
echo sprintf("CSP: %d inline scripts allowed by hash\n", count($hashes));
