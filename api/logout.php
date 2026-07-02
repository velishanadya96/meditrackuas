<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];
session_unset();
session_destroy();

// Hapus cookie dengan parameter yang sama persis kayak pas di-set
setcookie('user_id', '', time() - 3600, '/');
setcookie('user_name', '', time() - 3600, '/');
setcookie('user_role', '', time() - 3600, '/');

// Kosongin juga di request ini biar gak kebaca lagi kalau ada script lain di request yang sama
unset($_COOKIE['user_id'], $_COOKIE['user_name'], $_COOKIE['user_role']);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: /api/login.php?loggedout=1');
exit;