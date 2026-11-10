#!/usr/bin/env python3
import pathlib,sys
root=pathlib.Path(__file__).resolve().parent.parent
binary=pathlib.Path(sys.argv[1]);binary=binary if binary.is_absolute() else root/binary
sources=['php_asyncara.c','php_asyncara.h','native.h','workers.c','memory.c','backend.c','postgresql.c','mysql.c','driver_arginfo.h','server.h','server_php.h','compat/mariadb/ma_hash.h']
if not binary.is_file() or any((root/'ext'/name).stat().st_mtime>binary.stat().st_mtime for name in sources):
    raise SystemExit('Native build is missing or older than its sources: rebuild '+str(binary)+' before testing.')
