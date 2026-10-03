<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$forced = (bool) $u['must_change_password'];

if (is_post()) {
    csrf_check();
    $ok = try_action(function () use ($u) {
        $new = v_password($_POST['new_password'] ?? '', 'New password');
        $row = q_one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify((string) ($_POST['old_password'] ?? ''), $row['password_hash'])) throw new UserError('Current password is wrong');
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('change_password', 'user', $u['id']);
        session_regenerate_id(true);
        flash('ok', 'Password changed.');
        redirect(home_for($u));
    });
}

page_start('Change password', 'password.php');
?>
<h2><?= $forced ? 'Set a new password' : 'Change password' ?></h2>
<?php if ($forced): ?><p class="muted">For security, replace your temporary password before continuing.</p><?php endif; ?>
<div class="card narrow">
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <?php
    f_input('old_password', $forced ? 'Temporary password' : 'Current password', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']);
    f_input('new_password', 'New password (min 8 characters)', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password']);
    ?>
    <button class="btn" type="submit">Change password</button>
  </form>
</div>
<?php page_end();
