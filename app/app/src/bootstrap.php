<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', rtrim(getenv('DATA_DIR') ?: '/data', '/'));

date_default_timezone_set(getenv('APP_TZ') ?: (getenv('TZ') ?: 'UTC'));

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logparser.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/report.php';

function app_hostname(): string
{
    $h = getenv('APP_HOSTNAME');
    return $h !== false && $h !== '' ? $h : (string)gethostname();
}

function valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
