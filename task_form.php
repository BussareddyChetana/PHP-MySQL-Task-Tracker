<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$task = null;
if ($id > 0) {
    $task = find_task($id, $user);             // ownership / role check
    if (!$task) {
        abort(404, 'That task was not found.');
    }
}

$errors = [];
$values = $task
    ? ['title' => $task['title'], 'description' => (string) $task['description'], 'status' => $task['status']]
    : ['title' => '', 'description' => '', 'status' => 'Pending'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$values, $errors] = validate_task($_POST);   // server-side validation

    if (!$errors) {
        if ($task) {
            $st = db()->prepare(
                'UPDATE tasks SET title = :t, description = :d, status = :s WHERE id = :id'
            );
            $st->execute([':t' => $values['title'], ':d' => $values['description'],
                          ':s' => $values['status'], ':id' => $task['id']]);
            flash_set('success', 'Task "' . $values['title'] . '" was updated.');
        } else {
            $st = db()->prepare(
                'INSERT INTO tasks (user_id, title, description, status) VALUES (:u, :t, :d, :s)'
            );
            $st->execute([':u' => $user['id'], ':t' => $values['title'],
                          ':d' => $values['description'], ':s' => $values['status']]);
            flash_set('success', 'Task "' . $values['title'] . '" was added.');
        }
        redirect('index.php');
    }
}

render_header($task ? 'Edit task' : 'Add task');
?>
<section class="panel narrow">
  <h1><?= $task ? 'Edit task' : 'Add task' ?></h1>
  <form method="post" novalidate data-validate>
    <?= csrf_field() ?>
    <?php if ($task): ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><?php endif; ?>

    <label for="title">Title</label>
    <input id="title" name="title" required maxlength="150" value="<?= e($values['title']) ?>">
    <p class="error" data-error-for="title"><?= e($errors['title'] ?? '') ?></p>

    <label for="description">Description (optional)</label>
    <textarea id="description" name="description" rows="5" maxlength="2000"><?= e($values['description']) ?></textarea>
    <p class="error" data-error-for="description"><?= e($errors['description'] ?? '') ?></p>

    <label for="status">Status</label>
    <select id="status" name="status">
      <?php foreach (STATUSES as $s): ?>
        <option<?= $values['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="error" data-error-for="status"><?= e($errors['status'] ?? '') ?></p>

    <div class="actions">
      <button class="btn" type="submit"><?= $task ? 'Save changes' : 'Add task' ?></button>
      <a class="btn ghost" href="index.php">Cancel</a>
    </div>
  </form>
</section>
<?php render_footer();
