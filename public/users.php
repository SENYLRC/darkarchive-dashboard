<?php
/**
 * Admin-only user management: create users, set roles and organization
 * access, and send set-password emails.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

$db   = db();
$msg  = '';
$err  = '';
$orgs = $db->query("SELECT id, name, loc_code FROM organizations ORDER BY name")->fetchAll();

// ── Add user ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

/**
 * Email a user their welcome message with a fresh one-time "set your password"
 * link (reuses the password_resets flow). Any earlier unused link for the user
 * is cancelled, so only the newest email works. Used on create and by the
 * "Resend invite" button.
 */
function send_welcome_email(PDO $db, int $user_id): void {
    $u = $db->prepare("SELECT username, email, role FROM users WHERE id=?");
    $u->execute([$user_id]);
    $u = $u->fetch();
    if (!$u) return;

    $org_names = [];
    if ($u['role'] === 'readonly') {
        $os = $db->prepare("SELECT o.name FROM user_organizations uo JOIN organizations o ON o.id = uo.org_id
                            WHERE uo.user_id=? ORDER BY o.name");
        $os->execute([$user_id]);
        $org_names = $os->fetchAll(PDO::FETCH_COLUMN);
    }

    $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$user_id]);
    $tok = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
       ->execute([$user_id, $tok, date('Y-m-d H:i:s', strtotime('+' . SET_PASSWORD_LINK_DAYS . ' days'))]);
    $set_url = SITE_URL . '/reset_password.php?mode=reset&token=' . $tok;
    email_account_created($u['email'], $u['username'], $u['role'], $org_names, $set_url);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] === 'admin' ? 'admin' : 'readonly';
    $org_ids  = array_map('intval', $_POST['org_ids'] ?? []);

    if (!$username || !$email || !$password) {
        $err = 'Username, email, and password are required.';
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $username)) {
        // Rejects control characters (incl. CR/LF) — the username later goes
        // into an email Subject line unescaped, where PHP's mail() doesn't
        // strip them, so a bare CRLF here would be a header-injection vector.
        $err = 'Username cannot contain control characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address does not look valid.';
    } elseif (strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?,?,?,?)")
               ->execute([$username, $email, $hash, $role]);
            $user_id = (int)$db->lastInsertId();
            if ($role === 'readonly' && $org_ids) {
                $ins = $db->prepare("INSERT IGNORE INTO user_organizations (user_id, org_id) VALUES (?,?)");
                foreach ($org_ids as $oid) $ins->execute([$user_id, $oid]);
            }

            send_welcome_email($db, $user_id);

            $msg = "User \"$username\" created and notified by email.";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $err = 'Username or email already exists.';
            } else {
                error_log('users.php add: ' . $e->getMessage());
                $err = 'Could not create the user. Check the application log for details.';
            }
        }
    }
}

// ── Edit user ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $uid      = (int)$_POST['edit_id'];
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = $_POST['role'] === 'admin' ? 'admin' : 'readonly';
    $active   = isset($_POST['active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $org_ids  = array_map('intval', $_POST['org_ids'] ?? []);

    if (!$username || !$email) {
        $err = 'Username and email are required.';
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $username)) {
        $err = 'Username cannot contain control characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address does not look valid.';
    } else {
        try {
            if ($password) {
                if (strlen($password) < 8) {
                    $err = 'Password must be at least 8 characters.';
                    goto done;
                }
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET username=?,email=?,password_hash=?,role=?,active=? WHERE id=?")
                   ->execute([$username, $email, $hash, $role, $active, $uid]);
            } else {
                $db->prepare("UPDATE users SET username=?,email=?,role=?,active=? WHERE id=?")
                   ->execute([$username, $email, $role, $active, $uid]);
            }
            // Re-sync org assignments
            $db->prepare("DELETE FROM user_organizations WHERE user_id=?")->execute([$uid]);
            if ($role === 'readonly' && $org_ids) {
                $ins = $db->prepare("INSERT IGNORE INTO user_organizations (user_id, org_id) VALUES (?,?)");
                foreach ($org_ids as $oid) $ins->execute([$uid, $oid]);
            }
            $msg = "User \"$username\" updated.";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $err = 'Username or email already exists.';
            } else {
                error_log('users.php edit: ' . $e->getMessage());
                $err = 'Could not update the user. Check the application log for details.';
            }
        }
        done:
    }
}

