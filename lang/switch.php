<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'mm'])) {
    $_SESSION['lang'] = $_GET['lang'];
    $redirect = $_SERVER['HTTP_REFERER'] ?? $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: ' . $redirect);
    exit;
}
