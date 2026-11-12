<?php
require __DIR__ . '/includes/bootstrap.php';
require_post();
csrf_verify();
$_SESSION = [];
session_destroy();
session_start();
flash_set('success', 'You have been logged out.');
redirect('login.php');
