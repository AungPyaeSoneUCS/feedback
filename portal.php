<?php
session_start();
require_once 'config/base_url.php';

$role = $_GET['role'] ?? '';

if ($role === 'teacher' || $role === 'student') {
    $_SESSION['entry_allowed'] = true;
    $_SESSION['selected_role'] = $role;
    header('Location: ' . BASE_URL . $role . '/');
    exit;
}

header('Location: ' . BASE_URL . 'index.php');
exit;
