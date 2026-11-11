<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
require_login();

/* ───────────── POST: save / send now / test ───────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    session_write_close(); // may take a while (SMTP); do not hold session lock
    $action = (string)($_POST['action'] ?? 'save');
    $cur = settings();
    $errors = [];

    $time = (string)($_POST['report_time'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $errors[] = 'Send time must be HH:MM.';
        $time = $cur['report_time'];
    }

    $days = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['weekdays'] ?? [])),
        static fn($d) => $d >= 1 && $d <= 7
    )));
    sort($days);
    if (!$days) {
        $errors[] = 'Select at least one weekday.';
        $days = array_map('intval', explode(',', $cur['report_weekdays']));
    }

    $rawRecipients = trim((string)($_POST['report_recipients'] ?? ''));
    $bad = [];
    foreach (preg_split('/[\s,;]+/', $rawRecipients, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
        if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $bad[] = $a;
        }
    }
    if ($bad) {
        $errors[] = 'Invalid recipient(s): ' . implode(', ', array_slice($bad, 0, 5));
    }
    $recipients = implode(', ', parse_recipients($rawRecipients));

    $from = trim((string)($_POST['smtp_from'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid From address.';
        $from = $cur['smtp_from'];
    }

    $port = (int)($_POST['smtp_port'] ?? 25);
    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTP port must be 1-65535.';
        $port = (int)$cur['smtp_port'];
    }
    $sec = (string)($_POST['smtp_security'] ?? 'none');
    if (!in_array($sec, ['none', 'starttls', 'ssl'], true)) {
        $sec = 'none';
    }
    $maxItems = max(0, min(500, (int)($_POST['report_max_items'] ?? 50)));
    $retention = max(1, min(365, (int)($_POST['retention_days'] ?? 30)));
    $pass = (string)($_POST['smtp_pass'] ?? '');

    $subject = trim((string)($_POST['report_subject'] ?? ''));
    if ($subject === '') {
        $subject = setting_defaults()['report_subject'];
    }

    settings_save([
        'report_enabled'    => isset($_POST['report_enabled']) ? '1' : '0',
        'report_time'       => $time,
        'report_weekdays'   => implode(',', $days),
        'report_day'        => ($_POST['report_day'] ?? '') === 'today' ? 'today' : 'yesterday',
        'report_recipients' => $recipients,
        'report_subject'    => mb_substr($subject, 0, 200),
        'report_details'    => isset($_POST['report_details']) ? '1' : '0',
        'report_max_items'  => (string)$maxItems,
        'smtp_host'         => trim((string)($_POST['smtp_host'] ?? '')),
        'smtp_port'         => (string)$port,
        'smtp_security'     => $sec,
        'smtp_user'         => trim((string)($_POST['smtp_user'] ?? '')),
        'smtp_pass'         => $pass !== '' ? $pass : $cur['smtp_pass'],
        'smtp_from'         => $from,
        'smtp_verify_tls'   => isset($_POST['smtp_verify_tls']) ? '1' : '0',
        'retention_days'    => (string)$retention,
    ]);

    session_start();
    if ($errors) {
        flash('err', implode(' ', $errors));
    } elseif ($action === 'send_now') {
        $d = (string)($_POST['report_date'] ?? '');
        $res = valid_date($d) ? send_report($d, 'manual') : ['ok' => false, 'message' => 'Pick a valid report date.'];
        flash($res['ok'] ? 'ok' : 'err', ($res['ok'] ? 'Settings saved. ' : 'Settings saved, but report failed: ') . $res['message']);
    } elseif ($action === 'test') {
        $res = send_test_email();
        flash($res['ok'] ? 'ok' : 'err', ($res['ok'] ? 'Settings saved. ' : 'Settings saved, but test failed: ') . $res['message']);
    } else {
        flash('ok', 'Settings saved.');
    }
    header('Location: settings.php');
    exit;
}

/* ───────────── GET: render ───────────── */
$s = settings();
$flash = flash_take();
session_write_close();

$runs = db()->query('SELECT * FROM report_runs ORDER BY id DESC LIMIT 15')->fetchAll();
$weekdays = array_map('intval', explode(',', $s['report_weekdays']));
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$yesterday = date('Y-m-d', strtotime('yesterday'));

layout_start('Settings', 'settings');