// ── Resend welcome email ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $uid = (int)$_POST['resend_id'];
    try {
        send_welcome_email($db, $uid);
        $who = $db->prepare("SELECT email FROM users WHERE id=?");
        $who->execute([$uid]);
        $msg = 'Welcome email with a new set-password link sent to ' . ($who->fetchColumn() ?: 'the user') . '.';
    } catch (PDOException $e) {
        error_log('users.php resend: ' . $e->getMessage());
        $err = 'Could not resend the welcome email. Check the application log for details.';
    }
}

// ── Delete user ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $uid = (int)$_POST['del_id'];
    if ($uid === (int)$_SESSION['user_id']) {
        $err = 'You cannot delete your own account.';
    } else {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        $msg = 'User deleted.';
    }
}

// ── Load users ────────────────────────────────────────────────────────────────
$users = $db->query("SELECT * FROM users ORDER BY role, username")->fetchAll();
$user_orgs = [];
foreach ($db->query("SELECT user_id, org_id FROM user_organizations") as $r) {
    $user_orgs[$r['user_id']][] = $r['org_id'];
}

// Councils each user is assigned to, with how many member orgs that grants.
// Uses the same council_ids_for_orgs() as the real access check
// (includes/auth.php), so the list shown here is exactly the extra access the
// user gets.
$user_councils = [];
try {
    $councils_by_id = [];
    foreach ($db->query("
        SELECT c.id, c.loc_code, c.name, COUNT(o.id) AS members
        FROM library_councils c LEFT JOIN organizations o ON o.council_id = c.id
        GROUP BY c.id, c.loc_code, c.name
    ") as $c) $councils_by_id[(int)$c['id']] = $c;
    foreach ($user_orgs as $uid => $oids) {
        foreach (council_ids_for_orgs($db, $oids) as $cid) {
            if (isset($councils_by_id[$cid])) $user_councils[$uid][] = $councils_by_id[$cid];
        }
    }
} catch (PDOException $e) {
    error_log('users.php council summary: ' . $e->getMessage());
}

$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_user = null;
if ($edit_id) {
    foreach ($users as $u) {
        if ($u['id'] === $edit_id) { $edit_user = $u; break; }
    }
}

page_header('User Management', 'users.php');
?>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

<div class="card">
    <h2>All Users</h2>
    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Organizations</th>
                <th>Status</th>
                <th>Last Login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td style="font-weight:500"><?= htmlspecialchars($u['username']) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></td>
            <td>
                <?php if ($u['role'] === 'admin'): ?>
                    <span class="badge" style="background:rgba(250,199,117,.15);color:var(--amber-p);border:1px solid var(--border)">Admin</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(100,116,139,.15);color:var(--muted);border:1px solid var(--border)">Read-only</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px">
                <?php if ($u['role'] === 'admin'): ?>
                    <span style="color:var(--muted)">All orgs</span>
                <?php else: ?>
                    <?php foreach ($user_orgs[$u['id']] ?? [] as $oid): ?>
                        <?php $o = array_filter($orgs, fn($x) => $x['id'] == $oid); $o = reset($o); ?>
                        <?php if ($o): ?><span class="badge badge-org" style="margin:1px"><?= htmlspecialchars($o['loc_code']) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($user_orgs[$u['id']])): ?><span style="color:var(--muted)">None</span><?php endif; ?>
                    <?php foreach ($user_councils[$u['id']] ?? [] as $c): ?>
                        <div style="margin-top:3px;color:var(--muted)" title="<?= htmlspecialchars($c['name']) ?>">
                            Council <?= htmlspecialchars($c['loc_code']) ?>: +<?= (int)$c['members'] ?> member org<?= $c['members'] == 1 ? '' : 's' ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['active']): ?>
                    <span style="color:#22c55e;font-size:12px">● Active</span>
                <?php else: ?>
                    <span style="color:var(--red);font-size:12px">● Disabled</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--muted);white-space:nowrap">
                <?= $u['last_login'] ? date('M j, Y', strtotime($u['last_login'])) : 'Never' ?>
            </td>
            <td style="white-space:nowrap">
                <div style="display:flex;gap:6px;">
                <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-ghost">Edit</a>
                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                <form method="post" class="resend-user-form" data-email="<?= htmlspecialchars($u['email']) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="resend_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost" title="Email a new set-password link">Resend invite</button>
                </form>
                <form method="post" class="delete-user-form" data-username="<?= htmlspecialchars($u['username']) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="del_id" value="<?= $u['id'] ?>">
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
<?php if ($edit_user): ?>
<div class="card">
    <h2>Edit User</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" value="<?= $edit_user['id'] ?>">
        <div class="form-group">
            <label for="edit-username">Username *</label>
            <input type="text" name="username" id="edit-username" value="<?= htmlspecialchars($edit_user['username']) ?>" required>
        </div>
        <div class="form-group">
            <label for="edit-email">Email *</label>
            <input type="text" name="email" id="edit-email" value="<?= htmlspecialchars($edit_user['email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="edit-password">New Password <span style="color:var(--muted);font-size:11px">(leave blank to keep current)</span></label>
            <input type="password" name="password" id="edit-password" placeholder="Min 8 characters">
        </div>
        <div class="form-group">
            <label for="edit-role">Role *</label>
            <select name="role" id="edit-role" onchange="toggleOrgEdit(this.value)">
                <option value="readonly" <?= $edit_user['role']==='readonly'?'selected':'' ?>>Read-only</option>
                <option value="admin"    <?= $edit_user['role']==='admin'   ?'selected':'' ?>>Admin</option>
            </select>
        </div>
        <div class="form-group" id="edit-org-group" style="<?= $edit_user['role']==='admin'?'display:none':'' ?>">
            <label for="edit-org-ids">Organizations (Ctrl+click, or Shift+Arrow keys, for multiple)</label>
            <select name="org_ids[]" id="edit-org-ids" multiple style="height:120px">
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>"
                        <?= in_array($o['id'], $user_orgs[$edit_user['id']] ?? []) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:4px;font-size:11px;color:var(--muted)">Choosing a council also gives access to every member org of that council.</div>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="active" value="1" <?= $edit_user['active']?'checked':'' ?> style="width:auto;margin:0">
                Account active
            </label>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="users.php" class="btn btn-ghost btn-sm">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h2>Add User</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <label for="add-username">Username *</label>
            <input type="text" name="username" id="add-username" required placeholder="e.g. jsmith">
        </div>
        <div class="form-group">
            <label for="add-email">Email *</label>
            <input type="text" name="email" id="add-email" required placeholder="user@example.org">
        </div>
        <div class="form-group">
            <label for="add-password">Password * <span style="color:var(--muted);font-size:11px">(not emailed — the user gets a link to choose their own)</span></label>
            <input type="password" name="password" id="add-password" required placeholder="Min 8 characters">
        </div>
        <div class="form-group">
            <label for="add-role">Role *</label>
            <select name="role" id="add-role" onchange="toggleOrgAdd(this.value)">
                <option value="readonly">Read-only</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="form-group" id="add-org-group">
            <label for="add-org-ids">Organizations (Ctrl+click, or Shift+Arrow keys, for multiple)</label>
            <select name="org_ids[]" id="add-org-ids" multiple style="height:120px">
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= $o['id'] ?>">
                        [<?= htmlspecialchars($o['loc_code']) ?>] <?= htmlspecialchars($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:4px;font-size:11px;color:var(--muted)">Admins have access to all orgs automatically. Choosing a council (e.g. Central New York Library Resources Council) also gives access to every member org of that council.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create User</button>
    </form>
</div>
<?php endif; ?>
</div>

</div>

<script>
function toggleOrgAdd(role) {
    document.getElementById('add-org-group').style.display = role === 'admin' ? 'none' : '';
}
function toggleOrgEdit(role) {
    document.getElementById('edit-org-group').style.display = role === 'admin' ? 'none' : '';
}

// Read the username from a data attribute (browser HTML-decodes it, but never
// re-parses it as code) rather than interpolating it into an inline onsubmit
// string — a username containing a stray quote could otherwise break out of
// the JS string and run arbitrary script in another admin's session.
document.querySelectorAll('.resend-user-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!confirm('Email a new set-password link to ' + form.dataset.email + '? Any earlier link stops working.')) e.preventDefault();
    });
});
document.querySelectorAll('.delete-user-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!confirm('Delete user ' + form.dataset.username + '?')) e.preventDefault();
    });
});
</script>

<?php page_footer(); ?>
