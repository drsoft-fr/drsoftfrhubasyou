MODULE_NAME := drsoftfrhubasyou
VERSION := 1.0.1
COMPOSER ?= composer
ARCHIVE := $(CURDIR)/dist/$(MODULE_NAME)-$(VERSION).zip

.PHONY: build composer package clean test test-integration

composer:
	$(COMPOSER) install --no-dev --prefer-dist --optimize-autoloader --no-interaction

build: composer

package: build
	mkdir -p dist
	rm -f "$(ARCHIVE)"
	cd .. && zip -rq "$(ARCHIVE)" "$(MODULE_NAME)" \
		-x "$(MODULE_NAME)/.git/*" \
		   "$(MODULE_NAME)/.claude/*" \
		   "$(MODULE_NAME)/.idea/*" \
		   "$(MODULE_NAME)/dist/*" \
		   "$(MODULE_NAME)/docs/*" \
		   "$(MODULE_NAME)/tests/*" \
		   "$(MODULE_NAME)/.gitignore" \
		   "$(MODULE_NAME)/.gitkeep" \
		   "$(MODULE_NAME)/.mcp.json" \
		   "$(MODULE_NAME)/Makefile" \
		   "$(MODULE_NAME)/composer.json" \
		   "$(MODULE_NAME)/composer.lock" \
		   "$(MODULE_NAME)/config_*.xml" \
		   "$(MODULE_NAME)/phpunit*.xml.dist" \
		   "$(MODULE_NAME)/*.log"
	@echo "Archive créée : $(ARCHIVE)"

# Unit tests: no PrestaShop, no database
test:
	vendor/bin/phpunit

# Integration tests: inside a PrestaShop shop where the module is installed, as the user of
# the web server, never on a production shop (PS_ROOT_DIR if the module is not in <shop>/modules/)
test-integration:
	vendor/bin/phpunit -c phpunit-integration.xml.dist

clean:
	rm -rf dist
