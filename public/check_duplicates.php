<?php
/**
 * AJAX endpoint used by upload.php: given an org_id and a list of filenames,
 * reports which ones already exist as packages in that organization.
 * Admin-only, CSRF-protected POST.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
// Only upload.php calls this, and only admins can reach upload.php — matching
// that here (rather than just require_login()) closes an IDOR where any
// readonly user could probe filenames in organizations they have no access
// to by POSTing an arbitrary org_id directly to this endpoint.
require_admin();
verify_csrf();

header('Content-Type: application/json');

$org_id = (int)($_POST['org_id'] ?? 0);
$names  = json_decode($_POST['names'] ?? '[]', true);

if (!$org_id || empty($names) || !is_array($names)) {
    echo json_encode(['duplicates' => []]);
    exit;
}

// Only check names that are strings
$names = array_filter(array_map('strval', $names));
if (empty($names)) {
    echo json_encode(['duplicates' => []]);
    exit;
}

$db = db();
$ph = implode(',', array_fill(0, count($names), '?'));
$stmt = $db->prepare("
    SELECT original_name, id, processed_at
    FROM packages
    WHERE org_id = ? AND original_name IN ($ph)
");
$stmt->execute(array_merge([$org_id], array_values($names)));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$duplicates = [];
foreach ($rows as $r) {
    $duplicates[$r['original_name']] = [
        'id'   => $r['id'],
        'date' => date('M j, Y', strtotime($r['processed_at'])),
    ];
}

echo json_encode(['duplicates' => $duplicates]);
