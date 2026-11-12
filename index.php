<?php
require __DIR__ . '/includes/bootstrap.php';
$user  = require_login();
$admin = is_admin($user);

// Filters come from the query string and are all validated / bound as parameters.
$viewAll = $admin && (($_GET['scope'] ?? '') === 'all');
$q       = text_cut(trim((string) ($_GET['q'] ?? '')), 100);
$status  = (string) ($_GET['status'] ?? '');
if (!in_array($status, STATUSES, true)) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$scopeSql = $viewAll ? '1=1' : 't.user_id = :uid';
$scopeParams = $viewAll ? [] : [':uid' => $user['id']];

// Dashboard summary (within the user's scope, ignoring search/filter).
$st = db()->prepare("SELECT t.status, COUNT(*) AS c FROM tasks t WHERE $scopeSql GROUP BY t.status");
$st->execute($scopeParams);
$counts = array_fill_keys(STATUSES, 0);
foreach ($st->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
$total = array_sum($counts);

// Filtered list.
$where = [$scopeSql];
$params = $scopeParams;
if ($q !== '') {
    $where[] = "t.title LIKE :q ESCAPE '\\\\'";
    $params[':q'] = '%' . addcslashes($q, '%_\\') . '%';
}
if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}
$whereSql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM tasks t WHERE $whereSql");
$st->execute($params);
$matches = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($matches / PER_PAGE));
$page = min($page, $pages);

$st = db()->prepare(
    "SELECT t.*, u.name AS owner FROM tasks t JOIN users u ON u.id = t.user_id
     WHERE $whereSql ORDER BY t.created_at DESC, t.id DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $val) {
    $st->bindValue($k, $val);
}
$st->bindValue(':lim', PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * PER_PAGE, PDO::PARAM_INT);
$st->execute();
$tasks = $st->fetchAll();

function qs(array $over = []): string
{
    global $q, $status, $viewAll, $page;
    $base = ['q' => $q, 'status' => $status, 'scope' => $viewAll ? 'all' : '', 'page' => $page];
    $merged = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== 1);
    return $merged ? '?' . http_build_query($merged) : '';
}
$return = 'index.php' . qs();

render_header('Your tasks');
?>
<div class="head">
  <h1><?= $viewAll ? 'All tasks' : 'Your tasks' ?></h1>
  <a class="btn" href="task_form.php">Add task</a>
</div>

<?php if ($admin): ?>
<p class="hint">You are an admin.
  <?php if ($viewAll): ?><a href="index.php">Show only my tasks</a>
  <?php else: ?><a href="index.php?scope=all">Show every user's tasks</a><?php endif; ?>
</p>
<?php endif; ?>

<ul class="summary">
  <li><strong><?= $total ?></strong> total</li>
  <?php foreach (STATUSES as $s): ?>
    <li><strong><?= $counts[$s] ?></strong> <?= e(strtolower($s)) ?></li>
  <?php endforeach; ?>
</ul>

<form class="filters" method="get" action="index.php">
  <?php if ($viewAll): ?><input type="hidden" name="scope" value="all"><?php endif; ?>
  <label class="sr" for="q">Search by title</label>
  <input id="q" name="q" type="search" placeholder="Search by title" maxlength="100" value="<?= e($q) ?>">
  <label class="sr" for="f-status">Filter by status</label>
  <select id="f-status" name="status">
    <option value="">All statuses</option>
    <?php foreach (STATUSES as $s): ?>
      <option<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Apply</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="btn ghost" href="index.php<?= $viewAll ? '?scope=all' : '' ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$tasks): ?>
  <section class="panel empty">
    <?php if ($total === 0): ?>
      <p>You have no tasks yet. <a href="task_form.php">Add your first task.</a></p>
    <?php else: ?>
      <p>No tasks match your search or filter.</p>
    <?php endif; ?>
  </section>
<?php else: ?>
<table class="tasks">
  <thead><tr><th>Task</th><?php if ($viewAll): ?><th>Owner</th><?php endif; ?><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($tasks as $t): ?>
    <tr>
      <td data-label="Task">
        <strong><?= e($t['title']) ?></strong>
        <?php if ($t['description'] !== null && $t['description'] !== ''): ?>
          <p class="desc"><?= e($t['description']) ?></p>
        <?php endif; ?>
      </td>
      <?php if ($viewAll): ?><td data-label="Owner"><?= e($t['owner']) ?></td><?php endif; ?>
      <td data-label="Status">
        <form method="post" action="task_status.php" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <input type="hidden" name="return" value="<?= e($return) ?>">
          <span class="badge s-<?= e(strtolower(str_replace(' ', '-', $t['status']))) ?>"><?= e($t['status']) ?></span>
          <select name="status" aria-label="Change status for <?= e($t['title']) ?>" data-autosubmit>
            <?php foreach (STATUSES as $s): ?>
              <option<?= $t['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn small" type="submit">Set</button>
        </form>
      </td>
      <td data-label="Created"><?= e(date('d M Y, H:i', strtotime($t['created_at']))) ?></td>
      <td data-label="Actions" class="row-actions">
        <a class="btn small ghost" href="task_form.php?id=<?= (int) $t['id'] ?>">Edit</a>
        <form method="post" action="task_delete.php" class="inline"
              data-confirm="Delete &quot;<?= e($t['title']) ?>&quot;? This cannot be undone.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <input type="hidden" name="return" value="<?= e($return) ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a href="index.php<?= e(qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="index.php<?= e(qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php render_footer();
