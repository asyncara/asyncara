#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
mkdir -p build/test-certs
if [[ ! -f build/test-certs/key.pem ]] || ! openssl x509 -checkend 86400 -noout -in build/test-certs/cert.pem >/dev/null 2>&1; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
    -keyout build/test-certs/key.pem -out build/test-certs/cert.pem \
    -subj /CN=localhost -addext subjectAltName=DNS:localhost > build/test-certs/generation.log 2>&1
fi
