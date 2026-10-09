<?php

declare(strict_types=1);

/**
 * Builds the ZIP of a plugin of the plugins project (../excellent-plugins next to the CMS, or PLUGINS_SOURCE):
 * php bin/plugin-zip.php <name> [target folder]
 *
 * A plugin with a composer.json gets its libraries installed into vendor/ first (without dev
 * packages, autoloader optimized); one with a package.json gets its scripts built (npm install,
 * node build.mjs - e.g. web components into assets/). The server never runs Composer or Node.
 * Only files the upload accepts go into the ZIP (see PluginManager::EXTENSIONS) - no node_modules,
 * no sources of the scripts (js/).
 */

require dirname(__DIR__).'/vendor/autoload.php';

use App\Plugin\PluginManager;

$name = $argv[1] ?? '';
$sources = getenv('PLUGINS_SOURCE') ?: dirname(__DIR__, 2).'/excellent-plugins';
$source = rtrim($sources, '/').'/'.$name;
$targetDir = $argv[2] ?? dirname(__DIR__).'/runtime/plugins';
if ('' === $name || !is_file($source.'/plugin.json')) {
    fwrite(STDERR, "Usage: php bin/plugin-zip.php <name> - a folder of {$sources} with a plugin.json (other place: PLUGINS_SOURCE=…)\n");
    exit(1);
}

$build = sys_get_temp_dir().'/plugin-build-'.$name.'-'.bin2hex(random_bytes(4));
$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $item) {
        if ('.' === $item || '..' === $item || 'vendor' === $item || 'node_modules' === $item || str_starts_with($item, '.')) {
            continue;
        }
        is_dir("$from/$item") ? $copy("$from/$item", "$to/$item") : copy("$from/$item", "$to/$item");
    }
};
$copy($source, $build.'/'.$name);

if (is_file($build.'/'.$name.'/composer.json')) {
    $command = sprintf('cd %s && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet 2>&1', escapeshellarg($build.'/'.$name));
    exec($command, $output, $code);
    if (0 !== $code) {
        fwrite(STDERR, "composer install failed:\n".implode("\n", $output)."\n");
        exit(1);
    }
}

if (is_file($build.'/'.$name.'/package.json')) {
    $command = sprintf('cd %s && npm install --no-audit --no-fund --loglevel=error 2>&1 && node build.mjs 2>&1', escapeshellarg($build.'/'.$name));
    exec($command, $output, $code);
    if (0 !== $code) {
        fwrite(STDERR, "building the scripts failed:\n".implode("\n", $output)."\n");
        exit(1);
    }
}

@mkdir($targetDir, 0775, true);
$zipFile = $targetDir.'/'.$name.'.zip';
@unlink($zipFile);
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE);
$count = 0;
$skipped = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($build) + 1);
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $allowed = in_array($extension, PluginManager::EXTENSIONS, true) || in_array(basename($relative), ['LICENSE', 'README', 'CHANGELOG'], true);
    // Tests and docs of the libraries are not needed on the server
    $buildOnly = preg_match("#^[^/]+/(node_modules|js|sdk)/#", $relative) || preg_match('#^[^/]+/(package(-lock)?\.json|build\.mjs)$#', $relative);
    if (!$allowed || $buildOnly || str_contains($relative, '/.') || preg_match('#/vendor/[^/]+/[^/]+/(tests?|docs?|examples?)/#i', $relative)) {
        $skipped++;
        continue;
    }
    $zip->addFile($file->getPathname(), $relative);
    $count++;
}
$zip->close();
exec('rm -rf '.escapeshellarg($build));
printf("%s – %d files (%d left out), %.1f MB\n", $zipFile, $count, $skipped, filesize($zipFile) / 1024 / 1024);
