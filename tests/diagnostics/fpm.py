#!/usr/bin/env python3
"""Separate diagnostic FPM run. Never an uninstrumented acceptance result."""
import argparse,hashlib,importlib.util,json,os,pathlib,sys,time
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--extension',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False);(out/'traces').mkdir()
os.environ['ASYNCARA_FPM_DIR']=str(out);os.environ['ASYNCARA_EXTENSION']=str((root/a.extension).resolve())
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
audit.request=lambda port,index,mode:request(audit,port,index,mode,root/'tests/diagnostics/fpm_request.php')
start=time.monotonic();results=[]
try:
 for case in [('A-lifecycle',1,100,'lifecycle',False),('B-within-request',1,10,'io',False),('C-multiple-workers',4,12,'io',True),('D-streaming',4,12,'stream',True)]:
  result=audit.run(*case);results.append(result);print(case[0],result['status'],flush=True)
 assert not list((out/'traces').glob('*.failed')),'Deferred snapshot failed; retain failure files'
 identities=set();summary=[]
 for path in sorted((out/'traces').glob('*.json')):
  trace=json.loads(path.read_text());key=(trace['pid'],trace['request']);assert key not in identities;identities.add(key)
  events=trace['events'];assert len(events)<=4096 and trace['nativeBytes']<=263000
  assert all(y[0]>x[0]and y[1]>=x[1]for x,y in zip(events,events[1:])),path
  assert events and events[0][4]==1,'Request inherited an earlier ring or lost its beginning'
  summary.append({'file':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'request':key,'events':len(events),'overwritten':trace['overwritten'],'dropped':trace['contentionDropped'],'fatal':trace['fatal']})
 assert len(summary)==136,(len(summary),'Expected 134 main + fatal + recovery trace files')
 (out/'report.json').write_text(json.dumps({'status':'PASS','diagnosticOnly':True,'seconds':time.monotonic()-start,'cases':results,'traces':summary},indent=2)+'\n')
finally:audit.server.shutdown();audit.server.server_close()
