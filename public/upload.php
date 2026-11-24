<?php
/**
 * Admin-only upload of METS XML packages (.xml/.tar.gz/.zip) for an organization.
 * Stores the file, parses its metadata, and records the package.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/xml_metadata.php';

require_admin();

$db   = db();
$orgs = $db->query("SELECT id, name, loc_code FROM organizations ORDER BY loc_code")->fetchAll();

$errors    = [];
$successes = [];

/**
 * Compress, insert and index one XML file from an on-disk path.
 */
function web_process_xml(PDO $db, int $org_id, string $src,
                         string $orig_name, int $orig_size,
                         string $notes, string $loc_code,
                         array &$errors, array &$successes): void
{
    // Check for duplicate — same filename already exists for this org
    $dup = $db->prepare("SELECT id, processed_at FROM packages WHERE org_id=? AND original_name=?");
    $dup->execute([$org_id, $orig_name]);
    $existing = $dup->fetch();
    if ($existing) {
        $errors[] = "$orig_name was already uploaded for this organization on "
                  . date('M j, Y', strtotime($existing['processed_at']))
                  . " (package #" . $existing['id'] . "). Skipped.";
        return;
    }

    // Validate XML
    libxml_use_internal_errors(true);
    $valid = simplexml_load_file($src) !== false;
    $xml_errors = libxml_get_errors();
    libxml_clear_errors();
    if (!$valid) {
        $errors[] = "$orig_name is not valid XML: " . ($xml_errors[0]->message ?? 'parse error');
        return;
    }

    // Package as <LOC>-<create-date>-<clean originalName>.tar.gz
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $created       = extract_mets_created($src);
    $original_name = extract_premis_original_name($src);
    $base          = build_stored_basename($loc_code, $created, $original_name, $orig_name);
    $stored        = unique_stored_name(UPLOAD_DIR, $base, '.tar.gz');
    $dest          = UPLOAD_DIR . $stored;

    $pack_err = '';
    if (!create_tar_gz($src, $base . '.xml', $dest, $pack_err)) {
        $errors[] = "Could not package $orig_name for storage — $pack_err";
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO packages (org_id, filename, original_name, file_size, notes, processed_at)
        VALUES (:org_id, :filename, :original_name, :file_size, :notes, :processed_at)
    ");
    // processed_at reflects when the METS package was created, not when we
    // ingested it. Falls back to ingest time if the header carries no date.
    $processed_at = $created ?? date('Y-m-d H:i:s');

    $stmt->execute([
        ':org_id'        => $org_id,
        ':filename'      => $stored,
        ':original_name' => $orig_name,
        ':file_size'     => $orig_size,
        ':notes'         => $notes,
        ':processed_at'  => $processed_at,
    ]);
    $package_id = (int)$db->lastInsertId();
    store_xml_metadata($db, $package_id, $src);
    $successes[] = $orig_name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org_id = (int)($_POST['org_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');

    if (!$org_id) $errors[] = "Please select an organization.";

    $lc = $db->prepare("SELECT loc_code FROM organizations WHERE id = ?");
    $lc->execute([$org_id]);
    $loc_code = strtoupper((string)$lc->fetchColumn());
    if ($org_id && $loc_code === '') $errors[] = "That organization no longer exists.";

    $files = $_FILES['xml_files'] ?? null;
    if (empty($files['name'][0])) $errors[] = "Please select at least one file.";

    if (empty($errors)) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $orig_name = $files['name'][$i];
            $tmp_path  = $files['tmp_name'][$i];
            $file_size = $files['size'][$i];
            $err_code  = $files['error'][$i];
            $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if ($err_code !== UPLOAD_ERR_OK) {
                $errors[] = "Upload error for {$orig_name}: code {$err_code}";
                continue;
            }

            if ($ext === 'zip') {
                // Extract all XML files from ZIP and process each
                if (!class_exists('ZipArchive')) {
                    $errors[] = "ZipArchive PHP extension not available on this server.";
                    continue;
                }
                $zip = new ZipArchive();
                if ($zip->open($tmp_path) !== true) {
                    $errors[] = "Could not open ZIP file: $orig_name";
                    continue;
                }
                // Cap entry count before touching any entry.
                if ($zip->numFiles > ZIP_MAX_ENTRIES) {
                    $errors[] = "$orig_name has {$zip->numFiles} entries; the limit is "
                              . ZIP_MAX_ENTRIES . ". Skipped.";
                    $zip->close();
                    continue;
                }

                // Pre-flight on declared sizes: reject obviously oversized
                // archives without decompressing anything. Declared sizes are
                // attacker-controlled, so the streaming copy below enforces the
                // same limits on bytes actually produced.
                $too_big = null;
                for ($z = 0; $z < $zip->numFiles; $z++) {
                    $st = $zip->statIndex($z);
                    if ($st === false) continue;
                    if (strtolower(pathinfo($st['name'], PATHINFO_EXTENSION)) !== 'xml') continue;
                    if ($st['size'] > ZIP_MAX_ENTRY_BYTES) {
                        $too_big = "{$st['name']} declares " . format_bytes_limit((int)$st['size'])
                                 . " expanded; the per-file limit is " . format_bytes_limit(ZIP_MAX_ENTRY_BYTES);
                        break;
                    }
                }
                if ($too_big !== null) {
                    $errors[] = "$orig_name: $too_big. Skipped.";
                    $zip->close();
                    continue;
                }

                $found     = 0;
                $remaining = ZIP_MAX_TOTAL_BYTES;   // shared budget across all entries
                for ($z = 0; $z < $zip->numFiles; $z++) {
                    $entry = $zip->getNameIndex($z);
                    if ($entry === false) continue;
                    if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'xml') continue;
                    $found++;
                    $base_name = basename($entry);
                    $tmp_xml   = tempnam(sys_get_temp_dir(), 'da_xml_');
                    if ($tmp_xml === false) {
                        $errors[] = "Could not create a temp file for $base_name in $orig_name.";
                        break;
                    }

                    $copy_err = '';
                    $xml_size = zip_entry_to_file_limited(
                        $zip, $z, $tmp_xml, min(ZIP_MAX_ENTRY_BYTES, $remaining), $copy_err);
                    if ($xml_size === false) {
                        @unlink($tmp_xml);
                        $errors[] = "$base_name in $orig_name: $copy_err. "
                                  . "Skipped the rest of this ZIP.";
                        break;  // a bomb/limit hit invalidates the archive; stop processing it
                    }
                    $remaining -= $xml_size;

                    web_process_xml($db, $org_id, $tmp_xml, $base_name,
                                    $xml_size, "$notes [from $orig_name]", $loc_code,
                                    $errors, $successes);
                    @unlink($tmp_xml);
                }
                $zip->close();
                if ($found === 0) {
                    $errors[] = "$orig_name contained no XML files.";
                }

            } elseif ($ext === 'xml') {
                web_process_xml($db, $org_id, $tmp_path, $orig_name,
                                $file_size, $notes, $loc_code, $errors, $successes);
            } else {
                $errors[] = "$orig_name is not an .xml or .zip file.";
            }
        }
    }
}

