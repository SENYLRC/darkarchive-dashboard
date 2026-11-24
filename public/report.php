<?php
/**
 * Reports page: filterable package/file statistics by organization and
 * Library Council, with CSV export (?export=...).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xml_metadata.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$db = db();
if (is_admin()) {
    $orgs = $db->query("SELECT id, name, loc_code FROM organizations ORDER BY name")->fetchAll();
} else {
    $ids = allowed_orgs() ?: [0];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $s   = $db->prepare("SELECT id, name, loc_code FROM organizations WHERE id IN ($ph) ORDER BY name");
    $s->execute($ids);
    $orgs = $s->fetchAll();
}
$councils = $db->query("SELECT id, name, loc_code FROM library_councils ORDER BY name")->fetchAll();

$org_id     = (int)($_GET['org']     ?? 0);
$council_id = (int)($_GET['council'] ?? 0);
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';
$group_by  = in_array($_GET['group'] ?? '', ['org', 'date', 'none']) ? $_GET['group'] : 'org';
$export    = $_GET['export'] ?? '';
$report_type = ($_GET['type'] ?? 'packages') === 'files' ? 'files' : 'packages';

$where  = ['1=1'];
$params = [];

// Restrict to the caller's assigned orgs — without this, any readonly user
// could see or CSV-export every organization's packages just by loading this
// page with no org filter (or by passing a different org's id directly).
if (!is_admin()) {
    $allowed = allowed_orgs() ?: [0];
    $keys = [];
    foreach ($allowed as $i => $aid) {
        $key = ":aorg$i";
        $keys[] = $key;
        $params[$key] = $aid;
    }
    $where[] = 'p.org_id IN (' . implode(',', $keys) . ')';
}

if ($org_id) {
    $where[]  = "p.org_id = :org_id";
    $params[':org_id'] = $org_id;
}
if ($council_id) {
    $where[]  = "o.council_id = :council_id";
    $params[':council_id'] = $council_id;
}
if ($date_from) {
    $where[]  = "DATE(p.processed_at) >= :date_from";
    $params[':date_from'] = $date_from;
}
if ($date_to) {
    $where[]  = "DATE(p.processed_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_sql = implode(' AND ', $where);

// Defense in depth: the file-list query below joins file_metadata per
// package and, before an index existed for it, ran away for 3+ days on a
// large archive, holding a lock that blocked the entire site (2026-09-03
// incident) — see deploy/schema.sql's idx_package_element_id migration for
// the actual fix. This is a second, independent safety net: even a query
// that becomes slow again for some other reason (a future feature, a
// missing index after a schema change) can't hold a lock for more than 30s.
// MySQL-specific (5.7.8+/8.0) — RDS MySQL, confirmed by DB_HOST. Failure to
// set this is swallowed rather than fatal, since it's a safety net, not
// core functionality: a report should still work even if this one
// statement is rejected.
try {
    $db->exec('SET SESSION MAX_EXECUTION_TIME = 30000');
} catch (PDOException $e) {
    error_log('report.php: could not set MAX_EXECUTION_TIME — ' . $e->getMessage());
}

// Hard caps: fetch one row past the cap to detect truncation without a
// separate COUNT(*) query. A capped result still fully reflects the totals
// shown above it (those are computed from the same $rows/$file_rows), so a
// truncated report's own summary numbers are for the truncated set, not the
// true total — the banner below says so explicitly.
const REPORT_PACKAGE_CAP = 20000;
const REPORT_FILE_CAP    = 50000;

$stmt = $db->prepare("
    SELECT p.id, p.filename, p.original_name, p.file_size, p.size_gb, p.file_count, p.processed_at, p.notes,
           o.name AS org_name, o.loc_code
    FROM packages p JOIN organizations o ON p.org_id = o.id
    WHERE $where_sql
    ORDER BY o.loc_code, p.processed_at DESC
    LIMIT " . (REPORT_PACKAGE_CAP + 1) . "
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$rows_truncated = count($rows) > REPORT_PACKAGE_CAP;
if ($rows_truncated) $rows = array_slice($rows, 0, REPORT_PACKAGE_CAP);

// File-level rows: one per original file in each matching package, taken
// from the package's METS fileSec (cached_mets_original_files() — the same
// list the View page shows, and the same set packages.file_count counts).
//
// This used to list only ExifTool System:FileName entries from file_metadata,
// which silently left out every file ExifTool hadn't run on (e.g. 122 of a
// SUNY Morrisville package's 123 files, all PDFs). That query is kept as a
// per-package fallback for packages whose METS has no USE="original" group.
//
// Packages are walked in the same order as $rows (LOC code, newest first).
// Uncached packages are parsed from disk on first use, so a report over the
// whole archive with a cold cache could outrun PHP's time limit; the walk
// stops at REPORT_FILE_SECONDS and says so. Everything parsed so far is
// cached, so re-running continues further — backfill_file_lists.php warms
// the whole cache up front.
const REPORT_FILE_SECONDS = 20;

$file_rows = [];
$file_rows_truncated = false;
$file_rows_timed_out = false;
if ($report_type === 'files') {
    $fallback = $db->prepare("
        SELECT fn.value AS file_name,
               (SELECT value FROM file_metadata fs
                  WHERE fs.package_id = fn.package_id AND fs.element = 'System:FileSize'
                    AND fs.id > fn.id
                  ORDER BY fs.id LIMIT 1) AS file_size
        FROM file_metadata fn
        WHERE fn.package_id = ? AND fn.element = 'System:FileName'
        ORDER BY fn.id
    ");
    $deadline = microtime(true) + REPORT_FILE_SECONDS;

    foreach ($rows as $r) {
        if (count($file_rows) > REPORT_FILE_CAP) break;
        if (microtime(true) > $deadline) { $file_rows_timed_out = true; break; }

        $pkg = [
            'package_id'   => $r['id'],
            'package_name' => $r['original_name'],
            'processed_at' => $r['processed_at'],
            'org_name'     => $r['org_name'],
            'loc_code'     => $r['loc_code'],
        ];

        $list = cached_mets_original_files(UPLOAD_DIR . $r['filename']);
        if ($list === null) {
            $fallback->execute([$r['id']]);
            foreach ($fallback->fetchAll() as $f) {
                $file_rows[] = $pkg + ['file_name' => $f['file_name'], 'file_size' => $f['file_size'],
                                       'size_bytes' => null, 'format' => null, 'puid' => null,
                                       'mime' => null, 'checksum' => null];
            }
            continue;
        }
        foreach ($list as $f) {
            $file_rows[] = $pkg + [
                'file_name'  => $f['name'],
                'file_size'  => is_int($f['size']) ? format_bytes($f['size']) : null,
                'size_bytes' => $f['size'],
                'format'     => $f['format'],
                'puid'       => $f['puid'],
                'mime'       => $f['mime'],
                'checksum'   => $f['checksum'],
            ];
        }
    }
    $file_rows_truncated = count($file_rows) > REPORT_FILE_CAP;
    if ($file_rows_truncated) $file_rows = array_slice($file_rows, 0, REPORT_FILE_CAP);
}

function format_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
    return round($b/1073741824, 1) . ' GB';
}

/**
 * Shorten the report table's Notes display for auto-ingested packages.
 *
 * process_uploads.php writes an identical "Processed via CLI
 * (process_uploads.php) [from <archive>]" prefix on every package it
 * ingests — in a 200px report column that boilerplate (not the archive name,
 * the only actually-variable part) is what forces a 3-line wrap on nearly
 * every row. This only changes what's displayed here; the full text is
 * unchanged in the database, the CSV export, and view.php's edit form.
 */
