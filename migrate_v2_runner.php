<?php
require_once 'config/db.php';

$sql = file_get_contents('database/migrate_v2.sql');
// Remove USE statement
$sql = preg_replace('/^USE\s+\w+;\s*/im', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$ok = 0;
$fail = 0;
$messages = [];

foreach ($statements as $stmt) {
    if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
    if ($conn->query($stmt)) {
        $ok++;
    } else {
        $fail++;
        $messages[] = htmlspecialchars($conn->error) . ' — ' . htmlspecialchars(substr($stmt, 0, 80));
    }
}

echo "<!DOCTYPE html><html><head><title>Migration v2</title><script src='https://cdn.tailwindcss.com'></script></head><body class='bg-slate-50 p-8 font-sans'>";
echo "<div class='max-w-2xl mx-auto'>";
echo "<h1 class='text-2xl font-bold mb-4'>Database Migration v2</h1>";
echo "<p class='mb-2 text-green-700 font-semibold'>✓ $ok statements succeeded</p>";
if ($fail > 0) {
    echo "<p class='mb-4 text-red-700 font-semibold'>✗ $fail statements failed</p>";
    echo "<div class='bg-red-50 border border-red-200 rounded-lg p-4 mb-4'>";
    foreach ($messages as $m) {
        echo "<p class='text-sm text-red-700 mb-1'>$m</p>";
    }
    echo "</div>";
} else {
    echo "<div class='bg-green-50 border border-green-200 rounded-lg p-4 mb-4'>";
    echo "<p class='text-green-800 font-semibold'>All SA and Learning Environment tables created successfully!</p>";
    echo "</div>";
}
echo "<a href='admin/index.php' class='inline-block px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700'>→ Go to Admin</a>";
echo "</div></body></html>";
$conn->close();
