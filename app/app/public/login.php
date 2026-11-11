<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please sign in again.';
    } else {
        $error = attempt_login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    }
    if ($error === null) {
        header('Location: index.php');
        exit;
    }
}

layout_head('Sign in');
?>
<body>
  <main class="d-flex w-100 reveal-cards">
    <div class="container d-flex flex-column">
      <div class="row gx-0 vh-100">
        <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 mx-auto d-table h-100">
          <div class="d-table-cell align-middle">

            <div class="text-center mt-4">
              <h1 class="h2">Welcome back!</h1>
              <p class="lead">Sign in to Postfix Log Viewer</p>
            </div>

            <div class="card">
              <div class="card-body">
                <div class="m-sm-3">
                  <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><div class="alert-message"><?= h($error) ?></div></div>
                  <?php endif; ?>
                  <form method="post" action="login.php" autocomplete="on">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                      <label class="form-label" for="username">Username</label>
                      <input class="form-control form-control-lg" id="username" type="text" name="username" placeholder="Enter your username"
                             autocomplete="username" autofocus required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label" for="password">Password</label>
                      <input class="form-control form-control-lg" id="password" type="password" name="password" placeholder="Enter your password"
                             autocomplete="current-password" required>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                      <button type="submit" class="btn btn-lg btn-primary">Sign in</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