function display_notes(string $notes): string {
    if (!preg_match('/^Processed via CLI \(process_uploads\.php\)(?:\s*\[from\s+(.+?)\])?\s*(.*)$/s', $notes, $m)) {
        return $notes;
    }
    $archive = trim($m[1] ?? '');
    $rest    = trim($m[2] ?? '');
    $short   = $archive !== '' ? "From: $archive" : 'Processed via CLI';
    return $rest !== '' ? "$short — $rest" : $short;
}

/** Size/file-count totals for one set of package rows, used at every nesting level. */
function report_totals(array $rows): array {
    $with_size    = array_filter($rows, fn($r) => $r['size_gb'] !== null);
    $with_count   = array_filter($rows, fn($r) => $r['file_count'] !== null);
    return [
        'total_gb'      => array_sum(array_column($with_size, 'size_gb')),
        'missing_size'  => count($rows) - count($with_size),
        'total_files'   => array_sum(array_column($with_count, 'file_count')),
        'missing_count' => count($rows) - count($with_count),
    ];
}

/** The "(N missing size, N missing file count)" note shown next to a group's totals. */
function report_missing_note(int $missing_size, int $missing_count): string {
    $parts = [];
    if ($missing_size > 0)  $parts[] = "$missing_size missing size";
    if ($missing_count > 0) $parts[] = "$missing_count missing file count";
    return implode(', ', $parts);
}

