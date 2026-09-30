.PHONY: setup reset queue-work test up down

setup:
	bash laravel/setup.sh

reset:
	bash laravel/reset.sh

queue-work:
	cd laravel && docker compose exec app php artisan queue:work

test:
	cd laravel && docker compose exec app php artisan test

up:
	cd laravel && docker compose up

down:
	cd laravel && docker compose down
