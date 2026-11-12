<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
require_post();
csrf_verify();

$task = find_task((int) ($_POST['id'] ?? 0), $user);   // ownership / role check
if (!$task) {
    abort(404, 'That task was not found.');
}
$st = db()->prepare('DELETE FROM tasks WHERE id = :id');
$st->execute([':id' => $task['id']]);
flash_set('success', 'Task "' . $task['title'] . '" was deleted.');
redirect(safe_return($_POST['return'] ?? null));
