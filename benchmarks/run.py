#!/usr/bin/env python3
import os,sys,json,pathlib,platform,subprocess,datetime,hashlib
root=pathlib.Path(__file__).resolve().parent.parent;os.chdir(root)
subprocess.run([sys.executable,'scripts/check-build.py','build/asyncara.so'],check=True)
subprocess.run(['scripts/prepare-test-certs.sh'],check=True)
fixture=subprocess.Popen(['python3','tests/fixtures/http_server.py','build/test-certs/cert.pem','build/test-certs/key.pem'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=open('build/benchmark-fixture.log','w'),text=True)
results={'recordedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),'artifactSha256':hashlib.sha256((root/'build/asyncara.so').read_bytes()).hexdigest(),'sourceSha256':hashlib.sha256(b''.join(p.read_bytes() for p in sorted((root/'ext').glob('*.c')))).hexdigest(),'platform':platform.platform(),'cpuCount':os.cpu_count(),'workload':'24 local HTTP/1.1 requests; .02 second upstream delay; 4 active requests except sequential baseline; HTTP connection reuse disabled for matched baselines; three samples per case','cases':[]}
sources = sorted(list((root/'ext').glob('*.c')) + list((root/'ext').glob('*.h')) + list((root/'src').rglob('*.php')))
results['sourceManifest'] = {str(path.relative_to(root)):hashlib.sha256(path.read_bytes()).hexdigest() for path in sources}
results['runtime'] = json.loads(subprocess.check_output(['php','-n','-d','extension='+str(root/'build/asyncara.so'),'-r','echo json_encode(asyncara_info());'],text=True))
results['compiler'] = subprocess.check_output(['cc','--version'],text=True).splitlines()[0]
try:
 ports=json.loads(fixture.stdout.readline());url=f"http://127.0.0.1:{ports['http']}/slow?delay=.02"
 php=['php','-n','-d','memory_limit=1G','-d','extension='+str(root/'build/asyncara.so'),'-d','extension=curl']
 for mode in ['sequential-http','async-http','tasks','timers','amp-timers','sequential-files','files','sqlite','queue','sequential-cpu','cpu']:
  result=subprocess.run(php+['benchmarks/run.php',mode,url],capture_output=True,text=True,check=True);results['cases'].append(json.loads(result.stdout));print(mode,'complete',file=sys.stderr)
 subprocess.run(['cc','-std=c11','-D_POSIX_C_SOURCE=200809L','-O2','-Wall','-Wextra','-Werror','-I.local/sysroot/usr/include/x86_64-linux-gnu','benchmarks/curl_baseline.c','-l:libcurl.so.4','-o','build/curl-baseline'],check=True)
 results['cases'].append(json.loads(subprocess.check_output(['build/curl-baseline',url],text=True)))
 import fpm
 results['cases'].append(fpm.run(root,url))
 result=subprocess.run(['php','-n','-d','extension=pdo','-d','extension=mysqlnd','-d','extension=sockets','-d','extension=curl','-d','extension=swoole','benchmarks/swoole.php',str(ports['http'])],capture_output=True,text=True)
 if result.returncode==0:results['cases'].append(json.loads(result.stdout))
 else:results['swooleUnavailable']=result.stderr+result.stdout
finally:
 fixture.stdin.close();fixture.wait(timeout=10)
path=pathlib.Path('benchmarks/results');path.mkdir(exist_ok=True)
(path/'latest.json').write_text(json.dumps(results,indent=2)+'\n')
print(json.dumps({case['mode']:[round(s['seconds'],5) for s in case['samples']]for case in results['cases']},indent=2))
