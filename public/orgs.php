<?php
/**
 * Admin-only management of organizations: add, edit (name, LOC code,
 * Library Council) and delete member organizations.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_admin();
$db = db();
$msg = '';
$err = '';

// Add org
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name      = trim($_POST['name'] ?? '');
    $loc_code  = strtoupper(trim($_POST['loc_code'] ?? ''));
    $council_id = (int)($_POST['council_id'] ?? 0) ?: null;
    if (!$name || !$loc_code) {
        $err = 'Name and LOC code are required.';
    } else {
        // Check for duplicates before insert
        $dup = $db->prepare("SELECT id FROM organizations WHERE loc_code=? OR name=?");
        $dup->execute([$loc_code, $name]);
        $existing = $dup->fetch();
        if ($existing) {
            $err = "An organization with that LOC code or name already exists (ID #{$existing['id']}).";
        } else {
        try {
            $db->prepare("INSERT INTO organizations (name, loc_code, council_id) VALUES (?, ?, ?)")
               ->execute([$name, $loc_code, $council_id]);
            $msg = "Organization \"{$name}\" [{$loc_code}] added.";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $err = "LOC code {$loc_code} already exists.";
            } else {
                error_log('orgs.php: ' . $e->getMessage());
                $err = 'Could not save the organization. Check the application log for details.';
            }
        }
        }
    }
}

// Delete org
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)$_POST['del_id'];
    $count  = $db->prepare("SELECT COUNT(*) FROM packages WHERE org_id=?");
    $count->execute([$del_id]);
    if ($count->fetchColumn() > 0) {
        $err = 'Cannot delete — this organization has packages associated with it.';
    } else {
        $db->prepare("DELETE FROM organizations WHERE id=?")->execute([$del_id]);
        $msg = 'Organization deleted.';
    }
}

// Edit org
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $edit_id   = (int)$_POST['edit_id'];
    $name      = trim($_POST['name'] ?? '');
    $loc_code  = strtoupper(trim($_POST['loc_code'] ?? ''));
    $council_id = (int)($_POST['council_id'] ?? 0) ?: null;
    if (!$name || !$loc_code) {
        $err = 'Name and LOC code are required.';
    } else {
        $dup = $db->prepare("SELECT id FROM organizations WHERE (loc_code=? OR name=?) AND id != ?");
        $dup->execute([$loc_code, $name, $edit_id]);
        $existing = $dup->fetch();
        if ($existing) {
            $err = "Another organization with that LOC code or name already exists (ID #{$existing['id']}).";
        } else {
        try {
            $db->prepare("UPDATE organizations SET name=?, loc_code=?, council_id=? WHERE id=?")
               ->execute([$name, $loc_code, $council_id, $edit_id]);
            $msg = 'Organization updated.';
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $err = "LOC code {$loc_code} already exists.";
            } else {
                error_log('orgs.php: ' . $e->getMessage());
                $err = 'Could not save the organization. Check the application log for details.';
            }
        }
        }
    }
}

$councils = $db->query("SELECT id, name, loc_code FROM library_councils ORDER BY name")->fetchAll();

// ── List filters and sorting (all via GET, so a filtered view can be bookmarked) ──
// ?council=<id> one council, ?council=none unassigned only, omitted = all.
// ?q=     substring match on name or LOC code.
// ?sort=  name (default)|loc|council|packages, ?dir= asc|desc. Column names come from
//         the whitelist below, never from the request, so they are safe to
//         interpolate into ORDER BY.
$f_council = $_GET['council'] ?? '';
$f_q       = trim($_GET['q'] ?? '');
$sort_cols = [
    'loc'      => 'o.loc_code',
    'name'     => 'o.name',
    'council'  => 'c.name',
    'packages' => 'pkg_count',
];
$sort = isset($sort_cols[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name';
$dir  = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';

$where  = [];
$params = [];
if ($f_council === 'none') {
    $where[] = 'o.council_id IS NULL';
} elseif ((int)$f_council) {
    $where[]  = 'o.council_id = ?';
    $params[] = (int)$f_council;
}
if ($f_q !== '') {
    $where[]  = '(o.name LIKE ? OR o.loc_code LIKE ?)';
    $like     = '%' . addcslashes($f_q, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

// Default order is by name (A→Z). Unassigned orgs always sort after assigned
// ones when sorting by council; o.name is the tie-breaker so orgs within a
// council (or with equal package counts) stay alphabetical.
$order = ($sort === 'council' ? 'c.name IS NULL, ' : '') . "{$sort_cols[$sort]} $dir, o.name";

$s = $db->prepare("
    SELECT o.*, COUNT(p.id) AS pkg_count, c.name AS council_name, c.loc_code AS council_loc_code
    FROM organizations o
    LEFT JOIN packages p ON o.id = p.org_id
    LEFT JOIN library_councils c ON o.council_id = c.id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    GROUP BY o.id ORDER BY $order
");
$s->execute($params);
$orgs = $s->fetchAll();
$total_orgs = (int)$db->query("SELECT COUNT(*) FROM organizations")->fetchColumn();

// Current filter/sort as a query string, carried on Edit/Cancel links and the
// edit form's redirect so saving an org doesn't lose the filtered view.
$list_qs = http_build_query(array_filter(
    ['council' => $f_council, 'q' => $f_q, 'sort' => $sort, 'dir' => strtolower($dir)],
    fn($v) => $v !== ''
));

/** Header link that sorts by $key, toggling direction if already sorted by it. */
function sort_link(string $key, string $label, string $sort, string $dir): string {
    $next  = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
    $qs    = http_build_query(array_merge($_GET, ['sort' => $key, 'dir' => $next]));
    $arrow = $sort === $key ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars($qs) . '" style="color:inherit;text-decoration:none">'
         . htmlspecialchars($label) . $arrow . '</a>';
}

