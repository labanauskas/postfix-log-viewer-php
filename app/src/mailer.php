<?php
declare(strict_types=1);

/* Minimal SMTP client: plain / STARTTLS / implicit TLS, AUTH LOGIN, multipart/alternative. */

function parse_recipients(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($a)] = $a;
        }
    }
    return array_values($out);
}

function mime_header(string $s): string
{
    return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function build_mime(string $from, array $to, string $subject, string $html, string $text): string
{
    $b = 'pfv_' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $h = [
        'Date: ' . date('r'),
        'From: ' . $from,
        'To: ' . implode(', ', $to),
        'Subject: ' . mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ];
    $part = static fn(string $type, string $body) =>
        "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    return implode("\r\n", $h) . "\r\n\r\n"
        . $part('text/plain', $text) . $part('text/html', $html) . "--$b--\r\n";
}

function smtp_send(array $cfg, string $from, array $to, string $subject, string $html, string $text): void
{
    $host = trim((string)$cfg['smtp_host']);
    $port = (int)$cfg['smtp_port'];
    $sec = (string)$cfg['smtp_security'];
    if ($host === '') {
        throw new RuntimeException('SMTP host is not configured');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Sender (From) address is missing or invalid');
    }
    if (!$to) {
        throw new RuntimeException('No valid recipients');
    }

    $verify = $cfg['smtp_verify_tls'] === '1';
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
    ]]);
    $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("SMTP connect to $host:$port failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 25);

    $read = static function () use ($fp): string {
        $resp = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        return $resp;
    };
    $cmd = static function (string $c, array $ok, ?string $label = null) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            $label ??= explode(' ', $c)[0];
            throw new RuntimeException('SMTP error after ' . $label . ': ' . trim($r !== '' ? $r : 'no response'));
        }
        return $r;
    };

    try {
        $g = $read();
        if ((int)substr($g, 0, 3) !== 220) {
            throw new RuntimeException('SMTP greeting failed: ' . trim($g));
        }
        $me = gethostname() ?: 'localhost';
        $cmd("EHLO $me", [250]);
        if ($sec === 'starttls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS handshake failed (check certificate / "Verify TLS" setting)');
            }
            $cmd("EHLO $me", [250]);
        }
        if ((string)$cfg['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', [334], 'AUTH');
            $cmd(base64_encode((string)$cfg['smtp_user']), [334], 'AUTH username');
            $cmd(base64_encode((string)$cfg['smtp_pass']), [235], 'AUTH password');
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $r) {
            $cmd("RCPT TO:<$r>", [250, 251], "RCPT TO <$r>");
        }
        $cmd('DATA', [354]);
        $msg = build_mime($from, $to, $subject, $html, $text);
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($fp, $msg . "\r\n.\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== 250) {
            throw new RuntimeException('Message rejected: ' . trim($r));
        }
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}
