<?php
declare(strict_types=1);

const STATUSES = ['Pending', 'In Progress', 'Completed'];
const PER_PAGE = 10;

// Never show raw PHP/DB errors to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'");

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<p>Something went wrong on our side. Please try again later.</p>';
    exit;
});

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

/* ---------- configuration & database ---------- */

function config(string $key): string
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php is missing. Copy config.sample.php to config.php.');
        }
        $cfg = require $file;
    }
    return (string) $cfg[$key];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('db_host'), config('db_port'), config('db_name'));
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]);
    }
    return $pdo;
}

/* ---------- small helpers ---------- */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Character-aware length/cut without needing the mbstring extension. */
function text_len(string $s): int
{
    $n = preg_match_all('/./su', $s);
    return $n === false ? strlen($s) : $n;
}

function text_cut(string $s, int $max): string
{
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $chars === false ? substr($s, 0, $max) : implode('', array_slice($chars, 0, $max));
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Only allow returning to our own dashboard (prevents open redirects). */
function safe_return(?string $url): string
{
    if ($url !== null && preg_match('/^index\.php(\?[A-Za-z0-9_=&%.+\-]*)?$/', $url)) {
        return $url;
    }
    return 'index.php';
}

function abort(int $code, string $message): never
{
    http_response_code($code);
    render_header('Error');
    echo '<section class="panel narrow"><h1>' . e($message) . '</h1>'
       . '<p><a href="index.php">Back to your tasks</a></p></section>';
    render_footer();
    exit;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every state-changing (POST) handler. */
function csrf_verify(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        abort(419, 'Your session expired or the form was invalid. Please go back and try again.');
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        abort(405, 'This action must be submitted from the app.');
    }
}

/* ---------- authentication & authorization ---------- */

/** Loads the logged-in user fresh from the DB so role changes/deletions apply immediately. */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = db()->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
            $st->execute([':id' => (int) $_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
            if ($user === null) {
                $_SESSION = [];
            }
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

function is_admin(array $user): bool
{
    return $user['role'] === 'admin';
}

/**
 * Authorization rule (enforced here, in the backend):
 *   - a normal user can only reach tasks where tasks.user_id = their id
 *   - an admin can reach every task
 * Returns null for "not found" AND "not yours" so task ids can't be probed.
 */
function find_task(int $id, array $user): ?array
{
    $sql = 'SELECT * FROM tasks WHERE id = :id';
    $params = [':id' => $id];
    if (!is_admin($user)) {
        $sql .= ' AND user_id = :uid';
        $params[':uid'] = $user['id'];
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}

/* ---------- validation ---------- */

function validate_task(array $in): array
{
    $title  = trim((string) ($in['title'] ?? ''));
    $desc   = trim((string) ($in['description'] ?? ''));
    $status = (string) ($in['status'] ?? 'Pending');
    $errors = [];

    if ($title === '') {
        $errors['title'] = 'Please enter a title for the task.';
    } elseif (text_len($title) > 150) {
        $errors['title'] = 'The title must be 150 characters or fewer.';
    }
    if (text_len($desc) > 2000) {
        $errors['description'] = 'The description must be 2000 characters or fewer.';
    }
    if (!in_array($status, STATUSES, true)) {
        $errors['status'] = 'Please choose Pending, In Progress or Completed.';
    }
    return [['title' => $title, 'description' => $desc, 'status' => $status], $errors];
}

/* ---------- layout ---------- */

function render_header(string $title): void
{
    $user  = current_user();
    $flash = flash_get();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Task Tracker</title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">Task Tracker</a>
  <?php if ($user): ?>
  <nav class="who">
    <span><?= e($user['name']) ?> <span class="role"><?= e($user['role']) ?></span></span>
    <form method="post" action="logout.php"><?= csrf_field() ?><button class="link" type="submit">Log out</button></form>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php if ($flash): ?>
  <div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
<?php endif;
}

function render_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}
