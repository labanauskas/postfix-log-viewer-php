<?php
declare(strict_types=1);

/* Long-running loop: sends the daily report when due, purges old cache once an hour. */

require __DIR__ . '/../src/bootstrap.php';

fwrite(STDOUT, date('c') . " scheduler started\n");
$lastPurge = 0;

while (true) {
    try {
        run_due_report();
        if (time() - $lastPurge > 3600) {
            purge_old_data((int)settings()['retention_days']);
            $lastPurge = time();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' scheduler error: ' . $e->getMessage() . "\n");
    }
    sleep(30);
}
