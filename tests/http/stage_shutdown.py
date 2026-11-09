#!/usr/bin/env python3
import argparse,json,pathlib,subprocess,tempfile
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--output',required=True);a=p.parse_args();out=pathlib.Path(a.output).resolve();out.mkdir(exist_ok=False,parents=True);rows=[]
for mode in ['normal','fatal']:
 with tempfile.TemporaryDirectory(prefix='asyncara-stage-')as directory:
  cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.extension).resolve()),str(pathlib.Path(__file__).with_suffix('.php')),directory,mode];r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);(out/(mode+'.log')).write_text(r.stdout+r.stderr)
  assert r.returncode==(255 if mode=='fatal'else 0),(mode,r.stdout,r.stderr)
  if mode=='fatal':assert 'Allowed memory size'in r.stdout
  assert list(pathlib.Path(directory).iterdir())==[],'Unpublished temp survived RSHUTDOWN'
  rows.append({'mode':mode,'exit':r.returncode,'directoryEmptyAfterShutdown':True,'command':cmd})
(out/'report.json').write_text(json.dumps({'status':'PASS','cases':rows},indent=2)+'\n');print('PASS normal/fatal staged-file worker shutdown cleanup')
