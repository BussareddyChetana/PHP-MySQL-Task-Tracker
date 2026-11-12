<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
require_post();
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$back = safe_return($_POST['return'] ?? null);

$task = find_task($id, $user);                 // ownership / role check
if (!$task) {
    abort(404, 'That task was not found.');
}
if (!in_array($status, STATUSES, true)) {
    flash_set('error', 'Please choose Pending, In Progress or Completed.');
    redirect($back);
}
$st = db()->prepare('UPDATE tasks SET status = :s WHERE id = :id');
$st->execute([':s' => $status, ':id' => $task['id']]);
flash_set('success', 'Status updated to "' . $status . '".');
redirect($back);
