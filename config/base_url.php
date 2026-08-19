<?php
/**
 * Centralized BASE_URL Configuration
 *
 * Auto-detects whether the project lives inside a subfolder (local dev)
 * or at the document root (free hosting) and defines BASE_URL accordingly.
 *
 *   Local  → BASE_URL = '/studentfeedbackucsh/'
 *   Hosted → BASE_URL = '/'
 */
if (!defined('BASE_URL')) {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($scriptDir, '/studentfeedbackucsh') !== false) {
        define('BASE_URL', '/studentfeedbackucsh/');
    } else {
        define('BASE_URL', '/');
    }
}
