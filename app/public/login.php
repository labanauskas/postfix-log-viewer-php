<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $error = attempt_login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    if ($error === null) {
        header('Location: index.php');
        exit;
    }
}

layout_head('Sign in');
?>
<body>

<button class="login-theme-toggle" onclick="toggleTheme()" aria-label="Toggle dark mode" type="button">
  <i class="ti ti-moon" id="themeIcon"></i>
</button>

<div class="login-wrapper">
  <form class="login-card" method="post" action="login.php" autocomplete="on">
    <?= csrf_field() ?>

    <h1 class="login-title">Welcome back!</h1>
    <p class="login-subtitle">Sign in to Postfix Log Viewer</p>

    <?php if ($error): ?>
      <div class="form-error" role="alert"><i class="ti ti-alert-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="form-group">
      <label class="form-label" for="username">Username</label>
      <div class="input-icon-wrap">
        <i class="ti ti-user" aria-hidden="true"></i>
        <input id="username" name="username" type="text" class="form-input" placeholder="admin" autocomplete="username" autofocus required>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="password">Password</label>
      <div class="input-icon-wrap">
        <i class="ti ti-lock" aria-hidden="true"></i>
        <input id="password" name="password" type="password" class="form-input" placeholder="••••••••" autocomplete="current-password" required>
      </div>
    </div>

    <button class="btn-login" type="submit">
      Log in <i class="ti ti-arrow-right"></i>
    </button>
  </form>
</div>

<script src="assets/app.js"></script>
</body>
</html>
