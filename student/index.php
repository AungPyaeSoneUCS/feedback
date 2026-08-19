<?php
session_start();
require_once '../includes/auth.php';

if (isLoggedIn() && $_SESSION['role'] === 'student') {
    // Authorized
} else {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $isLangSwitch = isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'mm']);
    $hasEntry = isset($_SESSION['entry_allowed']) && isset($_SESSION['selected_role']) && $_SESSION['selected_role'] === 'student';
    $hasLoginIntent = isset($_SESSION['login_intent']) && $_SESSION['login_intent'] === 'student';

    if (!$hasEntry && !($isPost && $hasLoginIntent) && !($isLangSwitch && $hasLoginIntent)) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }

    if (!$isPost && !$isLangSwitch && $hasEntry) {
        // Consume the entry token to prevent browser refresh / manual URL bypass
        unset($_SESSION['entry_allowed']);
        // Set a login intent token to allow POST form submissions and language toggling
        $_SESSION['login_intent'] = 'student';
    }
}

$loginType = 'student';
include '../includes/landing.php';
