<?php
/**
 * SafeSpace Security Middleware
 * Provides CSRF protection, input sanitization, and security headers.
 * Include at the top of any page that handles user input.
 */

// ─── Security Headers ─────────────────────────────────────────────────────────
function setSecurityHeaders(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
}

// ─── CSRF Protection ──────────────────────────────────────────────────────────
function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Use in any form handler to validate the CSRF token.
 * On failure, sends 403 and exits.
 */
function requireCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        } else {
            echo '<p style="font-family:sans-serif;color:red;padding:2rem;">Security token mismatch. Please <a href="javascript:history.back()">go back</a> and try again.</p>';
        }
        exit;
    }
}

// ─── Input Sanitization ───────────────────────────────────────────────────────
/**
 * Sanitize a single string value.
 */
function sanitizeString(string $input, int $maxLength = 1000): string {
    $input = trim($input);
    $input = strip_tags($input);
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr($input, 0, $maxLength, 'UTF-8');
}

/**
 * Sanitize an integer value.
 */
function sanitizeInt($input, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int {
    $val = filter_var($input, FILTER_VALIDATE_INT);
    if ($val === false) return $min;
    return max($min, min($max, $val));
}

/**
 * Sanitize an email address.
 */
function sanitizeEmail(string $input): string {
    return filter_var(trim($input), FILTER_SANITIZE_EMAIL) ?: '';
}

/**
 * Recursively sanitize an entire array (e.g. $_POST).
 */
function sanitizeArray(array $data, int $maxLength = 1000): array {
    $clean = [];
    foreach ($data as $key => $value) {
        $cleanKey = sanitizeString((string)$key, 100);
        if (is_array($value)) {
            $clean[$cleanKey] = sanitizeArray($value, $maxLength);
        } else {
            $clean[$cleanKey] = sanitizeString((string)$value, $maxLength);
        }
    }
    return $clean;
}

// ─── Password Validation ──────────────────────────────────────────────────────
function validatePassword(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'Password must contain at least one letter.';
    }
    return $errors;  // Empty = valid
}

// ─── Rate Limiting (session-based) ───────────────────────────────────────────
/**
 * Limit actions to $maxAttempts per $windowSeconds.
 * Returns true if allowed, false if rate-limited.
 */
function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $now = time();
    $sessionKey = 'rate_limit_' . $key;

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = ['count' => 0, 'window_start' => $now];
    }

    $rl = &$_SESSION[$sessionKey];

    // Reset window if expired
    if ($now - $rl['window_start'] > $windowSeconds) {
        $rl = ['count' => 0, 'window_start' => $now];
    }

    $rl['count']++;

    return $rl['count'] <= $maxAttempts;
}

// ─── Auto-apply security headers ─────────────────────────────────────────────
setSecurityHeaders();
