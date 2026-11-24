<?php
/**
 * Archive handling for the ingest pipeline.
 *
 * Supports .zip, .7z and tar variants (.tar, .tar.gz/.tgz, .tar.bz2/.tbz2,
 * .tar.xz/.txz). Everything is extracted to a temp directory and the .xml files
 * are then collected recursively, so nested layouts inside an archive work.
 */

/** Reserved dropbox subdirectories that must never be treated as input. */
const INGEST_RESERVED_DIRS = ['processed', 'rejected', 'unresolved'];

/**
 * Classify an archive by extension. Returns 'zip' | '7z' | 'tar', or null if
 * the path is not a recognised archive.
 */
function archive_kind(string $path): ?string {
    $lower = strtolower(basename($path));
    if (str_ends_with($lower, '.zip')) return 'zip';
    if (str_ends_with($lower, '.7z'))  return '7z';
    foreach (['.tar.gz', '.tgz', '.tar.bz2', '.tbz2', '.tar.xz', '.txz', '.tar'] as $suffix) {
        if (str_ends_with($lower, $suffix)) return 'tar';
    }
    return null;
}

/** Locate an external extractor binary, or null if none is installed. */
function archive_tool(string $kind): ?string {
    $candidates = $kind === '7z' ? ['7z', '7za', '7zr'] : ['tar'];
    foreach ($candidates as $bin) {
        $found = trim((string)@shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($found !== '') return $found;
    }
    return null;
}

/**
 * Extract $path into $dest. Returns false and sets $err on failure.
 */
function extract_archive(string $path, string $dest, string &$err): bool {
    $err  = '';
    $kind = archive_kind($path);
    if ($kind === null) { $err = 'unsupported archive type'; return false; }

    if (!is_dir($dest) && !mkdir($dest, 0700, true)) {
        $err = "could not create temp directory $dest";
        return false;
    }

    if ($kind === 'zip') {
        if (!class_exists('ZipArchive')) { $err = 'ZipArchive extension not available'; return false; }
        $zip = new ZipArchive();
        $rc  = $zip->open($path);
        if ($rc !== true) { $err = "cannot open zip (code $rc)"; return false; }
        $ok = $zip->extractTo($dest);
        $zip->close();
        if (!$ok) { $err = 'zip extraction failed'; return false; }
        return true;
    }

    if ($kind === '7z') {
        $bin = archive_tool('7z');
        if ($bin === null) {
            $err = '7z binary not found — install it with: sudo apt install p7zip-full';
            return false;
        }
        // -o takes no space before its argument.
        $cmd = escapeshellarg($bin) . ' x -y -bd -o' . escapeshellarg($dest)
             . ' ' . escapeshellarg($path) . ' 2>&1';
        exec($cmd, $out, $rc);
        if ($rc !== 0) { $err = '7z failed: ' . trim(implode(' ', array_slice($out, -3))); return false; }
        return true;
    }

    // tar — GNU tar infers the compression from the file itself.
    $bin = archive_tool('tar');
    if ($bin !== null) {
        $cmd = escapeshellarg($bin) . ' -xf ' . escapeshellarg($path)
             . ' -C ' . escapeshellarg($dest) . ' 2>&1';
        exec($cmd, $out, $rc);
        if ($rc !== 0) { $err = 'tar failed: ' . trim(implode(' ', array_slice($out, -3))); return false; }
        return true;
    }
    if (class_exists('PharData')) {
        try {
            (new PharData($path))->extractTo($dest, null, true);
            return true;
        } catch (Throwable $e) {
            $err = 'PharData: ' . $e->getMessage();
            return false;
        }
    }
    $err = 'no tar extractor available (install tar or enable ext-phar)';
    return false;
}

/**
 * Collect every .xml under $dir, recursively.
 *
 * Each result is realpath-checked to stay inside $dir, so an archive member
 * with a "../" path cannot cause a file outside the temp directory to be
 * ingested. macOS resource-fork junk is skipped.
 */
function find_xml_files(string $dir): array {
    $root = realpath($dir);
    if ($root === false) return [];

    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        if (!$entry->isFile()) continue;
        if (strtolower($entry->getExtension()) !== 'xml') continue;

        $name = $entry->getFilename();
        if (str_starts_with($name, '._')) continue;                     // macOS fork
        if (str_contains($entry->getPathname(), '__MACOSX')) continue;  // macOS zip junk

        $real = realpath($entry->getPathname());
        if ($real === false) continue;
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) continue; // containment
        $found[] = $real;
    }
    sort($found);
    return $found;
}

