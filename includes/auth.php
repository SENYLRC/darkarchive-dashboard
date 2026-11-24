<?php
/**
 * Authentication helpers for Dark Archive.
 * Include this at the top of every page (after config.php).
 */

require_once __DIR__ . '/security.php';

// Start session with secure config
if (session_status() === PHP_SESSION_NONE) {
    configure_session();
    session_start();
}

// Send security headers on every request
send_security_headers();

/**
 * Redirect to login if not authenticated.
 * Call at the top of every protected page.
 *
 * For readonly users this also re-reads their accessible org list on every
 * request (see refresh_user_orgs()), so an admin assigning an org to a
 * council, or changing a user's assignments, takes effect on the user's next
 * page load instead of only after they sign out and back in.
 */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: login.php?redirect=$back");
        exit;
    }
    if (($_SESSION['user_role'] ?? '') === 'readonly') {
        refresh_user_orgs(db(), (int)$_SESSION['user_id']);
    }
}

/**
 * Redirect with error if not an admin.
 */
function require_admin(): void {
    require_login();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: index.php?error=noaccess');
        exit;
    }
}

/**
 * Returns true if the current user can access the given org_id.
 * Admins can access all. Readonly users only their assigned orgs, plus the
 * member orgs of any council they are assigned to (see refresh_user_orgs()).
 */
function can_access_org(int $org_id): bool {
    if (empty($_SESSION['user_id'])) return false;
    if ($_SESSION['user_role'] === 'admin') return true;
    return in_array($org_id, $_SESSION['user_orgs'] ?? []);
}

/**
 * Returns true if current user is admin.
 */
function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

/**
 * Returns the org_id filter for queries — null means all orgs (admin),
 * otherwise an array of allowed org IDs for readonly users.
 */
function allowed_orgs(): array|null {
    if (is_admin()) return null;
    return $_SESSION['user_orgs'] ?? [];
}

/**
 * Build a SQL WHERE fragment limiting to allowed orgs.
 * Returns ['sql' => '...', 'params' => [...]]
 * sql is empty string if admin (no restriction).
 */
function org_access_sql(string $org_col = 'p.org_id'): array {
    if (is_admin()) {
        return ['sql' => '', 'params' => []];
    }
    $orgs = allowed_orgs();
    if (empty($orgs)) {
        return ['sql' => 'AND 1=0', 'params' => []]; // no access
    }
    $placeholders = implode(',', array_fill(0, count($orgs), '?'));
    return ['sql' => "AND $org_col IN ($placeholders)", 'params' => $orgs];
}

/**
 * Load and cache user org list into session after login.
 */
function load_user_session(PDO $db, int $user_id): void {
    $u = $db->prepare("SELECT id, username, email, role, active FROM users WHERE id=?");
    $u->execute([$user_id]);
    $user = $u->fetch();

    if (!$user || !$user['active']) {
        session_destroy();
        header('Location: login.php?error=disabled');
        exit;
    }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['username'];
    $_SESSION['user_email']= $user['email'];
    $_SESSION['user_role'] = $user['role'];

    if ($user['role'] === 'readonly') {
        refresh_user_orgs($db, $user_id);
    } else {
        $_SESSION['user_orgs'] = null; // admin sees all
    }
}

/**
 * Recompute $_SESSION['user_orgs'] for a readonly user: their directly
 * assigned orgs (user_organizations) plus every member org of any council
 * they are assigned to (see council_member_org_ids()).
 *
 * Every access check in the app — can_access_org(), allowed_orgs(),
 * org_access_sql() — reads only this session list, so council access needs
 * no changes anywhere else.
 */
function refresh_user_orgs(PDO $db, int $user_id): void {
    $s = $db->prepare("SELECT org_id FROM user_organizations WHERE user_id=?");
    $s->execute([$user_id]);
    $direct = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

    $_SESSION['user_orgs'] = array_values(array_unique(
        array_merge($direct, council_member_org_ids($db, $direct))
    ));
}

/**
 * Given a set of organization IDs, return the IDs of all member organizations
 * of any council among them.
 *
 * Each council exists twice in the data: as a row in library_councils (what
 * member orgs point at via organizations.council_id) and as an ordinary row
 * in organizations (so the council can have its own packages and users can be
 * assigned to it on the Users page). The two rows are matched by loc_code
 * (e.g. NSYCNL = Central New York Library Resources Council), falling back to
 * an exact name match in case the organizations row was entered with a
 * different code.
 *
 * So a user assigned to the "Central New York Library Resources Council"
 * organization can see every org whose Library Council is set to Central New
 * York on the Organizations page. Orgs with no council assigned (council_id
 * NULL) are never included.
 *
 * Returns [] rather than throwing if the council tables are missing (schema
 * not yet migrated) — login must keep working with direct assignments only.
 */
function council_member_org_ids(PDO $db, array $org_ids): array {
    try {
        $council_ids = council_ids_for_orgs($db, $org_ids);
        if (!$council_ids) return [];
        $ph = implode(',', array_fill(0, count($council_ids), '?'));
        $s  = $db->prepare("SELECT id FROM organizations WHERE council_id IN ($ph)");
        $s->execute($council_ids);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('council_member_org_ids: ' . $e->getMessage());
        return [];
    }
}

/**
 * Which library_councils rows (by id) the given organizations ARE — i.e. which
 * of them are a council's own organization row. Matched by loc_code, or by
 * exact name, case-insensitively and ignoring stray whitespace.
 *
 * The comparison is done here in PHP rather than as a SQL join on purpose:
 * organizations and library_councils were created at different times, and if
 * their text columns ended up with different collations MySQL refuses the
 * comparison outright ("Illegal mix of collations") — which would silently
 * turn council access off for everyone.
 *
 * Throws PDOException if library_councils doesn't exist; callers catch it.
 */
function council_ids_for_orgs(PDO $db, array $org_ids): array {
    if (!$org_ids) return [];
    $ph = implode(',', array_fill(0, count($org_ids), '?'));
    $s  = $db->prepare("SELECT loc_code, name FROM organizations WHERE id IN ($ph)");
    $s->execute(array_values($org_ids));
    $orgs = $s->fetchAll(PDO::FETCH_ASSOC);

    $norm = fn($v) => strtolower(trim((string)$v));
    $ids  = [];
    foreach ($db->query("SELECT id, loc_code, name FROM library_councils")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        foreach ($orgs as $o) {
            if (($norm($c['loc_code']) !== '' && $norm($c['loc_code']) === $norm($o['loc_code']))
                || ($norm($c['name']) !== '' && $norm($c['name']) === $norm($o['name']))) {
                $ids[] = (int)$c['id'];
                break;
            }
        }
    }
    return $ids;
}
