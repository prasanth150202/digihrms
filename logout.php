<?php
require_once 'config.php'; // registers the DB session handler before destroying

session_unset();
session_destroy();

// Clear the session cookie regardless of whether it was a session or persistent cookie
setcookie(session_name(), '', [
    'expires'  => time() - 86400,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);

header("Location: login.php");
exit;