/** [year => [month => rows]] -> flat rows, newest-first order preserved. */
function flatten_two_level(array $year_month): array {
    $out = [];
    foreach ($year_month as $months) {
        foreach ($months as $rows) {
            foreach ($rows as $r) $out[] = $r;
        }
    }
    return $out;
}

/** [month => rows] -> flat rows, newest-first order preserved. */
function flatten_one_level(array $month_rows): array {
    $out = [];
    foreach ($month_rows as $rows) {
        foreach ($rows as $r) $out[] = $r;
    }
    return $out;
}

const REPORT_MONTH_NAMES = [
    1=>'January', 2=>'February', 3=>'March',     4=>'April',   5=>'May',      6=>'June',
    7=>'July',    8=>'August',   9=>'September', 10=>'October',11=>'November',12=>'December',
];

/** Renders the leaf-level package table shared by every grouping mode and nesting depth. */
function render_report_table(array $group_rows, bool $show_org): void {
    ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Filename</th>
                <?php if ($show_org): ?><th>Organization</th><?php endif; ?>
                <th>Stored Size</th>
                <th>Files</th>
                <th>Processed Date</th>
                <th>Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($group_rows as $r): ?>
            <tr>
                <td style="color:var(--muted);font-size:12px"><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['original_name']) ?></td>
                <?php if ($show_org): ?>
                    <td><span class="badge badge-org"><?= htmlspecialchars($r['loc_code']) ?></span></td>
                <?php endif; ?>
                <td style="color:var(--muted);font-size:13px"><?= $r['size_gb'] !== null ? number_format((float)$r['size_gb'], 3) . ' GB' : '— not recorded' ?></td>
                <td style="color:var(--muted);font-size:13px"><?= $r['file_count'] !== null ? number_format((int)$r['file_count']) : '—' ?></td>
                <td style="color:var(--muted);font-size:13px;white-space:nowrap"><?= date('Y-m-d', strtotime($r['processed_at'])) ?></td>
                <td class="notes-cell"><?= htmlspecialchars(display_notes($r['notes'] ?? '')) ?></td>
                <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-ghost">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv');
    $was_truncated = $report_type === 'files' ? ($file_rows_truncated || $file_rows_timed_out) : $rows_truncated;
    $fname = 'darkarchive_report_' . ($was_truncated ? 'TRUNCATED_' : '') . date('Ymd_His') . '.csv';
    header("Content-Disposition: attachment; filename=\"$fname\"");
    $out = fopen('php://output', 'w');

    if ($report_type === 'files') {
        // Columns after "Date Processed" were added with the METS-based list;
        // the original six keep their order so existing imports still line up.
        fputcsv($out, ['File Name', 'File Size', 'Package', 'LOC Code', 'Organization', 'Date Processed',
                       'Size (bytes)', 'Format', 'PRONOM ID', 'MIME Type', 'Checksum']);
        foreach ($file_rows as $r) {
            fputcsv($out, csv_safe_row([
                $r['file_name'],
                $r['file_size'],
                $r['package_name'],
                $r['loc_code'],
                $r['org_name'],
                date('Y-m-d H:i:s', strtotime($r['processed_at'])),
                $r['size_bytes'],
                $r['format'],
                $r['puid'],
                $r['mime'],
                $r['checksum'],
            ]));
        }
    } else {
        fputcsv($out, ['ID', 'Filename', 'LOC Code', 'Organization', 'METS File Size', 'Stored Size (GB)', 'Files', 'Processed Date', 'Notes']);
        foreach ($rows as $r) {
            fputcsv($out, csv_safe_row([
                $r['id'],
                $r['original_name'],
                $r['loc_code'],
                $r['org_name'],
                format_bytes($r['file_size']),
                $r['size_gb'] !== null ? number_format((float)$r['size_gb'], 3) : '',
                $r['file_count'] !== null ? $r['file_count'] : '',
                date('Y-m-d H:i:s', strtotime($r['processed_at'])),
                $r['notes'],
            ]));
        }
        $with_size  = array_filter($rows, fn($r) => $r['size_gb'] !== null);
        $missing    = count($rows) - count($with_size);
        $with_count = array_filter($rows, fn($r) => $r['file_count'] !== null);
        $missing_ct = count($rows) - count($with_count);
        fputcsv($out, []);
        fputcsv($out, [
            '', '', '', 'TOTAL (billable size)',
            '', number_format(array_sum(array_column($with_size, 'size_gb')), 3) . ' GB',
            array_sum(array_column($with_count, 'file_count')),
            '',
            trim(
                ($missing > 0 ? "$missing package(s) missing a recorded size" : '')
                . ($missing > 0 && $missing_ct > 0 ? '; ' : '')
                . ($missing_ct > 0 ? "$missing_ct package(s) missing a file count" : '')
            ),
        ]);
    }
    fclose($out);
    exit;
}

