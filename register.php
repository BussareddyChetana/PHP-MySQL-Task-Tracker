<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$v = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $v['name']  = trim((string) ($_POST['name'] ?? ''));
    $v['email'] = trim((string) ($_POST['email'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');
    $confirm    = (string) ($_POST['confirm'] ?? '');

    if ($v['name'] === '' || text_len($v['name']) > 100) {
        $errors['name'] = 'Enter your name (up to 100 characters).';
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || text_len($v['email']) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters for your password.';
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Passwords can be at most 72 characters.'; // bcrypt limit
    }
    if ($password !== $confirm) {
        $errors['confirm'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        try {
            // Role is always 'user' here: nobody can self-register as admin.
            $st = db()->prepare(
                "INSERT INTO users (name, email, password_hash, role) VALUES (:n, :e, :p, 'user')"
            );
            $st->execute([
                ':n' => $v['name'],
                ':e' => $v['email'],
                ':p' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            flash_set('success', 'Account created. You can log in now.');
            redirect('login.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') { // 23000 = duplicate key
                throw $ex;
            }
            $errors['email'] = 'An account with this email already exists.';
        }
    }
}

render_header('Create account');
?>
<section class="panel narrow">
  <h1>Create account</h1>
  <form method="post" novalidate data-validate>
    <?= csrf_field() ?>
    <label for="name">Name</label>
    <input id="name" name="name" required maxlength="100" value="<?= e($v['name']) ?>">
    <p class="error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required maxlength="190" value="<?= e($v['email']) ?>">
    <p class="error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
    <label for="password">Password (8+ characters)</label>
    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
    <p class="error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
    <label for="confirm">Confirm password</label>
    <input id="confirm" name="confirm" type="password" required autocomplete="new-password">
    <p class="error" data-error-for="confirm"><?= e($errors['confirm'] ?? '') ?></p>
    <button class="btn" type="submit">Create account</button>
  </form>
  <p class="hint">Already registered? <a href="login.php">Log in</a></p>
</section>
<?php render_footer();
