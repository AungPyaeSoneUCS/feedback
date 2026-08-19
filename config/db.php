<?php
require_once __DIR__ . '/base_url.php';

// Set application timezone so PHP date/time functions and MySQL NOW()
// share the same timezone.  All user-supplied datetime-local values
// (which carry no timezone info) are therefore interpreted in the
// application's local timezone.
date_default_timezone_set('Asia/Yangon');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "studentfeedbackintern";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Synchronize MySQL session timezone with PHP's default timezone
// so that NOW() in MySQL and time()/date() in PHP use the same timezone.
$phpTz = date_default_timezone_get();
$tzObj = new DateTimeZone($phpTz);
$offset = $tzObj->getOffset(new DateTime('now', $tzObj));
$hours = floor(abs($offset) / 3600);
$minutes = (abs($offset) % 3600) / 60;
$sign = $offset >= 0 ? '+' : '-';
$mysqlTz = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
$conn->query("SET time_zone = '" . $conn->real_escape_string($mysqlTz) . "'");
//echo "Connected successfully";