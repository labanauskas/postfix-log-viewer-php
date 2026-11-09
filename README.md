# Postfix Log Viewer

Small PHP + nginx + SQLite app (no frameworks) for browsing `/var/log/mail.log`, with a daily e-mail summary.
UI reuses the AdminKit design (`admin.css`).

## Run

```bash
cp .env.example .env     # set ADMIN_PASSWORD, LOG_GID, SMTP defaults
docker compose up -d --build
```

Open `http://<host>:8080` (`HTTP_PORT`).

All containers use `network_mode: host`, so the app reaches Postfix on the host directly
(report SMTP default: `127.0.0.1:25`). There is no `ports:` mapping: nginx binds `HTTP_BIND:HTTP_PORT`
on the host, php-fpm listens on `127.0.0.1:PHP_FPM_PORT` only. Make sure Postfix accepts mail from
`127.0.0.1` (default `mynetworks`) and that ports 8080/9000 are free on the host.

`LOG_GID` must be the group ID that can read the log on the host:

```bash
stat -c %g /var/log/mail.log      # Debian: 4 (adm)
```

The whole log directory (`MAIL_LOG_DIR`, default `/var/log`) is mounted read-only at `/hostlog`,
so rotation does not break the mount. Rotated files (`mail.log.1`, `mail.log.2.gz`) are read too
(`MAIL_LOG_GLOB`).

## Features

- Pick any date; messages are grouped by Postfix queue ID.
- Status per message: **Delivered**, **Deferred**, **Bounced**, **Rejected**, **Error**, **Queued/Other**.
  `NOQUEUE: reject` lines and `warning/error/fatal/panic` lines appear as separate entries.
- Stat cards, status filter, search (sender, recipient, queue ID, Message-ID, client, reason), paging, sort by time.
- Click a row: drawer with summary, per-recipient status (relay, DSN, delay, response), related bounce
  notification, and the raw log lines.
- Settings: daily report on/off, send time, weekdays, covered day (previous day / current day),
  recipients, subject (`{date}`, `{host}`), problem list size, SMTP (none / STARTTLS / SSL, auth),
  test e-mail, "send report now" for any date, history of runs.

## Layout

| Path | Purpose |
|---|---|
| `docker-compose.yml` | `nginx`, `php` (php-fpm), `scheduler` (same image, sends reports); all on host network |
| `app/public/` | web root: `index.php` (log), `settings.php`, `login.php`, `api.php`, `assets/` |
| `app/src/logparser.php` | log scan, status logic, SQLite cache and queries |
| `app/src/report.php`, `mailer.php` | report builder, SMTP client |
| `app/bin/scheduler.php` | loop: sends due report, purges old cache |
| `data/app.db` | SQLite: settings, parsed-day cache, report history |

## Notes

- Parsed days are cached in SQLite. Finished days are parsed once. Today is re-parsed when the log
  changes (at most every 15 s). "Refresh" forces a re-parse.
- Both log timestamp styles work: ISO (`2026-11-03T10:02:11+02:00`, Debian 13 rsyslog default) and
  classic syslog (`Nov  3 10:02:11`). Classic syslog has no year, so the date match is month + day.
- A message whose delivery lands on the next day shows as *Queued* on its first day.
- Login: single user from `ADMIN_USER` / `ADMIN_PASSWORD` (or `ADMIN_PASSWORD_HASH`). Failed logins are
  rate-limited per IP. Put the app behind HTTPS (reverse proxy) before exposing it.
- SMTP password is stored in plain text in `data/app.db`. Protect the `data/` directory.
- Timezone: `APP_TZ` (default `Europe/Vilnius`) decides "today", "yesterday" and the send time.
- Logs are never modified. Retention only purges the SQLite cache.