// Group rows for display. Organization mode nests two levels deeper — year,
// then month — each independently collapsible; date/none stay a flat single
// level, since a specific day (or "no grouping") has no useful year/month
// breakdown of its own.
// Rows already arrive ordered by loc_code, then processed_at DESC (the SQL
// query above), so year and month sub-keys are encountered newest-first and
// need no separate sort.
$nested  = ($group_by === 'org');
$grouped = [];
if ($nested) {
    foreach ($rows as $r) {
        $org_key = $r['loc_code'] . ' — ' . $r['org_name'];
        $year    = date('Y', strtotime($r['processed_at']));
        $month   = date('n', strtotime($r['processed_at'])); // 1-12, formatted for display below
        $grouped[$org_key][$year][$month][] = $r;
    }
} elseif ($group_by === 'date') {
    foreach ($rows as $r) {
        $key = date('F j, Y', strtotime($r['processed_at']));
        $grouped[$key][] = $r;
    }
} else {
    $grouped['All Packages'] = $rows;
}

page_header('Reports', 'report.php');
?>

<div class="card">
    <div style="margin-bottom:16px;">
        <a href="?<?= http_build_query(array_merge($_GET, ['type'=>'packages'])) ?>" class="btn btn-sm <?= $report_type==='packages'?'btn-primary':'btn-ghost' ?>">Package Report</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['type'=>'files'])) ?>" class="btn btn-sm <?= $report_type==='files'?'btn-primary':'btn-ghost' ?>">File List (from XML metadata)</a>
    </div>
    <form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end;">
        <input type="hidden" name="type" value="<?= $report_type ?>">
        <div class="form-group" style="margin:0">
            <label for="org">Organization</label>
            <select name="org" id="org">
                <option value="">All organizations</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $org_id == $o['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label for="council">Library Council</label>
            <select name="council" id="council">
                <option value="">All councils</option>
                <?php foreach ($councils as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $council_id == $c['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($c['loc_code']) ?>] <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label for="date_from">From Date</label>
            <input type="text" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" placeholder="YYYY-MM-DD">
        </div>
        <div class="form-group" style="margin:0">
            <label for="date_to">To Date</label>
            <input type="text" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" placeholder="YYYY-MM-DD">
        </div>
        <div class="form-group" style="margin:0">
            <label for="group">Group By</label>
            <select name="group" id="group">
                <option value="org"  <?= $group_by==='org'  ? 'selected':'' ?>>Organization (by year, then month)</option>
                <option value="date" <?= $group_by==='date' ? 'selected':'' ?>>Date</option>
                <option value="none" <?= $group_by==='none' ? 'selected':'' ?>>None</option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Run Report</button>
        </div>
    </form>
</div>

<?php if ($report_type === 'files'): ?>

<?php if ($file_rows): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <p style="color:var(--muted);font-size:14px"><?= number_format(count($file_rows)) ?> file(s) found<?php if ($file_rows_truncated): ?>
        <strong style="color:var(--red)"> — truncated at <?= number_format(REPORT_FILE_CAP) ?></strong>,
        narrow the filters to see the rest<?php endif; ?><?php if ($file_rows_timed_out): ?>
        <strong style="color:var(--red)"> — stopped after <?= REPORT_FILE_SECONDS ?>s while reading packages for the first time.</strong>
        Reload to continue (packages already read are now cached), or narrow the filters<?php endif; ?></p>
    <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['export'=>'csv']), fn($v)=>$v!=='')) ?>"
       class="btn btn-sm btn-ghost">⬇ Export CSV</a>
