<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
require_login();
session_write_close();

$today = date('Y-m-d');
$date = valid_date((string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : $today;
$status = isset(STATUS_LABELS[(string)($_GET['status'] ?? '')]) || ($_GET['status'] ?? '') === 'problems'
    ? (string)$_GET['status'] : '';

$cards = [
    ['',         'All entries',    'mail',           'text-primary'],
    ['sent',     'Delivered',      'circle-check',   'text-success'],
    ['deferred', 'Deferred',       'clock',          'text-warning'],
    ['bounced',  'Bounced',        'mail-x',         'text-danger'],
    ['rejected', 'Rejected',       'ban',            'text-danger'],
    ['error',    'Errors',         'circle-alert',   'text-danger'],
    ['warning',  'Warnings',       'triangle-alert', 'text-warning'],
    ['queued',   'Queued / other', 'hourglass',      'text-secondary'],
];

layout_start('Mail log', 'logs');
?>
        <div id="page" data-date="<?= h($date) ?>" data-today="<?= h($today) ?>" data-status="<?= h($status) ?>">

          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
              <h1 class="h3 mb-0"><strong>Mail</strong> log</h1>
              <div class="text-body-secondary" id="subtitle">Postfix delivery log for the selected day</div>
            </div>
            <div class="d-flex flex-wrap flex-lg-nowrap align-items-center gap-2">
              <div class="input-group flex-nowrap w-auto">
                <button class="btn btn-outline-secondary" id="prevDay" type="button" aria-label="Previous day" title="Previous day"><i data-lucide="chevron-left"></i></button>
                <input type="date" class="form-control" id="dateInput" value="<?= h($date) ?>" max="<?= h($today) ?>" aria-label="Log date">
                <button class="btn btn-outline-secondary" id="nextDay" type="button" aria-label="Next day" title="Next day"><i data-lucide="chevron-right"></i></button>
              </div>
              <button class="btn btn-outline-secondary" id="todayBtn" type="button">Today</button>
              <button class="btn btn-primary" id="refreshBtn" type="button"><i class="align-middle" data-lucide="refresh-cw"></i> <span class="align-middle">Refresh</span></button>
            </div>
          </div>

          <div class="alert alert-danger" id="loadError" role="alert" hidden></div>

          <div class="row row-cols-2 row-cols-md-4 row-cols-xxl-8 g-3 mb-3" role="list" aria-label="Summary statistics">
            <?php foreach ($cards as [$filter, $label, $icon, $color]): ?>
            <div class="col" role="listitem">
              <div class="card stat-filter h-100 mb-0" data-filter="<?= h($filter) ?>" tabindex="0">
                <div class="card-body">
                  <div class="row">
                    <div class="col mt-0"><h2 class="card-title"><?= h($label) ?></h2></div>
                    <div class="col-auto"><div class="stat <?= h($color) ?>"><i class="align-middle" data-lucide="<?= h($icon) ?>"></i></div></div>
                  </div>
                  <div class="h1 mt-1 mb-0" data-stat="<?= $filter === '' ? 'total' : h($filter) ?>">–</div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="card" id="tableSection">
            <div class="card-header">
              <div class="row g-2 align-items-center">
                <div class="col-12 col-md">
                  <div class="input-group">
                    <span class="input-group-text"><i data-lucide="search"></i></span>
                    <input type="search" class="form-control" id="searchInput" placeholder="Sender, recipient, queue ID, text…" aria-label="Search log">
                  </div>
                </div>
                <div class="col-6 col-md-auto">
                  <select class="form-select" id="statusFilter" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="problems">Problems only</option>
                    <option value="sent">Delivered</option>
                    <option value="deferred">Deferred</option>
                    <option value="bounced">Bounced</option>
                    <option value="rejected">Rejected</option>
                    <option value="error">Errors</option>
                    <option value="warning">Warnings</option>
                    <option value="queued">Queued / other</option>
                  </select>
                </div>
                <div class="col-6 col-md-auto">
                  <select class="form-select" id="perFilter" aria-label="Rows per page">
                    <option value="25">25 / page</option>
                    <option value="50" selected>50 / page</option>
                    <option value="100">100 / page</option>
                    <option value="200">200 / page</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-hover my-0" aria-label="Mail log">
                <thead>
                  <tr>
                    <th><button class="btn btn-link p-0 text-reset fw-bold text-decoration-none" id="sortTime" type="button">Time <span id="sortIcon">↓</span></button></th>
                    <th>Queue ID</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Relay / reason</th>
                    <th class="text-end">Size</th>
                  </tr>
                </thead>
                <tbody id="tableBody"></tbody>
              </table>
            </div>

            <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
              <span class="text-body-secondary small" id="tableInfo">Loading…</span>
              <nav aria-label="Table pagination"><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
            </div>
          </div>
        </div>

        <!-- Detail drawer -->
        <div class="offcanvas offcanvas-end" tabindex="-1" id="drawer" aria-labelledby="drawerTitle">
          <div class="offcanvas-header border-bottom">
            <div class="min-w-0 flex-grow-1">
              <h5 class="offcanvas-title font-monospace" id="drawerTitle">—</h5>
              <div class="text-body-secondary small" id="drawerSub"></div>
            </div>
            <span class="me-2" id="drawerBadge"></span>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
          </div>
          <div class="offcanvas-body" id="drawerBody"></div>
        </div>
<?php
layout_end(['logs.js']);
