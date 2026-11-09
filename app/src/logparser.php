<?php
declare(strict_types=1);

/*
 * Postfix mail.log parser.
 * Scans log files for one day, groups lines by queue ID into messages,
 * derives a status per message and caches the result in SQLite.
 */

const LOG_HEADER_RE = '/^(?:\d{4}-\d{2}-\d{2}T|[A-Z][a-z]{2}\s+\d{1,2}\s+)(\d{2}:\d{2}:\d{2})\S*\s+\S+\s+([^\s\[:]+)(?:\[\d+\])?:\s(.*)$/';
// Queue IDs always contain at least one digit; this keeps "warning:", "statistics:" etc. out.
const LOG_QID_RE = '/^((?=[0-9A-Za-z]{0,19}[0-9])[0-9A-Za-z]{6,20}):\s(.*)$/';

const MAX_RAW_LINES = 300;
const MAX_EVENTS = 20000;

const STATUS_LABELS = [
    'sent'     => 'Delivered',
    'deferred' => 'Deferred',
    'bounced'  => 'Bounced',
    'rejected' => 'Rejected',
    'error'    => 'Error',
    'queued'   => 'Queued',
    'other'    => 'Other',
];

/* ───────────── files ───────────── */

function log_files(): array
{
    $glob = getenv('MAIL_LOG_GLOB') ?: '/hostlog/mail.log*';
    $files = glob($glob) ?: [];
    $files = array_values(array_filter($files, static function ($f) {
        return is_file($f) && is_readable($f) && !preg_match('/\.(xz|bz2|zst|lz4)$/i', $f);
    }));
    usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));
    return $files;
}

function log_signature(array $files): string
{
    $parts = [];
    foreach ($files as $f) {
        $parts[] = $f . ':' . filesize($f) . ':' . filemtime($f);
    }
    return md5(implode('|', $parts));
}

function read_log_lines(string $file): Generator
{
    $gz = str_ends_with($file, '.gz');
    $fh = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$fh) {
        return;
    }
    try {
        while (($l = $gz ? gzgets($fh) : fgets($fh)) !== false) {
            yield $l;
        }
    } finally {
        $gz ? gzclose($fh) : fclose($fh);
    }
}

/* ───────────── parsing ───────────── */

function new_msg(string $id, int $seq, string $time): array
{
    return [
        'id' => $id, 'seq' => $seq, 'first_ts' => $time, 'last_ts' => $time,
        'sender' => null, 'size' => null, 'nrcpt' => null,
        'client' => '', 'sasl' => '', 'message_id' => '', 'subject' => '',
        'bounce_id' => '', 'recips' => [], 'warnings' => [],
        'removed' => false, 'expired' => false, 'rejected' => false, 'other_note' => '',
        'reason' => '', 'lines' => [],
    ];
}

