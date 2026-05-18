#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

echo "==> Resetting database to baseline state..."
docker compose exec app php artisan migrate:fresh --seed --force

echo ""
echo "Database reset complete. App is still running at http://localhost:35734"
