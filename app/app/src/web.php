<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_name('pfv');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $isHttps]);
session_start();

/** URL of a file under public/assets with a version query, so browsers fetch it again after an update. */
function asset(string $path): string
{
    $file = __DIR__ . '/../public/assets/' . $path;
    $v = is_file($file) ? (string)filemtime($file) : '0';
    return 'assets/' . $path . '?v=' . $v;
}

function send_page_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store');
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function require_login(bool $json = false): void
{
    if (is_logged_in()) {
        return;
    }
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not signed in']);
    } else {
        header('Location: login.php');
    }
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back and reload the page.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = [$type, $msg];
}

function flash_take(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Returns error text, or null on success. */
function attempt_login(string $user, string $pass): ?string
{
    $pdo = db();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?');
    $st->execute([$ip, time() - 600]);
    if ((int)$st->fetchColumn() >= 8) {
        return 'Too many failed attempts. Try again in a few minutes.';
    }

    $adminUser = getenv('ADMIN_USER') ?: 'admin';
    $hash = (string)getenv('ADMIN_PASSWORD_HASH');
    $plain = (string)getenv('ADMIN_PASSWORD');
    $okUser = hash_equals($adminUser, $user);
    $okPass = $hash !== '' ? password_verify($pass, $hash) : ($plain !== '' && hash_equals($plain, $pass));

    if ($okUser && $okPass) {
        session_regenerate_id(true);
        $_SESSION['user'] = $adminUser;
        return null;
    }
    $pdo->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
    usleep(700000);
    return 'Wrong username or password.';
}

/* ───────────── layout (AdminKit 4.x, Bootstrap 5.3, Lucide icons; all assets local) ───────────── */

function layout_head(string $title): void
{
    send_page_headers();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex">
  <link rel="shortcut icon" href="<?= h(asset('img/icons/icon-48x48.png')) ?>">
  <title><?= h($title) ?> | Postfix Log Viewer</title>

  <!-- Color mode before the first paint -->
  <script src="<?= h(asset('js/head.js')) ?>"></script>

  <script type="module" crossorigin src="<?= h(asset('js/app.js')) ?>"></script>
  <script type="module" crossorigin src="<?= h(asset('js/icons-app.js')) ?>"></script>
  <link rel="stylesheet" crossorigin href="<?= h(asset('css/app.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset('css/custom.css')) ?>">
  <link rel="preload" href="assets/fonts/inter-latin-400-normal-C38fXH4l.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/inter-latin-600-normal-LgqL8muc.woff2" as="font" type="font/woff2" crossorigin>
</head>
<?php
}

function layout_start(string $title, string $active): void
{
    layout_head($title);
    $user = (string)($_SESSION['user'] ?? '');
    $items = [
        'logs'     => ['index.php', 'mail', 'Mail log'],
        'settings' => ['settings.php', 'settings', 'Settings'],
    ];
    ?>
<body>
<div class="wrapper">
  <nav id="sidebar" class="sidebar js-sidebar offcanvas-lg offcanvas-start" tabindex="-1" aria-label="Sidebar">
    <div class="sidebar-content js-simplebar">
      <a class="sidebar-brand" href="index.php">
        <span class="align-middle">Postfix Log</span>
      </a>

      <ul class="sidebar-nav">
        <li class="sidebar-header">Monitor</li>
        <?php foreach ($items as $key => [$href, $icon, $label]): ?>
        <li class="sidebar-item<?= $key === $active ? ' active' : '' ?>">
          <a class="sidebar-link" href="<?= h($href) ?>">
            <i class="align-middle" data-lucide="<?= h($icon) ?>"></i> <span class="align-middle"><?= h($label) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
        <li class="sidebar-header">Account</li>
        <li class="sidebar-item">
          <a class="sidebar-link" href="logout.php">
            <i class="align-middle" data-lucide="log-out"></i> <span class="align-middle">Sign out</span>
          </a>
        </li>
      </ul>
    </div>
  </nav>

  <div class="main">
    <nav class="navbar navbar-expand navbar-light navbar-bg" aria-label="Top bar">
      <button type="button" class="sidebar-toggle js-sidebar-toggle" aria-controls="sidebar" aria-expanded="true" aria-label="Collapse sidebar">
        <i class="hamburger align-self-center"></i>
      </button>

      <div class="navbar-collapse collapse">
        <ul class="navbar-nav navbar-align">
          <li class="nav-item dropdown">
            <a class="nav-icon dropdown-toggle" href="#" id="colorModeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"
               aria-label="Color mode" data-color-mode-toggle>
              <span data-color-mode-icon="light" hidden><i class="align-middle" data-lucide="sun"></i></span>
              <span data-color-mode-icon="dark" hidden><i class="align-middle" data-lucide="moon"></i></span>
              <span data-color-mode-icon="auto"><i class="align-middle" data-lucide="sun-moon"></i></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="colorModeDropdown">
              <li><button type="button" class="dropdown-item" data-color-mode-value="light" aria-pressed="false"><i class="align-middle me-1" data-lucide="sun"></i> Light</button></li>
              <li><button type="button" class="dropdown-item" data-color-mode-value="dark" aria-pressed="false"><i class="align-middle me-1" data-lucide="moon"></i> Dark</button></li>
              <li><button type="button" class="dropdown-item" data-color-mode-value="auto" aria-pressed="false"><i class="align-middle me-1" data-lucide="sun-moon"></i> Auto</button></li>
            </ul>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="align-middle me-1" data-lucide="user"></i> <span class="text-dark"><?= h($user) ?></span>
              <span class="text-body-secondary small ms-1 d-none d-md-inline"><?= h(app_hostname()) ?></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end">
              <a class="dropdown-item" href="settings.php"><i class="align-middle me-1" data-lucide="settings"></i> Settings</a>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item" href="logout.php"><i class="align-middle me-1" data-lucide="log-out"></i> Sign out</a>
            </div>
          </li>
        </ul>
      </div>
    </nav>

    <main class="content reveal-cards">
      <div class="container-fluid p-0">
<?php
}

function layout_end(array $scripts = []): void
{
    ?>
      </div>
    </main>

    <footer class="footer reveal">
      <div class="container-fluid">
        <div class="row text-body-secondary">
          <div class="col-6 text-start">
            <p class="mb-0">Postfix Log Viewer &middot; <?= h(app_hostname()) ?></p>
          </div>
          <div class="col-6 text-end">
            <p class="mb-0">Design: AdminKit</p>
          </div>
        </div>
      </div>
    </footer>
  </div><!-- /main -->
</div><!-- /wrapper -->
<?php foreach ($scripts as $s): ?>
<script type="module" src="<?= h(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
