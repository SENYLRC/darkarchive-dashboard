<?php
/**
 * Security helpers — include at top of every page via auth.php
 */

// ── Secure session config (call before session_start) ────────────────────────
function configure_session(): void {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure',   '1');   // HTTPS only
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime',  '28800'); // 8 hours
    ini_set('session.cookie_lifetime', '0');     // until browser close
}

// ── Security response headers ─────────────────────────────────────────────────
function send_security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; "
        . "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "font-src 'self';"
    );
}

// ── CSRF token generation and validation ──────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF token mismatch. Please go back and try again.');
    }
}

// ── CSV formula-injection defense ─────────────────────────────────────────────
/**
 * Neutralize CSV/formula injection. Values in our exports can originate from
 * externally-supplied XML metadata or free-text notes, neither of which is
 * validated for spreadsheet safety. If a cell would open with a character a
 * spreadsheet reads as a formula/DDE trigger (=, +, -, @, tab, CR), prefix it
 * with a single quote so Excel/LibreOffice treat it as literal text instead
 * of executing it when the file is opened.
 */
function csv_safe(mixed $value): mixed {
    if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
        return "'" . $value;
    }
    return $value;
}

function csv_safe_row(array $row): array {
    return array_map('csv_safe', $row);
}

// ── Safe redirect — only allow relative paths ─────────────────────────────────
function safe_redirect(string $url, string $default = 'index.php'): string {
    // Strip anything that isn't a relative path
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (
        str_starts_with($url, 'http') ||  // absolute URL
        str_starts_with($url, '//') ||    // protocol-relative
        str_starts_with($url, '\\') ||    // backslash
        str_contains($url, ':')           // any scheme
    ) {
        return $default;
    }
    return $url ?: $default;
}
