<?php
/**
 * Single package detail page: package fields, parsed METS metadata and file
 * list (CSV export via ?export=csv). Admins can edit fields here, including
 * the MB->GB size helper.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/archive.php';
require_once __DIR__ . '/../includes/xml_metadata.php';
require_once __DIR__ . '/../includes/layout.php';

require_login();
$db = db();
$id = (int)($_GET['id'] ?? 0);

if (!$id) { header('Location: search.php'); exit; }

$pkg = $db->prepare("
    SELECT p.*, o.name AS org_name, o.loc_code
    FROM packages p JOIN organizations o ON p.org_id = o.id
    WHERE p.id = ?
");
$pkg->execute([$id]);
$p = $pkg->fetch();

if (!$p) { header('Location: search.php'); exit; }

// Check org access for readonly users
if (!can_access_org((int)$p['org_id'])) {
    header('Location: index.php?error=noaccess'); exit;
}

// Handle delete/update. "Readonly" means exactly that — can_access_org() above
// only confirms this user may *view* the package's org, so mutating actions
// need their own admin check regardless of org access.
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete', 'update'], true)) {
    require_admin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $file_path = UPLOAD_DIR . $p['filename'];
    if (file_exists($file_path)) unlink($file_path);
    $db->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
    header('Location: search.php?deleted=1');
    exit;
}

// Load and group metadata — only when actually requested (a "Show files"
// click, or a CSV export). Some packages carry 100k+ file_metadata rows (one
// per leaf XML element); grouping that into a file list is real work even
// with the memory-safe unbuffered fetch below, so it's skipped by default
// rather than running on every single page load.
//
// This — and the CSV export below — must run before page_header(), which
// starts writing the HTML page: headers set after that point are silently
// dropped, so the CSV was rendering inline instead of downloading.
$show_files = ($_GET['show_files'] ?? '') === '1';
$export_csv = ($_GET['export'] ?? '') === 'csv';
$files = [];

// Preferred source: the METS fileSec's original files, read straight from the
// stored package — one row per file, matching the package's file count.
// Fallback (non-Archivematica METS, or the stored file can't be read): group
// the ExifTool FileName rows in file_metadata, which only lists files that
// have ExifTool output.
$files_from_mets = false;
if ($show_files || $export_csv) {
    $mets_files = cached_mets_original_files(UPLOAD_DIR . $p['filename']);
    if ($mets_files !== null) {
        $files = $mets_files;
        $files_from_mets = true;
    }
}

if (($show_files || $export_csv) && !$files_from_mets) {
    $meta = $db->prepare(
        "SELECT element, value FROM file_metadata WHERE package_id = ? ORDER BY id",
        [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
    $meta->execute([$id]);

    $current = null;
    foreach ($meta as $m) {
        $el = $m['element'];
        $v  = $m['value'];

        if (preg_match('/(^|:)FileName$/', $el)) {
            if ($current) $files[] = $current;
            $current = [
                'name'        => $v,
                'size'        => null,
                'created'     => null,
                'modified'    => null,
                'accessed'    => null,
                'file_type'   => null,
                'file_ext'    => null,
                'mime'        => null,
                'major_brand' => null,
                'writer'      => null,
                'reader'      => null,
                'pkg_date'    => null,
            ];
            continue;
        }
        if ($current === null) continue;

        if (preg_match('/(^|:)FileSize$/', $el)                      && !$current['size'])        $current['size']        = $v;
        if (preg_match('/(^|:)(CreateDate|DateTimeOriginal)$/', $el) && !$current['created'])     $current['created']     = $v;
        if (preg_match('/(^|:)FileModifyDate$/', $el)                && !$current['modified'])    $current['modified']    = $v;
        if (preg_match('/(^|:)FileAccessDate$/', $el)                && !$current['accessed'])    $current['accessed']    = $v;
        if (preg_match('/(^|:)FileType$/', $el)                      && !$current['file_type'])   $current['file_type']   = $v;
        if (preg_match('/(^|:)FileTypeExtension$/', $el)             && !$current['file_ext'])    $current['file_ext']    = $v;
        if (preg_match('/(^|:)MIMEType$/', $el)                      && !$current['mime'])        $current['mime']        = $v;
        if (preg_match('/(^|:)MajorBrand$/', $el)                    && !$current['major_brand']) $current['major_brand'] = $v;
        if (preg_match('/(^|:)WriterName$/', $el)                    && !$current['writer'])      $current['writer']      = $v;
        if (preg_match('/(^|:)ReaderName$/', $el)                    && !$current['reader'])      $current['reader']      = $v;
        if (preg_match('/(^|:)eventDateTime$/', $el)                 && !$current['pkg_date'])    $current['pkg_date']    = $v;
    }
    $meta->closeCursor();
    if ($current) $files[] = $current;
}

// CSV export of file list — must exit before any HTML has been output.
// Columns after "Date Package Processed" were added with the METS-based list
// and are blank for packages using the fallback; existing columns keep their
// order so older spreadsheets/imports still line up.
if ($export_csv && $files) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="package_' . $id . '_files.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Filename','Size','File Type','MIME Type','Date Created','Date Modified','Date Accessed','Brand','Writer','Reader','Date Package Processed',
                   'Size (bytes)','Format','PRONOM ID','Checksum']);
    foreach ($files as $f) {
        $bytes = is_int($f['size']) ? $f['size'] : null;
        fputcsv($out, csv_safe_row([
            $f['name'], $bytes !== null ? format_bytes($bytes) : $f['size'], $f['file_type'], $f['mime'],
            $f['created'], $f['modified'], $f['accessed'],
            $f['major_brand'], $f['writer'], $f['reader'],
            $f['pkg_date'] ?? date('Y-m-d', strtotime($p['processed_at'])),
            $bytes, $f['format'] ?? null, $f['puid'] ?? null, $f['checksum'] ?? null,
        ]));
    }
    fclose($out);
    exit;
}

// Load XML for preview. Some METS packages run into the hundreds of MB, so
// the read is capped — loading the whole thing (plus DOMDocument's own
// overhead) can blow well past PHP's memory_limit.
$xml_path       = UPLOAD_DIR . $p['filename'];
$xml_preview    = '';
$xml_truncated  = false;
$xml_read_error = false;
$preview_cap    = 1048576; // 1 MB
if (file_exists($xml_path)) {
    $stream = stored_xml_stream_path($xml_path);
    $h      = $stream !== null ? @fopen($stream, 'rb') : false;
    if ($h) {
        $chunk = fread($h, $preview_cap + 1);
        fclose($h);
        $xml_truncated = strlen($chunk) > $preview_cap;
        if ($xml_truncated) $chunk = substr($chunk, 0, $preview_cap);

        if (!$xml_truncated) {
            $dom = new DOMDocument('1.0');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $xml_preview = @$dom->loadXML($chunk) ? $dom->saveXML() : $chunk;
        } else {
            // Partial XML won't parse — show the raw bytes as-is.
            $xml_preview = $chunk;
        }
    } else {
        $xml_read_error = true;
    }
}

function format_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
    return round($b/1073741824, 2) . ' GB';
}

function dash(?string $v): string {
    return ($v !== null && $v !== '') ? htmlspecialchars($v) : '<span style="color:var(--muted)">—</span>';
}

page_header('Package #' . $id, 'search.php');
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;margin-top:-16px">
    <div>
        <a href="search.php" style="font-size:13px;color:var(--muted)">← Back to search</a>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if (file_exists($xml_path)): ?>
        <a href="download.php?id=<?= $id ?>" class="btn btn-sm btn-ghost">⬇ Download XML</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <form method="post" onsubmit="return confirm('Delete this package permanently?')">
        <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:<?= is_admin() ? '1fr 1fr' : '1fr' ?>;gap:24px;margin-bottom:24px;">
<div class="card">
    <h2>Package Details</h2>
    <table>
        <tr><td style="color:var(--muted);width:130px;font-size:13px">ID</td><td>#<?= $p['id'] ?></td></tr>
        <tr><td style="color:var(--muted);font-size:13px">Filename</td><td><?= htmlspecialchars($p['original_name']) ?></td></tr>
        <tr><td style="color:var(--muted);font-size:13px">File Size</td><td><?= format_bytes($p['file_size']) ?></td></tr>
        <tr>
            <td style="color:var(--muted);font-size:13px">Organization</td>
            <td>
                <span class="badge badge-org"><?= htmlspecialchars($p['loc_code']) ?></span>
                <?= htmlspecialchars($p['org_name']) ?>
            </td>
        </tr>
        <tr>
            <td style="color:var(--muted);font-size:13px">Processed</td>
            <td><?= date('F j, Y g:i a', strtotime($p['processed_at'])) ?></td>
        </tr>
        <?php if ($p['size_gb'] !== null): ?>
        <tr>
            <td style="color:var(--muted);font-size:13px">Size (GB)</td>
            <td><?= number_format((float)$p['size_gb'], 4) ?> GB</td>
        </tr>
        <?php endif; ?>
        <tr>
            <td style="color:var(--muted);font-size:13px">Files</td>
            <td><?= $p['file_count'] !== null ? number_format((int)$p['file_count']) : '—' ?></td>
        </tr>
        <?php if ($p['glacier_location']): ?>
        <tr>
            <td style="color:var(--muted);font-size:13px">Glacier</td>
            <td style="font-size:12px;word-break:break-all"><?= htmlspecialchars($p['glacier_location']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($p['notes']): ?>
        <tr><td style="color:var(--muted);font-size:13px">Notes</td><td><?= nl2br(htmlspecialchars($p['notes'])) ?></td></tr>
        <?php endif; ?>
        <tr>
            <td style="color:var(--muted);font-size:13px">File on disk</td>
            <td style="font-size:12px;color:var(--muted)"><?= file_exists($xml_path) ? '✓ Present' : '✗ Missing' ?></td>
        </tr>
    </table>
</div>

<?php if (is_admin()): ?>
<div class="card">
    <h2>Edit Package</h2>
    <?php
    $update_msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
        $new_org      = (int)$_POST['org_id'];
        $new_notes    = trim($_POST['notes']);
        $new_size_gb  = $_POST['size_gb'] !== '' ? (float)$_POST['size_gb'] : null;
        $new_glacier  = trim($_POST['glacier_location'] ?? '');
        $db->prepare("UPDATE packages SET org_id=?, notes=?, size_gb=?, glacier_location=? WHERE id=?")
           ->execute([$new_org, $new_notes, $new_size_gb, $new_glacier ?: null, $id]);
        $update_msg = 'Saved.';
        $p['org_id']           = $new_org;
        $p['notes']            = $new_notes;
        $p['size_gb']          = $new_size_gb;
        $p['glacier_location'] = $new_glacier;
    }
    $orgs = $db->query("SELECT id, name, loc_code FROM organizations ORDER BY name")->fetchAll();
    ?>
    <?php if ($update_msg): ?><div class="alert alert-success"><?= $update_msg ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <div class="form-group">
            <label for="edit-org_id">Organization</label>
            <select name="org_id" id="edit-org_id">
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $p['org_id'] == $o['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="edit-size_gb">Size in GB (manual entry)</label>
            <input type="text" name="size_gb" id="edit-size_gb"
                value="<?= htmlspecialchars($p['size_gb'] ?? '') ?>"
                placeholder="e.g. 12.5000">
            <div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
                <input type="text" id="edit-size_mb" placeholder="or enter MB, e.g. 825.7"
                    style="max-width:180px" autocomplete="off">
                <span style="font-size:11px;color:var(--muted)">÷ 1000 → fills in GB above</span>
            </div>
            <div style="margin-top:4px;font-size:11px;color:var(--muted)">Leave blank if unknown</div>
        </div>
        <script>
        // MB is a convenience input only — the GB field above is what's
        // actually submitted. Divides by 1000 (decimal MB/GB), not 1024, to
        // match how storage size is billed/reported elsewhere in this app.
        document.getElementById('edit-size_mb').addEventListener('input', function () {
            const mb = parseFloat(this.value);
            if (!isNaN(mb)) {
                document.getElementById('edit-size_gb').value = (mb / 1000).toFixed(4);
            }
        });
        </script>
        <div class="form-group">
            <label for="edit-glacier_location">Glacier Storage Location</label>
            <input type="text" name="glacier_location" id="edit-glacier_location"
                value="<?= htmlspecialchars($p['glacier_location'] ?? '') ?>"
                placeholder="e.g. arn:aws:glacier:us-east-1:123456789:vaults/dark-archive">
            <div style="margin-top:4px;font-size:11px;color:var(--muted)">Vault ARN, archive ID, or any location reference</div>
        </div>
        <div class="form-group">
            <label for="edit-notes">Notes</label>
            <textarea name="notes" id="edit-notes" rows="3"><?= htmlspecialchars($p['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
    </form>
</div>
<?php endif; ?>
</div>

<?php if (!$show_files): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0">Files in Package</h2>
        <a href="?id=<?= $id ?>&show_files=1" class="btn btn-sm btn-ghost">Show files</a>
    </div>
    <p style="color:var(--muted);font-size:13px;margin-top:8px;margin-bottom:0">
        Not loaded by default — some packages carry hundreds of thousands of metadata rows.
    </p>
</div>
<?php elseif ($files): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0">Files in Package (<?= count($files) ?>)</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <input type="search" id="file-search" aria-label="Filter files in this package" placeholder="Filter files…"
                style="padding:6px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px;width:200px">
            <a href="?id=<?= $id ?>&export=csv" class="btn btn-sm btn-ghost">⬇ Export CSV</a>
            <a href="?id=<?= $id ?>" class="btn btn-sm btn-ghost">Hide</a>
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table id="file-table" style="min-width:1100px">
        <thead>
            <tr>
                <th>Filename</th>
                <th>Size</th>
                <th>Type</th>
                <th>Format</th>
                <th>MIME Type</th>
                <th>Date Created</th>
                <th>Date Modified</th>
                <th>Brand / Software</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $f): ?>
            <tr>
                <td style="font-size:12px;word-break:break-all"><?= htmlspecialchars($f['name']) ?></td>
                <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= is_int($f['size']) ? format_bytes($f['size']) : dash($f['size']) ?></td>
                <td style="font-size:12px;white-space:nowrap">
                    <?php if ($f['file_type']): ?>
                        <span class="badge badge-org"><?= htmlspecialchars($f['file_type']) ?></span>
                    <?php else: echo '<span style="color:var(--muted)">—</span>'; endif; ?>
                </td>
                <td style="color:var(--muted);font-size:12px" title="<?= htmlspecialchars($f['puid'] ?? '') ?>"><?= dash($f['format'] ?? null) ?></td>
                <td style="color:var(--muted);font-size:12px"><?= dash($f['mime']) ?></td>
                <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= dash($f['created']) ?></td>
                <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= dash($f['modified']) ?></td>
                <td style="font-size:12px">
                    <?php
                    $sw = array_filter([$f['major_brand'], $f['writer'], $f['reader']]);
                    echo $sw ? htmlspecialchars(implode(' / ', $sw)) : '<span style="color:var(--muted)">—</span>';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<script>
document.getElementById('file-search').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#file-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
<?php else: ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0">Files in Package</h2>
        <a href="?id=<?= $id ?>" class="btn btn-sm btn-ghost">Hide</a>
    </div>
    <p style="color:var(--muted);font-size:14px;margin-top:8px;margin-bottom:0">No file-level metadata found for this package.</p>
</div>
<?php endif; ?>

<?php if ($xml_preview): ?>
<div class="card">
    <h2>XML Preview</h2>
    <?php if ($xml_truncated): ?>
    <p style="color:var(--muted);font-size:13px;margin-top:-8px;margin-bottom:12px;">
        Showing the first <?= number_format($preview_cap / 1048576, 1) ?> MB of a
        <?= format_bytes($p['file_size']) ?> file — too large to preview in full.
        <a href="download.php?id=<?= $id ?>">Download the full XML</a> to view everything.
    </p>
    <?php endif; ?>
    <pre style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:16px;overflow:auto;font-size:12px;line-height:1.6;max-height:500px;color:#a5b4fc"><?= htmlspecialchars($xml_preview) ?></pre>
</div>
<?php elseif ($xml_read_error): ?>
<div class="alert alert-error">Could not read the stored package on disk.</div>
<?php else: ?>
<div class="alert alert-error">XML file not found on disk at expected path.</div>
<?php endif; ?>

<?php page_footer(); ?>
