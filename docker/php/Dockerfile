FROM php:8.3-fpm-alpine

# GID of the group that can read /var/log/mail.log on the host (Debian: adm = 4)
ARG LOG_GID=4
# php-fpm listens on 127.0.0.1 only (host network); port is read from the environment at start
ENV PHP_FPM_PORT=9000

RUN apk add --no-cache tzdata \
 && if ! getent group "${LOG_GID}" >/dev/null; then addgroup -g "${LOG_GID}" -S hostlog; fi \
 && LOG_GROUP="$(getent group "${LOG_GID}" | cut -d: -f1)" \
 && printf '[www]\nuser = www-data\ngroup = %s\nlisten = 127.0.0.1:${PHP_FPM_PORT}\npm = dynamic\npm.max_children = 8\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 3\nclear_env = no\n' "$LOG_GROUP" \
    > /usr/local/etc/php-fpm.d/zz-www.conf

COPY app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
