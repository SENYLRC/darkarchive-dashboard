<?php
/**
 * Builds a per-organization PDF collection report by shelling out to
 * generate_pdf_report.py, then sends the result as a download.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/xml_metadata.php';

require_login();

$db = db();
if (is_admin()) {
    $orgs = $db->query("
        SELECT o.id, o.name, o.loc_code, COUNT(p.id) AS pkg_count
        FROM organizations o LEFT JOIN packages p ON o.id = p.org_id
        GROUP BY o.id ORDER BY o.name
    ")->fetchAll();
} else {
    $ids = allowed_orgs() ?: [0];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $s   = $db->prepare("
        SELECT o.id, o.name, o.loc_code, COUNT(p.id) AS pkg_count
        FROM organizations o LEFT JOIN packages p ON o.id = p.org_id
        WHERE o.id IN ($ph)
        GROUP BY o.id ORDER BY o.name
    ");
    $s->execute($ids);
    $orgs = $s->fetchAll();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org_id = (int)($_POST['org_id'] ?? 0);
    if (!$org_id) {
        $error = 'Please select an organization.';
    } elseif (!can_access_org($org_id)) {
        $error = 'You do not have access to that organization.';
    } else {
        // DB config for the Python script. Sent on stdin, never as an argv
        // value: /proc/<pid>/cmdline is world-readable, so a password on the
        // command line is visible to every local user via `ps` for as long as
        // the report runs.
        $db_conf = [
            'host'   => DB_HOST,
            'name'   => DB_NAME,
            'user'   => DB_USER,
            'pass'   => DB_PASS,
            'ssl_ca' => RDS_CERT,
        ];

        // Per-package file lists from the METS fileSec's original files —
        // the same list the View page and the Reports "File List" show, and
        // the same set packages.file_count counts. The Python script used to
        // rebuild the list from ExifTool FileName rows in file_metadata, so a
        // SUNY Morrisville package of PDFs (no ExifTool output) came out as
        // just its images. A null entry (no USE="original" group) tells the
        // script to fall back to the old file_metadata grouping.
        $pkg_stmt = $db->prepare("SELECT id, filename FROM packages WHERE org_id = ?");
        $pkg_stmt->execute([$org_id]);
        $file_lists = [];
        foreach ($pkg_stmt->fetchAll() as $pkg) {
            $file_lists[(string)$pkg['id']] = cached_mets_original_files(UPLOAD_DIR . $pkg['filename']);
        }
        $db_conf['file_lists'] = $file_lists;
        $db_conf = json_encode($db_conf, JSON_INVALID_UTF8_SUBSTITUTE);

        // tempnam() creates the file immediately under an unpredictable name,
        // so a local user cannot pre-plant a symlink at a guessable path and
        // redirect the write.
        $tmp_pdf = tempnam(sys_get_temp_dir(), 'da_report_');
        $script  = __DIR__ . '/../generate_pdf_report.py';
        $output  = '';

        if ($tmp_pdf === false) {
            $error = 'Could not create a temporary file for the report.';
        } else {
            // Array form of proc_open bypasses the shell entirely, so no
            // argument needs escaping and nothing is re-parsed.
            $proc = proc_open(
                ['python3', $script, (string)$org_id, $tmp_pdf],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (!is_resource($proc)) {
                $error = 'Could not start the PDF generator.';
            } else {
                fwrite($pipes[0], $db_conf);
                fclose($pipes[0]);
                $output  = stream_get_contents($pipes[1]);
                $output .= stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        }

        if (!$error && str_starts_with(trim($output), 'OK:') && filesize($tmp_pdf) > 0) {
            // Get org name for filename
            $org_row = $db->prepare("SELECT name, loc_code FROM organizations WHERE id=?");
            $org_row->execute([$org_id]);
            $org = $org_row->fetch();
            $safe_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $org['loc_code']);
            $filename = 'darkarchive_' . $safe_name . '_' . date('Ymd') . '.pdf';

            header('Content-Type: application/pdf');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header('Content-Length: ' . filesize($tmp_pdf));
            readfile($tmp_pdf);
            unlink($tmp_pdf);
            exit;
        } elseif (!$error) {
            if ($tmp_pdf !== false) @unlink($tmp_pdf);
            $error = 'PDF generation failed. Output: ' . ($output ?: 'no output');
        }
    }
}

page_header('PDF Report', 'report.php');
?>

<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <h2>Generate Organization PDF Report</h2>
    <p style="font-size:14px;color:var(--muted);margin-bottom:20px;">
        Produces a PDF listing all files across all packages for the selected organization,
        including filename, size, file type, date created, and date processed.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="org_id">Organization *</label>
            <select name="org_id" id="org_id" required>
                <option value="">— Select organization —</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= (($_POST['org_id'] ?? '') == $o['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                        (<?= $o['pkg_count'] ?> package<?= $o['pkg_count'] != 1 ? 's' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">⬇ Generate &amp; Download PDF</button>
    </form>
</div>

<div class="card" style="max-width:600px;">
    <h2>Report Contents</h2>
    <ul style="font-size:14px;color:var(--muted);line-height:2;padding-left:20px;">
        <li>One section per package, sorted by date processed</li>
        <li>Per-file rows: Filename, Size, File Type, Date Created, Date Processed</li>
        <li>Date Processed comes from <code>premis:eventDateTime</code> in the XML</li>
        <li>Landscape letter format, dark-themed, page numbers</li>
        <li>Large packages may take a few seconds to generate</li>
    </ul>
</div>

<?php page_footer(); ?>
