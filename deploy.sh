#!/usr/bin/env bash
#
# deploy.sh - update / roll back Postfix Log Viewer on this server.
#
#   ./deploy.sh update <file.tar.gz> [-y] [--with-db] [--commit] [--push]
#   ./deploy.sh pull [-y] [--with-db]        update from the git remote (git pull --ff-only)
#   ./deploy.sh rollback [backup.tar.gz] [-y]
#   ./deploy.sh backup [--with-db]
#   ./deploy.sh backups                      list backups
#   ./deploy.sh status
#   ./deploy.sh restart
#
# Never touches .env or data/ (except an optional database snapshot with --with-db).
# Every update makes a backup first, checks the PHP code before switching, restarts only
# what changed, runs a health check and rolls back automatically if it fails.
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

BACKUP_DIR="$ROOT/backups"
KEEP_BACKUPS="${KEEP_BACKUPS:-10}"
LOG_FILE="$BACKUP_DIR/deploy.log"
PHP_IMAGE="postfix-viewer-php"
# Paths this tool manages (replaced on update, saved in backups). .env and data/ are never in here.
MANAGED_DIRS=(app docker)
MANAGED_FILES=(docker-compose.yml README.md .env.example deploy.sh)

ASSUME_YES=0
WITH_DB=0
DO_COMMIT=0
DO_PUSH=0
TMP=""

# ───────────── helpers ─────────────

if [ -t 1 ]; then C_B=$'\e[1m'; C_G=$'\e[32m'; C_Y=$'\e[33m'; C_R=$'\e[31m'; C_0=$'\e[0m'; else C_B=; C_G=; C_Y=; C_R=; C_0=; fi
info() { printf '%s==>%s %s\n' "$C_B" "$C_0" "$*"; }
ok()   { printf '%s ok%s %s\n' "$C_G" "$C_0" "$*"; }
warn() { printf '%s !!%s %s\n' "$C_Y" "$C_0" "$*" >&2; }
die()  { printf '%sERR%s %s\n' "$C_R" "$C_0" "$*" >&2; exit 1; }

cleanup() { [ -n "$TMP" ] && rm -rf "$TMP"; return 0; }
trap cleanup EXIT

log_event() {
    mkdir -p "$BACKUP_DIR"
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"
}

confirm() {
    [ "$ASSUME_YES" = 1 ] && return 0
    [ -t 0 ] || die "Not a terminal. Re-run with -y to confirm."
    local a
    read -r -p "$1 [y/N] " a
    [[ "$a" =~ ^[Yy]$ ]]
}

compose() {
    if docker compose version >/dev/null 2>&1; then
        docker compose "$@"
    elif command -v docker-compose >/dev/null 2>&1; then
        docker-compose "$@"
    else
        die "Docker Compose not found."
    fi
}

env_get() { # env_get KEY DEFAULT  (reads .env, falls back to DEFAULT)
    local v=""
    if [ -f .env ]; then
        v="$(grep -E "^[[:space:]]*$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^["'"'"']//' -e 's/["'"'"']$//' || true)"
    fi
    printf '%s' "${v:-$2}"
}

require_tools() {
    for t in tar curl diff find; do
        command -v "$t" >/dev/null 2>&1 || die "'$t' is required (apt install $t)."
    done
    docker info >/dev/null 2>&1 || die "Cannot talk to Docker (run as root or a docker group member)."
}

# ───────────── checks ─────────────

health_check() {
    local bind port host url code body
    bind="$(env_get HTTP_BIND 0.0.0.0)"
    port="$(env_get HTTP_PORT 8080)"
    host="$bind"; [ "$host" = "0.0.0.0" ] && host="127.0.0.1"
    url="http://$host:$port/login.php"
    for _ in $(seq 1 20); do
        body="$(curl -s -m 5 -w '\n%{http_code}' "$url" 2>/dev/null || true)"
        code="${body##*$'\n'}"
        if [ "$code" = "200" ] && grep -q 'Postfix Log Viewer' <<<"$body"; then
            ok "health check: $url answers 200"
            return 0
        fi
        sleep 1
    done
    warn "health check failed: $url (last HTTP status: ${code:-none})"
    return 1
}

