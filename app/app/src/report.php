<?php
declare(strict_types=1);

function rep_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_reason(string $s): string
{
    $s = preg_replace('/<[^<>\s]+@[^<>\s]+>/', '<addr>', $s);
    $s = preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '<addr>', (string)$s);
    $s = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '<ip>', (string)$s);
    $s = preg_replace('/\b[0-9A-F]{9,}\b/', '<id>', (string)$s);
    return trim(mb_substr((string)$s, 0, 160));
}

function report_data(string $date, int $maxItems): array
{
    $pdo = db();
    $q = static function (string $sql, array $p) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    };

    $stats = day_stats($date);

    $rc = $q("SELECT status, COUNT(*) c FROM recipients WHERE date = ? GROUP BY status", [$date]);
    $rcpt = ['sent' => 0, 'deferred' => 0, 'bounced' => 0, 'other' => 0];
    foreach ($rc as $r) {
        isset($rcpt[$r['status']]) ? $rcpt[$r['status']] += (int)$r['c'] : $rcpt['other'] += (int)$r['c'];
    }
    $attempted = $rcpt['sent'] + $rcpt['deferred'] + $rcpt['bounced'];
    $rate = $attempted > 0 ? round($rcpt['sent'] * 100 / $attempted, 1) : null;

    $senders = $q("SELECT sender, COUNT(*) c FROM messages WHERE date = ? AND kind = 'msg' AND sender <> ''
        GROUP BY sender ORDER BY c DESC LIMIT 10", [$date]);

    $domains = $q("SELECT domain, COUNT(*) total, SUM(status = 'sent') sent, SUM(status = 'deferred') deferred,
        SUM(status = 'bounced') bounced FROM recipients WHERE date = ? AND domain <> ''
        GROUP BY domain ORDER BY total DESC LIMIT 10", [$date]);

    $reasons = [];
    $rows = array_merge(
        $q("SELECT status, reason FROM recipients WHERE date = ? AND status <> 'sent' AND reason <> ''", [$date]),
        $q("SELECT status, reason FROM messages WHERE date = ? AND kind = 'event' AND reason <> ''", [$date])
    );
    foreach ($rows as $r) {
        $k = $r['status'] . '|' . normalize_reason((string)$r['reason']);
        $reasons[$k] = ($reasons[$k] ?? 0) + 1;
    }
    arsort($reasons);
    $topReasons = [];
    foreach (array_slice($reasons, 0, 10, true) as $k => $n) {
        [$status, $text] = explode('|', $k, 2);
        $topReasons[] = ['status' => $status, 'reason' => $text, 'count' => $n];
    }

    $problems = $q("SELECT ts, id, status, sender, rcpt, rcpt_count, reason FROM messages
        WHERE date = ? AND status IN ('bounced','rejected','error','deferred')
        ORDER BY CASE status WHEN 'bounced' THEN 0 WHEN 'rejected' THEN 1 WHEN 'deferred' THEN 2 ELSE 3 END, ts
        LIMIT " . max(0, $maxItems), [$date]);
    $problemTotal = $stats['bounced'] + $stats['rejected'] + $stats['error'] + $stats['deferred'];

    return compact('stats', 'rcpt', 'rate', 'senders', 'domains', 'topReasons', 'problems', 'problemTotal');
}

function build_report(string $date, array $s): array
{
    $maxItems = (int)$s['report_max_items'];
    $withDetails = $s['report_details'] === '1' && $maxItems > 0;
    $d = report_data($date, $withDetails ? $maxItems : 0);
    $host = app_hostname();
    $st = $d['stats'];

    $subject = str_replace(['{date}', '{host}'], [$date, $host], (string)$s['report_subject']);

    $sum = [
        ['Total log entries', $st['total'], '#1C1917'],
        ['Delivered', $st['sent'], '#16A34A'],
        ['Deferred', $st['deferred'], '#D97706'],
        ['Bounced', $st['bounced'], '#DC2626'],
        ['Rejected', $st['rejected'], '#DC2626'],
        ['Errors', $st['error'], '#DC2626'],
        ['Warnings', $st['warning'], '#D97706'],
        ['Queued / other', $st['queued'] + $st['other'], '#6366F1'],
    ];
    $tiles = $sum;
    $tiles[] = ['Recipient success rate', $d['rate'] === null ? null : $d['rate'] . '%', '#1C1917'];

    $th = 'style="text-align:left;padding:6px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#78716C;border-bottom:1px solid #E7E5E4;"';
    $td = 'style="padding:6px 10px;font-size:13px;border-bottom:1px solid #F5F5F4;vertical-align:top;"';
    $h2 = 'style="font-size:15px;margin:26px 0 8px;color:#1C1917;"';

    $h = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1C1917;max-width:760px;margin:0 auto;padding:20px;">';
    $h .= '<h1 style="font-size:20px;margin:0 0 4px;">Mail delivery report</h1>';
    $h .= '<div style="font-size:13px;color:#78716C;">' . rep_h($date) . ' &middot; ' . rep_h($host) . '</div>';

    $h .= '<table style="width:100%;border-collapse:collapse;margin-top:18px;">';
    foreach (array_chunk($tiles, 3) as $row) {
        $h .= '<tr>';
        foreach ($row as [$label, $val, $color]) {
            $shown = $val === null ? '&ndash;' : (is_int($val) ? number_format($val) : rep_h((string)$val));
            $h .= '<td style="width:33%;padding:12px;border:1px solid #E7E5E4;"><div style="font-size:11px;color:#78716C;">' . rep_h($label)
                . '</div><div style="font-size:22px;font-weight:bold;color:' . $color . ';">' . $shown . '</div></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</table>';

    $t = "Mail delivery report - $date - $host\n\n";
    foreach ($sum as [$label, $val]) {
        $t .= str_pad($label . ':', 24) . $val . "\n";
    }
    $t .= str_pad('Recipient success rate:', 24) . ($d['rate'] === null ? 'n/a' : $d['rate'] . '%') . "\n";

    if ($d['senders']) {
        $h .= "<h2 $h2>Top senders</h2><table style=\"width:100%;border-collapse:collapse;\"><tr><th $th>Sender</th><th $th>Messages</th></tr>";
        $t .= "\nTop senders\n";
        foreach ($d['senders'] as $r) {
            $h .= "<tr><td $td>" . rep_h($r['sender']) . "</td><td $td>" . (int)$r['c'] . '</td></tr>';
            $t .= sprintf("  %-5d %s\n", $r['c'], $r['sender']);
        }
        $h .= '</table>';
    }

    if ($d['domains']) {
        $h .= "<h2 $h2>Top recipient domains</h2><table style=\"width:100%;border-collapse:collapse;\"><tr><th $th>Domain</th><th $th>Delivered</th><th $th>Deferred</th><th $th>Bounced</th></tr>";
        $t .= "\nTop recipient domains (delivered / deferred / bounced)\n";
        foreach ($d['domains'] as $r) {
            $h .= "<tr><td $td>" . rep_h($r['domain']) . "</td><td $td>" . (int)$r['sent'] . "</td><td $td>" . (int)$r['deferred'] . "</td><td $td>" . (int)$r['bounced'] . '</td></tr>';
            $t .= sprintf("  %-30s %d / %d / %d\n", $r['domain'], $r['sent'], $r['deferred'], $r['bounced']);
        }
        $h .= '</table>';
    }

    if ($d['topReasons']) {
        $h .= "<h2 $h2>Top problems</h2><table style=\"width:100%;border-collapse:collapse;\"><tr><th $th>Count</th><th $th>Status</th><th $th>Reason</th></tr>";
        $t .= "\nTop problems\n";
        foreach ($d['topReasons'] as $r) {
            $h .= "<tr><td $td>" . (int)$r['count'] . "</td><td $td>" . rep_h($r['status']) . "</td><td $td>" . rep_h($r['reason']) . '</td></tr>';
            $t .= sprintf("  %-5d [%s] %s\n", $r['count'], $r['status'], $r['reason']);
        }
        $h .= '</table>';
    }

    if ($withDetails && $d['problems']) {
        $shown = count($d['problems']);
        $h .= "<h2 $h2>Problem messages ($shown of {$d['problemTotal']})</h2><table style=\"width:100%;border-collapse:collapse;\"><tr><th $th>Time</th><th $th>Status</th><th $th>From &rarr; To</th><th $th>Reason</th></tr>";
        $t .= "\nProblem messages ($shown of {$d['problemTotal']})\n";
        foreach ($d['problems'] as $r) {
            $to = $r['rcpt'] !== '' ? $r['rcpt'] . ($r['rcpt_count'] > 1 ? ' +' . ($r['rcpt_count'] - 1) : '') : '-';
            $from = $r['sender'] !== '' ? $r['sender'] : '-';
            $h .= "<tr><td $td>" . rep_h($r['ts']) . "</td><td $td>" . rep_h($r['status']) . "</td><td $td>" . rep_h($from . ' → ' . $to)
                . "</td><td $td>" . rep_h(mb_substr((string)$r['reason'], 0, 200)) . '</td></tr>';
            $t .= sprintf("  %s [%s] %s -> %s | %s\n", $r['ts'], $r['status'], $from, $to, mb_substr((string)$r['reason'], 0, 200));
        }
        $h .= '</table>';
    } elseif ($d['problemTotal'] === 0) {
        $h .= '<p style="margin-top:22px;color:#16A34A;font-size:14px;">No bounced, rejected, deferred or error entries.</p>';
        $t .= "\nNo bounced, rejected, deferred or error entries.\n";
    }

    $h .= '<p style="margin-top:28px;font-size:11px;color:#A8A29E;">Generated ' . rep_h(date('Y-m-d H:i')) . ' by Postfix Log Viewer.</p></div>';
    $t .= "\nGenerated " . date('Y-m-d H:i') . " by Postfix Log Viewer.\n";

    return [$subject, $h, $t];
}

function report_day_for(array $s): string
{
    return $s['report_day'] === 'today' ? date('Y-m-d') : date('Y-m-d', strtotime('yesterday'));
}

function send_report(string $date, string $source): array
{
    $s = settings();
    try {
        $to = parse_recipients((string)$s['report_recipients']);
        if (!$to) {
            throw new RuntimeException('No valid recipients configured');
        }
        $r = ensure_day($date, true);
        if (!$r['ok']) {
            throw new RuntimeException((string)$r['error']);
        }
        [$subject, $html, $text] = build_report($date, $s);
        smtp_send($s, (string)$s['smtp_from'], $to, $subject, $html, $text);
        $msg = 'Sent to ' . implode(', ', $to);
        log_report_run($date, $source, true, $msg);
        return ['ok' => true, 'message' => "Report for $date. $msg"];
    } catch (Throwable $e) {
        log_report_run($date, $source, false, $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function send_test_email(): array
{
    $s = settings();
    try {
        $to = parse_recipients((string)$s['report_recipients']);
        if (!$to) {
            throw new RuntimeException('No valid recipients configured');
        }
        $host = app_hostname();
        smtp_send($s, (string)$s['smtp_from'], $to, "Test e-mail - $host",
            '<p>SMTP settings of Postfix Log Viewer on <b>' . rep_h($host) . '</b> work.</p>',
            "SMTP settings of Postfix Log Viewer on $host work.\n");
        log_report_run(null, 'test', true, 'Test e-mail sent to ' . implode(', ', $to));
        return ['ok' => true, 'message' => 'Test e-mail sent to ' . implode(', ', $to)];
    } catch (Throwable $e) {
        log_report_run(null, 'test', false, $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* Called by scheduler every ~30s. Sends the daily report once when due. */
function run_due_report(): void
{
    $s = settings();
    if ($s['report_enabled'] !== '1') {
        return;
    }
    $now = new DateTime();
    $days = array_map('intval', explode(',', $s['report_weekdays']));
    if (!in_array((int)$now->format('N'), $days, true)) {
        return;
    }
    if ($now->format('H:i') < $s['report_time']) {
        return;
    }
    $midnight = strtotime('today');
    $st = db()->prepare("SELECT ok, run_at FROM report_runs WHERE source = 'schedule' AND run_at >= ? ORDER BY id DESC");
    $st->execute([$midnight]);
    $fails = 0;
    $lastTry = 0;
    foreach ($st as $r) {
        if ((int)$r['ok'] === 1) {
            return; // already sent today
        }
        $fails++;
        $lastTry = max($lastTry, (int)$r['run_at']);
    }
    if ($fails >= 3 || time() - $lastTry < 600) {
        return;
    }
    send_report(report_day_for($s), 'schedule');
}