/** Recursively remove a directory tree (used for extraction temp dirs). */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

/**
 * Package a single XML file as <dest>, a gzip-compressed tar containing one
 * member named $inner_name.
 *
 * Prefers the tar binary (memory-flat regardless of package size); falls back
 * to PharData where tar is unavailable. The source is hard-linked into a
 * staging directory when possible so a 37 MB package is not copied twice.
 */
function create_tar_gz(string $src_file, string $inner_name, string $dest, string &$err): bool {
    $err   = '';
    $stage = sys_get_temp_dir() . '/da_pack_' . bin2hex(random_bytes(6));
    if (!mkdir($stage, 0700, true)) {
        $err = "could not create staging directory $stage";
        return false;
    }

    $staged = $stage . '/' . $inner_name;
    if (!@link($src_file, $staged) && !@copy($src_file, $staged)) {
        $err = "could not stage $src_file";
        rrmdir($stage);
        return false;
    }

    $tar = archive_tool('tar');
    if ($tar !== null) {
        $cmd = escapeshellarg($tar) . ' -czf ' . escapeshellarg($dest)
             . ' -C ' . escapeshellarg($stage) . ' ' . escapeshellarg($inner_name) . ' 2>&1';
        exec($cmd, $out, $rc);
        rrmdir($stage);
        if ($rc !== 0) {
            $err = 'tar failed: ' . trim(implode(' ', array_slice($out, -3)));
            return false;
        }
        return true;
    }

    if (class_exists('PharData')) {
        $tmp_tar = $stage . '.tar';
        try {
            $archive = new PharData($tmp_tar);
            $archive->addFile($staged, $inner_name);
            $archive->compress(Phar::GZ);
            unset($archive);
            $ok = @rename($tmp_tar . '.gz', $dest);
            @unlink($tmp_tar);
            rrmdir($stage);
            if (!$ok) { $err = 'could not move packaged archive into place'; return false; }
            return true;
        } catch (Throwable $e) {
            @unlink($tmp_tar);
            @unlink($tmp_tar . '.gz');
            rrmdir($stage);
            $err = 'PharData: ' . $e->getMessage();
            return false;
        }
    }

    rrmdir($stage);
    $err = 'no tar packer available (install tar or enable ext-phar)';
    return false;
}

/**
 * Resolve a stored package file to a readable stream path for its XML.
 *
 * Handles all three shapes transparently so callers do not branch:
 *   - .tar.gz / .tgz / .tar  → a phar:// path to the XML member (current format)
 *   - .gz                    → compress.zlib:// wrapper (legacy .xml.gz)
 *   - anything else          → the path itself
 *
 * Returns null when the archive holds no XML member.
 */
function stored_xml_stream_path(string $path): ?string {
    $lower = strtolower($path);

    if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz') || str_ends_with($lower, '.tar')) {
        if (!class_exists('PharData')) {
            error_log('stored_xml_stream_path: ext-phar required to read ' . $path);
            return null;
        }
        try {
            $archive = new PharData($path);
            foreach (new RecursiveIteratorIterator($archive) as $entry) {
                if (strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION)) === 'xml') {
                    return $entry->getPathname(); // phar:// URL, streamable by readfile()
                }
            }
            error_log("stored_xml_stream_path: no .xml member in $path");
        } catch (Throwable $e) {
            error_log('stored_xml_stream_path: ' . $e->getMessage() . " ($path)");
        }
        return null;
    }

    if (str_ends_with($lower, '.gz')) return 'compress.zlib://' . $path;
    return $path;
}

/**
 * Return a filename that does not yet exist in $dir, appending -2, -3, ... when
 * the standardised name collides (same org, same date, same package name).
 */
function unique_stored_name(string $dir, string $base, string $ext): string {
    $dir       = rtrim($dir, '/');
    $candidate = $base . $ext;
    for ($n = 2; file_exists("$dir/$candidate"); $n++) {
        if ($n > 999) return $base . '-' . bin2hex(random_bytes(3)) . $ext;
        $candidate = $base . '-' . $n . $ext;
    }
    return $candidate;
}
