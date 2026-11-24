<?php
/**
 * Password reset flow. mode=request emails a one-time reset link (reCAPTCHA
 * protected); mode=reset with a valid token lets the user set a new password.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/recaptcha.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    configure_session();
    session_start();
}
send_security_headers();

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// SITE_URL and FROM_EMAIL are defined in includes/mailer.php (loaded from .env)

$mode  = $_GET['mode'] ?? 'request'; // request | reset
$token = $_GET['token'] ?? '';
$msg   = '';
$error = '';

try {
    $db = db();
} catch (PDOException $e) {
    error_log('Password reset: database error — ' . $e->getMessage());
    $db    = null;
    $error = 'This service is temporarily unavailable. Please contact an administrator.';
    $mode  = 'dberror';
}

// Post/Redirect/Get: show the result of the previous request-mode POST once.
if ($mode === 'request' && !empty($_SESSION['reset_flash'])) {
    $flash = $_SESSION['reset_flash'];
    unset($_SESSION['reset_flash']);
    $error = $flash['error'] ?? '';
    $msg   = $flash['msg']   ?? '';
    if (!empty($flash['mode'])) $mode = $flash['mode'];
}

// ── Verify reCAPTCHA token ────────────────────────────────────────────────────
// verify_recaptcha() is provided by includes/recaptcha.php

// ── MODE: reset (user clicked link in email) ──────────────────────────────────
if ($mode === 'reset' && $token) {
    $stmt = $db->prepare("
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.email, u.username
        FROM password_resets pr JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset || $reset['used'] || strtotime($reset['expires_at']) < time()) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
        $mode  = 'expired';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($new_pass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $reset['user_id']]);
            $db->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$reset['id']]);
            email_password_changed($reset['email'], $reset['username'], 'password reset link');
            $msg  = 'Your password has been reset. You can now sign in.';
            $mode = 'done';
        }
    }
}

// ── MODE: request (form submission) ──────────────────────────────────────────
if ($mode === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email       = trim($_POST['email'] ?? '');
    $recap_token = $_POST['recaptcha_token'] ?? '';
    $reason      = '';
    $flash       = [];

    if (!$email) {
        $flash['error'] = 'Please enter your email address.';
    } elseif (!verify_recaptcha($recap_token, 'RESET_PASSWORD', $reason)) {
        $flash['error'] = match ($reason) {
            'retry' => 'Your security check expired. Please try again.',
            'config', 'autoload', 'error' =>
                'This service is temporarily unavailable. Please contact an administrator.',
            default => 'reCAPTCHA verification failed. Please try again.',
        };
    } else {
        try {
            // Always show the same message even if the email is unknown (prevents enumeration)
            $user = $db->prepare("SELECT id, username FROM users WHERE email=? AND active=1");
            $user->execute([$email]);
            $u = $user->fetch();

            if ($u) {
                // Invalidate any previous unused tokens for this user
                $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$u['id']]);

                // Generate new token
                $tok     = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
                   ->execute([$u['id'], $tok, $expires]);

                $reset_url = SITE_URL . '/reset_password.php?mode=reset&token=' . $tok;
                email_password_reset_requested($email, $u['username'], $reset_url);
            }

            $flash['msg']  = 'If that email address is in our system, you will receive a reset link shortly.';
            $flash['mode'] = 'sent';
        } catch (PDOException $e) {
            error_log('Password reset: database error — ' . $e->getMessage());
            $flash['error'] = 'Could not reach the database. Please contact an administrator.';
        }
    }

    // Redirect so a refresh cannot resend the email or replay the spent token.
    $_SESSION['reset_flash'] = $flash;
    header('Location: reset_password.php', true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password — Dark Archive</title>
<script src="https://www.google.com/recaptcha/enterprise.js?render=<?= RECAPTCHA_SITE_KEY ?>"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #0f1117; --surface: #1a1f2e; --surface2: #232838;
    --border: rgba(181,130,56,.8); --amber: #b5821d; --amber-l: #d4a435;
    --amber-p: #fac775; --text: #e2e8f0; --muted: #94a3b8;
    --red: #f87171; --green: #22c55e; --radius: 8px;
}
body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg);
    color: var(--text); min-height: 100vh; display: flex;
    align-items: center; justify-content: center; }
.box { background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 40px; width: 100%; max-width: 400px; }
.logo { text-align: center; margin-bottom: 28px; }
.logo-title { font-size: 22px; font-weight: 700; color: var(--amber-p); }
.logo-sub   { font-size: 12px; color: var(--muted); margin-top: 4px; }
h2 { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
p  { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.6; }
label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
input[type=text], input[type=email], input[type=password] {
    width: 100%; padding: 10px 12px; background: var(--surface2);
    border: 1px solid var(--border); border-radius: 6px; color: var(--text);
    font-size: 14px; margin-bottom: 16px; transition: border-color .15s; }
input:focus { outline: none; border-color: var(--amber); }
.btn { width: 100%; padding: 11px; background: var(--amber); color: #0f1117;
    border: none; border-radius: 6px; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: background .15s; }
.btn:hover { background: var(--amber-l); }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; }
.alert-error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: var(--red); }
.alert-success { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.3);  color: var(--green); }
.back { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: var(--muted); }
.back a { color: var(--amber-l); }
#match-msg { font-size:12px; margin-top:-10px; margin-bottom:12px; }
.recaptcha-note { font-size: 11px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.5; }
.recaptcha-note a { color: var(--muted); }
</style>
</head>
<body>
<div class="box">
    <div class="logo">
        <div class="logo-title">Dark Archive</div>
        <div class="logo-sub">SENYLRC — XML Package Manager</div>
    </div>

<?php if ($mode === 'request'): ?>
    <h2>Reset Your Password</h2>
    <p>Enter your email address and we'll send you a link to reset your password.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" id="reset-form">
        <?= csrf_field() ?>
        <input type="hidden" name="recaptcha_token" id="recaptcha_token" autocomplete="off">
        <label for="email">Email Address</label>
        <input type="text" name="email" id="email" autofocus autocomplete="email"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <button type="submit" class="btn" id="submit-btn">Send Reset Link</button>
    </form>
    <div class="recaptcha-note">
        Protected by reCAPTCHA —
        <a href="https://policies.google.com/privacy" target="_blank">Privacy</a> &amp;
        <a href="https://policies.google.com/terms" target="_blank">Terms</a>
    </div>

<?php elseif ($mode === 'sent'): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>
    <p>Check your inbox — the link expires in 1 hour.</p>

<?php elseif ($mode === 'reset'): ?>
    <h2>Choose a New Password</h2>
    <p>Enter a new password for your account.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label for="new-pass">New Password <span style="color:var(--muted);font-size:11px">(min 8 characters)</span></label>
        <input type="password" name="new_password" id="new-pass" required autocomplete="new-password">
        <label for="confirm-pass">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm-pass" required autocomplete="new-password">
        <div id="match-msg"></div>
        <button type="submit" class="btn">Reset Password</button>
    </form>

<?php elseif ($mode === 'done'): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>

<?php elseif ($mode === 'expired'): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>

<?php elseif ($mode === 'dberror'): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

    <div class="back"><a href="login.php">← Back to Sign In</a></div>
</div>

<script>
// reCAPTCHA for request form
<?php if ($mode === 'request'): ?>
// A bfcache restore repopulates the hidden field with the previous token;
// resubmitting it makes Google return DUPE. Clear it and restore the button.
window.addEventListener('pageshow', function () {
    document.getElementById('recaptcha_token').value = '';
    const btn = document.getElementById('submit-btn');
    btn.disabled = false;
    btn.textContent = 'Send Reset Link';
});

document.getElementById('reset-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Verifying…';
    grecaptcha.enterprise.ready(async () => {
        try {
            const token = await grecaptcha.enterprise.execute(
                '<?= RECAPTCHA_SITE_KEY ?>',
                { action: 'RESET_PASSWORD' }
            );
            document.getElementById('recaptcha_token').value = token;
            document.getElementById('reset-form').submit();
        } catch(err) {
            btn.disabled = false;
            btn.textContent = 'Send Reset Link';
            alert('reCAPTCHA error. Please refresh and try again.');
        }
    });
});
<?php endif; ?>

// Password match indicator
<?php if ($mode === 'reset'): ?>
const np = document.getElementById('new-pass');
const cp = document.getElementById('confirm-pass');
const mm = document.getElementById('match-msg');
function checkMatch() {
    if (!cp.value) { mm.textContent=''; return; }
    if (np.value === cp.value) {
        mm.style.color='#22c55e'; mm.textContent='✓ Passwords match';
    } else {
        mm.style.color='#f87171'; mm.textContent='✗ Passwords do not match';
    }
}
np.addEventListener('input', checkMatch);
cp.addEventListener('input', checkMatch);
<?php endif; ?>
</script>
</body>
</html>
