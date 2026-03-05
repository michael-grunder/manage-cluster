.PHONY: build-phar

build-phar:
	@bin/build-phar-shim $(if $(OUTPUT),--output $(OUTPUT),)
