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
?>
    <div class="page active">
      <div class="page-header">
        <div>
          <h1 class="page-title">Settings</h1>
          <p class="page-subtitle">Daily delivery report and SMTP for sending it</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="status"><?= h($flash[1]) ?></div>
      <?php endif; ?>

      <form method="post" action="settings.php" class="settings-wrap" autocomplete="off">
        <?= csrf_field() ?>

        <div class="card">
          <div class="card-head"><i class="ti ti-calendar-time"></i> Daily report</div>
          <div class="card-body">
            <label class="checkbox-label">
              <input type="checkbox" name="report_enabled" value="1" <?= $s['report_enabled'] === '1' ? 'checked' : '' ?>>
              Send the daily report automatically
            </label>

            <div class="form-grid">
              <div class="field">
                <label class="form-label" for="report_time">Send time</label>
                <input type="time" id="report_time" name="report_time" value="<?= h($s['report_time']) ?>" required>
                <div class="hint">Server time zone: <?= h(date_default_timezone_get()) ?></div>
              </div>
              <div class="field">
                <label class="form-label" for="report_day">Report covers</label>
                <select id="report_day" name="report_day">
                  <option value="yesterday" <?= $s['report_day'] === 'yesterday' ? 'selected' : '' ?>>Previous day (full day)</option>
                  <option value="today" <?= $s['report_day'] === 'today' ? 'selected' : '' ?>>Current day (until send time)</option>
                </select>
              </div>
              <div class="field">
                <label class="form-label" for="report_max_items">Max problem lines in report</label>
                <input type="number" id="report_max_items" name="report_max_items" min="0" max="500" value="<?= h($s['report_max_items']) ?>">
                <div class="hint">Bounced, rejected, deferred, errors. 0 = summary only.</div>
              </div>
            </div>

            <div class="field">
              <span class="form-label">Send on</span>
              <div class="check-row">
                <?php foreach ($dayNames as $n => $label): ?>
                  <label class="checkbox-label">
                    <input type="checkbox" name="weekdays[]" value="<?= $n ?>" <?= in_array($n, $weekdays, true) ? 'checked' : '' ?>> <?= h($label) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="field">
              <label class="form-label" for="report_recipients">Recipients</label>
              <textarea id="report_recipients" name="report_recipients" rows="2" placeholder="admin@example.com, it@example.com"><?= h($s['report_recipients']) ?></textarea>
              <div class="hint">Separate with comma, space or new line.</div>
            </div>

            <div class="form-grid">
              <div class="field">
                <label class="form-label" for="report_subject">Subject</label>
                <input type="text" id="report_subject" name="report_subject" value="<?= h($s['report_subject']) ?>">
                <div class="hint">Placeholders: {date} {host}</div>
              </div>
            </div>

            <label class="checkbox-label">
              <input type="checkbox" name="report_details" value="1" <?= $s['report_details'] === '1' ? 'checked' : '' ?>>
              Include list of problem messages
            </label>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><i class="ti ti-server"></i> SMTP server for reports</div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field">
                <label class="form-label" for="smtp_host">Host</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?= h($s['smtp_host']) ?>" placeholder="mail.example.com">
              </div>
              <div class="field">
                <label class="form-label" for="smtp_port">Port</label>
                <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?= h($s['smtp_port']) ?>">
              </div>
              <div class="field">
                <label class="form-label" for="smtp_security">Security</label>
                <select id="smtp_security" name="smtp_security">
                  <option value="none" <?= $s['smtp_security'] === 'none' ? 'selected' : '' ?>>None (25)</option>
                  <option value="starttls" <?= $s['smtp_security'] === 'starttls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                  <option value="ssl" <?= $s['smtp_security'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                </select>
              </div>
              <div class="field">
                <label class="form-label" for="smtp_user">Username</label>
                <input type="text" id="smtp_user" name="smtp_user" value="<?= h($s['smtp_user']) ?>" autocomplete="off">
                <div class="hint">Empty = no authentication.</div>
              </div>
              <div class="field">
                <label class="form-label" for="smtp_pass">Password</label>
                <input type="password" id="smtp_pass" name="smtp_pass" value="" placeholder="<?= $s['smtp_pass'] !== '' ? '•••••••• (saved)' : '' ?>" autocomplete="new-password">
                <div class="hint">Leave empty to keep the saved password.</div>
              </div>
              <div class="field">
                <label class="form-label" for="smtp_from">From address</label>
                <input type="email" id="smtp_from" name="smtp_from" value="<?= h($s['smtp_from']) ?>" placeholder="postfix-report@example.com">
              </div>
            </div>
            <label class="checkbox-label">
              <input type="checkbox" name="smtp_verify_tls" value="1" <?= $s['smtp_verify_tls'] === '1' ? 'checked' : '' ?>>
              Verify TLS certificate (turn off for self-signed certificates)
            </label>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><i class="ti ti-database"></i> Data</div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field">
                <label class="form-label" for="retention_days">Keep parsed log index (days)</label>
                <input type="number" id="retention_days" name="retention_days" min="1" max="365" value="<?= h($s['retention_days']) ?>">
                <div class="hint">Only the SQLite cache is purged. Log files are never touched.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="actions-row">
          <button class="btn btn-primary" type="submit" name="action" value="save"><i class="ti ti-device-floppy"></i> Save settings</button>
          <button class="btn btn-ghost" type="submit" name="action" value="test"><i class="ti ti-send"></i> Save &amp; send test e-mail</button>
          <span class="spacer"></span>
          <label class="inline-field">Report date
            <input type="date" name="report_date" value="<?= h($yesterday) ?>" max="<?= h(date('Y-m-d')) ?>">
          </label>
          <button class="btn btn-ghost" type="submit" name="action" value="send_now"><i class="ti ti-mail-forward"></i> Save &amp; send report now</button>
        </div>

        <div class="card">
          <div class="card-head"><i class="ti ti-history"></i> Recent report runs</div>
          <?php if (!$runs): ?>
            <div class="empty-state"><i class="ti ti-mail-off"></i><p>No reports sent yet</p></div>
          <?php else: ?>
            <div style="overflow-x:auto;">
              <table class="runs" aria-label="Report runs">
                <thead><tr><th>When</th><th>Report date</th><th>Source</th><th>Result</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r): ?>
                  <tr>
                    <td class="nowrap mono"><?= h(date('Y-m-d H:i', (int)$r['run_at'])) ?></td>
                    <td class="mono"><?= h((string)$r['report_date']) ?: '—' ?></td>
                    <td><?= h((string)$r['source']) ?></td>
                    <td><span class="badge badge-<?= (int)$r['ok'] ? 'success' : 'danger' ?>"><?= (int)$r['ok'] ? 'OK' : 'Failed' ?></span></td>
                    <td class="muted"><?= h((string)$r['message']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </form>
    </div>
<?php
layout_end();
