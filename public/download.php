<?php
/**
 * Streams a stored package's original uploaded file (the METS XML
 * .tar.gz) back to a logged-in user as an attachment.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/archive.php';

require_login();
$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

$pkg = $db->prepare("SELECT * FROM packages WHERE id = ?");
$pkg->execute([$id]);
$p = $pkg->fetch();

if (!$p) { http_response_code(404); exit('Not found'); }

if (!can_access_org((int)$p['org_id'])) { http_response_code(403); exit('Access denied'); }

$path = UPLOAD_DIR . $p['filename'];
if (!file_exists($path)) { http_response_code(404); exit('File not on disk'); }

header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="' . addslashes($p['original_name']) . '"');

// Resolve .tar.gz packages (and legacy .xml.gz) to a streamable XML path so the
// user always downloads plain .xml.
$stream = stored_xml_stream_path($path);
if ($stream === null) {
    http_response_code(500);
    exit('Stored package could not be read');
}
readfile($stream);
exit;