page_header('Upload XML', 'upload.php');
?>

<?php if ($successes): ?>
<div class="alert alert-success">
    ✓ Successfully uploaded <?= count($successes) ?> file(s):
    <?= implode(', ', array_map('htmlspecialchars', $successes)) ?>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $e): ?>
        <div>✗ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload XML Package(s)</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="org_id">Organization (LOC Code) *</label>
            <select name="org_id" id="org_id" required>
                <option value="">— Select organization —</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= (($_POST['org_id'] ?? '') == $o['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:6px;font-size:12px;color:var(--muted)">
                Don't see your organization? <a href="orgs.php">Add it here.</a>
            </div>
        </div>

        <div class="form-group">
            <label for="xml_files">XML or ZIP File(s) *</label>
            <input type="file" name="xml_files[]" id="xml_files"
                accept=".xml,.zip" multiple required
                style="display:block;padding:10px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;width:100%">
            <div style="margin-top:6px;font-size:12px;color:var(--muted)">
                Accepted: .xml files and .zip archives containing .xml files. You may select multiple.
            </div>
            <div id="dup-warning" style="display:none;margin-top:10px;padding:10px 14px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.3);border-radius:6px;font-size:13px;color:#eab308"></div>
        </div>

        <div class="form-group">
            <label for="notes">Notes (optional)</label>
            <textarea name="notes" id="notes" rows="3"
                placeholder="Processing notes, batch description, etc."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Upload &amp; Process</button>
    </form>
</div>

<script>
function checkDuplicates() {
    const orgId  = document.getElementById('org_id').value;
    const files  = document.getElementById('xml_files').files;
    const warn   = document.getElementById('dup-warning');
    if (!orgId || !files.length) { warn.style.display='none'; return; }

    // Collect .xml names (direct + guess from zip names)
    const names = [];
    for (const f of files) {
        const ext = f.name.split('.').pop().toLowerCase();
        if (ext === 'xml') names.push(f.name);
        // For ZIPs we can't peek inside client-side, just note the zip
    }
    if (!names.length) { warn.style.display='none'; return; }

    const fd = new FormData();
    fd.append('org_id', orgId);
    fd.append('names', JSON.stringify(names));
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);

    fetch('check_duplicates.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            const dups = data.duplicates || {};
            const keys = Object.keys(dups);
            if (!keys.length) { warn.style.display='none'; return; }
            // Filenames come straight from whatever the uploading browser sent
            // (no charset restriction), so they must be HTML-escaped before
            // going anywhere near innerHTML — otherwise a filename containing
            // markup executes in the next admin's session who selects a file
            // with the same name.
            const escapeHtml = s => s.replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
            let html = '⚠️ <strong>Possible duplicates detected:</strong><ul style="margin:6px 0 0 18px">';
            for (const name of keys) {
                html += `<li>${escapeHtml(name)} — already uploaded ${escapeHtml(dups[name].date)} (package #${parseInt(dups[name].id, 10)})</li>`;
            }
            html += '</ul><div style="margin-top:6px;font-size:12px">These files will be skipped automatically if you proceed.</div>';
            warn.innerHTML = html;
            warn.style.display = 'block';
        })
        .catch(() => { warn.style.display='none'; });
}

document.getElementById('org_id').addEventListener('change', checkDuplicates);
document.getElementById('xml_files').addEventListener('change', checkDuplicates);
</script>

<div class="card">
    <h2>Upload Guidelines</h2>
    <ul style="font-size:14px;color:var(--muted);line-height:2;padding-left:20px;">
        <li>Files must be valid, well-formed XML</li>
        <li>ZIP archives are extracted — each .xml inside becomes its own package</li>
        <li>Each XML file is stored gzip-compressed with a unique ID</li>
        <li>Original filenames are preserved for reference</li>
        <li>Files are linked to the selected organization by LOC code</li>
        <li>For very large files, use the CLI processor instead (bypasses upload limits)</li>
    </ul>
</div>

<?php page_footer(); ?>
