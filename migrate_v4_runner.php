<?php
require_once 'config/db.php';

$sqlFile = file_get_contents(__DIR__ . '/database/migrate_v4.sql');

$statements = array_filter(
    array_map('trim', explode(';', $sqlFile)),
    fn($s) => $s !== '' && !str_starts_with($s, '--')
);

$success = 0;
$failed  = 0;

foreach ($statements as $stmt) {
    if (trim($stmt) === '') continue;
    try {
        $conn->query($stmt);
        $success++;
    } catch (Exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists')) {
            $success++;
        } else {
            $failed++;
            echo "<p style='color:red'>FAILED: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre style='font-size:11px;color:#666'>" . htmlspecialchars(substr($stmt, 0, 200)) . "</pre>";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>SFIS v4 Migration</title></head>
<body style="font-family:system-ui;padding:40px">
    <h1>SFIS v4 — Global SA & Admin Questions Migration</h1>
    <p style="color:green">Statements succeeded: <?= $success ?></p>
    <p style="color:<?= $failed ? 'red' : 'green' ?>">Statements failed: <?= $failed ?></p>
    <?php if ($failed === 0): ?>
        <p style="color:green;font-weight:bold">✓ Migration complete. SA and Admin feedback questions are now global (shared across all forms).</p>
    <?php endif; ?>
    <p><a href="admin/sa_questions.php">Go to SA Questions</a> | <a href="admin/adm_questions.php">Go to Admin Questions</a></p>
</body>
</html>
