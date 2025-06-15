.PHONY: all build up composer bash test check fix stan

build:
	@docker compose build

up:
	@docker compose up -d

composer:
	@docker compose run --rm php composer $(c)

bash:
	@docker compose exec php bash

test:
	@docker compose run --rm php vendor/bin/phpunit $(c)

check:
	@docker compose run --rm -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix --diff --dry-run

fix:
	@docker compose run --rm -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix -vvv

stan:
	@docker compose run --rm php composer stan