# PHP syntax check of a directory with the app's own PHP image (before it goes live).
lint_dir() {
    local dir="$1" out
    if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
        warn "image $PHP_IMAGE not built yet, skipping PHP syntax check"
        return 0
    fi
    if ! out="$(docker run --rm --network none --entrypoint sh -v "$dir:/chk:ro" "$PHP_IMAGE" -c \
        'rc=0; for f in $(find /chk -name "*.php"); do php -l "$f" >/dev/null 2>/tmp/e || { cat /tmp/e; rc=1; }; done; exit $rc' 2>&1)"; then
        printf '%s\n' "$out" >&2
        return 1
    fi
    ok "PHP syntax check passed"
}

# ───────────── backups ─────────────

prune_backups() {
    local old
    old="$(ls -1t "$BACKUP_DIR"/backup-*.tar.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" || true)"
    [ -z "$old" ] && return 0
    while IFS= read -r f; do
        rm -f "$f" "${f%.tar.gz}.db"
    done <<<"$old"
}

make_backup() { # prints the backup file path on stdout
    mkdir -p "$BACKUP_DIR"
    local ts file items=()
    ts="$(date '+%Y%m%d-%H%M%S')"
    file="$BACKUP_DIR/backup-$ts.tar.gz"
    for p in "${MANAGED_DIRS[@]}" "${MANAGED_FILES[@]}"; do
        [ -e "$p" ] && items+=("$p")
    done
    tar czf "$file" "${items[@]}"
    if [ "$WITH_DB" = 1 ]; then
        # consistent snapshot of the SQLite database, taken inside the running php container
        if compose exec -T php php -r '$p=new PDO("sqlite:/data/app.db"); $p->exec("VACUUM INTO \"/data/.snapshot.db\"");' >&2; then
            mv -f data/.snapshot.db "$BACKUP_DIR/backup-$ts.db"
        else
            warn "database snapshot failed (is the php container running?)"
        fi
    fi
    prune_backups
    printf '%s\n' "$file"
}

restore_backup() {
    local file="$1"
    [ -f "$file" ] || die "Backup not found: $file"
    local tmp d f
    tmp="$(mktemp -d)"
    tar xzf "$file" -C "$tmp"
    for d in "${MANAGED_DIRS[@]}"; do
        [ -d "$tmp/$d" ] && sync_dir "$tmp/$d" "$ROOT/$d"
    done
    for f in "${MANAGED_FILES[@]}"; do
        # deploy.sh itself is not rolled back (it is running, and the newest tool should stay)
        [ "$f" = deploy.sh ] && continue
        [ -f "$tmp/$f" ] && cp -p "$tmp/$f" "$ROOT/$f"
    done
    rm -rf "$tmp"
}

# ───────────── apply + restart ─────────────

# Make DST identical to SRC, updating files in place.
# (app/ and the nginx template are bind-mounted into running containers: replacing the
#  directory itself would leave the containers looking at the old, deleted copy.)
sync_dir() {
    local src="$1" dst="$2" p
    mkdir -p "$dst"
    cp -a "$src/." "$dst/"
    (cd "$dst" && find . -mindepth 1 -depth -print0) | while IFS= read -r -d '' p; do
        [ -e "$src/$p" ] || [ -L "$src/$p" ] || rm -rf "${dst:?}/${p#./}"
    done
}

dir_differs() { ! diff -rq "$1" "$2" >/dev/null 2>&1; }

# Sets globals: CHG_APP CHG_PHPIMG CHG_NGINX CHG_COMPOSE (1/0) comparing $1 (new tree) with the live tree.
detect_changes() {
    local src="$1"
    CHG_APP=0; CHG_PHPIMG=0; CHG_NGINX=0; CHG_COMPOSE=0
    dir_differs "$src/app" app && CHG_APP=1
    dir_differs "$src/docker/php" docker/php && CHG_PHPIMG=1
    dir_differs "$src/docker/nginx" docker/nginx && CHG_NGINX=1
    cmp -s "$src/docker-compose.yml" docker-compose.yml || CHG_COMPOSE=1
    return 0
}

