#!/usr/bin/env python3
"""Original FPM A/B/C/D plus deferred path identity and fatal staged-owner cleanup."""
import argparse,hashlib,importlib.util,json,os,pathlib,sys,time,traceback
from filesystem_paths_fpm_check import require_memory_fatal
r=pathlib.Path(__file__).resolve().parents[1];p=argparse.ArgumentParser()
for key in ['module','fpm','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);a=p.parse_args()
o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False,mode=0o700)
os.environ.update(ASYNCARA_FPM_DIR=str(o),ASYNCARA_EXTENSION=str(pathlib.Path(a.module).resolve()),FPM_BIN=a.fpm)
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src']for f in(r/d).rglob('*')if f.is_file()}
for name in ['scripts/audit-fpm.py','tests/fast/fpm_client.py','tests/audit/fpm_request.php','tests/filesystem_paths_fpm.py','tests/filesystem_paths_fpm_request.php','tests/filesystem_paths_fpm_check.py']:manifest[name]=sha(r/name)
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
spec=importlib.util.spec_from_file_location('audit',r/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
sys.path.insert(0,str(r/'tests/fast'));from fpm_client import request
(o/'request-files').mkdir()
original=o/'original.php';original.write_text('<?php putenv('+json.dumps('ASYNCARA_BACKEND='+a.backend)+'); require '+json.dumps(str(r/'tests/audit/fpm_request.php'))+';\n')
fixture=o/'paths.php';fixture.write_text('<?php $config='+"json_decode("+json.dumps(json.dumps({'backend':a.backend,'root':str(o/'request-files')}))+",true,flags:JSON_THROW_ON_ERROR); require "+json.dumps(str(r/'tests/filesystem_paths_fpm_request.php'))+';\n')
rows=[];started=time.monotonic();report={'status':'RUNNING','backend':a.backend,'moduleSha256':sha(pathlib.Path(a.module)),'cases':rows}
try:
    for original_case,case in [(True,('A-lifecycle',1,100,'lifecycle',False)),(True,('B-within-request',1,10,'io',False)),(True,('C-multiple-workers',4,12,'io',True)),(True,('D-streaming',4,12,'stream',True)),(False,('A-lifecycle',1,20,'io',False)),(False,('Path-four-workers',4,40,'io',True))]:
        scope='original'if original_case else'paths';folder=o/scope;folder.mkdir(exist_ok=True);audit.out=folder
        selected=original if original_case else fixture
        audit.request=lambda port,index,mode:request(audit,port,index,mode,selected)
        (o/'active.json').write_text(json.dumps({'status':'RUNNING','case':scope+'/'+case[0],'command':[a.fpm,'-n','-F'],'configuration':str(folder/case[0]/'fpm.conf')}))
        result=audit.run(*case)
        assert all(v['before']['eventLoopBackend']==a.backend for v in result['responses']),'Incorrect backend'
        if case[0]=='A-lifecycle':
            errors=folder/case[0]/'php-errors.log';require_memory_fatal(errors.read_text())
            result['fatalEvidence']={'memoryExhaustionVerified':True,'log':str(errors),'sha256':sha(errors)}
        rows.append({'scope':scope,**result});(o/'cases.json').write_text(json.dumps(rows,indent=2));print(scope,case[0],'PASS',flush=True)
    assert all(sha(r/n)==s for n,s in manifest.items()),'Source changed during test'
    report['status']='PASS'
except BaseException as error:report.update(status='FAIL',error=repr(error));(o/'failure.log').write_text(traceback.format_exc())
finally:
    audit.server.shutdown();audit.server.server_close();(o/'cases.json').write_text(json.dumps(rows,indent=2))
    report.update(seconds=time.monotonic()-started,casesSha256=sha(o/'cases.json'))
    (o/'report.json').write_text(json.dumps(report,indent=2));(o/'active.json').write_text('{"status":"TERMINAL"}')
print(report['status']);raise SystemExit(report['status']!='PASS')
