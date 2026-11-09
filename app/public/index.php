<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
require_login();
session_write_close();

$today = date('Y-m-d');
$date = valid_date((string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : $today;
$status = isset(STATUS_LABELS[(string)($_GET['status'] ?? '')]) || ($_GET['status'] ?? '') === 'problems'
    ? (string)$_GET['status'] : '';

layout_start('Mail log', 'logs');
?>
    <div class="page active" id="page" data-date="<?= h($date) ?>" data-today="<?= h($today) ?>" data-status="<?= h($status) ?>">

      <div class="page-header">
        <div>
          <h1 class="page-title">Mail log</h1>
          <p class="page-subtitle" id="subtitle">Postfix delivery log for the selected day</p>
        </div>
        <div class="page-actions">
          <div class="date-nav">
            <button class="icon-btn" id="prevDay" aria-label="Previous day" title="Previous day" type="button"><i class="ti ti-chevron-left"></i></button>
            <input type="date" id="dateInput" value="<?= h($date) ?>" max="<?= h($today) ?>" aria-label="Log date">
            <button class="icon-btn" id="nextDay" aria-label="Next day" title="Next day" type="button"><i class="ti ti-chevron-right"></i></button>
          </div>
          <button class="btn btn-ghost" id="todayBtn" type="button">Today</button>
          <button class="btn btn-primary" id="refreshBtn" type="button"><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button>
        </div>
      </div>

      <div class="alert alert-err" id="loadError" hidden></div>

      <div class="stats-grid" role="list" aria-label="Summary statistics">
        <div class="stat-card clickable" role="listitem" data-filter="" tabindex="0">
          <div class="stat-label"><i class="ti ti-mail" aria-hidden="true"></i> All entries</div>
          <div class="stat-value" data-stat="total">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="sent" tabindex="0">
          <div class="stat-label"><i class="ti ti-circle-check" aria-hidden="true"></i> Delivered</div>
          <div class="stat-value" data-stat="sent">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="deferred" tabindex="0">
          <div class="stat-label"><i class="ti ti-clock" aria-hidden="true"></i> Deferred</div>
          <div class="stat-value" data-stat="deferred">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="bounced" tabindex="0">
          <div class="stat-label"><i class="ti ti-mail-off" aria-hidden="true"></i> Bounced</div>
          <div class="stat-value" data-stat="bounced">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="rejected" tabindex="0">
          <div class="stat-label"><i class="ti ti-ban" aria-hidden="true"></i> Rejected</div>
          <div class="stat-value" data-stat="rejected">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="error" tabindex="0">
          <div class="stat-label"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Errors</div>
          <div class="stat-value" data-stat="error">–</div>
        </div>
        <div class="stat-card clickable" role="listitem" data-filter="queued" tabindex="0">
          <div class="stat-label"><i class="ti ti-hourglass" aria-hidden="true"></i> Queued / other</div>
          <div class="stat-value" data-stat="queued">–</div>
        </div>
      </div>

      <div class="table-section" id="tableSection">
        <div class="table-toolbar">
          <div class="table-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" id="searchInput" placeholder="Sender, recipient, queue ID, text…" aria-label="Search log">
          </div>
          <div class="filter-group filters">
            <select id="statusFilter" aria-label="Filter by status">
              <option value="">All statuses</option>
              <option value="problems">Problems only</option>
              <option value="sent">Delivered</option>
              <option value="deferred">Deferred</option>
              <option value="bounced">Bounced</option>
              <option value="rejected">Rejected</option>
              <option value="error">Errors</option>
              <option value="queued">Queued / other</option>
            </select>
            <select id="perFilter" aria-label="Rows per page">
              <option value="25">25 / page</option>
              <option value="50" selected>50 / page</option>
              <option value="100">100 / page</option>
              <option value="200">200 / page</option>
            </select>
          </div>
        </div>

        <div style="overflow-x:auto;">
          <table aria-label="Mail log">
            <thead>
              <tr>
                <th><button class="sort-btn" id="sortTime" type="button">Time <i class="ti ti-arrow-down" id="sortIcon"></i></button></th>
                <th>Queue ID</th>
                <th>From</th>
                <th>To</th>
                <th>Status</th>
                <th>Relay / reason</th>
                <th>Size</th>
              </tr>
            </thead>
            <tbody id="tableBody"></tbody>
          </table>
        </div>

        <div class="table-footer">
          <span class="table-info" id="tableInfo">Loading…</span>
          <nav class="pagination" aria-label="Table pagination" id="pagination"></nav>
        </div>
      </div>
    </div>

    <!-- Detail drawer -->
    <div class="drawer-overlay" id="drawerOverlay"></div>
    <aside class="drawer" id="drawer" aria-hidden="true" aria-label="Message details">
      <div class="drawer-head">
        <div class="drawer-head-main">
          <div class="drawer-title" id="drawerTitle">—</div>
          <div class="drawer-sub" id="drawerSub"></div>
        </div>
        <span id="drawerBadge"></span>
        <button class="icon-btn" id="drawerClose" aria-label="Close details" type="button"><i class="ti ti-x"></i></button>
      </div>
      <div class="drawer-body" id="drawerBody"></div>
    </aside>
<?php
layout_end(['logs.js']);