show_changes() {
    local src="$1" list n
    list="$(diff -rq "$src/app" app 2>/dev/null | awk -v S="$src/" '
        /^Files / { f = $2; sub(S, "", f); print "  changed  " f; next }
        /^Only in / {
            d = $3; sub(/:$/, "", d); f = d "/" $4
            if (index(f, S) == 1) { sub(S, "", f); print "  added    " f } else { print "  deleted  " f }
        }' || true)"
    info "Changes:"
    if [ -n "$list" ]; then
        n="$(printf '%s\n' "$list" | wc -l)"
        printf '%s\n' "$list" | head -n 40 || true
        [ "$n" -gt 40 ] && echo "  ... and $((n - 40)) more"
    fi
    [ "$CHG_PHPIMG" = 1 ] && echo "  changed  docker/php/ (PHP image will be rebuilt)"
    [ "$CHG_NGINX" = 1 ] && echo "  changed  docker/nginx/ (nginx will be recreated)"
    if [ "$CHG_COMPOSE" = 1 ]; then
        echo "  changed  docker-compose.yml:"
        diff -u docker-compose.yml "$src/docker-compose.yml" | sed 's/^/    /' | head -n 40 || true
    fi
    local f
    for f in README.md .env.example deploy.sh; do
        if [ -f "$src/$f" ] && ! cmp -s "$src/$f" "$f"; then echo "  changed  $f"; fi
    done
    if [ -f "$src/.env.example" ] && [ -f .env ]; then
        local missing
        missing="$(grep -oE '^[A-Z_]+=' "$src/.env.example" | while read -r k; do grep -q "^$k" .env || printf '%s ' "${k%=}"; done)"
        [ -n "$missing" ] && warn "new settings in .env.example not in your .env: $missing(defaults apply)"
    fi
    return 0
}

apply_tree() {
    local src="$1" f
    sync_dir "$src/app" app
    sync_dir "$src/docker" docker
    for f in "${MANAGED_FILES[@]}"; do
        [ -f "$src/$f" ] || continue
        if [ "$f" = deploy.sh ]; then
            # copy + rename (new inode): bash keeps reading the old deploy.sh while it runs
            cp -p "$src/$f" "$f.new" && mv -f "$f.new" "$f"
        else
            cp -p "$src/$f" "$f"
        fi
    done
    chmod +x deploy.sh 2>/dev/null || true
    mkdir -p data
    [ -f data/.gitkeep ] || : > data/.gitkeep
    ensure_gitignore
}

ensure_gitignore() {
    [ -f .gitignore ] || return 0
    grep -qxF 'backups/' .gitignore || printf 'backups/\n' >> .gitignore
}

restart_services() { # uses CHG_* globals; "full" as $1 forces everything
    if [ "${1:-}" = full ] || [ "$CHG_PHPIMG" = 1 ] || [ "$CHG_COMPOSE" = 1 ]; then
        info "Rebuilding and starting containers"
        compose up -d --build --remove-orphans
    fi
    if [ "${1:-}" = full ] || [ "$CHG_NGINX" = 1 ]; then
        info "Recreating nginx"
        compose up -d --force-recreate nginx
    fi
    # The scheduler is a long-running PHP process: it only picks up new code after a restart.
    info "Restarting scheduler"
    compose restart scheduler >/dev/null
    ok "services restarted"
}

# Run after a failed deploy: put the backup back and restart everything.
auto_rollback() {
    local backup="$1"
    warn "Rolling back to $(basename "$backup")"
    restore_backup "$backup"
    restart_services full
    if health_check; then
        warn "Rolled back. The live app is the previous version."
    else
        warn "Still unhealthy after rollback. Check: docker compose logs --tail 50"
    fi
    log_event "FAILED update, rolled back to $(basename "$backup")"
    exit 1
}

