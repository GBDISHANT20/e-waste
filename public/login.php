<?php
require __DIR__ . '/../src/bootstrap.php';
if ($u = current_user()) redirect(home_for($u));

if (is_post()) {
    csrf_check();
    try_action(function () {
        $phone = v_str($_POST['phone'] ?? '', 'Mobile number', 10);
        $uid = attempt_login($phone, (string) ($_POST['password'] ?? ''));
        login_user($uid);
        redirect('index.php');
    });
}

page_start('Login', '', true);
?>
<h1>SWM Portal</h1>
<p class="muted">Solid Waste Management – district garbage collection management</p>
<div class="tabs"><a class="active" href="login.php">Login</a><a href="register.php">Citizen sign-up</a></div>
<p class="center"><a class="btn secondary" href="report-issue.php">Report a garbage problem (no login)</a></p>
<div class="card">
  <form method="post" class="stack" autocomplete="on">
    <?= csrf_field() ?>
    <?php f_input('phone', 'Mobile number', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric', 'autocomplete' => 'username']); ?>
    <?php f_input('password', 'Password', ['required' => true, 'type' => 'password', 'autocomplete' => 'current-password']); ?>
    <button class="btn" type="submit">Login</button>
    <p class="muted small-text">Officials receive their first password from the person who registered them.</p>
  </form>
</div>
<?php page_end();
