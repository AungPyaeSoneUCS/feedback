<?php
$setId = (int)($_GET['set_id'] ?? 0);
if ($setId) {
    header('Location: manage_questions.php?set_id=' . $setId);
} else {
    header('Location: question_sets.php');
}
exit;
