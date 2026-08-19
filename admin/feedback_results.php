<?php
$params = $_GET;
$target = 'results_all.php';
if ($params) {
    $target .= '?' . http_build_query($params);
}
header('Location: ' . $target);
exit;
