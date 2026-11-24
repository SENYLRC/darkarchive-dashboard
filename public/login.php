<?php
/**
 * Login form. Verifies username/password and reCAPTCHA, then starts the
 * authenticated session. Already-logged-in users are sent to index.php.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    configure_session();
    session_start();
}
send_security_headers();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recaptcha.php';

$error    = '';
$redirect = $_GET['redirect'] ?? 'index.php';

// Post/Redirect/Get: an error stashed by the previous POST is shown once here.
if (!empty($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// Refuse to attempt a sign-in at all when the database is unreachable. Doing
// this before verify_recaptcha() means we never spend a reCAPTCHA token on a
// request that cannot possibly succeed.
$db_ok = db_available();
if (!$db_ok) {
    $error = 'Sign-in is unavailable — the database cannot be reached. Please contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token    = $_POST['recaptcha_token'] ?? '';
    $fail     = '';
    $reason   = '';

    if (!$db_ok) {
        $fail = 'Sign-in is unavailable — the database cannot be reached. Please contact an administrator.';
    } elseif (!$username || !$password) {
        $fail = 'Please enter your username and password.';
    } elseif (!verify_recaptcha($token, 'LOGIN', $reason)) {
        $fail = match ($reason) {
            // Stale token (expired / already used): not the user's fault.
            'retry' => 'Your security check expired. Please sign in again.',
            // Server-side misconfiguration — do not blame the user's browser.
            'config', 'autoload', 'error' =>
                'Sign-in is temporarily unavailable. Please contact an administrator.',
            default => 'reCAPTCHA verification failed. Please try again.',
        };
    } else {
        try {
            $db   = db();
            $stmt = $db->prepare("SELECT id, password_hash, active FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $fail = 'Invalid username or password.';
            } elseif (!$user['active']) {
                $fail = 'Your account has been disabled. Contact an administrator.';
            } else {
                load_user_session($db, $user['id']);
                session_regenerate_id(true);
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                header("Location: " . safe_redirect($redirect, 'index.php'));
                exit;
            }
        } catch (PDOException $e) {
            // Previously escaped as an uncaught fatal, so the browser got a bare
            // 500 and the already-spent reCAPTCHA token was replayed on retry.
            error_log('Login: database error — ' . $e->getMessage());
            $fail = 'Could not reach the database. Please contact an administrator.';
        }
    }

    // Redirect after every failed POST so a refresh cannot resubmit the spent token.
    $_SESSION['login_error'] = $fail;
    header('Location: login.php?redirect=' . urlencode($redirect), true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Dark Archive</title>
<script src="https://www.google.com/recaptcha/enterprise.js?render=<?= RECAPTCHA_SITE_KEY ?>"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #0f1117; --surface: #1a1f2e; --surface2: #232838;
    --border: rgba(181,130,56,.8); --amber: #b5821d; --amber-l: #d4a435;
    --amber-p: #fac775; --text: #e2e8f0; --muted: #94a3b8;
    --red: #f87171; --radius: 8px;
}
body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg);
    color: var(--text); min-height: 100vh; display: flex;
    align-items: center; justify-content: center; }
.box { background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 40px; width: 100%; max-width: 400px; }
.logo { text-align: center; margin-bottom: 32px; }
.logo-title { font-size: 22px; font-weight: 700; color: var(--amber-p); }
.logo-sub   { font-size: 12px; color: var(--muted); margin-top: 4px; }
label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
input[type=text], input[type=password] {
    width: 100%; padding: 10px 12px; background: var(--surface2);
    border: 1px solid var(--border); border-radius: 6px; color: var(--text);
    font-size: 14px; margin-bottom: 16px; transition: border-color .15s; }
input:focus { outline: none; border-color: var(--amber); }
button { width: 100%; padding: 11px; background: var(--amber); color: #0f1117;
    border: none; border-radius: 6px; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: background .15s; margin-top: 4px; }
button:hover { background: var(--amber-l); }
button:disabled { opacity: .6; cursor: not-allowed; }
.error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
    color: var(--red); padding: 10px 14px; border-radius: 6px;
    font-size: 13px; margin-bottom: 20px; }
.recaptcha-note { font-size: 11px; color: var(--muted); text-align: center;
    margin-top: 14px; line-height: 1.5; }
.recaptcha-note a { color: var(--muted); }
</style>
</head>
<body>
<div class="box">
    <div class="logo">
        <div class="logo-title">Dark Archive</div>
        <div class="logo-sub">SENYLRC — XML Package Manager</div>
    </div>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (($_GET['error'] ?? '') === 'disabled'): ?>
    <div class="error">Your account has been disabled.</div>
    <?php endif; ?>

    <form method="post" id="login-form">
        <?= csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <input type="hidden" name="recaptcha_token" id="recaptcha_token" autocomplete="off">
        <label for="username">Username or Email</label>
        <input type="text" name="username" id="username" autofocus autocomplete="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"<?= $db_ok ? '' : ' disabled' ?>>
        <label for="password">Password</label>
        <input type="password" name="password" id="password" autocomplete="current-password"<?= $db_ok ? '' : ' disabled' ?>>
        <div style="text-align:right;margin-top:-10px;margin-bottom:16px;">
            <a href="reset_password.php" style="font-size:12px;color:var(--muted)">Forgot password?</a>
        </div>
        <button type="submit" id="submit-btn"<?= $db_ok ? '' : ' disabled' ?>>Sign In</button>
    </form>

    <div class="recaptcha-note">
        Protected by reCAPTCHA —
        <a href="https://policies.google.com/privacy" target="_blank">Privacy</a> &amp;
        <a href="https://policies.google.com/terms" target="_blank">Terms</a>
    </div>
</div>

<script>
// On back/forward (bfcache) restore, Firefox and Safari repopulate the hidden
// field with the token from the previous submit. Reusing it makes Google return
// DUPE. Always clear it and restore the button.
window.addEventListener('pageshow', function () {
    document.getElementById('recaptcha_token').value = '';
<?php if ($db_ok): ?>
    const btn = document.getElementById('submit-btn');
    btn.disabled = false;
    btn.textContent = 'Sign In';
<?php endif; ?>
});

document.getElementById('login-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Verifying…';

    grecaptcha.enterprise.ready(async () => {
        try {
            const token = await grecaptcha.enterprise.execute(
                '<?= RECAPTCHA_SITE_KEY ?>',
                { action: 'LOGIN' }
            );
            document.getElementById('recaptcha_token').value = token;
            document.getElementById('login-form').submit();
        } catch (err) {
            btn.disabled = false;
            btn.textContent = 'Sign In';
            alert('reCAPTCHA error. Please refresh and try again.');
        }
    });
});
</script>
</body>
</html>
