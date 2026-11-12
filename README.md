# Task Tracker (PHP + MySQL)

A small multi-user task tracker built with core PHP 8 (PDO) and MySQL/MariaDB.
Levels 1, 2 and 3 are complete, plus two bonus items (pagination, dashboard summary).

## Setup

Requirements: PHP 8.1+ with the `pdo\\\_mysql` extension, and MySQL 5.7+/MariaDB 10.3+.

1. **Create the database and demo data**

```bash
   mysql -u root -p < database.sql
   ```

This creates the `task\\\_tracker` database, both tables and three demo users.

2. **Create a least-privilege database user** (the app never needs more than this):

```sql
   CREATE USER 'task\\\_user'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON task\\\_tracker.\\\* TO 'task\\\_user'@'localhost';
   ```

3. **Configure the app**: copy `config.sample.php` to `config.php` and put in your host, database name, user and password.
`config.php` is in `.gitignore`, so credentials are not committed or shipped.
4. **Run it**

```bash
   php -S localhost:8000
   ```

then open [http://localhost:8000](http://localhost:8000). (With XAMPP/WAMP, put the folder in `htdocs`/`www` instead.)

### Demo logins (local testing only)

|Role|Email|Password|
|-|-|-|
|admin|admin@example.com|Admin@123|
|user|alice@example.com|Alice@123|
|user|bob@example.com|Bob@12345|

New users can register at `/register.php`. Delete the demo accounts before any real use.

## Files

|File|Purpose|
|-|-|
|`index.php`|Dashboard: summary counts, search, status filter, pagination, status change, edit/delete links|
|`task\\\_form.php`|Add and edit a task (one form, one validation path)|
|`task\\\_status.php`, `task\\\_delete.php`|POST-only actions|
|`login.php`, `register.php`, `logout.php`|Authentication|
|`includes/bootstrap.php`|DB connection, sessions, CSRF, authorization helper, validation, layout|
|`assets/`|`style.css` (responsive), `app.js` (confirm dialog, client-side validation)|
|`database.sql`|Schema + demo data|
|`tests/smoke.sh`|Repeatable curl-based test script|

## Database design

* **users**: `id`, `name`, `email` (UNIQUE), `password\\\_hash` (VARCHAR(255) for `password\\\_hash()` output), `role` ENUM(`user`,`admin`), `created\\\_at`.
* **tasks**: `id`, `user\\\_id` (FK to `users`, `ON DELETE CASCADE`), `title` VARCHAR(150), `description` TEXT NULL, `status` ENUM(`Pending`,`In Progress`,`Completed`), `created\\\_at`, `updated\\\_at`.
* Why: a separate `users` table gives each task an owner, which the authorization rule needs. The ENUM keeps invalid statuses out at the database level as well as in PHP. An index on `(user\\\_id, status)` serves the dashboard query and the status filter. InnoDB is used so the foreign key is enforced.

## Roles and permissions

|Action|`user`|`admin`|
|-|-|-|
|Register / log in|yes|yes (admin accounts are created in the database only; the registration form always creates `user`)|
|See, add, edit, change status, delete **own** tasks|yes|yes|
|See / edit / change status / delete **other users'** tasks|**no**|yes (via "Show every user's tasks")|

Enforcement is in the backend, in `find\\\_task()` (`includes/bootstrap.php`). Every page that touches a task goes through it: for a normal user the SQL includes `AND user\\\_id = :uid`, so another user's task is simply "not found" (HTTP 404, which also stops people probing for valid ids). The user's role is re-read from the database on every request, and `?scope=all` is ignored for non-admins. Changing the URL or form data (`id`, `scope`, `return`) therefore cannot reach another user's data.

## Security measures

* Passwords: `password\\\_hash()` / `password\\\_verify()`; session ID regenerated on login; same error message for unknown email and wrong password.
* SQL: PDO with real prepared statements (`EMULATE\\\_PREPARES` off) for every query that uses input. `LIKE` wildcards in the search are escaped.
* XSS: all user-supplied output goes through `e()` (`htmlspecialchars`), plus a Content-Security-Policy that blocks inline scripts.
* CSRF: a per-session random token is in every POST form and checked with `hash\\\_equals()`. Actions that change state are POST-only (GET returns 405). Session cookie is `HttpOnly` and `SameSite=Lax`.
* Validation: done in PHP (the source of truth) and repeated in the browser for convenience. Title is required and max 150 characters; description max 2000; status must be one of the three allowed values.
* Errors: exceptions are logged, and visitors only see a generic message, never SQL or credentials. The `return` parameter only accepts local `index.php?...` paths, so there is no open redirect.

## Levels completed and known limitations

* **Level 1, 2, 3: complete.** Bonus: pagination (10 per page) and dashboard summary.
* Not done: due dates/priority; no password reset or email verification; no login rate-limiting/lockout (a real deployment should add it); no admin screen for managing users or roles (roles are changed in SQL); deleting a task is permanent (no undo); the browser `confirm()` dialog is the delete confirmation (the server still requires a valid POST + CSRF token without it); HTTPS must be provided by the web server.

## Tests

Run `BASE=http://localhost:8000 bash tests/smoke.sh` against a fresh import of `database.sql`. The automated test script is included in the tests/ folder and can be run locally using the command above.

|#|Case|Result|
|-|-|-|
|1|Logged-out visitor opening dashboard / task form is redirected to login|Pass|
|2|Wrong password rejected; correct login opens dashboard|Pass|
|3|Passwords stored as bcrypt hashes (no plain text in DB)|Pass|
|4|Add task with valid data shows "was added"|Pass|
|5|Empty/whitespace title and 151-character title rejected with friendly errors|Pass|
|6|Edit task, change status, invalid status value rejected|Pass|
|7|Search by title, search with no match, status filter|Pass|
|8|Search input `' OR 1=1 --` and `%` handled safely (no SQL error, treated literally)|Pass|
|9|`<script>` in a title is shown as escaped text|Pass|
|10|Alice cannot view / edit / change status of / delete Bob's task by changing `id` (404, data unchanged)|Pass|
|11|`?scope=all` shows nothing extra for a normal user; admin sees all tasks|Pass|
|12|POST without CSRF token returns 419; delete/logout via GET return 405|Pass|
|13|Off-site `return` URL ignored; non-numeric id gives no raw error|Pass|
|14|Duplicate email, short password, and `role=admin` in the registration request handled|Pass|
|15|Delete own task shows "was deleted"|Pass|
|16|Wrong database password: visitor sees only a generic error page (checked manually)|Pass|

**Bugs found and fixed while testing**

1. Pages returned HTTP 500 on a PHP install without the `mbstring` extension (`mb\\\_strlen()` was undefined). Replaced with `text\\\_len()`/`text\\\_cut()` helpers that need no extension.
2. My first "wrong password" test sent a fake CSRF token and so tested the CSRF check instead of the login. Fixed the test to send a real token.

Still to do by hand: view the app in a browser at phone width and desktop width and add screenshots to `screenshots/`.

## Backup and restore

```bash
# Backup (schema + data, consistent snapshot for InnoDB)
mysqldump -u root -p --single-transaction --routines task\\\_tracker > backup\\\_$(date +%F).sql

# Restore into an empty or existing database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS task\\\_tracker CHARACTER SET utf8mb4"
mysql -u root -p task\\\_tracker < backup\\\_2026-11-12.sql
```

Schedule the backup with cron (or Task Scheduler), keep several copies off the server, store backups securely because they contain password hashes, and test a restore on another machine now and then. Note that importing `database.sql` itself drops and recreates the tables, so only use it for a fresh setup.

## AI assistant disclosure

Tool used: Claude (Anthropic) generated the first version of this code.
Changes and testing performed after reviewing the generated code:

Validation Improvement: Improved task validation messages to make them clearer and more user-friendly. Tested empty and invalid inputs to verify appropriate error messages.



UI Improvement: Improved the task table styling to make task details and action buttons easier to view and use.



Security Testing: Reviewed task ownership validation and tested direct URL access using different user accounts. Verified that normal users cannot access tasks belonging to other users.



Delete Button Fix: Identified a CSS issue that was hiding the Delete button. Corrected the CSS selector so that the Delete button remains visible while unnecessary status form buttons stay hidden. Tested the functionality successfully.

