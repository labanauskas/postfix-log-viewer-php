# Postfix Log Viewer with PHP

Small PHP + nginx + SQLite app (no frameworks) for browsing `/var/log/mail.log`, with a daily e-mail summary.
UI: custom AdminKit-style design (`assets/admin.css`), DM Sans / DM Mono fonts and Tabler icons, all served locally from `app/public/assets/` (no CDN).

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

## Updating (`deploy.sh`)

Run on the server, in the app folder (as root or a `docker` group member):

```bash
./deploy.sh update ~/postfix-viewer.tar.gz      # install a new package
./deploy.sh pull                                # or: update from the git remote (git pull --ff-only)
./deploy.sh rollback                            # undo the last update
```

What an update does:

1. Unpacks the package to a temp folder and checks every PHP file with the app's own PHP image.
   A package with errors is refused before anything changes.
2. Shows what will change (files, `docker-compose.yml` diff, new `.env.example` settings) and asks to confirm.
3. Backs up `app/`, `docker/`, `docker-compose.yml`, `README.md`, `.env.example` to `backups/`
   (last 10 kept, `KEEP_BACKUPS=20 ./deploy.sh ...` to change).
4. Copies the new files in place. **`.env` and `data/` are never touched.**
5. Restarts only what is needed: rebuilds the PHP image if `docker/php/` or `docker-compose.yml` changed,
   recreates nginx if its config changed, and always restarts the scheduler (it keeps old code in memory).
6. Checks that `http://127.0.0.1:HTTP_PORT/login.php` answers. If not, it restores the backup automatically.

Other commands and options:

| Command | |
|---|---|
| `./deploy.sh status` | containers, health check, git state, last deployment |
| `./deploy.sh backups` | list backups and the deployment log |
| `./deploy.sh backup [--with-db]` | backup now |
| `./deploy.sh rollback [backup-....tar.gz]` | restore the newest or a named backup |
| `./deploy.sh restart` | rebuild and restart everything, then health check |
| `-y` | do not ask (for scripts and cron) |
| `--with-db` | also snapshot the SQLite database (`backup-....db`; can be large) |
| `--commit` / `--push` | after `update`: git commit the new files / push to every remote |

Requirements on the host: `bash`, `tar`, `curl`, `diff`, Docker Compose.
The first time, copy `deploy.sh` from the package into the app folder by hand; after that it updates itself.

## Features

- Pick any date; messages are grouped by Postfix queue ID.
- Status per message: **Delivered**, **Deferred**, **Bounced**, **Rejected**, **Error**, **Warning**, **Queued/Other**.
  `NOQUEUE: reject` / `milter-reject` lines and `warning/error/fatal/panic` lines appear as separate entries.
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
  changes (at most every 30 s). "Refresh" forces a re-parse.
- Memory use is flat: a message is written to SQLite as soon as Postfix logs `removed`.
  Tested: 1.5M lines in one day parse in ~20 s with ~45 MB RAM. Cache size is roughly 2x the log size
  (message details are stored compressed). Expired days are deleted and the database is vacuumed.
- Statuses: Delivered, Deferred, Bounced, Rejected (incl. `NOQUEUE: reject` and `milter-reject`),
  Error (`error/fatal/panic`), Warning (`warning:`), Queued/Other.
- Messages that span midnight: the part on the second day picks up sender, size, client and subject
  from the earlier day (up to 7 days back) if that day was already indexed. On the first day the message
  shows as Deferred or Queued.
- Log files last modified before the selected day are skipped, so old days parse faster.
- Both log timestamp styles work: ISO (`2026-11-03T10:02:11+02:00`, Debian 13 rsyslog default) and
  classic syslog (`Nov  3 10:02:11`). `APP_TZ` should match the host time zone.
- Login: single user from `ADMIN_USER` / `ADMIN_PASSWORD` (or `ADMIN_PASSWORD_HASH`). Failed logins are
  rate-limited per client IP. Behind a reverse proxy all clients share one IP, so 8 bad logins lock
  everyone out for 10 minutes.
- Behind an HTTPS reverse proxy that sends `X-Forwarded-Proto: https`, the session cookie is marked Secure.
- Pages send a strict Content-Security-Policy (no external scripts, styles or fonts).
- SMTP password is stored in plain text in `data/app.db`. Protect the `data/` directory.
- Logs are never modified. Retention only purges the SQLite cache.
