#!/usr/bin/env php
<?php
/**
 * One-time backfill for packages ingested before file_count existed.
 *
 * Reads each already-stored package (.tar.gz, or legacy .xml.gz/.xml) and
 * counts the files it was built from (count_mets_files() — the METS
 * USE="original" group), then UPDATEs packages.file_count. Does NOT touch
 * file_metadata, re-validate XML, re-check duplicates, or move any files —
 * read the stored package, write one column. Safe to re-run; by default it
 * only processes rows where file_count IS NULL.
 *
 * Usage:
 *   php backfill_file_counts.php              # fill in NULL counts only
 *   php backfill_file_counts.php --recount    # recount EVERY package and
 *                                             # fix any whose stored count
 *                                             # differs (needed once, after
 *                                             # count_mets_files() stopped
 *                                             # counting preservation copies
 *                                             # — those rows are inflated)
 *   php backfill_file_counts.php --recount --dry-run   # show changes, write none
 *
 * DELETE THIS FILE once every package shows a file_count on the Dashboard.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/xml_metadata.php';

$recount = in_array('--recount', $argv, true);
$dry_run = in_array('--dry-run', $argv, true);

$db = db();

$rows = $db->query("
    SELECT id, filename, original_name, file_count
    FROM packages
    " . ($recount ? '' : 'WHERE file_count IS NULL') . "
    ORDER BY id
")->fetchAll();

$total = count($rows);
if ($total === 0) {
    echo "Nothing to do — every package already has a file_count.\n";
    exit(0);
}
echo ($recount ? "Recounting" : "Backfilling") . " file_count for $total package(s)"
   . ($dry_run ? " (dry run — nothing will be written)" : "") . "...\n";

$upd = $db->prepare("UPDATE packages SET file_count = ? WHERE id = ?");
$ok = 0; $fail = 0; $changed = 0;

foreach ($rows as $i => $r) {
    $n = $i + 1;
    $path = rtrim(UPLOAD_DIR, '/') . '/' . $r['filename'];

    if (!file_exists($path)) {
        echo "[$n/$total] #{$r['id']} {$r['original_name']} — MISSING on disk ({$r['filename']}), skipped\n";
        $fail++;
        continue;
    }

    $count = count_mets_files($path);
    if ($count === null) {
        echo "[$n/$total] #{$r['id']} {$r['original_name']} — could not read package, skipped\n";
        $fail++;
        continue;
    }

    $old = $r['file_count'];
    if ($recount && $old !== null && (int)$old === $count) {
        $ok++;   // already correct — nothing to write, nothing to print
        continue;
    }

    if (!$dry_run) $upd->execute([$count, $r['id']]);
    $changed++;
    echo "[$n/$total] #{$r['id']} {$r['original_name']} — "
       . ($old !== null ? "$old -> " : "") . "$count file(s)\n";
    $ok++;
}

echo "\nDone. $ok checked, $changed " . ($dry_run ? "would change" : "changed") . ", $fail skipped.\n";
exit($fail > 0 ? 1 : 0);
