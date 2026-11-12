<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

// Dummy hash so unknown emails take about as long as wrong passwords.
const DUMMY_HASH = '$2y$10$R.QdgB87nKbpdZqrihj30ObwQ089GkOLiV8otM3iyYq6umxgUXrJa';
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = :email');
    $st->execute([':email' => $email]);
    $row = $st->fetch();

    $ok = password_verify($password, $row['password_hash'] ?? DUMMY_HASH);
    if ($row && $ok) {
        session_regenerate_id(true);          // prevents session fixation
        $_SESSION['user_id'] = (int) $row['id'];
        unset($_SESSION['csrf']);
        redirect('index.php');
    }
    $error = 'That email and password do not match. Check them and try again.';
}

render_header('Log in');
?>
<section class="panel narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" novalidate data-validate>
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?= e($email) ?>" autocomplete="username">
    <p class="error" data-error-for="email"></p>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <p class="error" data-error-for="password"></p>
    <button class="btn" type="submit">Log in</button>
  </form>
  <p class="hint">New here? <a href="register.php">Create an account</a></p>
</section>
<?php render_footer();