function apply_body(array &$m, string $body, string $time, string $prog): void
{
    if (strncmp($body, 'client=', 7) === 0) {
        if (preg_match('/^client=([^,]+)/', $body, $x)) {
            $m['client'] = $x[1];
        }
        if (preg_match('/sasl_username=([^,\s]+)/', $body, $x)) {
            $m['sasl'] = $x[1];
        }
        return;
    }
    if (strncmp($body, 'message-id=', 11) === 0) {
        $m['message_id'] = trim(substr($body, 11), "<> \t");
        return;
    }
    if (preg_match('/^from=<([^>]*)>, size=(\d+), nrcpt=(\d+)/', $body, $x)) {
        $m['sender'] = $x[1];
        $m['size'] = (int)$x[2];
        $m['nrcpt'] = (int)$x[3];
        return;
    }
    if (preg_match('/^from=<([^>]*)>, status=expired/', $body, $x)) {
        $m['sender'] = $x[1];
        $m['expired'] = true;
        $m['reason'] = 'expired, returned to sender';
        return;
    }
    if (strncmp($body, 'to=<', 4) === 0) {
        $p = strpos($body, ', status=');
        if ($p === false) {
            return;
        }
        $kv = substr($body, 0, $p);
        $st = substr($body, $p + 2);
        $f = [];
        if (preg_match_all('/(\w+)=(<[^>]*>|[^,]*)(?:,\s*|$)/', $kv, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $f[$x[1]] = trim($x[2], "<> \t");
            }
        }
        if (!preg_match('/^status=(\S+)(?:\s+\((.*)\))?/s', $st, $s)) {
            return;
        }
        $key = ($f['to'] ?? '') . '|' . ($f['orig_to'] ?? '');
        $m['recips'][$key] = [
            'addr'     => $f['to'] ?? '',
            'orig_to'  => $f['orig_to'] ?? '',
            'status'   => strtolower($s[1]),
            'relay'    => $f['relay'] ?? '',
            'dsn'      => $f['dsn'] ?? '',
            'delay'    => $f['delay'] ?? '',
            'delays'   => $f['delays'] ?? '',
            'response' => trim($s[2] ?? ''),
            'time'     => $time,
            'proc'     => $prog,
            'attempts' => ($m['recips'][$key]['attempts'] ?? 0) + 1,
        ];
        return;
    }
    if ($body === 'removed') {
        $m['removed'] = true;
        return;
    }
    if (preg_match('/^(reject|milter-reject|hold|discard):\s*(.*)$/', $body, $x)) {
        if ($x[1] === 'hold' || $x[1] === 'discard') {
            $m['other_note'] = $x[1] . ': ' . $x[2];
        } else {
            $m['rejected'] = true;
            if (preg_match('/\bfrom=<([^>]*)>/', $x[2], $y) && $m['sender'] === null) {
                $m['sender'] = $y[1];
            }
            if (preg_match('/\bto=<([^>]*)>/', $x[2], $y)) {
                $m['recips']['rej|' . $y[1]] = [
                    'addr' => $y[1], 'orig_to' => '', 'status' => 'rejected', 'relay' => '',
                    'dsn' => '', 'delay' => '', 'delays' => '', 'response' => $x[2],
                    'time' => $time, 'proc' => $prog, 'attempts' => 1,
                ];
            }
            $m['reason'] = substr($x[2], 0, 300);
        }
        return;
    }
    if (preg_match('/^sender (?:non-)?delivery notification: (\S+)/', $body, $x)) {
        $m['bounce_id'] = $x[1];
        return;
    }
    if (preg_match('/^warning: header Subject: (.*?) from /', $body, $x)) {
        $m['subject'] = substr($x[1], 0, 200);
        return;
    }
    if (strncmp($body, 'warning:', 8) === 0 && count($m['warnings']) < 20) {
        $m['warnings'][] = substr($body, 0, 300);
    }
}

function make_event(int $seq, string $time, string $status, string $line, array $f): array
{
    return [
        'id' => 'evt-' . $seq, 'seq' => $seq, 'first_ts' => $time, 'status' => $status,
        'sender' => $f['sender'] ?? '', 'rcpt' => $f['rcpt'] ?? '', 'client' => $f['client'] ?? '',
        'reason' => substr($f['reason'] ?? '', 0, 400), 'proc' => $f['proc'] ?? '', 'line' => $line,
    ];
}

function addr_domain(string $addr): string
{
    $p = strrpos($addr, '@');
    return $p === false ? '' : strtolower(substr($addr, $p + 1));
}

