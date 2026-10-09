.PHONY: help serve dev dev-frontend migrate fixtures db-reset test test-db-reset cs generate release update plugin-zip

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-16s %s\n", $$1, $$2}'

serve: ## Start the API on http://localhost:8090
	php -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=512M yii serve 0.0.0.0:8090 -t public -r public/api/index.php

dev-frontend: ## Start the admin app on http://localhost:3090
	cd frontend && npx nuxt dev --host 0.0.0.0 --port 3090

dev: ## Start API and frontend together
	$(MAKE) serve & $(MAKE) dev-frontend & wait

migrate: ## Apply pending migrations
	php yii migrate:up --no-interaction

fixtures: ## Load the demo data (admin, editor, countries, authors, books, API client)
	php yii fixtures:load dev

db-reset: ## Drop all tables, re-run migrations and load the demo data
	php bin/reset-db.php
	$(MAKE) migrate
	$(MAKE) fixtures

test-db-reset: ## Recreate the test database with the test fixtures
	php bin/reset-db.php excellent_cms_test
	APP_ENV=test DB_NAME=excellent_cms_test php yii migrate:up --no-interaction
	APP_ENV=test DB_NAME=excellent_cms_test php yii fixtures:load test

test: test-db-reset ## Run the test suite
	APP_ENV=test DB_NAME=excellent_cms_test vendor/bin/codecept run

cs: ## Fix code style
	vendor/bin/php-cs-fixer fix

generate: ## Build the admin app as static files into public (next to public/api)
	@node -e 'process.exit(+process.versions.node.split(".")[0] >= 22 ? 0 : 1)' || (echo "Node 22 or newer required (nvm use, see frontend/.nvmrc)"; exit 1)
	cd frontend && npx nuxt generate
	rsync -a --delete --exclude api/ --exclude assets/ --exclude .htaccess --exclude .DS_Store frontend/.output/public/ public/
	php bin/csp.php

release: generate ## One package for every installation: runtime/release/excellent-cms-<version>.tar.gz (see docs/deployment.md)
	sh bin/release.sh

update: ## On a server after unpacking a new version: migrations, plugin blocks, search index
	php yii migrate:up --no-interaction
	php yii plugins:sync
	php yii search:rebuild

plugin-zip: ## Build the ZIP of a plugin of ../excellent-plugins (name=notifier; other place: PLUGINS_SOURCE=…) into runtime/plugins/ - with its Composer libraries
	@test -n "$(name)" || (echo "Usage: make plugin-zip name=<plugin>"; exit 1)
	php bin/plugin-zip.php $(name)
