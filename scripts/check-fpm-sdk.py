#!/usr/bin/env python3
"""Check the actual FPM executable, not just the CLI used to compile a module."""
import hashlib,json,pathlib,re,shutil,subprocess,sys
php,fpm,module=sys.argv[1:]
fpm=shutil.which(fpm) or fpm
code='echo json_encode(["runtime"=>PHP_VERSION,"build"=>asyncara_info()["buildPhpVersion"]],JSON_THROW_ON_ERROR);'
p=subprocess.run([php,'-n','-d','extension='+str(pathlib.Path(module).resolve()),'-r',code],text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
if p.returncode:raise SystemExit('Matching CLI/module probe failed: '+p.stdout+p.stderr)
identity=json.loads(p.stdout)
info=subprocess.check_output([fpm,'-n','-v'],text=True);version=re.search(r'^PHP ([^ ]+)',info)
if not version or identity['build']!=identity['runtime'] or version[1]!=identity['build']:
 raise SystemExit('FPM SDK mismatch: CLI='+identity['runtime']+' module='+str(identity['build'])+' FPM='+(version[1]if version else'unknown')+'. Set FPM_BIN to the matching isolated executable or rebuild with its SDK.')
print(json.dumps(identity|{'fpm':str(pathlib.Path(fpm).resolve()),'fpmVersion':version[1],'fpmSha256':hashlib.sha256(pathlib.Path(fpm).read_bytes()).hexdigest(),'status':'PASS'}))
