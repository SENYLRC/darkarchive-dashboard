#!/usr/bin/env php
<?php
/**
 * CLI processor for darkarchive XML packages.
 *
 * Usage:
 *   php process_uploads.php /path/to/dropbox
 *   php process_uploads.php /path/to/file.xml  LOC_CODE
 *   php process_uploads.php /path/to/file.zip  LOC_CODE
 *
 * Dropbox folder structure:
 *   dropbox/
 *     <LOC_CODE>/
 *       file1.xml
 *       file2.zip   ← ZIP containing one or more .xml files
 *
 * For each .xml (directly or extracted from .zip):
 *   - validates well-formed XML
 *   - gzip-compresses into uploads/ as <timestamp>_<hash>.xml.gz
 *   - extracts metadata elements into file_metadata
 *   - inserts a packages row linked to the org by LOC code
 *   - moves source to processed/ (or rejected/ on error)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/xml_metadata.php';
require_once __DIR__ . '/includes/archive.php';

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Compress, insert, and index one XML file.
 * $src      — path to a valid XML file on disk (temp or real)
 * $orig_name — original filename to store in DB
 * $orig_size — original byte size
 * Returns package_id on success, false on failure (sets $err_msg).
 */
function process_one_xml(PDO $db, PDOStatement|false $insert, int $org_id,
                         string $loc_code, string $src, string $orig_name,
                         int $orig_size, string $notes, string &$err_msg): int|false
{
    // Check for duplicate
    $dup = $db->prepare("SELECT id, processed_at FROM packages WHERE org_id=? AND original_name=?");
    $dup->execute([$org_id, $orig_name]);
    $existing = $dup->fetch();
    if ($existing) {
        $err_msg = "duplicate of package #" . $existing['id']
                 . " uploaded " . date('Y-m-d', strtotime($existing['processed_at']));
        return false;
    }

    // Package into uploads/ as <LOC>-<create-date>-<clean originalName>.tar.gz
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $created       = extract_mets_created($src);
    $original_name = extract_premis_original_name($src);
    $base          = build_stored_basename($loc_code, $created, $original_name, $orig_name);
    $stored        = unique_stored_name(UPLOAD_DIR, $base, '.tar.gz');
    $dest          = rtrim(UPLOAD_DIR, '/') . '/' . $stored;

    $pack_err = '';
    if (!create_tar_gz($src, $base . '.xml', $dest, $pack_err)) {
        $err_msg = "Could not package $orig_name — $pack_err";
        return false;
    }

    if (!($insert instanceof PDOStatement)) {
        $insert = $db->prepare("
            INSERT INTO packages (org_id, filename, original_name, file_size, file_count, notes, processed_at)
            VALUES (:org_id, :filename, :original_name, :file_size, :file_count, :notes, :processed_at)
        ");
    }

    // processed_at reflects when the METS package was created, not when we
    // ingested it. Falls back to ingest time if the header carries no date.
    $processed_at = $created ?? date('Y-m-d H:i:s');
    $file_count   = count_mets_files($src);

    $insert->execute([
        ':org_id'        => $org_id,
        ':filename'      => $stored,
        ':original_name' => $orig_name,
        ':file_size'     => $orig_size,
        ':file_count'    => $file_count,
        ':notes'         => $notes,
        ':processed_at'  => $processed_at,
    ]);
    $package_id = (int)$db->lastInsertId();
    store_xml_metadata($db, $package_id, $src);
    return $package_id;
}

/**
 * Validate XML content from a file path.
 * Returns true/false; sets $err_msg on failure.
 */
function validate_xml(string $path, string &$err_msg): bool {
    libxml_use_internal_errors(true);
    $valid = simplexml_load_file($path) !== false;
    $errs  = libxml_get_errors();
    libxml_clear_errors();
    if (!$valid) $err_msg = $errs[0]->message ?? 'parse error';
    return $valid;
}

/**
 * Determine which organization a file belongs to.
 *
 * Precedence:
 *   1. the first token of the filename  (NMBRIES_cary_newsletters_mets.zip)
 *   2. any later token that matches a known code
 *   3. the containing folder name
 *
 * $orgs maps UPPERCASE loc_code => org_id. $how receives a short note saying
 * which rule matched, so the run log is auditable.
 */
function resolve_loc_code(string $filename, array $orgs, ?string $folder_loc, string &$how): ?string {
    $tokens = preg_split('/[^A-Za-z0-9]+/', basename($filename), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($tokens) {
        $first = strtoupper($tokens[0]);
        if (isset($orgs[$first])) { $how = 'filename prefix'; return $first; }
    }
    foreach (array_slice($tokens, 1) as $tok) {
        $t = strtoupper($tok);
        if (isset($orgs[$t])) { $how = "filename token '$t'"; return $t; }
    }
    if ($folder_loc !== null) {
        $f = strtoupper($folder_loc);
        if (isset($orgs[$f])) { $how = 'folder name'; return $f; }
    }
    $how = 'unresolved';
    return null;
}

/**
 * Extract an archive (.zip, .7z, .tar.gz and friends) to a temp directory and
 * ingest every .xml inside it, recursively. Returns [ok, fail].
 */
function process_archive(PDO $db, int $org_id, string $loc_code, string $archive_path,
                         string $archive_name, string $notes,
                         bool $is_cli = true): array
{
    $tmp_dir = sys_get_temp_dir() . '/da_arch_' . bin2hex(random_bytes(6));
    $err     = '';

    if (!extract_archive($archive_path, $tmp_dir, $err)) {
        if ($is_cli) echo "  ERROR: cannot extract $archive_name — $err\n";
        rrmdir($tmp_dir);
        return [0, 1];
    }

    $xmls = find_xml_files($tmp_dir);
    if (!$xmls) {
        if ($is_cli) echo "  ERROR: $archive_name contains no .xml files\n";
        rrmdir($tmp_dir);
        return [0, 1];
    }

    $ok = 0; $fail = 0;
    $insert = $db->prepare("
        INSERT INTO packages (org_id, filename, original_name, file_size, file_count, notes, processed_at)
        VALUES (:org_id, :filename, :original_name, :file_size, :file_count, :notes, :processed_at)
    ");

    foreach ($xmls as $xml) {
        $base = basename($xml);
        if ($is_cli) echo "  → $base ... ";

        $err_msg = '';
        if (!validate_xml($xml, $err_msg)) {
            if ($is_cli) echo "INVALID ($err_msg)\n";
            $fail++; continue;
        }
        $size   = filesize($xml);
        $pkg_id = process_one_xml($db, $insert, $org_id, $loc_code, $xml, $base, $size,
                                  "$notes [from $archive_name]", $err_msg);
        if ($pkg_id === false) {
            if (str_starts_with($err_msg, 'duplicate')) {
                if ($is_cli) echo "DUPLICATE ($err_msg) — skipped\n";
            } else {
                if ($is_cli) echo "ERROR ($err_msg)\n";
                $fail++;
            }
        } else {
            if ($is_cli) echo "OK (package #$pkg_id, " . round($size / 1048576, 2) . " MB)\n";
            $ok++;
        }
    }

    rrmdir($tmp_dir);
    return [$ok, $fail];
}

/**
 * Ingest one source file, which may be a bare .xml or any supported archive.
 * Returns [ok, fail, disposition] where disposition steers where the source
 * file is filed afterwards.
 */
function ingest_file(PDO $db, array $orgs, string $src, ?string $folder_loc,
                     string $label, ?string $forced_loc = null): array
{
    $file = basename($src);

    if ($forced_loc !== null) {
        $loc = $forced_loc;
        $how = 'command line';
    } else {
        $how = '';
        $loc = resolve_loc_code($file, $orgs, $folder_loc, $how);
    }

    if ($loc === null) {
        echo "[SKIP] $label — no LOC code in filename or folder\n";
        return [0, 0, 'unresolved'];
    }
    $org_id = $orgs[$loc];
    $notes  = 'Processed via CLI (process_uploads.php)';

    if (archive_kind($src) !== null) {
        echo "Archive: $label  [$loc via $how]\n";
        [$a_ok, $a_fail] = process_archive($db, $org_id, $loc, $src, $file, $notes, true);
        return [$a_ok, $a_fail, ($a_ok === 0 && $a_fail > 0) ? 'error' : null];
    }

    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'xml') {
        echo "[SKIP] $label — not an .xml or supported archive\n";
        return [0, 0, 'unsupported'];
    }

    echo "Processing: $label  [$loc via $how] ... ";
    $err = '';
    if (!validate_xml($src, $err)) {
        echo "INVALID ($err)\n";
        return [0, 1, 'error'];
    }
    $size   = filesize($src);
    $pkg_id = process_one_xml($db, false, $org_id, $loc, $src, $file, $size, $notes, $err);
    if ($pkg_id === false) {
        if (str_starts_with($err, 'duplicate')) {
            echo "DUPLICATE ($err)\n";
            return [0, 0, 'duplicate'];
        }
        echo "ERROR ($err)\n";
        return [0, 1, 'error'];
    }
    echo "OK (package #$pkg_id, " . round($size / 1048576, 2) . " MB)\n";
    return [1, 0, null];
}

/** File the source into processed/ rejected/ unresolved/ beside its origin. */
function file_source(string $base_dir, string $src, string $file, ?string $disposition): void {
    $sub = match ($disposition) {
        null, 'duplicate'           => 'processed',
        'unresolved', 'unsupported' => 'unresolved',
        default                     => 'rejected',
    };
    $dir = "$base_dir/$sub";
    @mkdir($dir, 0755, true);
    @rename($src, "$dir/$file");
}

// ── Main ──────────────────────────────────────────────────────────────────────

if ($argc < 2) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php process_uploads.php /path/to/dropbox\n");
    fwrite(STDERR, "  php process_uploads.php /path/to/file.xml [LOC_CODE]\n");
    fwrite(STDERR, "  php process_uploads.php /path/to/archive  [LOC_CODE]\n\n");
    fwrite(STDERR, "Archives: .zip  .7z  .tar  .tar.gz/.tgz  .tar.bz2/.tbz2  .tar.xz/.txz\n");
    fwrite(STDERR, "LOC_CODE is optional when the filename starts with it, e.g.\n");
    fwrite(STDERR, "  NMBRIES_cary_newsletters_mets.zip\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$db     = db();

$orgs = [];
foreach ($db->query("SELECT id, loc_code FROM organizations") as $row) {
    $orgs[strtoupper($row['loc_code'])] = (int)$row['id'];
}
if (!$orgs) {
    fwrite(STDERR, "Error: no organizations are defined in the database.\n");
    exit(1);
}

// ── Single file mode ──────────────────────────────────────────────────────────
if (is_file($target)) {
    $forced = null;
    if ($argc >= 3) {
        $forced = strtoupper($argv[2]);
        if (!isset($orgs[$forced])) {
            fwrite(STDERR, "Error: unknown LOC code '$forced'.\n");
            exit(1);
        }
    }

    [$ok, $fail, $disp] = ingest_file($db, $orgs, $target, null, basename($target), $forced);
    echo "\nDone. $ok processed, $fail failed.\n";
    exit($fail > 0 ? 1 : 0);
}

// ── Dropbox folder mode ───────────────────────────────────────────────────────
$dropbox = $target;
if (!is_dir($dropbox)) {
    fwrite(STDERR, "Error: '$dropbox' is not a file or directory\n");
    exit(1);
}

$ok = 0; $fail = 0; $unresolved = 0;

$entries = array_diff(scandir($dropbox), ['.', '..']);
sort($entries);

// Loose files sitting directly in the dropbox — the filename supplies the code.
foreach ($entries as $entry) {
    $src = "$dropbox/$entry";
    if (!is_file($src)) continue;

    [$o, $f, $disp] = ingest_file($db, $orgs, $src, null, $entry);
    $ok += $o; $fail += $f;
    if ($disp === 'unresolved' || $disp === 'unsupported') $unresolved++;
    file_source($dropbox, $src, $entry, $disp);
}

// Subfolders. A folder named after a LOC code still works as before, and now
// acts only as a fallback when the filename does not name its own.
foreach ($entries as $entry) {
    $dir = "$dropbox/$entry";
    if (!is_dir($dir)) continue;
    if (in_array(strtolower($entry), INGEST_RESERVED_DIRS, true)) continue;

    $folder_loc = isset($orgs[strtoupper($entry)]) ? strtoupper($entry) : null;
    if ($folder_loc === null) {
        echo "[NOTE] Folder '$entry' is not a LOC code — files inside must name their own.\n";
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    sort($files);
    foreach ($files as $file) {
        $src = "$dir/$file";
        if (!is_file($src)) continue;

        [$o, $f, $disp] = ingest_file($db, $orgs, $src, $folder_loc, "$entry/$file");
        $ok += $o; $fail += $f;
        if ($disp === 'unresolved' || $disp === 'unsupported') $unresolved++;
        file_source($dir, $src, $file, $disp);
    }
}

echo "\nDone. $ok package(s) ingested, $fail failed";
echo $unresolved > 0 ? ", $unresolved unresolved (see unresolved/).\n" : ".\n";

// ── Cleanup expired/used password reset tokens ────────────────────────────────
$deleted = db()->exec("DELETE FROM password_resets WHERE used=1 OR expires_at < NOW()");
if ($deleted > 0) echo "Cleaned up $deleted expired password reset token(s).\n";
