<?php
/**
 * TELEPAGE — admin/logout.php
 */
define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/vendor/autoload.php';
Bootstrap::init(Bootstrap::MODE_HTML);

Session::start();
if (!empty($_SESSION['admin_user'])) {
    Logger::admin(Logger::INFO, 'Logout', ['username' => $_SESSION['admin_user']]);
}
session_destroy();
header('Location: login.php');
exit;