</div>
<div class="card">
    <table>
        <thead>
            <tr>
                <th>File Name</th>
                <th>File Size</th>
                <th>Format</th>
                <th>Package</th>
                <th>Organization</th>
                <th>Date Processed</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($file_rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['file_name']) ?></td>
                <td style="color:var(--muted);font-size:13px;white-space:nowrap"><?= $r['file_size'] ? htmlspecialchars($r['file_size']) : '—' ?></td>
                <td style="color:var(--muted);font-size:13px" title="<?= htmlspecialchars($r['puid'] ?? '') ?>"><?= $r['format'] ? htmlspecialchars($r['format']) : '—' ?></td>
                <td style="font-size:13px"><?= htmlspecialchars($r['package_name']) ?></td>
                <td><span class="badge badge-org"><?= htmlspecialchars($r['loc_code']) ?></span></td>
                <td style="color:var(--muted);font-size:13px;white-space:nowrap"><?= date('Y-m-d', strtotime($r['processed_at'])) ?></td>
                <td><a href="view.php?id=<?= $r['package_id'] ?>" class="btn btn-sm btn-ghost">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="card">
    <p style="color:var(--muted);font-size:14px">No files found for the selected filters<?php if ($file_rows_timed_out): ?> — stopped after <?= REPORT_FILE_SECONDS ?>s while reading packages for the first time. Reload to continue<?php endif; ?>.</p>
</div>
<?php endif; ?>

<?php elseif ($rows): ?>
<?php
$with_size    = array_filter($rows, fn($r) => $r['size_gb'] !== null);
$missing_size = count($rows) - count($with_size);
$grand_total_gb = array_sum(array_column($with_size, 'size_gb'));

$with_count      = array_filter($rows, fn($r) => $r['file_count'] !== null);
$missing_count   = count($rows) - count($with_count);
$grand_total_files = array_sum(array_column($with_count, 'file_count'));
?>
<style>
.report-group > summary,
.report-year  > summary,
.report-month > summary { cursor: pointer; list-style: none; }
.report-group > summary::-webkit-details-marker,
.report-year  > summary::-webkit-details-marker,
.report-month > summary::-webkit-details-marker { display: none; }
.report-group > summary .chevron,
.report-year  > summary .chevron,
.report-month > summary .chevron { display: inline-block; transition: transform .15s; margin-right: 6px; color: var(--muted); }
.report-group[open] > summary .chevron,
.report-year[open]  > summary .chevron,
.report-month[open] > summary .chevron { transform: rotate(90deg); }
.report-group > summary h2 { display: inline-block; margin-bottom: 0; font-size: 22px; }
.report-group > table { margin-top: 16px; }

/* Year/month sub-groups, indented under their parent with a rail so the
   hierarchy stays legible several levels deep. */
.report-nest { padding-left: 22px; border-left: 1px solid var(--border); margin-top: 14px; margin-left: 3px; }
.report-year { margin-bottom: 14px; }
.report-year:last-child { margin-bottom: 0; }
.report-month { margin-bottom: 10px; }
.report-month:last-child { margin-bottom: 0; }
.report-month > table { margin-top: 10px; }

/* Notes column: overflow-wrap lets one long unbroken token (a source
   filename) break within the column instead of overflowing past it or
   forcing an unusually tall wrap that crowds the row's bottom border. */
.notes-cell { color: var(--muted); font-size: 12px; max-width: 220px; overflow-wrap: anywhere; line-height: 1.5; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <p style="color:var(--muted);font-size:14px">
        <?php if ($rows_truncated): ?><strong style="color:var(--red)">Truncated at <?= number_format(REPORT_PACKAGE_CAP) ?> — narrow the filters to see the rest.</strong><br><?php endif; ?>
        <?= number_format(count($rows)) ?> package(s) found —
        <strong style="color:var(--amber-p)">Total: <?= number_format($grand_total_gb, 3) ?> GB</strong>
        <span style="color:var(--muted)">(billable stored size for this filter)</span> —
        <strong style="color:var(--amber-p)"><?= number_format($grand_total_files) ?> files</strong>
        <?php if ($missing_size > 0 || $missing_count > 0): ?>
            <span style="color:var(--muted)">—
                <?php
                $notes = [];
                if ($missing_size > 0)  $notes[] = "$missing_size package(s) have no recorded size";
                if ($missing_count > 0) $notes[] = "$missing_count package(s) have no recorded file count";
                echo implode('; ', $notes) . ' (excluded from totals)';
                ?>
            </span>
        <?php endif; ?>
    </p>
    <div style="display:flex;gap:8px;">
        <?php if (count($grouped) > 1 || $nested): ?>
        <button type="button" class="btn btn-sm btn-ghost" onclick="document.querySelectorAll('.report-group,.report-year,.report-month').forEach(d=>d.open=true)">Expand all</button>
        <button type="button" class="btn btn-sm btn-ghost" onclick="document.querySelectorAll('.report-group,.report-year,.report-month').forEach(d=>d.open=false)">Collapse all</button>
        <?php endif; ?>
        <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['export'=>'csv']), fn($v)=>$v!=='')) ?>"
           class="btn btn-sm btn-ghost">⬇ Export CSV</a>
    </div>
