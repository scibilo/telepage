<?php
/**
 * TELEPAGE — admin/logout.php
 */
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/app/Logger.php';

session_name('tp_' . substr(md5(TELEPAGE_ROOT), 0, 12));
session_start();
if (!empty($_SESSION['admin_user'])) {
    Logger::admin(Logger::INFO, 'Logout', ['username' => $_SESSION['admin_user']]);
}
session_destroy();
header('Location: login.php');
exit;
