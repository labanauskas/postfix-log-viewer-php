<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

session_name('pfv');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

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

/* ───────────── layout (same markup/classes as dashboard.html) ───────────── */

function layout_head(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — Postfix Log Viewer</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="assets/app.css">
  <script>try{var t=localStorage.getItem('adminkit-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
</head>
<?php
}

function layout_start(string $title, string $active): void
{
    layout_head($title);
    $user = (string)($_SESSION['user'] ?? '');
    $initials = strtoupper(substr($user, 0, 2));
    $items = [
        'logs'     => ['index.php', 'ti-mail', 'Mail log'],
        'settings' => ['settings.php', 'ti-settings', 'Settings'],
    ];
    ?>
<body>
<div class="app">
  <nav class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="sidebar-logo">
      <div class="logo-mark"><i class="ti ti-mail-fast"></i></div>
      <span class="logo-text">Postfix Log</span>
    </div>
    <div class="sidebar-nav">
      <div class="nav-section">
        <div class="nav-label">Monitor</div>
        <?php foreach ($items as $key => [$href, $icon, $label]): ?>
        <a class="nav-item<?= $key === $active ? ' active' : '' ?>" href="<?= h($href) ?>">
          <i class="ti <?= h($icon) ?>" aria-hidden="true"></i>
          <span class="nav-item-text"><?= h($label) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="sidebar-footer">
      <div class="user-card">
        <div class="avatar"><?= h($initials) ?></div>
        <div class="user-info">
          <div class="user-name"><?= h($user) ?></div>
          <div class="user-role"><?= h(app_hostname()) ?></div>
        </div>
      </div>
      <a class="nav-item" href="logout.php" style="margin-top:4px;">
        <i class="ti ti-logout" aria-hidden="true"></i>
        <span class="nav-item-text">Sign out</span>
      </a>
    </div>
  </nav>
  <div class="sidebar-overlay" onclick="closeMobileSidebar()" id="sidebarOverlay"></div>

  <div class="main" id="mainContent">
    <header class="topbar">
      <button class="topbar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
        <i class="ti ti-menu-2"></i>
      </button>
      <div class="topbar-actions">
        <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle dark mode">
          <i class="ti ti-moon" id="themeIcon"></i>
        </button>
        <div class="avatar" style="margin-left:4px;" title="<?= h($user) ?>"><?= h($initials) ?></div>
      </div>
    </header>
<?php
}

function layout_end(array $scripts = []): void
{
    ?>
  </div><!-- /main -->
</div><!-- /app -->
<script src="assets/app.js"></script>
<?php foreach ($scripts as $s): ?>
<script src="assets/<?= h($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