</div>

<?php $show_org = ($group_by !== 'org'); ?>
<?php foreach ($grouped as $group_label => $group_data): ?>
<?php
$group_rows = $nested ? flatten_two_level($group_data) : $group_data;
$gt = report_totals($group_rows);
$gmiss = report_missing_note($gt['missing_size'], $gt['missing_count']);
?>
<details class="card report-group" style="margin-bottom:20px;" <?= count($grouped) === 1 ? 'open' : '' ?>>
    <summary>
        <span class="chevron">▶</span>
        <h2 style="display:inline"><?= htmlspecialchars($group_label) ?>
            <span style="font-weight:400;color:var(--muted);font-size:16px">(<?= count($group_rows) ?>)</span>
            <span style="font-weight:400;color:var(--amber-p);font-size:16px">— <?= number_format($gt['total_gb'], 3) ?> GB</span>
            <span style="font-weight:400;color:var(--amber-p);font-size:16px">— <?= number_format($gt['total_files']) ?> files</span>
            <?php if ($gmiss !== ''): ?>
                <span style="font-weight:400;color:var(--muted);font-size:14px">(<?= $gmiss ?>)</span>
            <?php endif; ?>
        </h2>
    </summary>

    <?php if (!$nested): ?>
        <?php render_report_table($group_rows, $show_org); ?>
    <?php else: ?>
    <div class="report-nest">
        <?php foreach ($group_data as $year => $months): ?>
        <?php
        $year_rows = flatten_one_level($months);
        $yt = report_totals($year_rows);
        $ymiss = report_missing_note($yt['missing_size'], $yt['missing_count']);
        ?>
        <details class="report-year" <?= count($group_data) === 1 ? 'open' : '' ?>>
            <summary>
                <span class="chevron">▶</span>
                <h3 style="display:inline;font-size:20px;color:var(--amber-p);font-weight:600;margin:0"><?= htmlspecialchars((string)$year) ?>
                    <span style="font-weight:400;color:var(--muted);font-size:15px">(<?= count($year_rows) ?>)</span>
                    <span style="font-weight:400;color:var(--amber-p);font-size:15px">— <?= number_format($yt['total_gb'], 3) ?> GB</span>
                    <span style="font-weight:400;color:var(--amber-p);font-size:15px">— <?= number_format($yt['total_files']) ?> files</span>
                    <?php if ($ymiss !== ''): ?>
                        <span style="font-weight:400;color:var(--muted);font-size:13px">(<?= $ymiss ?>)</span>
                    <?php endif; ?>
                </h3>
            </summary>
            <div class="report-nest">
                <?php foreach ($months as $month_num => $month_rows): ?>
                <?php
                $mt = report_totals($month_rows);
                $mmiss = report_missing_note($mt['missing_size'], $mt['missing_count']);
                ?>
                <details class="report-month" <?= count($months) === 1 ? 'open' : '' ?>>
                    <summary>
                        <span class="chevron">▶</span>
                        <h4 style="display:inline;font-size:18px;color:var(--text);font-weight:600;margin:0"><?= REPORT_MONTH_NAMES[(int)$month_num] ?? $month_num ?>
                            <span style="font-weight:400;color:var(--muted);font-size:14px">(<?= count($month_rows) ?>)</span>
                            <span style="font-weight:400;color:var(--amber-p);font-size:14px">— <?= number_format($mt['total_gb'], 3) ?> GB</span>
                            <span style="font-weight:400;color:var(--amber-p);font-size:14px">— <?= number_format($mt['total_files']) ?> files</span>
                            <?php if ($mmiss !== ''): ?>
                                <span style="font-weight:400;color:var(--muted);font-size:12px">(<?= $mmiss ?>)</span>
                            <?php endif; ?>
                        </h4>
                    </summary>
                    <?php render_report_table($month_rows, $show_org); ?>
                </details>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</details>
<?php endforeach; ?>

<?php else: ?>
<div class="card">
    <p style="color:var(--muted);font-size:14px">No packages match the selected filters. Adjust your criteria above.</p>
</div>
<?php endif; ?>

<?php page_footer(); ?>