// Looked up on its own, not from $orgs: the org being edited may be hidden
// by the current filter.
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_org = null;
if ($edit_id) {
    $s = $db->prepare("SELECT * FROM organizations WHERE id=?");
    $s->execute([$edit_id]);
    $edit_org = $s->fetch() ?: null;
}

page_header('Organizations', 'orgs.php');
?>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

<div class="card">
    <h2>All Organizations</h2>
    <form method="get" style="display:grid;grid-template-columns:2fr 1.3fr auto auto;gap:12px;align-items:end;margin-bottom:12px;">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="dir" value="<?= strtolower($dir) ?>">
        <div class="form-group" style="margin:0">
            <label for="f-council">Library Council</label>
            <select name="council" id="f-council" onchange="this.form.submit()">
                <option value="">All councils</option>
                <?php foreach ($councils as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $f_council === (string)$c['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($c['loc_code']) ?>] <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="none" <?= $f_council === 'none' ? 'selected' : '' ?>>— Unassigned —</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label for="f-q">Name or LOC code</label>
            <input type="text" name="q" id="f-q" value="<?= htmlspecialchars($f_q) ?>" placeholder="e.g. Morrisville">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($f_council !== '' || $f_q !== ''): ?>
            <a href="orgs.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php else: ?><span></span><?php endif; ?>
    </form>
    <div style="font-size:12px;color:var(--muted);margin-bottom:8px">
        Showing <?= count($orgs) ?> of <?= $total_orgs ?> organizations · click a column heading to sort
    </div>
    <table>
        <thead><tr>
            <th><?= sort_link('loc', 'LOC Code', $sort, $dir) ?></th>
            <th><?= sort_link('name', 'Name', $sort, $dir) ?></th>
            <th><?= sort_link('council', 'Council', $sort, $dir) ?></th>
            <th><?= sort_link('packages', 'Packages', $sort, $dir) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (!$orgs): ?>
        <tr><td colspan="5" style="color:var(--muted)">No organizations match this filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($orgs as $o): ?>
        <tr>
            <td><span class="badge badge-org"><?= htmlspecialchars($o['loc_code']) ?></span></td>
            <td><?= htmlspecialchars($o['name']) ?></td>
            <td style="color:var(--muted);font-size:13px"><?= $o['council_name'] ? htmlspecialchars($o['council_name']) : '—' ?></td>
            <td style="color:var(--amber-p);font-weight:600"><?= $o['pkg_count'] ?></td>
            <td>
                <div style="display:flex;gap:6px;">
                <a href="orgs.php?<?= htmlspecialchars(http_build_query(['edit' => $o['id']]) . ($list_qs ? "&$list_qs" : '')) ?>" class="btn btn-sm btn-ghost">Edit</a>
                <?php if ($o['pkg_count'] == 0): ?>
                <form method="post" onsubmit="return confirm('Delete this organization?')" style="display:inline">
        <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="del_id" value="<?= $o['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div>
<?php if ($edit_org): ?>
<div class="card">
    <h2>Edit Organization</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" value="<?= $edit_org['id'] ?>">
        <div class="form-group">
            <label for="loc_code">LOC Code *</label>
            <input type="text" name="loc_code" id="loc_code" value="<?= htmlspecialchars($edit_org['loc_code']) ?>" required placeholder="e.g. NSeN" maxlength="50">
        </div>
        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" name="name" id="name" value="<?= htmlspecialchars($edit_org['name']) ?>" required placeholder="Organization full name">
        </div>
        <div class="form-group">
            <label for="council_id">Library Council</label>
            <select name="council_id" id="council_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($councils as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$edit_org['council_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($c['loc_code']) ?>] <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="orgs.php<?= $list_qs ? '?' . htmlspecialchars($list_qs) : '' ?>" class="btn btn-ghost btn-sm">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h2>Add Organization</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <label for="loc_code">LOC Code *</label>
            <input type="text" name="loc_code" id="loc_code" required placeholder="e.g. NSeN" maxlength="50">
            <div style="margin-top:4px;font-size:11px;color:var(--muted)">Library of Congress location code — will be stored in UPPERCASE</div>
        </div>
        <div class="form-group">
            <label for="name">Organization Name *</label>
            <input type="text" name="name" id="name" required placeholder="Full organization name">
        </div>
        <div class="form-group">
            <label for="council_id">Library Council</label>
            <select name="council_id" id="council_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($councils as $c): ?>
                    <option value="<?= $c['id'] ?>">[<?= htmlspecialchars($c['loc_code']) ?>] <?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Add Organization</button>
    </form>
</div>
<?php endif; ?>
</div>

</div>

<?php page_footer(); ?>