$sel = static fn(bool $c): string => $c ? ' selected' : '';
$chk = static fn(bool $c): string => $c ? ' checked' : '';
?>
        <h1 class="h3 mb-1"><strong>Settings</strong></h1>
        <p class="text-body-secondary mb-3">Daily delivery report and SMTP for sending it</p>

        <?php if ($flash): ?>
          <div class="alert alert-<?= $flash[0] === 'ok' ? 'success' : 'danger' ?>" role="status"><div class="alert-message"><?= h($flash[1]) ?></div></div>
        <?php endif; ?>

        <form method="post" action="settings.php" autocomplete="off">
          <?= csrf_field() ?>

          <div class="card">
            <div class="card-header"><h2 class="card-title mb-0">Daily report</h2></div>
            <div class="card-body">
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="report_enabled" name="report_enabled" value="1"<?= $chk($s['report_enabled'] === '1') ?>>
                <label class="form-check-label" for="report_enabled">Send the daily report automatically</label>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-4">
                  <label class="form-label" for="report_time">Send time</label>
                  <input type="time" class="form-control" id="report_time" name="report_time" value="<?= h($s['report_time']) ?>" required>
                  <div class="form-text">Server time zone: <?= h(date_default_timezone_get()) ?></div>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="report_day">Report covers</label>
                  <select class="form-select" id="report_day" name="report_day">
                    <option value="yesterday"<?= $sel($s['report_day'] === 'yesterday') ?>>Previous day (full day)</option>
                    <option value="today"<?= $sel($s['report_day'] === 'today') ?>>Current day (until send time)</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="report_max_items">Max problem lines in report</label>
                  <input type="number" class="form-control" id="report_max_items" name="report_max_items" min="0" max="500" value="<?= h($s['report_max_items']) ?>">
                  <div class="form-text">Bounced, rejected, deferred, errors. 0 = summary only.</div>
                </div>
              </div>

              <div class="mb-3">
                <div class="form-label">Send on</div>
                <?php foreach ($dayNames as $n => $label): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="wd<?= $n ?>" name="weekdays[]" value="<?= $n ?>"<?= $chk(in_array($n, $weekdays, true)) ?>>
                    <label class="form-check-label" for="wd<?= $n ?>"><?= h($label) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="mb-3">
                <label class="form-label" for="report_recipients">Recipients</label>
                <textarea class="form-control" id="report_recipients" name="report_recipients" rows="2" placeholder="admin@example.com, it@example.com"><?= h($s['report_recipients']) ?></textarea>
                <div class="form-text">Separate with comma, space or new line.</div>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label" for="report_subject">Subject</label>
                  <input type="text" class="form-control" id="report_subject" name="report_subject" value="<?= h($s['report_subject']) ?>">
                  <div class="form-text">Placeholders: {date} {host}</div>
                </div>
              </div>

              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="report_details" name="report_details" value="1"<?= $chk($s['report_details'] === '1') ?>>
                <label class="form-check-label" for="report_details">Include list of problem messages</label>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h2 class="card-title mb-0">SMTP server for reports</h2></div>
            <div class="card-body">
              <div class="row g-3 mb-3">
                <div class="col-md-4">
                  <label class="form-label" for="smtp_host">Host</label>
                  <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= h($s['smtp_host']) ?>" placeholder="127.0.0.1">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="smtp_port">Port</label>
                  <input type="number" class="form-control" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?= h($s['smtp_port']) ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="smtp_security">Security</label>
                  <select class="form-select" id="smtp_security" name="smtp_security">
                    <option value="none"<?= $sel($s['smtp_security'] === 'none') ?>>None (25)</option>
                    <option value="starttls"<?= $sel($s['smtp_security'] === 'starttls') ?>>STARTTLS (587)</option>
                    <option value="ssl"<?= $sel($s['smtp_security'] === 'ssl') ?>>SSL/TLS (465)</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="smtp_user">Username</label>
                  <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="<?= h($s['smtp_user']) ?>" autocomplete="off">
                  <div class="form-text">Empty = no authentication.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="smtp_pass">Password</label>
                  <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" value="" placeholder="<?= $s['smtp_pass'] !== '' ? '•••••••• (saved)' : '' ?>" autocomplete="new-password">
                  <div class="form-text">Leave empty to keep the saved password.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="smtp_from">From address</label>
                  <input type="email" class="form-control" id="smtp_from" name="smtp_from" value="<?= h($s['smtp_from']) ?>" placeholder="postfix-report@example.com">
                </div>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="smtp_verify_tls" name="smtp_verify_tls" value="1"<?= $chk($s['smtp_verify_tls'] === '1') ?>>
                <label class="form-check-label" for="smtp_verify_tls">Verify TLS certificate (turn off for self-signed certificates)</label>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h2 class="card-title mb-0">Data</h2></div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label" for="retention_days">Keep parsed log index (days)</label>
                  <input type="number" class="form-control" id="retention_days" name="retention_days" min="1" max="365" value="<?= h($s['retention_days']) ?>">
                  <div class="form-text">Only the SQLite cache is purged. Log files are never touched.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <button class="btn btn-primary" type="submit" name="action" value="save"><i class="align-middle" data-lucide="save"></i> <span class="align-middle">Save settings</span></button>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="test"><i class="align-middle" data-lucide="send"></i> <span class="align-middle">Save &amp; send test e-mail</span></button>
                <span class="flex-grow-1"></span>
                <label class="d-flex align-items-center gap-2 mb-0">
                  <span class="text-body-secondary text-nowrap">Report date</span>
                  <input type="date" class="form-control" name="report_date" value="<?= h($yesterday) ?>" max="<?= h(date('Y-m-d')) ?>">
                </label>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="send_now"><i class="align-middle" data-lucide="forward"></i> <span class="align-middle">Save &amp; send report now</span></button>
              </div>
            </div>
          </div>
        </form>

        <div class="card">
          <div class="card-header"><h2 class="card-title mb-0">Recent report runs</h2></div>
          <?php if (!$runs): ?>
            <div class="card-body text-center text-body-secondary py-5">
              <i data-lucide="inbox"></i>
              <div class="mt-2">No reports sent yet</div>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover my-0" aria-label="Report runs">
                <thead><tr><th>When</th><th>Report date</th><th>Source</th><th>Result</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r): ?>
                  <tr>
                    <td class="text-nowrap font-monospace small"><?= h(date('Y-m-d H:i', (int)$r['run_at'])) ?></td>
                    <td class="font-monospace small"><?= h((string)$r['report_date']) ?: '—' ?></td>
                    <td><?= h((string)$r['source']) ?></td>
                    <td><span class="badge bg-<?= (int)$r['ok'] ? 'success' : 'danger' ?>"><?= (int)$r['ok'] ? 'OK' : 'Failed' ?></span></td>
                    <td class="text-body-secondary text-break"><?= h((string)$r['message']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
<?php
layout_end();
