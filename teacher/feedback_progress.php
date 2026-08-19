<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

header('Location: feedback_results.php');
exit;
