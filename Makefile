# —— Inspired by ———————————————————————————————————————————————————————————————
# https://speakerdeck.com/mykiwi/outils-pour-ameliorer-la-vie-des-developpeurs-symfony?slide=47
# https://blog.theodo.fr/2018/05/why-you-need-a-makefile-on-your-project/

# Setup ————————————————————————————————————————————————————————————————————————
SHELL         = bash
PROJECT       = password
SYMFONY_BIN   = ./symfony
EXEC_PHP      = $(SYMFONY_BIN) php
REDIS         = $(DOCKER_EXEC) redis redis-cli
SYMFONY       = $(SYMFONY_BIN) console
COMPOSER      = $(EXEC_PHP) composer.phar
DOCKER        = docker-compose
DOCKER_EXEC   = docker-compose exec
PHPUNIT       = $(EXEC_PHP) bin/phpunit
.DEFAULT_GOAL = help
#.PHONY       = # Not needed for now

## —— The Enabel IT Team Symfony Makefile 🍺 ———————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

wait: ## Sleep 5 seconds
	sleep 5

## —— Composer 🧙‍♂️ ————————————————————————————————————————————————————————————
./composer.phar:
	$(EXEC_PHP) -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
	$(EXEC_PHP) composer-setup.php
	$(EXEC_PHP) -r "unlink('composer-setup.php');"

get-composer: ./symfony ./composer.phar ## Download and install composer in the project (file is ignored)

install: get-composer composer.lock ## Install vendors according to the current composer.lock file
	$(COMPOSER) install --no-progress --no-suggest --prefer-dist --optimize-autoloader

update: get-composer composer.json ## Update vendors according to the composer.json file
	$(COMPOSER) update

## —— Symfony 🎵 ———————————————————————————————————————————————————————————————
sf: ## List all Symfony commands
	$(SYMFONY)

cc: ## Clear the cache. DID YOU CLEAR YOUR CACHE????
	$(SYMFONY) c:c

warmup: ## Warmump the cache
	$(SYMFONY) cache:warmup

fix-perms: ## Fix permissions of all var files
	chmod -R 777 var/*

assets: purge ## Install the assets with symlinks in the public folder
	$(SYMFONY) assets:install public/ --symlink --relative

purge: ## Purge cache and logs
	rm -rf var/cache/* var/logs/*

create-migration: ## Creates a new migration based on database changes
	$(SYMFONY) make:migration

exec-migration: ## Execute a migration to a specified version or the latest available version.
	$(SYMFONY) doctrine:migrations:migrate

create-controller: ## Creates a new controller class
	$(SYMFONY) make:controller

create-entity: ## Creates or updates a Doctrine entity class
	$(SYMFONY) make:entity

create-form: ## Creates a new form class
	$(SYMFONY) make:form

create-voter: ## Creates a new security voter class
	$(SYMFONY) make:voter

get-translation: ## Get translation files from localise
	$(SYMFONY) translation:download
	$(SYMFONY) cache:clear

## —— Symfony binary 💻 ————————————————————————————————————————————————————————
./symfony:
	curl -sS https://get.symfony.com/cli/installer | bash
	mv ~/.symfony/bin/symfony .

bin-install: ./symfony## Download and install the binary in the project (file is ignored)

cert-install: ./symfony ## Install the local HTTPS certificates
	$(SYMFONY_BIN) server:ca:install

serve: ./symfony ## Serve the application with HTTPS support
	$(SYMFONY_BIN) serve --daemon

unserve: ./symfony ## Stop the web server
	$(SYMFONY_BIN) server:stop

open: serve ## Open the local project in a browser
	$(SYMFONY_BIN) open:local

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
up: docker-compose.yml ## Start the docker hub (MySQL,redis,phpmyadmin,mailcatcher)
	$(DOCKER) -f docker-compose.yml up -d

down: docker-compose.yml ## Stop the docker hub
	$(DOCKER) down --remove-orphans

dpsn: ## List Docker containers for the project
	$(DOCKER) images
	@echo "--------------------------------------------------------------------------------------------------------------"
	docker ps -a | grep "$(PROJECT)_"
	@echo "--------------------------------------------------------------------------------------------------------------"

## —— Project 🛠———————————————————————————————————————————————————————————————
run: bin-install up wait schema serve open ## Start docker, load fixtures and start the web server

reload: load-fixtures ## Reload fixtures

abort: down unserve ## Stop docker and the Symfony binary server

cc-redis: ## Flush all Redis cache
	$(REDIS) flushall

commands: ## Display all commands in the project namespace
	$(SYMFONY) list $(PROJECT)

schema: ## Build the db, control the schema validity and check the migration status
	$(SYMFONY) doctrine:cache:clear-metadata
	$(SYMFONY) doctrine:database:create --if-not-exists
	$(SYMFONY) doctrine:migrations:migrate -q

load-fixtures: schema ## Load fixtures
	$(SYMFONY) doctrine:fixtures:load -n

## —— Tests ✅ —————————————————————————————————————————————————————————————————
phpunit.xml:
	cp phpunit.xml.dist phpunit.xml

test: phpunit.xml ## Launch main functionnal and unit tests
	$(PHPUNIT) --group=main --stop-on-failure --debug

test-external: phpunit.xml ## Launch tests implying external resources (api, services...)
	$(PHPUNIT) --group=external --stop-on-failure --debug

test-all: phpunit.xml ## Launch all tests
	$(PHPUNIT) --stop-on-failure

## —— Coding standards ✨ ——————————————————————————————————————————————————————
cs: codesniffer mess stan ## Launch check style and static analysis

codesniffer: ## Run php_codesniffer only
	./vendor/bin/phpcs --standard=checkstyle.xml -n -p src/

stan: ## Run PHPStan only
	./vendor/bin/phpstan analyse --memory-limit 1G -c phpstan.neon

mess: ## Run PHP Mess Dectector only
	./vendor/bin/phpmd --exclude Migrations,AuthBundle/Command,AuthBundle/Service src/ ansi ./codesize.xml

psalm: ## Run psalm only
	./vendor/bin/psalm --show-info=false

init-psalm: ## Init a new psalm config file for a given level, it must be decremented to have stricter rules
	rm ./psalm.xml
	./vendor/bin/psalm --init src/ 3

cs-fix: ## Run php-cs-fixer and fix the code.
	./vendor/bin/php-cs-fixer fix src/

## —— Deploy & Prod 🚀 —————————————————————————————————————————————————————————
deploy-prod: ## Deploy on prod, no-downtime deployment with Ansistrano
	ansible-playbook ansible/deploy.yml -l production

deploy-stage: ## Deploy on stage no-downtime deployment with Ansistrano
	ansible-playbook ansible/deploy.yml -l stage