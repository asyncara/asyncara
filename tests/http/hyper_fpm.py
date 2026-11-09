#!/usr/bin/env python3
"""Short prototype FPM check: real peer barrier/two ticks and fatal teardown.
This is separate from both the curl feature fixture and historical acceptance.
"""
import argparse,hashlib,importlib.util,json,os,pathlib,sys,time
p=argparse.ArgumentParser();p.add_argument('--extension',required=True);p.add_argument('--output',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
os.environ['ASYNCARA_FPM_DIR']=str(out);os.environ['ASYNCARA_EXTENSION']=str(pathlib.Path(a.extension).resolve())
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
fixture=root/'tests/http/hyper_fpm_request.php'
audit.request=lambda port,index,mode:request(audit,port,index,mode,fixture)
results=[];start=time.monotonic()
try:
 for case in [('A-lifecycle',1,20,'io',False),('Hyper-four-workers',4,40,'io',True)]:
  results.append(audit.run(*case));print(case[0],'PASS',flush=True)
 (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'fixtureSha256':hashlib.sha256(fixture.read_bytes()).hexdigest(),'moduleSha256':hashlib.sha256(pathlib.Path(a.extension).read_bytes()).hexdigest(),'cases':results},indent=2)+'\n')
except BaseException as e:
 (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':repr(e),'completedCases':results},indent=2)+'\n');raise
finally:audit.server.shutdown();audit.server.server_close()
