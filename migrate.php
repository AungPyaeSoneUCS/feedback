<?php
// One-time migration script - run once then delete
require_once 'config/db.php';

$queries = [
    // Add description to departments if missing
    "ALTER TABLE `departments` ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL AFTER `department_name`",
];

$results = [];
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        $results[] = ['sql' => $sql, 'status' => 'OK ✓'];
    } else {
        $results[] = ['sql' => $sql, 'status' => 'ERROR: ' . $conn->error];
    }
}
?>
<!DOCTYPE html>
<html><head><title>DB Migration</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 p-8 font-mono">
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold mb-4 text-slate-800">Database Migration</h1>
    <?php foreach ($results as $r): ?>
    <div class="mb-3 p-4 rounded-xl <?= str_starts_with($r['status'], 'OK') ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' ?>">
        <p class="text-xs text-slate-500 mb-1"><?= htmlspecialchars($r['sql']) ?></p>
        <p class="text-sm font-bold <?= str_starts_with($r['status'], 'OK') ? 'text-green-700' : 'text-red-700' ?>"><?= $r['status'] ?></p>
    </div>
    <?php endforeach ?>
    <p class="text-xs text-slate-400 mt-6">Delete this file after running: <code>migrate.php</code></p>
    <a href="admin/index.php" class="inline-block mt-4 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg">→ Go to Admin</a>
</div>
</body></html>
