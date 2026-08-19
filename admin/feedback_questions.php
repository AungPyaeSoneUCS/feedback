<?php
// Redirect to unified question management page
// If set_id is provided, pass it through
$setId = (int)($_GET['set_id'] ?? 0);
if ($setId) {
    header('Location: manage_questions.php?set_id=' . $setId);
} else {
    header('Location: question_sets.php');
}
exit;
