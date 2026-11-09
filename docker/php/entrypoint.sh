#!/bin/sh
set -e
mkdir -p /data
chown -R www-data /data
exec php-fpm
