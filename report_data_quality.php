#!/usr/bin/env php
<?php
/**
 * Cron-runnable data quality report for Dark Archive.
 *
 * Checks the packages table for:
 *   1. Packages missing a recorded stored size (size_gb) and/or a Glacier
 *      storage location (glacier_location) — both are entered by hand via
 *      view.php's Edit Package form and have no automatic source.
 *   2. Packages that share the exact same glacier_location — almost always
 *      a copy/paste mistake when filling in that form, silently pointing two
 *      different archives at one Glacier location.
 *
 * Emails a plaintext report to MAIL_NOC (see includes/mailer.php / .env)
 * ONLY when there is something to report — a clean run sends no mail, so the
 * inbox stays a signal of "something needs attention" rather than a daily
 * repeat of an already-known backlog. See --always to change that.
 *
 * Usage:
 *   php report_data_quality.php            # email only if issues are found
 *   php report_data_quality.php --always   # also email a clean "all OK" report
 *   php report_data_quality.php --dry-run  # print the report, send no mail
 *
 * Exit code reflects whether the script itself ran successfully (DB reachable,
 * mail() accepted for delivery), NOT whether issues were found — finding
 * issues is this script's normal, expected job, not a failure of the script.
 * A cron-monitoring tool (healthchecks.io, etc.) can key off this exit code
 * without producing false alarms every time the report has content.
 *
 * Suggested crontab (Monday mornings, 7am):
 *   0 7 * * 1 /usr/bin/php /var/www/darkarchive/report_data_quality.php >> /var/log/darkarchive_data_quality.log 2>&1
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

$dry_run = in_array('--dry-run', $argv, true);
$always  = in_array('--always', $argv, true);

try {
    $db = db();
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: could not connect to the database — " . $e->getMessage() . "\n");
    exit(1);
}

// ── 1. Packages missing size and/or storage location ──────────────────────────
$missing = $db->query("
    SELECT p.id, p.original_name, p.processed_at, p.size_gb, p.glacier_location,
           o.name AS org_name, o.loc_code
    FROM packages p
    JOIN organizations o ON p.org_id = o.id
    WHERE p.size_gb IS NULL
       OR p.glacier_location IS NULL
       OR p.glacier_location = ''
    ORDER BY o.loc_code, p.processed_at DESC
")->fetchAll();

// ── 2. Packages sharing an identical, non-empty storage location ──────────────
$dupe_rows = $db->query("
    SELECT p.id, p.original_name, p.glacier_location, o.name AS org_name, o.loc_code
    FROM packages p
    JOIN organizations o ON p.org_id = o.id
    WHERE p.glacier_location IN (
        SELECT glacier_location FROM packages
        WHERE glacier_location IS NOT NULL AND glacier_location <> ''
        GROUP BY glacier_location
        HAVING COUNT(*) > 1
    )
    ORDER BY p.glacier_location, p.id
")->fetchAll();

$duplicates = [];
foreach ($dupe_rows as $r) {
    $duplicates[$r['glacier_location']][] = $r;
}

$issue_count = count($missing) + count($duplicates);

if ($issue_count === 0 && !$always) {
    echo "OK — no missing size/location, no duplicate storage locations. Nothing sent.\n";
    exit(0);
}

// ── Build the plaintext report ─────────────────────────────────────────────────
$lines   = [];
$lines[] = SITE_NAME . ' — Data Quality Report';
$lines[] = 'Generated ' . date('Y-m-d H:i:s T');
$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($missing) {
    $lines[] = count($missing) . ' package(s) missing recorded size and/or storage location:';
    $lines[] = str_repeat('-', 60);
    foreach ($missing as $r) {
        $what = [];
        if ($r['size_gb'] === null) $what[] = 'SIZE';
        if ($r['glacier_location'] === null || $r['glacier_location'] === '') $what[] = 'LOCATION';

        $lines[] = sprintf('[%s] #%d %s', implode('+', $what), $r['id'], $r['original_name']);
        $lines[] = sprintf('    Org: %s (%s)   Processed: %s',
            $r['org_name'], $r['loc_code'], date('Y-m-d', strtotime($r['processed_at'])));
        $lines[] = sprintf('    %s/view.php?id=%d', rtrim(SITE_URL, '/'), $r['id']);
        $lines[] = '';
    }
} else {
    $lines[] = 'No packages are missing a recorded size or storage location.';
    $lines[] = '';
}

$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($duplicates) {
    $lines[] = count($duplicates) . ' storage location(s) used by more than one package:';
    $lines[] = str_repeat('-', 60);
    foreach ($duplicates as $location => $pkgs) {
        $lines[] = sprintf('Location: %s  (%d packages)', $location, count($pkgs));
        foreach ($pkgs as $r) {
            $lines[] = sprintf('    #%d %s — %s (%s)', $r['id'], $r['original_name'], $r['org_name'], $r['loc_code']);
            $lines[] = sprintf('        %s/view.php?id=%d', rtrim(SITE_URL, '/'), $r['id']);
        }
        $lines[] = '';
    }
} else {
    $lines[] = 'No duplicate storage locations found.';
    $lines[] = '';
}

$lines[] = str_repeat('=', 60);
$lines[] = 'This is an automated report from ' . SITE_NAME . '.';
$lines[] = 'Fix a package by editing it at ' . rtrim(SITE_URL, '/') . '/view.php';

$body = implode("\r\n", $lines);

echo $body . "\n\n";

if ($dry_run) {
    echo "--dry-run: not sending mail.\n";
    exit(0);
}

$subject = $issue_count > 0
    ? sprintf('%s — Data Quality Report: %d issue(s) found', SITE_NAME, $issue_count)
    : sprintf('%s — Data Quality Report: all clear', SITE_NAME);

if (!send_mail(MAIL_NOC, $subject, $body)) {
    fwrite(STDERR, "ERROR: mail() returned false — check the local MTA (Postfix) is running and accepting mail.\n");
    exit(1);
}

echo "Report emailed to " . MAIL_NOC . ".\n";
exit(0);
