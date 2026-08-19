<?php
// One-time migration runner — delete this file after running!
require_once 'config/db.php';
$sql = file_get_contents('database/migrate_v2.sql');
// Remove USE statement since we're already connected
$sql = preg_replace('/^USE\s+\w+;\s*/im', '', $sql);
$stmts = array_filter(array_map('trim', explode(';', $sql)));
$ok = 0; $fail = 0; $msgs = [];
foreach ($stmts as $s) {
    if (!$s || str_starts_with(ltrim($s), '--')) continue;
    if ($conn->query($s)) { $ok++; $msgs[] = "<span style='color:green'>✓ OK</span>"; }
    else { $fail++; $msgs[] = "<span style='color:red'>✗ " . htmlspecialchars($conn->error) . "</span><pre style='font-size:11px;color:#555'>".htmlspecialchars($s)."</pre>"; }
}
echo "<h2>Migration v2</h2><p>$ok succeeded, $fail failed</p><ul>";
foreach ($msgs as $m) echo "<li>$m</li>";
echo "</ul>";
if ($fail === 0) echo "<p style='color:green;font-weight:bold'>✅ All tables created! You can delete migrate_runner.php now.</p>";
?>
