MODULE_NAME := drsoftfrhubasyou
VERSION := 1.0.0
COMPOSER ?= composer
ARCHIVE := $(CURDIR)/dist/$(MODULE_NAME)-$(VERSION).zip

.PHONY: build composer package clean

composer:
	$(COMPOSER) install --no-dev --prefer-dist --optimize-autoloader --no-interaction

build: composer

package: build
	mkdir -p dist
	rm -f "$(ARCHIVE)"
	cd .. && zip -rq "$(ARCHIVE)" "$(MODULE_NAME)" \
		-x "$(MODULE_NAME)/.git/*" \
		   "$(MODULE_NAME)/.idea/*" \
		   "$(MODULE_NAME)/dist/*" \
		   "$(MODULE_NAME)/docs/*" \
		   "$(MODULE_NAME)/.gitignore" \
		   "$(MODULE_NAME)/.gitkeep" \
		   "$(MODULE_NAME)/Makefile" \
		   "$(MODULE_NAME)/composer.json" \
		   "$(MODULE_NAME)/composer.lock" \
		   "$(MODULE_NAME)/config_*.xml" \
		   "$(MODULE_NAME)/*.log"
	@echo "Archive créée : $(ARCHIVE)"

clean:
	rm -rf dist
