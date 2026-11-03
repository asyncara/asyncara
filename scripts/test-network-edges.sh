#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
./scripts/prepare-test-certs.sh
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/network_edges.php
EXTRA_LDFLAGS="${EXTRA_LDFLAGS:-} tests/fixtures/dns_delay.c -Wl,--wrap=getaddrinfo" ASYNCARA_OUTPUT=build/dns-delay.so ./scripts/build-local.sh > build/dns-delay-build.log
"${PHP_BIN:-php}" -n -d extension="$PWD/build/dns-delay.so" tests/dns_deadline.php