git_commit_push() {
    [ -d .git ] || { warn "not a git repository, skipping commit"; return 0; }
    if [ "$DO_COMMIT" = 1 ] || [ "$DO_PUSH" = 1 ]; then
        git add -A
        if git diff --cached --quiet; then
            ok "git: nothing to commit"
        else
            git commit -q -m "Deploy update $(date '+%Y-%m-%d %H:%M')"
            ok "git: committed $(git rev-parse --short HEAD)"
        fi
    fi
    if [ "$DO_PUSH" = 1 ]; then
        local r branch
        branch="$(git branch --show-current)"
        for r in $(git remote); do
            if git push -q "$r" "$branch"; then ok "git: pushed to $r"; else warn "git: push to $r failed"; fi
        done
    fi
}

# ───────────── commands ─────────────

cmd_update() {
    local archive="${1:-}"
    [ -n "$archive" ] || die "Usage: ./deploy.sh update <file.tar.gz> [-y] [--with-db] [--commit] [--push]"
    [ -f "$archive" ] || die "File not found: $archive"
    archive="$(cd "$(dirname "$archive")" && pwd)/$(basename "$archive")"
    require_tools

    TMP="$(mktemp -d)"
    info "Unpacking $(basename "$archive")"
    tar xzf "$archive" -C "$TMP" || die "Cannot unpack $archive"
    local idx src
    idx="$(find "$TMP" -path '*/app/public/index.php' -not -path '*/app/app/*' | head -n1)"
    [ -n "$idx" ] || die "Archive does not contain app/public/index.php - not a Postfix Log Viewer package."
    src="$(dirname "$(dirname "$(dirname "$idx")")")"
    for f in app/src/logparser.php app/bin/scheduler.php docker-compose.yml docker/php/Dockerfile; do
        [ -e "$src/$f" ] || die "Archive is incomplete: missing $f"
    done

    lint_dir "$src/app" || die "New version has PHP errors. Nothing was changed."

    detect_changes "$src"
    if [ "$CHG_APP$CHG_PHPIMG$CHG_NGINX$CHG_COMPOSE" = "0000" ] && cmp -s "$src/deploy.sh" deploy.sh 2>/dev/null \
        && cmp -s "$src/README.md" README.md 2>/dev/null; then
        ok "Already up to date."
        return 0
    fi
    show_changes "$src"
    confirm "Apply this update?" || die "Cancelled."

    local backup
    backup="$(make_backup)"
    ok "backup: $backup"

    info "Installing new files"
    apply_tree "$src"
    restart_services
    health_check || auto_rollback "$backup"

    log_event "update from $(basename "$archive") (backup $(basename "$backup"))"
    git_commit_push
    ok "Update finished."
}

cmd_pull() {
    require_tools
    [ -d .git ] || die "Not a git repository."
    [ -z "$(git status --porcelain -- "${MANAGED_DIRS[@]}" "${MANAGED_FILES[@]}" 2>/dev/null)" ] \
        || die "Local uncommitted changes in managed files. Commit or stash them first (git status)."
    info "Fetching"
    git fetch -q
    local upstream old
    upstream="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)" \
        || die "Current branch has no upstream. Run once: git branch -u <remote>/<branch>"
    old="$(git rev-parse HEAD)"
    if [ "$(git rev-list --count "HEAD..$upstream")" = 0 ]; then
        ok "Already up to date with $upstream."
        return 0
    fi
    info "Incoming commits from $upstream:"
    git log --oneline "HEAD..$upstream" | sed 's/^/  /'
    confirm "Pull and deploy?" || die "Cancelled."

    local backup
    backup="$(make_backup)"
    ok "backup: $backup"
    git pull -q --ff-only || die "git pull failed (diverged history?). Nothing deployed."

    local changed
    changed="$(git diff --name-only "$old" HEAD)"
    CHG_APP=0; CHG_PHPIMG=0; CHG_NGINX=0; CHG_COMPOSE=0
    grep -q '^app/' <<<"$changed" && CHG_APP=1
    grep -q '^docker/php/' <<<"$changed" && CHG_PHPIMG=1
    grep -q '^docker/nginx/' <<<"$changed" && CHG_NGINX=1
    grep -qx 'docker-compose.yml' <<<"$changed" && CHG_COMPOSE=1

    if ! lint_dir "$ROOT/app"; then
        warn "PHP errors in pulled code"
        git reset -q --hard "$old"
        auto_rollback "$backup"
    fi
    restart_services
    if ! health_check; then
        git reset -q --hard "$old"
        auto_rollback "$backup"
    fi
    log_event "pull $(git rev-parse --short "$old")..$(git rev-parse --short HEAD) (backup $(basename "$backup"))"
    ok "Deployed $(git rev-parse --short HEAD)."
}

