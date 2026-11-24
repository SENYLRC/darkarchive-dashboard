<?php
/**
 * Dashboard: package/file/size totals and recent activity, scoped to the
 * organizations the logged-in user is allowed to see.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_login();
$db = db();

$access = org_access_sql('org_id');
$apkg   = org_access_sql('p.org_id');

if ($access['sql']) {
    // org_access_sql() always emits a literal "AND " prefix, so a plain
    // substr() strips it — ltrim() takes a character mask, not a prefix
    // string, so ltrim($sql, 'AND ') would silently misbehave the moment
    // this were ever called with a column name starting with a/A, n/N, d/D,
    // or a space.
    $w = 'WHERE ' . substr($access['sql'], 4);
    $s = $db->prepare("SELECT COUNT(*) FROM packages $w"); $s->execute($access['params']); $total_files = $s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(DISTINCT o.id) FROM organizations o JOIN packages p ON o.id=p.org_id " . str_replace('org_id', 'p.org_id', $w)); $s->execute($access['params']); $total_orgs = $s->fetchColumn();
    $s = $db->prepare("SELECT COALESCE(SUM(file_count),0) FROM packages $w"); $s->execute($access['params']); $total_files_stored = $s->fetchColumn();
    $s = $db->prepare("SELECT COALESCE(SUM(size_gb),0) FROM packages $w"); $s->execute($access['params']); $total_gb_stored = $s->fetchColumn();
} else {
    $total_files = $db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
    $total_orgs  = $db->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
    $total_files_stored = $db->query("SELECT COALESCE(SUM(file_count),0) FROM packages")->fetchColumn();
    $total_gb_stored    = $db->query("SELECT COALESCE(SUM(size_gb),0) FROM packages")->fetchColumn();
}

$recent_sql = "SELECT p.id, p.original_name, p.processed_at, o.name AS org_name, o.loc_code
    FROM packages p JOIN organizations o ON p.org_id = o.id
    " . ($apkg['sql'] ? 'WHERE ' . substr($apkg['sql'], 4) : '') . "
    ORDER BY p.processed_at DESC LIMIT 10";
$rs = $db->prepare($recent_sql);
$rs->execute($apkg['params']);
$recent = $rs->fetchAll();

// Filtered on o.id (which organizations appear), not p.org_id — filtering a
// LEFT JOIN's right-hand table in WHERE silently turns it into an INNER JOIN,
// which was dropping a readonly user's own organization from this widget
// whenever it had zero packages.
$org_where = $access['sql'] ? 'WHERE ' . str_replace('org_id', 'o.id', substr($access['sql'], 4)) : '';
$org_sql = "SELECT o.name, o.loc_code, COUNT(p.id) AS cnt,
           SUM(CASE WHEN p.size_gb IS NOT NULL THEN p.size_gb ELSE 0 END) AS total_gb,
           SUM(CASE WHEN p.id IS NOT NULL AND p.size_gb IS NULL THEN 1 ELSE 0 END) AS missing_size,
           SUM(CASE WHEN p.file_count IS NOT NULL THEN p.file_count ELSE 0 END) AS total_files,
           SUM(CASE WHEN p.id IS NOT NULL AND p.file_count IS NULL THEN 1 ELSE 0 END) AS missing_count
    FROM organizations o LEFT JOIN packages p ON o.id = p.org_id
    $org_where
    GROUP BY o.id ORDER BY cnt DESC";
$os = $db->prepare($org_sql);
$os->execute($access['params']);
$by_org = $os->fetchAll();

page_header('Dashboard', 'index.php');
?>

<div class="stats">
    <div class="stat"><div class="stat-value"><?= number_format($total_files) ?></div><div class="stat-label">Total XML Packages</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_orgs) ?></div><div class="stat-label">Organizations</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_files_stored) ?></div><div class="stat-label">Total Files Stored</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_gb_stored, 2) ?></div><div class="stat-label">Total GB Stored</div></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

<div class="card">
    <h2>Recently Processed</h2>
    <?php if ($recent): ?>
    <table>
        <thead><tr><th>Filename</th><th>Organization</th><th>Processed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['original_name']) ?></td>
                <td><span class="badge badge-org"><?= htmlspecialchars($r['loc_code']) ?></span> <?= htmlspecialchars($r['org_name']) ?></td>
                <td style="color:var(--muted);font-size:13px"><?= date('M j, Y g:ia', strtotime($r['processed_at'])) ?></td>
                <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-ghost">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p style="color:var(--muted);font-size:14px">No packages uploaded yet. <a href="upload.php">Upload your first XML file.</a></p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>By Organization</h2>
    <table>
        <thead><tr><th>LOC Code</th><th>Packages</th><th>Stored Size</th><th>Files</th></tr></thead>
        <tbody>
        <?php foreach ($by_org as $o): ?>
            <tr>
                <td>
                    <span class="badge badge-org"><?= htmlspecialchars($o['loc_code']) ?></span><br>
                    <span style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($o['name']) ?></span>
                </td>
                <td style="font-weight:600;color:var(--amber-p)"><?= $o['cnt'] ?></td>
                <td style="font-size:13px;color:var(--muted)">
                    <?php
                    $gb      = (float)($o['total_gb'] ?? 0);
                    $missing = (int)($o['missing_size'] ?? 0);
                    if ($o['cnt'] == 0) echo '—';
                    else {
                        echo $gb > 0 ? number_format($gb, 2) . ' GB' : '—';
                        if ($missing > 0) echo ' <span style="font-size:11px">(' . $missing . ' unrecorded)</span>';
                    }
                    ?>
                </td>
                <td style="font-size:13px;color:var(--muted)">
                    <?php
                    $files        = (int)($o['total_files'] ?? 0);
                    $missing_ct   = (int)($o['missing_count'] ?? 0);
                    if ($o['cnt'] == 0) echo '—';
                    else {
                        echo $files > 0 ? number_format($files) : '—';
                        if ($missing_ct > 0) echo ' <span style="font-size:11px">(' . $missing_ct . ' unrecorded)</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</div>

<?php page_footer(); ?>
