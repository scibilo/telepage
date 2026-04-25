<?php
/**
 * TELEPAGE — tests/CsrfGuardTest.php
 *
 * Minimal smoke tests. Run with: php tests/CsrfGuardTest.php
 * Exits with code 0 on success, non-zero on any failure.
 *
 * This is a placeholder until we introduce PHPUnit in Phase C. Keeping
 * it dependency-free means you can run it on any PHP 8.1+ install.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$failures = 0;
function assertTrue(bool $cond, string $label): void {
    global $failures;
    if ($cond) {
        echo "  ✓ {$label}\n";
    } else {
        echo "  ✗ {$label}\n";
        $failures++;
    }
}

function resetRequest(): void {
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
}

echo "CsrfGuard tests\n";

// --- GET is always allowed ---
resetRequest();
$_SERVER['REQUEST_METHOD'] = 'GET';
assertTrue(CsrfGuard::isValid() === true, 'GET request is allowed without token');

// --- HEAD is always allowed ---
resetRequest();
$_SERVER['REQUEST_METHOD'] = 'HEAD';
assertTrue(CsrfGuard::isValid() === true, 'HEAD request is allowed without token');

// --- POST without token in session → rejected ---
resetRequest();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'whatever';
assertTrue(CsrfGuard::isValid() === false, 'POST rejected when session has no token');

// --- POST without header → rejected ---
resetRequest();
$_SESSION['csrf_token'] = 'abcdef0123456789';
$_SERVER['REQUEST_METHOD'] = 'POST';
assertTrue(CsrfGuard::isValid() === false, 'POST rejected when header missing');

// --- POST with wrong header → rejected ---
resetRequest();
$_SESSION['csrf_token'] = 'abcdef0123456789';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'abcdef0123456788'; // off-by-one
assertTrue(CsrfGuard::isValid() === false, 'POST rejected when header differs by one char');

// --- POST with correct header → accepted ---
resetRequest();
$_SESSION['csrf_token'] = 'abcdef0123456789';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'abcdef0123456789';
assertTrue(CsrfGuard::isValid() === true, 'POST accepted when header matches session');

// --- method matching is case-insensitive ---
resetRequest();
$_SESSION['csrf_token'] = 'tok';
$_SERVER['REQUEST_METHOD'] = 'post';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
assertTrue(CsrfGuard::isValid() === true, 'Lowercase method name handled');

// --- DELETE also checked ---
resetRequest();
$_SESSION['csrf_token'] = 'tok';
$_SERVER['REQUEST_METHOD'] = 'DELETE';
assertTrue(CsrfGuard::isValid() === false, 'DELETE checked even without header');

// --- token() reads session, empty string fallback ---
$_SESSION = [];
assertTrue(CsrfGuard::token() === '', 'token() returns empty string when session unset');
$_SESSION['csrf_token'] = 'x';
assertTrue(CsrfGuard::token() === 'x', 'token() returns session value');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} test(s)\n";
    exit(1);
}
echo "All tests passed\n";
exit(0);
