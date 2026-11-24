#!/usr/bin/env php
<?php
/**
 * Cron-runnable reconciliation between the packages table and the S3 bucket
 * holding the actual archived assets.
 *
 * darkarchive itself only stores the descriptive METS XML (as a .tar.gz in
 * uploads/) — the real archived content (images, audio, etc.) lives in S3,
 * under keys shaped like:
 *   digital-archive-senylrc/digital-archive-senylrc-<loc>/<YYYY>/<MM>/<name>-<uuid>.<ext>
 *
 * A package is matched to an S3 object two ways:
 *   1. Authoritative: if packages.glacier_location holds an "s3://bucket/key"
 *      URI, that EXACT key is checked for existence.
 *   2. Best-effort fallback: for packages with no usable recorded location,
 *      the package's original_name and the S3 key's filename are both run
 *      through the same cleanup used for local package naming (strips the
 *      LOC prefix, Archivematica UUIDs, dates, "mets" boilerplate) and
 *      compared as an identity string. This can miss real matches if the
 *      two sides were named too differently to clean down to the same
 *      string — it is a "probably missing" signal, not a certainty, and the
 *      report says so explicitly.
 *
 * Reports, in one email:
 *   - packages with a RECORDED s3:// location that doesn't exist (confirmed
 *     404 — distinct from a check that simply couldn't be completed, e.g. a
 *     403 because the key's prefix isn't covered by this app's IAM policy;
 *     those are reported separately, never counted as "missing")
 *   - packages with NO recorded location and no identity match either (probable)
 *   - packages with NO recorded location but a LIKELY match found in S3
 *     (glacier_location should be filled in — an easy, safe fix)
 *   - packages whose recorded location points at a different bucket entirely
 *   - S3 objects (matching the archive key convention) with no corresponding
 *     packages row at all
 *   - S3 keys under the prefix that don't match the convention, for visibility
 *
 * Usage:
 *   php report_s3_reconciliation.php            # email only if issues are found
 *   php report_s3_reconciliation.php --always    # also email a clean "all OK" report
 *   php report_s3_reconciliation.php --dry-run   # print the report, send no mail
 *
 * Exit code reflects whether the script itself ran successfully (database
 * and S3 both reachable, mail accepted for delivery) — NOT whether
 * discrepancies were found; finding them is this script's normal job.
 *
 * Suggested crontab (first of the month, 6am — a full bucket listing is
 * heavier than the weekly data-quality report, so this runs less often):
 *   0 6 1 * * /usr/bin/php /var/www/darkarchive/report_s3_reconciliation.php >> /var/log/darkarchive_s3_reconciliation.log 2>&1
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/xml_metadata.php';
require_once __DIR__ . '/includes/s3_reconcile.php';

const S3_KEY_PREFIX = 'digital-archive-senylrc/';

$dry_run = in_array('--dry-run', $argv, true);
$always  = in_array('--always', $argv, true);

if (S3_BUCKET === '') {
    fwrite(STDERR, "ERROR: S3_BUCKET is not set in .env — nothing to reconcile against.\n");
    exit(1);
}
if (AWS_ACCESS_KEY_ID === '' || AWS_SECRET_ACCESS_KEY === '') {
    fwrite(STDERR, "ERROR: AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY are not set in .env.\n");
    exit(1);
}
if (!class_exists(\Aws\S3\S3Client::class)) {
    fwrite(STDERR, "ERROR: aws/aws-sdk-php is not installed — run: composer require aws/aws-sdk-php\n");
    exit(1);
}

try {
    $db = db();
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: could not connect to the database — " . $e->getMessage() . "\n");
    exit(1);
}

$s3 = new \Aws\S3\S3Client([
    'version'     => 'latest',
    'region'      => AWS_REGION,
    'credentials' => [
        'key'    => AWS_ACCESS_KEY_ID,
        'secret' => AWS_SECRET_ACCESS_KEY,
    ],
]);

$packages = $db->query("
    SELECT p.id, p.original_name, p.glacier_location, p.processed_at,
           o.name AS org_name, o.loc_code
    FROM packages p
    JOIN organizations o ON p.org_id = o.id
    ORDER BY o.loc_code, p.processed_at DESC
")->fetchAll();

try {
    $all_keys = s3_list_all_keys($s3, S3_BUCKET, S3_KEY_PREFIX);
} catch (\Aws\Exception\AwsException $e) {
    fwrite(STDERR, "ERROR: could not list s3://" . S3_BUCKET . "/" . S3_KEY_PREFIX . " — "
                 . $e->getAwsErrorMessage() . "\n");
    exit(1);
}

$exists_checker = function (string $key) use ($s3): ?bool {
    return s3_object_exists($s3, S3_BUCKET, $key);
};

$r = reconcile_s3($packages, S3_BUCKET, $exists_checker, $all_keys);

$issue_count = count($r['db_missing_confirmed']) + count($r['db_unverified']) + count($r['db_missing_probable'])
             + count($r['wrong_bucket']) + count($r['s3_orphans']);
// db_found_unrecorded and unrecognized_keys are informational, not counted as
// "issues" for the subject line / silent-when-clean decision — the first is
// good news (an easy metadata fix), the second may just be unrelated bucket
// content outside this app's naming convention. db_unverified IS counted:
// it means the reconciliation is incomplete (usually an IAM policy gap),
// which needs a human decision even though it's not proof of data loss.

if ($issue_count === 0 && !$always) {
    echo "OK — every package with a recorded location resolves in S3, no probable "
       . "mismatches, and no orphaned objects. Nothing sent.\n";
    if ($r['db_found_unrecorded']) {
        echo count($r['db_found_unrecorded']) . " package(s) have no recorded location "
           . "but a likely match was found (run with --dry-run to see details).\n";
    }
    if ($r['unrecognized_keys']) {
        echo count($r['unrecognized_keys']) . " S3 key(s) under the prefix didn't match "
           . "the naming convention and were skipped (run with --dry-run to see details).\n";
    }
    exit(0);
}

function view_url(int $id): string {
    return rtrim(SITE_URL, '/') . '/view.php?id=' . $id;
}

$lines   = [];
$lines[] = SITE_NAME . ' — S3 Reconciliation Report';
$lines[] = 'Bucket: s3://' . S3_BUCKET . '/' . S3_KEY_PREFIX;
$lines[] = 'Generated ' . date('Y-m-d H:i:s T');
$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($r['db_missing_confirmed']) {
    $lines[] = count($r['db_missing_confirmed']) . ' package(s) have a recorded S3 location that does NOT exist:';
    $lines[] = str_repeat('-', 60);
    foreach ($r['db_missing_confirmed'] as $p) {
        $lines[] = sprintf('#%d %s — %s (%s)', $p['id'], $p['original_name'], $p['org_name'], $p['loc_code']);
        $lines[] = '    Expected: s3://' . S3_BUCKET . '/' . $p['expected_key'];
        $lines[] = '    ' . view_url((int)$p['id']);
        $lines[] = '';
    }
} else {
    $lines[] = 'No package with a recorded S3 location is missing that object.';
    $lines[] = '';
}
$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($r['db_unverified']) {
    $lines[] = count($r['db_unverified']) . ' package(s) have a recorded S3 location that could NOT be verified (access error, not confirmed missing — likely an IAM policy gap for this key\'s prefix):';
    $lines[] = str_repeat('-', 60);
    foreach ($r['db_unverified'] as $p) {
        $lines[] = sprintf('#%d %s — %s (%s)', $p['id'], $p['original_name'], $p['org_name'], $p['loc_code']);
        $lines[] = '    Could not check: s3://' . S3_BUCKET . '/' . $p['expected_key'];
        $lines[] = '    ' . view_url((int)$p['id']);
        $lines[] = '';
    }
    $lines[] = str_repeat('=', 60);
    $lines[] = '';
}

if ($r['db_missing_probable']) {
    $lines[] = count($r['db_missing_probable']) . ' package(s) have NO recorded location, and no matching object was found in S3 (probable — filename matching is best-effort):';
    $lines[] = str_repeat('-', 60);
    foreach ($r['db_missing_probable'] as $p) {
        $lines[] = sprintf('#%d %s — %s (%s)   Processed: %s',
            $p['id'], $p['original_name'], $p['org_name'], $p['loc_code'],
            date('Y-m-d', strtotime($p['processed_at'])));
        $lines[] = '    ' . view_url((int)$p['id']);
        $lines[] = '';
    }
} else {
    $lines[] = 'No packages are probably missing from S3.';
    $lines[] = '';
}
$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($r['wrong_bucket']) {
    $lines[] = count($r['wrong_bucket']) . ' package(s) have a recorded location pointing at a DIFFERENT bucket than ' . S3_BUCKET . ' (not checked):';
    $lines[] = str_repeat('-', 60);
    foreach ($r['wrong_bucket'] as $p) {
        $lines[] = sprintf('#%d %s — %s (%s)', $p['id'], $p['original_name'], $p['org_name'], $p['loc_code']);
        $lines[] = '    Recorded bucket: ' . $p['recorded_bucket'];
        $lines[] = '    ' . view_url((int)$p['id']);
        $lines[] = '';
    }
    $lines[] = str_repeat('=', 60);
    $lines[] = '';
}

if ($r['s3_orphans']) {
    $lines[] = count($r['s3_orphans']) . ' object(s) exist in S3 with no corresponding package in the database:';
    $lines[] = str_repeat('-', 60);
    foreach ($r['s3_orphans'] as $o) {
        $lines[] = $o['identity'] . ':';
        foreach ($o['keys'] as $key) {
            $lines[] = '    s3://' . S3_BUCKET . '/' . $key;
        }
        $lines[] = '';
    }
} else {
    $lines[] = 'No S3 objects are orphaned (untracked by any package).';
    $lines[] = '';
}
$lines[] = str_repeat('=', 60);
$lines[] = '';

if ($r['db_found_unrecorded']) {
    $lines[] = count($r['db_found_unrecorded']) . ' package(s) have no recorded location, but a LIKELY match was found in S3 — filling in glacier_location would resolve these:';
    $lines[] = str_repeat('-', 60);
    foreach ($r['db_found_unrecorded'] as $p) {
        $lines[] = sprintf('#%d %s — %s (%s)', $p['id'], $p['original_name'], $p['org_name'], $p['loc_code']);
        foreach ($p['found_keys'] as $key) {
            $lines[] = '    s3://' . S3_BUCKET . '/' . $key;
        }
        $lines[] = '    ' . view_url((int)$p['id']);
        $lines[] = '';
    }
    $lines[] = str_repeat('=', 60);
    $lines[] = '';
}

if ($r['unrecognized_keys']) {
    $lines[] = count($r['unrecognized_keys']) . ' S3 key(s) under the prefix did not match the expected naming convention (not checked against any package):';
    $lines[] = str_repeat('-', 60);
    foreach ($r['unrecognized_keys'] as $key) {
        $lines[] = '    s3://' . S3_BUCKET . '/' . $key;
    }
    $lines[] = '';
    $lines[] = str_repeat('=', 60);
    $lines[] = '';
}

$lines[] = 'This is an automated report from ' . SITE_NAME . '.';
$lines[] = 'Fix a package by editing it at ' . rtrim(SITE_URL, '/') . '/view.php';

$body = implode("\r\n", $lines);
echo $body . "\n\n";

if ($dry_run) {
    echo "--dry-run: not sending mail.\n";
    exit(0);
}

$subject = $issue_count > 0
    ? sprintf('%s — S3 Reconciliation: %d issue(s) found', SITE_NAME, $issue_count)
    : sprintf('%s — S3 Reconciliation: all clear', SITE_NAME);

if (!send_mail(MAIL_NOC, $subject, $body)) {
    fwrite(STDERR, "ERROR: mail() returned false — check the local MTA (Postfix) is running and accepting mail.\n");
    exit(1);
}

echo "Report emailed to " . MAIL_NOC . ".\n";
exit(0);
