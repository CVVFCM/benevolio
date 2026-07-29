HTTP_PORT ?= 80
HTTPS_PORT ?= 443
HTTP3_PORT ?= 443
DATABASE_PORT ?= 5432
MAILPIT_SMTP_PORT ?= 1025
MAILPIT_HTTP_PORT ?= 8025
GOTENBERG_PORT ?= 3000
S3MOCK_PORT ?= 9090
DOCKER_COMPOSE = EXTERNAL_USER_ID=$(shell id -u) HTTP_PORT=$(HTTP_PORT) HTTPS_PORT=$(HTTPS_PORT) HTTP3_PORT=$(HTTP3_PORT) DATABASE_PORT=$(DATABASE_PORT) MAILPIT_SMTP_PORT=$(MAILPIT_SMTP_PORT) MAILPIT_HTTP_PORT=$(MAILPIT_HTTP_PORT) GOTENBERG_PORT=$(GOTENBERG_PORT) S3MOCK_PORT=$(S3MOCK_PORT) docker compose

.PHONY: help
help: ## display this help message
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: pull
pull: ## Build the docker images
	@$(DOCKER_COMPOSE) pull --ignore-pull-failures

.PHONY: build
build: ## Build the docker images
	@$(DOCKER_COMPOSE) build

.PHONY: reset
reset: ## Reset (or create) the database
	@$(DOCKER_COMPOSE) exec php composer reset

.PHONY: reset-test
reset-test: ## Reset (or create) the test database
	@$(DOCKER_COMPOSE) exec php composer reset-test

.PHONY: cli
cli: ## Open a CLI in the PHP container. If you need this it means that I fucked up this Makefile.
	@$(DOCKER_COMPOSE) exec php bash

vendor/: ## Uh ?
	@$(DOCKER_COMPOSE) run --rm php composer install

.PHONY: up
up: ## Start the containers. Mail sent in dev is caught by Mailpit on http://localhost:$(MAILPIT_HTTP_PORT)
	@mkdir -p var/data
	@$(DOCKER_COMPOSE) up -d --remove-orphans --wait

.configured:
	test -f .configured || make first_run
	touch .configured

.PHONY: run
run: .configured up ## Run the project. Create the Database  and build the images if needed

.infra/docker/tls/cert.pem:
	mkdir -p .infra/docker/tls
	mkcert -cert-file .infra/docker/tls/cert.pem -key-file=.infra/docker/tls/cert.key localhost 127.0.0.1

assets/vendor: ## Install importmap vendors
	@$(DOCKER_COMPOSE) exec php bin/console importmap:install

.PHONY: first_run
first_run: .infra/docker/tls/cert.pem pull build vendor/ up reset assets/vendor

.PHONY: clean
clean: ## Stop the containers and remove all the data
	$(DOCKER_COMPOSE) down -v
	rm -rf \
		.configured \
		.infra/docker/tls/cert.* \
		.php-cs-fixer.cache \
		.phpunit.cache \
		.twig-cs-fixer.cache \
		assets/vendor \
		node_modules \
		public/assets \
		public/bundles \
		test-results \
		var \
		vendor

.PHONY: cs
cs: ## Fix code style
	@docker run --rm -v $(PWD):/app -w /app ghcr.io/php-cs-fixer/php-cs-fixer:3-php8.5 fix
	@$(DOCKER_COMPOSE) exec -T php ./vendor/bin/twig-cs-fixer fix
	@npm run --silent fix:js
	@npm run --silent fix:css

.PHONY: cs-check
cs-check: ## Check code style without writing (what the CI runs)
	@docker run --rm -v $(PWD):/app -w /app ghcr.io/php-cs-fixer/php-cs-fixer:3-php8.5 check --diff
	@$(DOCKER_COMPOSE) exec -T php ./vendor/bin/twig-cs-fixer lint
	@npm run --silent lint:js
	@npm run --silent lint:css

.PHONY: test
test: vendor/ ## Run the test suite
	@$(DOCKER_COMPOSE) exec -T php ./vendor/bin/phpunit --testdox --colors=always

.PHONY: stan
stan: vendor/ ## Run the static analysis
	@$(DOCKER_COMPOSE) exec -T php ./vendor/bin/phpstan analyse --ansi --memory-limit 1G

.PHONY: lint
lint: ## Lint the container, YAML, Twig and translation files
	@$(DOCKER_COMPOSE) exec -T php bin/console lint:container
	@$(DOCKER_COMPOSE) exec -T php bin/console lint:yaml --parse-tags config translations
	@$(DOCKER_COMPOSE) exec -T php bin/console lint:twig templates
	@$(DOCKER_COMPOSE) exec -T php bin/console lint:xliff translations
	@$(DOCKER_COMPOSE) exec -T php bin/console doctrine:schema:validate

.PHONY: qa
qa: cs-check lint stan test ## Run every check the CI runs

.PHONY: mate
mate: ## Run the mate command
	@$(DOCKER_COMPOSE) exec php ./vendor/bin/mate serve --force-keep-alive
