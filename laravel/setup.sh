#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

echo "==> Copying environment file..."
if [ ! -f .env ]; then
    cp .env.example .env
fi

echo "==> Building Docker image..."
docker compose build

echo "==> Starting MySQL..."
docker compose up -d mysql

echo "==> Waiting for MySQL to be ready..."
until docker compose exec mysql mysqladmin ping -h localhost -u coding_test --password=secret --silent 2>/dev/null; do
    printf '.'
    sleep 2
done
echo ""

echo "==> Installing Composer dependencies..."
docker compose run --rm app composer install --no-interaction --prefer-dist

echo "==> Generating application key..."
docker compose run --rm app php artisan key:generate

echo "==> Running migrations and seeders..."
docker compose run --rm app php artisan migrate:fresh --seed --force

echo "==> Starting application..."
docker compose up -d app

echo ""
echo "============================================"
echo " Ready!"
echo " App:          http://localhost:35734"
echo " Health check: http://localhost:35734/up"
echo ""
echo " Queue worker: docker compose exec app php artisan queue:work"
echo " Run tests:    docker compose exec app php artisan test"
echo " Stop:         docker compose down"
echo "============================================"
