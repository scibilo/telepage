<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use CsrfGuard;

/**
 * Tests for CsrfGuard.
 *
 * Migrated from tests/CsrfGuardTest.php (kept historically until C2 —
 * this is C2). Each scenario is now its own test method so a failure
 * narrows down to the specific HTTP-method × token-state combination.
 *
 * setUp() resets $_SESSION and the relevant $_SERVER keys before
 * every test — important because PHPUnit shares process state across
 * methods and a leftover token from a previous test would silently
 * make the next one pass for the wrong reason.
 */
final class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testGetIsAllowedWithoutToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(CsrfGuard::isValid());
    }

    public function testHeadIsAllowedWithoutToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertTrue(CsrfGuard::isValid());
    }

    public function testPostRejectedWhenSessionHasNoToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'whatever';
        $this->assertFalse(CsrfGuard::isValid());
    }

    public function testPostRejectedWhenHeaderMissing(): void
    {
        $_SESSION['csrf_token'] = 'abcdef0123456789';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse(CsrfGuard::isValid());
    }

    public function testPostRejectedWhenHeaderDiffersByOneChar(): void
    {
        $_SESSION['csrf_token'] = 'abcdef0123456789';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abcdef0123456788'; // off-by-one
        $this->assertFalse(CsrfGuard::isValid());
    }

    public function testPostAcceptedWhenHeaderMatchesSession(): void
    {
        $_SESSION['csrf_token'] = 'abcdef0123456789';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abcdef0123456789';
        $this->assertTrue(CsrfGuard::isValid());
    }

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $_SESSION['csrf_token'] = 'tok';
        $_SERVER['REQUEST_METHOD'] = 'post';   // lowercase
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
        $this->assertTrue(CsrfGuard::isValid());
    }

    public function testDeleteAlsoChecked(): void
    {
        $_SESSION['csrf_token'] = 'tok';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        // No header → reject
        $this->assertFalse(CsrfGuard::isValid());
    }

    public function testTokenReturnsEmptyStringWhenSessionUnset(): void
    {
        $_SESSION = [];
        $this->assertSame('', CsrfGuard::token());
    }

    public function testTokenReturnsSessionValue(): void
    {
        $_SESSION['csrf_token'] = 'x';
        $this->assertSame('x', CsrfGuard::token());
    }
}
