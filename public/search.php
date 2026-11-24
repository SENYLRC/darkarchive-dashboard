<?php
/**
 * Search / browse packages by organization, name, date range and metadata element, limited to
 * the organizations the user can access.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_login();

$db   = db();
if (is_admin()) {
    $orgs = $db->query("SELECT id, name, loc_code FROM organizations ORDER BY name")->fetchAll();
} else {
    $ids = allowed_orgs() ?: [0];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $s   = $db->prepare("SELECT id, name, loc_code FROM organizations WHERE id IN ($ph) ORDER BY name");
    $s->execute($ids);
    $orgs = $s->fetchAll();
}
$known_elements = $db->query("SELECT DISTINCT element FROM file_metadata ORDER BY element LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);

// Search params
$q        = trim($_GET['q']   ?? '');
$org_id   = (int)($_GET['org'] ?? 0);
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';
$elem      = trim($_GET['elem'] ?? '');
$elem_val  = trim($_GET['elem_val'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// Build query
$where  = ['1=1'];
$params = [];

// Restrict readonly users to their assigned orgs. Built with named
// placeholders (not org_access_sql(), which returns positional "?") since
// PDO rejects mixing "?" and ":name" markers in one statement — that mix
// was throwing an uncaught PDOException for every non-admin search.
if (!is_admin()) {
    $allowed = allowed_orgs() ?: [0];
    $keys = [];
    foreach ($allowed as $i => $aid) {
        $key = ":asrch$i";
        $keys[] = $key;
        $params[$key] = $aid;
    }
    $where[] = 'p.org_id IN (' . implode(',', $keys) . ')';
}

if ($q !== '') {
    // Match either the package's own batch name (original_name) or an
    // individual file inside it — the same System:FileName rows report.php
    // lists per package. Without the EXISTS clause, searching for a file
    // that's just one of many TIFFs/JPEGs inside a batch (e.g.
    // "bc_arch.blith.009.a.tif") found nothing here even though it's right
    // there in that org's PDF report, because the batch's own original_name
    // is a different string entirely.
    $where[]  = "(p.original_name LIKE :q OR EXISTS (
        SELECT 1 FROM file_metadata fq
        WHERE fq.package_id = p.id AND fq.element = 'System:FileName' AND fq.value LIKE :q
    ))";
    $params[':q'] = '%' . $q . '%';
}
if ($org_id) {
    $where[]  = "p.org_id = :org_id";
    $params[':org_id'] = $org_id;
}
if ($date_from) {
    $where[]  = "DATE(p.processed_at) >= :date_from";
    $params[':date_from'] = $date_from;
}
if ($date_to) {
    $where[]  = "DATE(p.processed_at) <= :date_to";
    $params[':date_to'] = $date_to;
}
if ($elem !== '' || $elem_val !== '') {
    $sub = "SELECT package_id FROM file_metadata WHERE 1=1";
    if ($elem !== '') {
        $sub .= " AND element = :elem";
        $params[':elem'] = $elem;
    }
    if ($elem_val !== '') {
        $sub .= " AND value LIKE :elem_val";
        $params[':elem_val'] = '%' . $elem_val . '%';
    }
    $where[] = "p.id IN ($sub)";
}

$where_sql = implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) FROM packages p JOIN organizations o ON p.org_id=o.id WHERE $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pages = (int)ceil($total / $per_page);

$stmt = $db->prepare("
    SELECT p.id, p.original_name, p.file_size, p.size_gb, p.glacier_location, p.processed_at, p.notes,
           o.name AS org_name, o.loc_code
    FROM packages p JOIN organizations o ON p.org_id = o.id
    WHERE $where_sql
    ORDER BY p.processed_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function format_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    return round($b/1048576, 1) . ' MB';
}

function query_string(array $overrides = []): string {
    $p = array_merge($_GET, $overrides);
    unset($p['page']);
    return http_build_query(array_filter($p, fn($v) => $v !== ''));
}

page_header('Search / Browse', 'search.php');
?>

<div class="card">
    <form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:12px;align-items:end;">
        <div class="form-group" style="margin:0">
            <label for="q">Filename</label>
            <input type="search" name="q" id="q" value="<?= htmlspecialchars($q) ?>" placeholder="Package or file name…">
        </div>
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
            <label for="date_from">Processed From</label>
            <input type="text" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" placeholder="YYYY-MM-DD">
        </div>
        <div class="form-group" style="margin:0">
            <label for="date_to">Processed To</label>
            <input type="text" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" placeholder="YYYY-MM-DD">
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
    </form>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
        <div style="margin-bottom:8px;font-size:13px;color:var(--muted)">Search XML metadata (e.g. ExifTool elements)</div>
        <form method="get" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
            <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
            <input type="hidden" name="org" value="<?= htmlspecialchars((string)$org_id) ?>">
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            <div class="form-group" style="margin:0">
                <label for="elem">Element</label>
                <input type="text" name="elem" id="elem" list="elem-list" value="<?= htmlspecialchars($elem) ?>" placeholder="e.g. System:FileName">
                <datalist id="elem-list">
                    <?php foreach ($known_elements as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group" style="margin:0">
                <label for="elem_val">Value contains</label>
                <input type="text" name="elem_val" id="elem_val" value="<?= htmlspecialchars($elem_val) ?>" placeholder="e.g. NIKON D2X">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Search Metadata</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0"><?= number_format($total) ?> result<?= $total !== 1 ? 's' : '' ?></h2>
        <?php if ($total > 0): ?>
        <a href="report.php?<?= query_string() ?>" class="btn btn-sm btn-ghost">Export this list →</a>
        <?php endif; ?>
    </div>

    <?php if ($rows): ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Filename</th>
                <th>Organization</th>
                <th>Size</th>
                <th>Glacier</th>
                <th>Processed</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="color:var(--muted);font-size:12px"><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['original_name']) ?></td>
                <td>
                    <span class="badge badge-org"><?= htmlspecialchars($r['loc_code']) ?></span>
                    <span style="font-size:12px;color:var(--muted);margin-left:4px"><?= htmlspecialchars($r['org_name']) ?></span>
                </td>
                <td style="color:var(--muted);font-size:13px">
                    <?= $r['size_gb'] !== null ? number_format((float)$r['size_gb'], 3) . ' GB' : format_bytes($r['file_size']) ?>
                </td>
                <td style="font-size:11px;color:var(--muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= htmlspecialchars($r['glacier_location'] ?? '') ?>">
                    <?= $r['glacier_location'] ? htmlspecialchars($r['glacier_location']) : '—' ?>
                </td>
                <td style="color:var(--muted);font-size:13px;white-space:nowrap"><?= date('M j, Y', strtotime($r['processed_at'])) ?></td>
                <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-ghost">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= query_string(['page' => $page - 1]) ?>">← Prev</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page-2); $p <= min($pages, $page+2); $p++): ?>
            <?php if ($p == $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="?<?= query_string(['page' => $p]) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
            <a href="?<?= query_string(['page' => $page + 1]) ?>">Next →</a>
        <?php endif; ?>
        <span style="margin-left:8px;font-size:12px;color:var(--muted)">Page <?= $page ?> of <?= $pages ?></span>
    </div>
    <?php endif; ?>

    <?php else: ?>
        <p style="color:var(--muted);font-size:14px">No packages match your search.</p>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