function finalize_msg(array $m): array
{
    $c = ['sent' => 0, 'deferred' => 0, 'bounced' => 0, 'other' => 0];
    $relay = '';
    $reason = $m['reason'];
    $reasonFromRcpt = false;
    foreach ($m['recips'] as $r) {
        $s = $r['status'];
        isset($c[$s]) ? $c[$s]++ : $c['other']++;
        if ($relay === '' && $r['relay'] !== '' && $r['relay'] !== 'none') {
            $relay = $r['relay'];
        }
        if (!$reasonFromRcpt && $s !== 'sent' && $r['response'] !== '') {
            $reason = $r['response'];
            $reasonFromRcpt = true;
        }
    }
    if (!$reasonFromRcpt && $reason === '' && $c['sent'] > 0) {
        foreach ($m['recips'] as $r) {
            if ($r['response'] !== '') {
                $reason = $r['response'];
                break;
            }
        }
    }

    if ($m['rejected']) {
        $status = 'rejected';
    } elseif ($m['expired'] || $c['bounced'] > 0) {
        $status = 'bounced';
    } elseif ($c['deferred'] > 0) {
        $status = 'deferred';
    } elseif ($c['sent'] > 0) {
        $status = 'sent';
    } elseif ($c['other'] > 0 || $m['other_note'] !== '') {
        $status = 'other';
        if ($reason === '') {
            $reason = $m['other_note'];
        }
    } else {
        $status = 'queued';
    }

    $recips = array_values($m['recips']);
    $addrs = array_column($recips, 'addr');
    $first = $addrs[0] ?? '';

    $search = strtolower(implode(' ', array_filter(array_merge(
        [$m['id'], (string)$m['sender'], $m['message_id'], $m['client'], $m['sasl'], $m['subject'], $relay, $reason],
        $addrs,
        array_column($recips, 'orig_to')
    ))));

    $data = [
        'id' => $m['id'], 'kind' => 'msg', 'status' => $status,
        'first_ts' => $m['first_ts'], 'last_ts' => $m['last_ts'],
        'sender' => (string)$m['sender'], 'size' => $m['size'], 'nrcpt' => $m['nrcpt'],
        'client' => $m['client'], 'sasl' => $m['sasl'], 'message_id' => $m['message_id'],
        'subject' => $m['subject'], 'bounce_id' => $m['bounce_id'], 'expired' => $m['expired'],
        'removed' => $m['removed'], 'note' => $m['other_note'],
        'recipients' => $recips, 'warnings' => $m['warnings'], 'lines' => $m['lines'],
    ];

    return [
        'row' => [
            'id' => $m['id'], 'seq' => $m['seq'], 'kind' => 'msg', 'ts' => $m['first_ts'], 'status' => $status,
            'sender' => (string)$m['sender'], 'rcpt' => $first,
            'rcpt_count' => count($recips) ?: (int)($m['nrcpt'] ?? 0),
            'size' => $m['size'], 'relay' => $relay, 'reason' => substr($reason, 0, 300),
            'client' => $m['client'], 'search' => $search,
        ],
        'recips' => $recips,
        'data' => $data,
    ];
}

function scan_day(string $date, array $files): array
{
    $t = strtotime($date . ' 12:00:00');
    $isoPrefix = $date . 'T';
    $sysPrefix = date('M', $t) . ' ' . str_pad((string)(int)date('j', $t), 2, ' ', STR_PAD_LEFT) . ' ';
    $isoLen = strlen($isoPrefix);
    $sysLen = strlen($sysPrefix);

    $msgs = [];
    $events = [];
    $seq = 0;
    $lines = 0;

    foreach ($files as $file) {
        foreach (read_log_lines($file) as $raw) {
            if (strncmp($raw, $isoPrefix, $isoLen) !== 0 && strncmp($raw, $sysPrefix, $sysLen) !== 0) {
                continue;
            }
            $line = rtrim($raw, "\r\n");
            if (!preg_match(LOG_HEADER_RE, $line, $h)) {
                continue;
            }
            [, $time, $prog, $rest] = $h;
            if (strncmp($prog, 'postfix', 7) !== 0) {
                continue;
            }
            $lines++;
            $seq++;

            if (preg_match(LOG_QID_RE, $rest, $q)) {
                $id = $q[1];
                if (!isset($msgs[$id])) {
                    $msgs[$id] = new_msg($id, $seq, $time);
                }
                $m =& $msgs[$id];
                $m['last_ts'] = $time;
                if (count($m['lines']) < MAX_RAW_LINES) {
                    $m['lines'][] = $line;
                }
                apply_body($m, $q[2], $time, $prog);
                unset($m);
            } elseif (strncmp($rest, 'NOQUEUE: reject: ', 17) === 0) {
                if (count($events) >= MAX_EVENTS) {
                    continue;
                }
                $after = substr($rest, 17);
                $f = ['proc' => $prog];
                if (preg_match('/^(\w+) from (\S+): (.*)$/', $after, $mm)) {
                    $f['client'] = $mm[2];
                    $f['reason'] = preg_replace('/;\s*from=<.*$/', '', $mm[3]);
                } else {
                    $f['reason'] = $after;
                }
                if (preg_match('/\bfrom=<([^>]*)>/', $after, $mm)) {
                    $f['sender'] = $mm[1];
                }
                if (preg_match('/\bto=<([^>]*)>/', $after, $mm)) {
                    $f['rcpt'] = $mm[1];
                }
                $events[] = make_event($seq, $time, 'rejected', $line, $f);
            } elseif (preg_match('/^(warning|error|fatal|panic):\s*(.*)$/i', $rest, $w)) {
                if (count($events) >= MAX_EVENTS) {
                    continue;
                }
                $events[] = make_event($seq, $time, 'error', $line, ['reason' => $w[1] . ': ' . $w[2], 'proc' => $prog]);
            }
        }
    }

    $out = [];
    foreach ($msgs as $m) {
        $out[] = finalize_msg($m);
    }
    return ['msgs' => $out, 'events' => $events, 'lines' => $lines];
}

