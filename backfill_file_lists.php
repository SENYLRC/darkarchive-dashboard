#!/usr/bin/env php
<?php
/**
 * Pre-build the per-package file-list cache (UPLOAD_DIR/.filelists/).
 *
 * The View page's "Show files" and the Reports "File List" both read each
 * package's original files from its stored METS via
 * cached_mets_original_files(). The first read of a package parses the whole
 * XML (up to ~7s for the largest); every later read uses the cache. Run this
 * once after deploying, and any time the cache is cleared, so the first
 * report over the whole archive doesn't have to do all that parsing itself.
 *
 * Needs no database connection: it walks the stored package files directly.
 * Safe to re-run — packages whose cache is already current are skipped.
 *
 * Usage:
 *   php backfill_file_lists.php                 # uses UPLOAD_DIR from .env
 *   php backfill_file_lists.php /path/to/uploads
 */

require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/xml_metadata.php';

if (isset($argv[1])) {
    $dir = rtrim($argv[1], '/');
} else {
    require_once __DIR__ . '/includes/config.php';
    $dir = rtrim(UPLOAD_DIR, '/');
}
if (!is_dir($dir)) {
    fwrite(STDERR, "Not a directory: $dir\n");
    exit(1);
}

$paths = array_values(array_filter(
    glob("$dir/*") ?: [],
    fn($p) => is_file($p) && preg_match('/\.(tar\.gz|tgz|tar|xml\.gz|xml)$/i', $p)
));
$total = count($paths);
echo "Building file-list cache for $total stored package(s) in $dir...\n";

$built = 0; $fresh = 0; $fallback = 0;
foreach ($paths as $i => $path) {
    $n     = $i + 1;
    $cache = "$dir/.filelists/" . basename($path) . '.json.gz';
    if (@filemtime($cache) >= filemtime($path)) { $fresh++; continue; }

    $t     = microtime(true);
    $files = cached_mets_original_files($path);
    $built++;
    if ($files === null) $fallback++;
    printf("[%d/%d] %s — %s (%.1fs)\n", $n, $total, basename($path),
           $files === null ? 'no original file group (View page uses fallback)' : count($files) . ' file(s)',
           microtime(true) - $t);
}

// The web server must be able to replace entries later, whoever ran this.
@chmod("$dir/.filelists", 0777);

echo "\nDone. $built built, $fresh already current, $fallback without an original file group.\n";
