#!/usr/bin/env python3
"""Reject mismatched patch SDKs, including October 2026 allocation ABI changes."""
import pathlib,re,subprocess,sys
include=pathlib.Path(sys.argv[1]);php=sys.argv[2]
version=re.search(r'^#define PHP_VERSION "([^"]+)"', (include/'main/php_version.h').read_text(),re.M)
runtime=subprocess.check_output([php,'-n','-r','echo PHP_VERSION;'],text=True)
if not version or version[1]!=runtime:
    raise SystemExit('PHP SDK '+(version[1]if version else'unknown')+' does not match interpreter '+runtime+'. Select matching PHP_BIN and PHP_INCLUDE_DIR/development package; module API alone is insufficient.')
