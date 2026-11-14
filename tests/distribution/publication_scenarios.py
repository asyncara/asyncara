#!/usr/bin/env python3
"""Exercise generated publisher with real pinned assets and isolated fake gh/curl.

These tests NEVER publish to GitHub; the report names this limitation explicitly.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

FAKE = r'''
import hashlib,json,os,pathlib,shutil,sys
root=pathlib.Path(os.environ['PUB_FIXTURE']);remote=root/'remote';config=json.loads((root/'config.json').read_text());args=sys.argv[1:]
with (root/'calls.jsonl').open('a') as f:f.write(json.dumps({'tool':pathlib.Path(sys.argv[0]).name,'argv':args})+'\n')
if pathlib.Path(sys.argv[0]).name=='curl':
 name=args[-1].rsplit('/',1)[-1];target=pathlib.Path(args[args.index('--output')+1]);shutil.copyfile(remote/name,target)
 if config.get('badPublic'):target.write_bytes(b'corrupt')
 raise SystemExit(0)
if args[:2]==['auth','status']:
 if config.get('unauthenticated'):print('No authentication',file=sys.stderr);raise SystemExit(4)
 print('Authenticated fixture');raise SystemExit(0)
if args[0]=='api':
 path=args[-1];repo='repos/asyncara/asyncara'
 if path==repo:reply={'full_name':'asyncara/asyncara','private':config.get('private',True)}
 elif path==repo+'/git/ref/tags/v0.10.0':reply={'object':{'type':'tag','sha':'a'*40}}
 elif path==repo+'/git/tags/'+'a'*40:reply={'object':{'type':'commit','sha':config.get('commit',os.environ['PUB_EXPECTED_COMMIT'])}}
 elif path==repo+'/releases/tags/v0.10.0':reply={'id':1,'tag_name':'v0.10.0','draft':config.get('draft',False),'immutable':config.get('immutable',False)}
 elif path==repo+'/releases/1/assets?per_page=100&page=1':
  reply=[{'id':int(hashlib.sha256(p.name.encode()).hexdigest()[:8],16),'name':p.name,'size':p.stat().st_size,'state':'uploaded'} for p in sorted(remote.iterdir())]
 else:raise SystemExit('Unsupported fixture API: '+path)
 print(json.dumps(reply));raise SystemExit(0)
if args[:2]==['release','download']:
 name=args[args.index('--pattern')+1];dest=pathlib.Path(args[args.index('--dir')+1])/name;shutil.copyfile(remote/name,dest)
 if config.get('badAfter') and '/after/' in str(dest) and name=='install.sh':dest.write_bytes(b'x'*dest.stat().st_size)
 raise SystemExit(0)
if args[:2]==['release','upload']:
 assert '--clobber' not in args
 counter=root/'uploads.json';uploads=json.loads(counter.read_text()) if counter.exists() else []
 if config.get('failAfter') is not None and len(uploads)>=config['failAfter']:print('Injected upload failure',file=sys.stderr);raise SystemExit(19)
 src=pathlib.Path(args[-1]);dest=remote/src.name
 assert not dest.exists(),'Attempted overwrite'
 shutil.copyfile(src,dest);uploads.append(src.name);counter.write_text(json.dumps(uploads));raise SystemExit(0)
raise SystemExit('Unknown fixture operation')
'''

p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--output',type=Path,required=True)
p.add_argument('--assets',type=Path,required=True)
a=p.parse_args();out=a.output.resolve();out.mkdir(parents=True,exist_ok=False)
root=Path(__file__).resolve().parents[2];plan=json.loads((root/'packaging/releases/0.10.0-assets.json').read_text())
source=a.assets.resolve();start=time.monotonic();results=[]
for f in plan['assets']:
 assert hashlib.sha256((source/f['name']).read_bytes()).hexdigest()==f['sha256']

def scenario(name,config=None,existing=2):
 d=out/name;d.mkdir();(d/'remote').mkdir();(d/'bin').mkdir()
 (d/'config.json').write_text(json.dumps(config or {}))
 for f in plan['assets'][:existing]:shutil.copyfile(source/f['name'],d/'remote'/f['name'])
 for tool in ['gh','curl']:
  f=d/'bin'/tool;f.write_text('#!'+sys.executable+'\n'+FAKE);f.chmod(0o755)
 return d

def run(d,expected,extra=(),assets=source):
 number=len(list(d.glob('run-*.log')));report_dir=d/f'report-{number}'
 env=dict(os.environ,PATH=str(d/'bin')+':'+os.environ['PATH'],PUB_FIXTURE=str(d),PUB_EXPECTED_COMMIT=plan.get('tagCommit',plan['sourceCommit']))
 cmd=['sh',str(root/'scripts/distribution/publish-0.10.0.sh'),'--assets',str(assets),'--output',str(report_dir),*extra]
 r=subprocess.run(cmd,env=env,capture_output=True,text=True,timeout=120)
 (d/f'run-{number}.log').write_text(r.stdout+r.stderr)
 report=json.loads((report_dir/'report.json').read_text())
 assert report['status']==expected,(d,r.stdout,r.stderr)
 assert r.returncode==(1 if expected=='FAIL' else 0)
 results.append({'name':d.name,'status':'PASS','publisherStatus':report['status'],'exit':r.returncode,'report':str(report_dir/'report.json')})
 return report

def uploads(d):return json.loads((d/'uploads.json').read_text()) if (d/'uploads.json').exists() else []
def config(d,**values):(d/'config.json').write_text(json.dumps(values))

try:
 d=scenario('local-only');run(d,'LOCAL_VERIFIED',['--check-local']);assert not (d/'calls.jsonl').exists()
 d=scenario('two-existing-and-resume');(d/'remote'/'user-note.txt').write_text('preserve me')
 r=run(d,'PUBLISHED_VERIFIED_PRIVATE');assert len(uploads(d))==11;assert len(r['after'])==13
 assert (d/'remote'/'user-note.txt').read_text()=='preserve me'
 assert uploads(d)[-1]=='install.sh' and all('install.py' not in n for n in uploads(d))
 run(d,'PUBLISHED_VERIFIED_PRIVATE');assert len(uploads(d))==11
 assert all(json.loads(line)['tool']!='curl' for line in (d/'calls.jsonl').read_text().splitlines())
 d=scenario('dry-run');r=run(d,'PLAN_VERIFIED',['--dry-run']);assert len(r['missing'])==11 and not uploads(d)
 d=scenario('same-size-remote-conflict');f=d/'remote'/plan['assets'][0]['name'];f.write_bytes(b'x'*f.stat().st_size)
 run(d,'FAIL');assert not uploads(d)
 d=scenario('local-corruption');local=d/'assets';local.mkdir()
 for f in plan['assets']:shutil.copyfile(source/f['name'],local/f['name'])
 (local/'install.sh').write_text('modified');run(d,'FAIL',assets=local);assert not (d/'calls.jsonl').exists()
 d=scenario('tag-mismatch',{'commit':'b'*40});run(d,'FAIL');assert not uploads(d)
 d=scenario('missing-auth',{'unauthenticated':True});run(d,'FAIL');assert not uploads(d)
 d=scenario('draft-release',{'draft':True});run(d,'FAIL');assert not uploads(d)
 d=scenario('immutable-release',{'immutable':True});run(d,'FAIL');assert not uploads(d)
 d=scenario('interrupted-upload',{'failAfter':2});run(d,'FAIL');assert len(uploads(d))==2
 config(d);r=run(d,'PUBLISHED_VERIFIED_PRIVATE');assert len(uploads(d))==11 and len(r['uploaded'])==9
 d=scenario('post-upload-integrity',{'badAfter':True});run(d,'FAIL');assert len(uploads(d))==11
 d=scenario('public-https',{'private':False});r=run(d,'PUBLISHED_VERIFIED_PUBLIC');assert len(r['public'])==13
 d=scenario('public-download-corruption',{'private':False,'badPublic':True});run(d,'FAIL')
 result={'status':'PASS','seconds':time.monotonic()-start,'executions':len(results),'cases':results,
  'scope':'Real pinned local assets and generated publisher; gh/curl simulated in private fixtures. No GitHub publication or real public URL verification claimed.'}
except BaseException as error:
 result={'status':'FAIL','error':repr(error),'cases':results}
 (out/'report.json').write_text(json.dumps(result,indent=2)+'\n');raise
(out/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
