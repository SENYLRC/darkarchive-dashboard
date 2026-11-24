<?php
/**
 * The logged-in user's own profile: update email and change password.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/mailer.php';
require_login();

$db  = db();
$uid = (int)$_SESSION['user_id'];

$msg = '';
$err = '';

// Load current user
$stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

// ── Update email ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email') {
    $new_email = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';

    $s = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $s->execute([$uid]);
    $hash = $s->fetchColumn();

    if (!$new_email || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } elseif (!$password) {
        $err = 'Please enter your current password to confirm.';
    } elseif (!password_verify($password, $hash)) {
        $err = 'Current password is incorrect.';
    } else {
        try {
            $db->prepare("UPDATE users SET email=? WHERE id=?")->execute([$new_email, $uid]);
            $_SESSION['user_email'] = $new_email;
            $user['email'] = $new_email;
            $msg = 'Email address updated successfully.';
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $err = 'That email address is already in use.';
            } else {
                error_log('profile.php email update: ' . $e->getMessage());
                $err = 'Could not update your email. Check the application log for details.';
            }
        }
    }
}

// ── Update password ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $current  = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $s = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $s->execute([$uid]);
    $hash = $s->fetchColumn();

    if (!$current || !$new_pass || !$confirm) {
        $err = 'All password fields are required.';
    } elseif (!password_verify($current, $hash)) {
        $err = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $err = 'New passwords do not match.';
    } elseif (password_verify($new_pass, $hash)) {
        $err = 'New password must be different from your current password.';
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$new_hash, $uid]);
        email_password_changed($user['email'], $user['username'], 'profile settings');
        $msg = 'Password updated successfully.';
    }
}

page_header('My Profile', 'profile.php');
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error">✗ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:860px;">

<!-- Account Info -->
<div class="card">
    <h2>Account Info</h2>
    <table>
        <tr>
            <td style="color:var(--muted);font-size:13px;width:110px">Username</td>
            <td style="font-weight:500"><?= htmlspecialchars($user['username']) ?></td>
        </tr>
        <tr>
            <td style="color:var(--muted);font-size:13px">Email</td>
            <td><?= htmlspecialchars($user['email']) ?></td>
        </tr>
        <tr>
            <td style="color:var(--muted);font-size:13px">Role</td>
            <td>
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="badge" style="background:rgba(250,199,117,.15);color:var(--amber-p);border:1px solid var(--border)">Admin</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(100,116,139,.15);color:var(--muted);border:1px solid var(--border)">Read-only</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($user['role'] === 'readonly'): ?>
        <tr>
            <td style="color:var(--muted);font-size:13px;vertical-align:top;padding-top:12px">Organizations</td>
            <td style="padding-top:12px">
                <?php
                $uo = $db->prepare("
                    SELECT o.name, o.loc_code FROM organizations o
                    JOIN user_organizations uo ON o.id = uo.org_id
                    WHERE uo.user_id = ? ORDER BY o.loc_code
                ");
                $uo->execute([$uid]);
                foreach ($uo->fetchAll() as $o):
                ?>
                    <span class="badge badge-org" style="margin:2px"><?= htmlspecialchars($o['loc_code']) ?></span>
                    <span style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($o['name']) ?></span><br>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<!-- Change Email -->
<div class="card">
    <h2>Change Email</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <div class="form-group">
            <label for="email">New Email Address *</label>
            <input type="text" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? $user['email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email-confirm-password">Current Password (to confirm) *</label>
            <input type="password" name="password" id="email-confirm-password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Update Email</button>
    </form>
</div>

<!-- Change Password -->
<div class="card" style="grid-column:1/-1;max-width:420px;">
    <h2>Change Password</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="form-group">
            <label for="current_password">Current Password *</label>
            <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label for="new-pass">New Password * <span style="color:var(--muted);font-size:11px">(min 8 characters)</span></label>
            <input type="password" name="new_password" required autocomplete="new-password" id="new-pass">
        </div>
        <div class="form-group">
            <label for="confirm-pass">Confirm New Password *</label>
            <input type="password" name="confirm_password" required autocomplete="new-password" id="confirm-pass">
            <div id="match-msg" style="font-size:12px;margin-top:4px;display:none"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
    </form>
</div>

</div>

<script>
// Live password match indicator
const np = document.getElementById('new-pass');
const cp = document.getElementById('confirm-pass');
const mm = document.getElementById('match-msg');
function checkMatch() {
    if (!cp.value) { mm.style.display='none'; return; }
    if (np.value === cp.value) {
        mm.style.display='block'; mm.style.color='#22c55e'; mm.textContent='✓ Passwords match';
    } else {
        mm.style.display='block'; mm.style.color='#f87171'; mm.textContent='✗ Passwords do not match';
    }
}
np.addEventListener('input', checkMatch);
cp.addEventListener('input', checkMatch);
</script>

<?php page_footer(); ?>
