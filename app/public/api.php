<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';

require_login(true);
session_write_close(); // read-only endpoint: do not hold the session lock while parsing logs

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$date = (string)($_GET['date'] ?? '');
if (!valid_date($date)) {
    out(['error' => 'Invalid date'], 400);
}

try {
    if ($action === 'logs') {
        $res = ensure_day($date, ($_GET['refresh'] ?? '') === '1');
        if (!$res['ok']) {
            out(['error' => $res['error']]);
        }
        $list = query_messages(
            $date,
            (string)($_GET['status'] ?? ''),
            (string)($_GET['q'] ?? ''),
            (string)($_GET['dir'] ?? 'desc'),
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per'] ?? 50)
        );
        $st = db()->prepare('SELECT parsed_at, lines FROM day_index WHERE date = ?');
        $st->execute([$date]);
        $idx = $st->fetch() ?: ['parsed_at' => null, 'lines' => 0];
        out([
            'date'      => $date,
            'stats'     => day_stats($date),
            'list'      => $list,
            'parsed_at' => $idx['parsed_at'] ? date('H:i:s', (int)$idx['parsed_at']) : null,
            'lines'     => (int)$idx['lines'],
        ]);
    }

    if ($action === 'message') {
        $msg = get_message($date, (string)($_GET['id'] ?? ''));
        if (!$msg) {
            out(['error' => 'Message not found for this day'], 404);
        }
        out(['message' => $msg]);
    }

    out(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('api error: ' . $e->getMessage());
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
