<?php
require_once 'config/db.php';

$sqlFile = __DIR__ . '/database/migrate_v13.sql';
if (!file_exists($sqlFile)) {
    die("Migration file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$errors = 0;

foreach ($statements as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
    if ($conn->query($stmt)) {
        $success++;
    } else {
        $errors++;
        echo "ERROR: " . $conn->error . "\n";
        echo "SQL: " . substr($stmt, 0, 200) . "\n\n";
    }
}

echo "Migration v13 complete. Success: $success, Errors: $errors\n";