cmd_rollback() {
    require_tools
    local file="${1:-}"
    if [ -z "$file" ]; then
        file="$(ls -1t "$BACKUP_DIR"/backup-*.tar.gz 2>/dev/null | head -n1 || true)"
        [ -n "$file" ] || die "No backups in $BACKUP_DIR"
    elif [ ! -f "$file" ] && [ -f "$BACKUP_DIR/$file" ]; then
        file="$BACKUP_DIR/$file"
    fi
    [ -f "$file" ] || die "Backup not found: $file"
    info "Roll back to $(basename "$file")"
    [ -f "${file%.tar.gz}.db" ] && echo "  (database snapshot ${file%.tar.gz}.db exists; it is NOT restored automatically)"
    confirm "Replace the current version with this backup?" || die "Cancelled."
    local safety
    safety="$(make_backup)"
    ok "current version saved as $(basename "$safety")"
    restore_backup "$file"
    restart_services full
    health_check || warn "Health check failed after rollback. Check: docker compose logs --tail 50"
    log_event "rollback to $(basename "$file") (previous saved as $(basename "$safety"))"
    ok "Rollback finished."
}

cmd_backup() {
    require_tools
    local f
    f="$(make_backup)"
    ok "backup: $f"
    [ -f "${f%.tar.gz}.db" ] && ok "database: ${f%.tar.gz}.db"
    log_event "manual backup $(basename "$f")"
}

cmd_backups() {
    if ! ls "$BACKUP_DIR"/backup-*.tar.gz >/dev/null 2>&1; then
        echo "No backups yet."
        return 0
    fi
    ls -lht "$BACKUP_DIR"/backup-*.tar.gz "$BACKUP_DIR"/backup-*.db 2>/dev/null | awk '{print "  " $6, $7, $8, $5, $9}'
    if [ -f "$LOG_FILE" ]; then
        echo; info "Last deployments:"
        tail -n 10 "$LOG_FILE" | sed 's/^/  /'
    fi
}

cmd_status() {
    info "Containers"
    compose ps
    echo
    health_check || true
    if [ -d .git ]; then
        echo; info "Git: $(git rev-parse --short HEAD 2>/dev/null) on $(git branch --show-current 2>/dev/null)"
        git status --short | head -n 20
    fi
    if [ -f "$LOG_FILE" ]; then
        echo; info "Last deployment: $(tail -n1 "$LOG_FILE")"
    fi
}

cmd_restart() {
    require_tools
    CHG_PHPIMG=0; CHG_NGINX=0; CHG_COMPOSE=0
    restart_services full
    health_check
}

usage() {
    sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

# ───────────── main ─────────────

CMD="${1:-}"; [ $# -gt 0 ] && shift
ARGS=()
while [ $# -gt 0 ]; do
    case "$1" in
        -y|--yes) ASSUME_YES=1 ;;
        --with-db) WITH_DB=1 ;;
        --commit) DO_COMMIT=1 ;;
        --push) DO_PUSH=1 ;;
        -h|--help) usage 0 ;;
        -*) die "Unknown option: $1" ;;
        *) ARGS+=("$1") ;;
    esac
    shift
done

case "$CMD" in
    update)   cmd_update "${ARGS[@]+"${ARGS[@]}"}" ;;
    pull)     cmd_pull ;;
    rollback) cmd_rollback "${ARGS[@]+"${ARGS[@]}"}" ;;
    backup)   cmd_backup ;;
    backups)  cmd_backups ;;
    status)   cmd_status ;;
    restart)  cmd_restart ;;
    ""|-h|--help|help) usage 0 ;;
    *) warn "Unknown command: $CMD"; usage 1 ;;
esac
