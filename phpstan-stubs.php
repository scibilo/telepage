<?php

/**
 * PHPStan stubs for runtime-defined constants.
 *
 * Every public entry-point (index.php, api/*.php, admin/*.php,
 * install/index.php, bin/*.php) does `define('TELEPAGE_ROOT', ...)`
 * before loading anything in app/. PHPStan analyses files in app/
 * in isolation, so it can't know about the constant. This stub tells
 * it the constant exists and is a string.
 *
 * NEVER required at runtime — PHPStan reads it for type info only.
 */

declare(strict_types=1);

if (!defined('TELEPAGE_ROOT')) {
    define('TELEPAGE_ROOT', '');
}
