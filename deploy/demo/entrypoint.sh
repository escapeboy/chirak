#!/bin/sh
# Data lives on the /data volume: database.sqlite (from `artisan chirak:export-demo`) and storage/.
set -e
mkdir -p /data/storage/app/runs /data/storage/framework/cache /data/storage/framework/sessions /data/storage/framework/views /data/storage/logs
[ -f /data/database.sqlite ] || { echo "missing /data/database.sqlite (run chirak:export-demo)"; exit 1; }
php artisan migrate --force --no-interaction
php artisan config:cache && php artisan route:cache && php artisan view:cache
exec "$@"
