#!/usr/bin/env python3
import argparse,pathlib,json,subprocess,os,time,hashlib
p=argparse.ArgumentParser()
for k in ['php','module','backend','out']:p.add_argument('--'+k,required=True)
a=p.parse_args();r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False);sha=lambda p:hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest();t=time.monotonic();failure=None;code=None;child=None
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src','tests/http']for f in(r/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
with(o/'peer.log').open('x')as log:peer=subprocess.Popen(['python3',str(r/'tests/http/declared_size_peer.py')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
try:
 config=json.loads(peer.stdout.readline());(o/'config.json').write_text(json.dumps(config));env=os.environ|{'ASYNCARA_HTTP_BACKEND':'hyper-prototype','ASYNCARA_BACKEND':a.backend}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
 cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/declared_size.php'),str(o/'config.json')]
 with(o/'php.log').open('x')as log:
  child=subprocess.Popen(cmd,cwd=r,env=env,stdout=log,stderr=log);(o/'active.json').write_text(json.dumps({'pid':child.pid,'peerPid':peer.pid,'command':cmd}));code=child.wait(timeout=60)
except BaseException as error:failure=repr(error)
finally:
 if child is not None and child.poll()is None:child.terminate();child.wait(timeout=5)
 peer.stdin.close()
 try:peer.wait(timeout=5)
 except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
 (o/'peer-final.json').write_text(peer.stdout.read())
try:
 assert all(sha(r/n)==digest for n,digest in manifest.items()),'Source changed'
 stats=json.loads((o/'peer-final.json').read_text())
 assert stats['announced']==1 and stats['closed']==1 and stats['bodyBytesOnAnnounced']==0,stats
except BaseException as error:failure=repr(error)
report={'status':'PASS'if code==0 and peer.returncode==0 and failure is None else'FAIL','failure':failure,'exit':code,'seconds':time.monotonic()-t,'command':cmd if'cmd'in locals()else None,'moduleSha256':sha(a.module),'sourceManifestSha256':sha(o/'source-manifest.json'),'logSha256':sha(o/'php.log')if(o/'php.log').exists()else None,'artifacts':{f.name:sha(f)for f in o.glob('*.log')}};(o/'report.json').write_text(json.dumps(report,indent=2));(o/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
