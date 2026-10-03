<?php
require __DIR__ . '/../src/bootstrap.php';
if (is_post()) {
    csrf_check();
    logout_user();
}
redirect('login.php');
