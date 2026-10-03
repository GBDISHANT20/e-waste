<?php
require __DIR__ . '/../src/bootstrap.php';
$u = current_user();
redirect($u ? home_for($u) : 'login.php');
