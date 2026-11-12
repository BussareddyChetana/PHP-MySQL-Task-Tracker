#!/usr/bin/env bash
# Manual-style smoke tests driven by curl. Usage: BASE=http://127.0.0.1:8080 ./tests/smoke.sh
# Needs the demo data from database.sql and the `mysql` client (only for the password-hash check).
BASE="${BASE:-http://127.0.0.1:8080}"
DIR=$(mktemp -d); pass=0; fail=0
A="$DIR/alice"; B="$DIR/bob"; ADM="$DIR/admin"; V="$DIR/visitor"

ok()   { printf 'PASS  %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf 'FAIL  %s\n' "$1"; fail=$((fail+1)); }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

csrf() { curl -s -b "$1" -c "$1" "$BASE/${2:-login.php}" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
login(){ local t; t=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -d "csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE/login.php"; }
post() { # jar path [curl args...] -> body, with csrf token from dashboard
  local jar=$1 path=$2; shift 2; local t; t=$(csrf "$jar" index.php)
  curl -s -L -b "$jar" -c "$jar" -d "csrf=$t" "$@" "$BASE/$path"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

login "$A" alice@example.com 'Alice@123'
login "$B" bob@example.com 'Bob@12345'
login "$ADM" admin@example.com 'Admin@123'

# --- Level 3: access control & auth ---
check "Logged-out visitor is redirected away from dashboard" '[ "$(code "$BASE/index.php")" = 302 ] && curl -s -I "$BASE/index.php" | grep -qi "location: login.php"'
check "Logged-out visitor cannot open task form" '[ "$(code "$BASE/task_form.php")" = 302 ]'
r=$(curl -s -d "csrf=$(csrf "$V")" --data-urlencode "email=alice@example.com" --data-urlencode "password=wrong" -b "$V" -c "$V" "$BASE/login.php")
check "Wrong password is rejected without login" 'echo "$r" | grep -q "do not match"'
check "Valid login reaches dashboard" 'curl -s -b "$A" "$BASE/index.php" | grep -q "Your tasks"'
check "Passwords are stored as bcrypt hashes" '[ "$(mysql -uroot -N -e "select count(*) from task_tracker.users where password_hash not like \"\$2y\$%\"")" = 0 ]'

# --- Level 1/2: CRUD ---
r=$(post "$A" task_form.php --data-urlencode "title=Smoke test task" --data-urlencode "description=hello" --data-urlencode "status=Pending")
check "Add task with valid data shows success message" 'echo "$r" | grep -q "was added"'
id=$(mysql -uroot -N -e "select max(id) from task_tracker.tasks where title='Smoke test task'")
r=$(post "$A" task_form.php --data-urlencode "title=   " --data-urlencode "description=x" --data-urlencode "status=Pending")
check "Empty (whitespace) title rejected with friendly error" 'echo "$r" | grep -q "Please enter a title"'
r=$(post "$A" task_form.php --data-urlencode "title=$(printf 'x%.0s' $(seq 1 151))" --data-urlencode "status=Pending")
check "Over-long title rejected" 'echo "$r" | grep -q "150 characters"'
r=$(post "$A" task_form.php -d "id=$id" --data-urlencode "title=Smoke test edited" --data-urlencode "description=changed" --data-urlencode "status=Pending")
check "Edit task shows updated message" 'echo "$r" | grep -q "was updated"'
r=$(post "$A" task_status.php -d "id=$id" --data-urlencode "status=In Progress" -d "return=index.php")
check "Change status to In Progress" 'echo "$r" | grep -q "Status updated"'
r=$(post "$A" task_status.php -d "id=$id" --data-urlencode "status=Bogus" -d "return=index.php")
check "Invalid status value rejected" 'echo "$r" | grep -q "Please choose Pending" && [ "$(mysql -uroot -N -e "select status from task_tracker.tasks where id=$id")" = "In Progress" ]'
check "Search by title finds the task" 'curl -s -b "$A" "$BASE/index.php?q=edited" | grep -q "Smoke test edited"'
check "Search for unknown title shows no-match message" 'curl -s -b "$A" "$BASE/index.php?q=zzzzzz" | grep -q "No tasks match"'
check "Status filter returns only that status" 'o=$(curl -s -b "$A" "$BASE/index.php?status=Completed"); echo "$o" | grep -q "Send weekly report" && ! echo "$o" | grep -q "Smoke test edited"'
check "SQL-injection style search returns nothing and no error" 'o=$(curl -s -b "$A" --get --data-urlencode "q=%'"'"' OR 1=1 -- " "$BASE/index.php"); echo "$o" | grep -q "No tasks match" && ! echo "$o" | grep -qi "sql syntax"'
check "LIKE wildcard % is treated literally" 'curl -s -b "$A" "$BASE/index.php?q=%25" | grep -q "No tasks match"'
r=$(post "$A" task_form.php --data-urlencode "title=<script>alert(1)</script>" --data-urlencode "status=Pending")
check "HTML in title is escaped on output (XSS)" 'echo "$r" | grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" && ! echo "$r" | grep -q "<script>alert(1)"'

# --- Authorization / ownership ---
bobtask=$(mysql -uroot -N -e "select id from task_tracker.tasks where user_id=3 limit 1")
check "Alice cannot open Bob's task edit page (404)" '[ "$(code -b "$A" "$BASE/task_form.php?id=$bobtask")" = 404 ]'
t=$(csrf "$A" index.php)
check "Alice cannot change Bob's task status (404, unchanged)" 'before=$(mysql -uroot -N -e "select status from task_tracker.tasks where id=$bobtask"); [ "$(code -b "$A" -d "csrf=$t&id=$bobtask&status=Completed" "$BASE/task_status.php")" = 404 ] && [ "$(mysql -uroot -N -e "select status from task_tracker.tasks where id=$bobtask")" = "$before" ]'
check "Alice cannot edit Bob's task via POST (404, unchanged)" '[ "$(code -b "$A" -d "csrf=$t&id=$bobtask" --data-urlencode "title=HACKED" "$BASE/task_form.php")" = 404 ] && [ "$(mysql -uroot -N -e "select count(*) from task_tracker.tasks where title=\"HACKED\"")" = 0 ]'
check "Alice cannot delete Bob's task (404, still exists)" '[ "$(code -b "$A" -d "csrf=$t&id=$bobtask" "$BASE/task_delete.php")" = 404 ] && [ "$(mysql -uroot -N -e "select count(*) from task_tracker.tasks where id=$bobtask")" = 1 ]'
check "Alice cannot see Bob's tasks via ?scope=all" '! curl -s -b "$A" "$BASE/index.php?scope=all" | grep -q "Fix login page typo"'
check "Admin can see every user's tasks" 'curl -s -b "$ADM" "$BASE/index.php?scope=all" | grep -q "Fix login page typo"'
check "Admin can open another user's task" '[ "$(code -b "$ADM" "$BASE/task_form.php?id=$bobtask")" = 200 ]'

# --- CSRF & misc hardening ---
check "POST without CSRF token is rejected (419)" '[ "$(code -b "$A" -d "id=$id" "$BASE/task_delete.php")" = 419 ]'
check "Delete via GET is refused (405)" '[ "$(code -b "$A" "$BASE/task_delete.php?id=$id")" = 405 ]'
check "Logout via GET is refused (405)" '[ "$(code -b "$A" "$BASE/logout.php")" = 405 ]'
check "Non-numeric id handled without raw errors" 'o=$(curl -s -b "$A" "$BASE/task_form.php?id=abc"); ! echo "$o" | grep -Eqi "fatal|exception|stack trace|mysql|password"'
check "Off-site return URL is ignored (no open redirect)" 'u=$(curl -s -o /dev/null -w "%{redirect_url}" -b "$A" -d "csrf=$t&id=$id&status=Completed&return=https://evil.example/" "$BASE/task_status.php"); [ "$u" = "$BASE/index.php" ]'

# --- Registration ---
r=$(curl -s -b "$V" -c "$V" -d "csrf=$(csrf "$V" register.php)" --data-urlencode "name=Dup" --data-urlencode "email=alice@example.com" --data-urlencode "password=Password1" --data-urlencode "confirm=Password1" "$BASE/register.php")
check "Duplicate email registration shows friendly error" 'echo "$r" | grep -q "already exists"'
r=$(curl -s -b "$V" -c "$V" -d "csrf=$(csrf "$V" register.php)" --data-urlencode "name=Eve" --data-urlencode "email=eve@example.com" --data-urlencode "password=short" --data-urlencode "confirm=short" "$BASE/register.php")
check "Short password rejected on server" 'echo "$r" | grep -q "at least 8"'
curl -s -o /dev/null -b "$V" -c "$V" -d "csrf=$(csrf "$V" register.php)" --data-urlencode "name=Eve" --data-urlencode "email=eve@example.com" --data-urlencode "password=Password1" --data-urlencode "confirm=Password1" -d "role=admin" "$BASE/register.php"
check "Self-registration always creates role 'user' (role=admin ignored)" '[ "$(mysql -uroot -N -e "select role from task_tracker.users where email=\"eve@example.com\"")" = user ]'

# --- Delete (last, uses the task from earlier) ---
r=$(post "$A" task_delete.php -d "id=$id" -d "return=index.php")
check "Delete own task shows deleted message" 'echo "$r" | grep -q "was deleted"'
mysql -uroot -e "delete from task_tracker.users where email='eve@example.com'; delete from task_tracker.tasks where title like '%script%'"
echo; echo "Passed: $pass   Failed: $fail"; rm -rf "$DIR"; [ "$fail" = 0 ]