/* ───────────── cache in SQLite ───────────── */

function store_day(string $date, string $sig, array $res): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (['messages', 'recipients', 'day_index'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE date = ?")->execute([$date]);
        }
        $im = $pdo->prepare('INSERT OR REPLACE INTO messages
            (date,id,seq,kind,ts,status,sender,rcpt,rcpt_count,size,relay,reason,client,search,data)
            VALUES (:date,:id,:seq,:kind,:ts,:status,:sender,:rcpt,:rcpt_count,:size,:relay,:reason,:client,:search,:data)');
        $ir = $pdo->prepare('INSERT INTO recipients (date,msg_id,addr,domain,status,relay,dsn,reason)
            VALUES (?,?,?,?,?,?,?,?)');

        foreach ($res['msgs'] as $x) {
            $r = $x['row'];
            $im->execute([
                ':date' => $date, ':id' => $r['id'], ':seq' => $r['seq'], ':kind' => 'msg', ':ts' => $r['ts'],
                ':status' => $r['status'], ':sender' => $r['sender'], ':rcpt' => $r['rcpt'],
                ':rcpt_count' => $r['rcpt_count'], ':size' => $r['size'], ':relay' => $r['relay'],
                ':reason' => $r['reason'], ':client' => $r['client'], ':search' => $r['search'],
                ':data' => json_encode($x['data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            foreach ($x['recips'] as $rc) {
                $ir->execute([$date, $r['id'], $rc['addr'], addr_domain($rc['addr']), $rc['status'],
                    $rc['relay'], $rc['dsn'], substr($rc['response'], 0, 300)]);
            }
        }
        foreach ($res['events'] as $e) {
            $data = [
                'id' => $e['id'], 'kind' => 'event', 'status' => $e['status'], 'first_ts' => $e['first_ts'],
                'sender' => $e['sender'], 'recipient' => $e['rcpt'], 'client' => $e['client'],
                'reason' => $e['reason'], 'process' => $e['proc'], 'lines' => [$e['line']],
            ];
            $im->execute([
                ':date' => $date, ':id' => $e['id'], ':seq' => $e['seq'], ':kind' => 'event', ':ts' => $e['first_ts'],
                ':status' => $e['status'], ':sender' => $e['sender'], ':rcpt' => $e['rcpt'], ':rcpt_count' => $e['rcpt'] !== '' ? 1 : 0,
                ':size' => null, ':relay' => '', ':reason' => $e['reason'], ':client' => $e['client'],
                ':search' => strtolower(implode(' ', [$e['sender'], $e['rcpt'], $e['client'], $e['reason'], $e['proc']])),
                ':data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
        $pdo->prepare('INSERT INTO day_index (date, sig, parsed_at, lines) VALUES (?,?,?,?)')
            ->execute([$date, $sig, time(), $res['lines']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function day_needs_parse(string $date, string $sig): bool
{
    $st = db()->prepare('SELECT sig, parsed_at FROM day_index WHERE date = ?');
    $st->execute([$date]);
    $row = $st->fetch();
    if (!$row) {
        return true;
    }
    if ($date < date('Y-m-d')) {
        // finished day: cache is final once parsed after the day ended
        return (int)$row['parsed_at'] < strtotime($date . ' 23:59:59') + 300;
    }
    if ($row['sig'] === $sig) {
        return false;
    }
    return time() - (int)$row['parsed_at'] >= 15;
}

function ensure_day(string $date, bool $force = false): array
{
    $files = log_files();
    if (!$files) {
        return ['ok' => false, 'parsed' => false,
            'error' => 'No readable log files match ' . (getenv('MAIL_LOG_GLOB') ?: '/hostlog/mail.log*')
                . '. Check MAIL_LOG_DIR / LOG_GID in .env.'];
    }
    $sig = log_signature($files);
    if (!$force && !day_needs_parse($date, $sig)) {
        return ['ok' => true, 'parsed' => false, 'error' => null];
    }
    @set_time_limit(180);
    $lock = @fopen(DATA_DIR . '/parse-' . $date . '.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        if (!$force && !day_needs_parse($date, $sig)) {
            return ['ok' => true, 'parsed' => false, 'error' => null];
        }
        store_day($date, $sig, scan_day($date, $files));
        return ['ok' => true, 'parsed' => true, 'error' => null];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/* ───────────── queries ───────────── */

function status_filter_list(string $status): ?array
{
    $groups = [
        'problems' => ['bounced', 'deferred', 'rejected', 'error'],
        'queued'   => ['queued', 'other'],
    ];
    if (isset($groups[$status])) {
        return $groups[$status];
    }
    return isset(STATUS_LABELS[$status]) ? [$status] : null;
}

function day_stats(string $date): array
{
    $st = db()->prepare('SELECT status, COUNT(*) AS c FROM messages WHERE date = ? GROUP BY status');
    $st->execute([$date]);
    $out = array_fill_keys(array_keys(STATUS_LABELS), 0);
    $total = 0;
    foreach ($st as $r) {
        $out[$r['status']] = (int)$r['c'];
        $total += (int)$r['c'];
    }
    $out['total'] = $total;
    return $out;
}

function query_messages(string $date, string $status, string $q, string $dir, int $page, int $per): array
{
    $where = 'date = :d';
    $p = [':d' => $date];
    $list = $status !== '' ? status_filter_list($status) : null;
    if ($list) {
        $ph = [];
        foreach ($list as $i => $s) {
            $ph[] = ':s' . $i;
            $p[':s' . $i] = $s;
        }
        $where .= ' AND status IN (' . implode(',', $ph) . ')';
    }
    $q = strtolower(trim($q));
    if ($q !== '') {
        $where .= ' AND instr(search, :q) > 0';
        $p[':q'] = $q;
    }
    $pdo = db();
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE $where");
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();

    $per = max(10, min(200, $per));
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($pages, $page));
    $dir = $dir === 'asc' ? 'ASC' : 'DESC';

    $st = $pdo->prepare("SELECT id, kind, ts, status, sender, rcpt, rcpt_count, size, relay, reason
        FROM messages WHERE $where ORDER BY ts $dir, seq $dir LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($p);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['rcpt_count'] = (int)$r['rcpt_count'];
        $r['size'] = $r['size'] !== null ? (int)$r['size'] : null;
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
}

function get_message(string $date, string $id): ?array
{
    $st = db()->prepare('SELECT data FROM messages WHERE date = ? AND id = ?');
    $st->execute([$date, $id]);
    $d = $st->fetchColumn();
    return $d ? json_decode((string)$d, true) : null;
}
