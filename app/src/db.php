<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.db', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=8000');
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS day_index (
    date TEXT PRIMARY KEY,
    sig TEXT NOT NULL,
    parsed_at INTEGER NOT NULL,
    lines INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS messages (
    date TEXT NOT NULL,
    id TEXT NOT NULL,
    seq INTEGER NOT NULL,
    kind TEXT NOT NULL,
    ts TEXT NOT NULL,
    status TEXT NOT NULL,
    sender TEXT,
    rcpt TEXT,
    rcpt_count INTEGER NOT NULL DEFAULT 0,
    size INTEGER,
    relay TEXT,
    reason TEXT,
    client TEXT,
    search TEXT,
    data TEXT NOT NULL,
    PRIMARY KEY (date, id)
);
CREATE INDEX IF NOT EXISTS idx_messages_status ON messages (date, status);
CREATE INDEX IF NOT EXISTS idx_messages_ts ON messages (date, ts, seq);

CREATE TABLE IF NOT EXISTS recipients (
    date TEXT NOT NULL,
    msg_id TEXT NOT NULL,
    addr TEXT,
    domain TEXT,
    status TEXT,
    relay TEXT,
    dsn TEXT,
    reason TEXT
);
CREATE INDEX IF NOT EXISTS idx_recipients_date ON recipients (date, status);

CREATE TABLE IF NOT EXISTS report_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_at INTEGER NOT NULL,
    report_date TEXT,
    source TEXT NOT NULL,
    ok INTEGER NOT NULL,
    message TEXT
);

CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL);
SQL);
}

function setting_defaults(): array
{
    return [
        'report_enabled'    => '0',
        'report_time'       => '07:00',
        'report_weekdays'   => '1,2,3,4,5,6,7',
        'report_day'        => 'yesterday', // yesterday | today
        'report_recipients' => '',
        'report_subject'    => 'Mail report {date} - {host}',
        'report_details'    => '1',
        'report_max_items'  => '50',
        'smtp_host'         => (string)(getenv('SMTP_HOST') ?: ''),
        'smtp_port'         => (string)(getenv('SMTP_PORT') ?: '25'),
        'smtp_security'     => (string)(getenv('SMTP_SECURITY') ?: 'none'), // none | starttls | ssl
        'smtp_user'         => (string)(getenv('SMTP_USER') ?: ''),
        'smtp_pass'         => (string)(getenv('SMTP_PASS') ?: ''),
        'smtp_from'         => (string)(getenv('SMTP_FROM') ?: ''),
        'smtp_verify_tls'   => '1',
        'retention_days'    => '30',
    ];
}

function settings(): array
{
    $out = setting_defaults();
    foreach (db()->query('SELECT k, v FROM settings') as $r) {
        if (array_key_exists($r['k'], $out)) {
            $out[$r['k']] = $r['v'];
        }
    }
    return $out;
}

function settings_save(array $kv): void
{
    $allowed = setting_defaults();
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (:k, :v) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    foreach ($kv as $k => $v) {
        if (array_key_exists($k, $allowed)) {
            $st->execute([':k' => $k, ':v' => (string)$v]);
        }
    }
    $pdo->commit();
}

function log_report_run(?string $date, string $source, bool $ok, string $message): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO report_runs (run_at, report_date, source, ok, message) VALUES (?,?,?,?,?)')
        ->execute([time(), $date, $source, $ok ? 1 : 0, mb_substr($message, 0, 500)]);
    $pdo->exec('DELETE FROM report_runs WHERE id NOT IN (SELECT id FROM report_runs ORDER BY id DESC LIMIT 200)');
}

function purge_old_data(int $keepDays): void
{
    $cut = date('Y-m-d', strtotime('-' . max(1, $keepDays) . ' days'));
    $pdo = db();
    foreach (['messages', 'recipients', 'day_index'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE date < ?")->execute([$cut]);
    }
    $pdo->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 3600]);
}
