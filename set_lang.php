<?php
session_start();
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'bn'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$ref = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header('Location: ' . $ref);
exit;