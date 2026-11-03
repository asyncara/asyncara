.PHONY: all build test audit audit-fpm databases stress sanitize thread-sanitize fpm valgrind benchmark phpize-local verify clean
all: build
build:
	./scripts/build-local.sh
test: build
	./scripts/test.sh
audit: build
	python3 scripts/audit.py
audit-fpm: build
	python3 scripts/audit-fpm.py
databases: build
	./scripts/test-databases.py
stress: build
	php -n -d extension=$(CURDIR)/build/asyncara.so tests/stress.php 100
sanitize:
	./scripts/sanitize.sh
thread-sanitize:
	./scripts/thread-sanitize.sh
fpm: build
	./scripts/test-fpm.py
valgrind: build
	./scripts/valgrind.sh
benchmark: build
	./benchmarks/run.py
phpize-local:
	./scripts/phpize-local.sh
verify: test databases stress sanitize
clean:
	@echo 'Build artifacts live under build/ and ext/modules/. Remove only the artifacts you intend to rebuild.'
