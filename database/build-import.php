<?php
// Rebuilds database/phpmyadmin-import.sql (schema + first State Admin, mobile 9999999999 / ChangeMe@123).
//   php database/build-import.php
declare(strict_types=1);
$schema = (string) file_get_contents(__DIR__ . '/schema.sql');
$hash = password_hash('ChangeMe@123', PASSWORD_DEFAULT);
$sql = $schema . "\n-- First State Admin: mobile 9999999999, temporary password ChangeMe@123\n-- (you are forced to choose a new password at first login).\n"
    . "INSERT INTO users (name, phone, password_hash, role, must_change_password)\n"
    . "SELECT 'State Admin', '9999999999', '$hash', 'STATE_ADMIN', 1\nWHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'STATE_ADMIN');\n";
file_put_contents(__DIR__ . '/phpmyadmin-import.sql', $sql);
echo "Wrote database/phpmyadmin-import.sql\n";
