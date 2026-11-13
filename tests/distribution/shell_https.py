#!/usr/bin/env python3
"""Exercise curl | sh through real local HTTPS; Python is test infrastructure only."""
import argparse
import hashlib
import http.server
import json
import os
from pathlib import Path
import shlex
import shutil
import ssl
import subprocess
import threading
import time

p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--bundle',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
root=Path(__file__).resolve().parents[2];out=a.output.resolve();out.mkdir(parents=True,exist_ok=False);start=time.monotonic()
script=(root/'scripts/install.sh').read_bytes();bundle=a.bundle.resolve();sha=Path(str(bundle)+'.sha256').read_bytes();requests=[];mode='good'
with (out/'openssl.log').open('w') as log:
 subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1','-keyout',str(out/'key'),'-out',str(out/'cert')],check=True,stdout=log,stderr=log)
class Server(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_GET(self):
  requests.append({'mode':mode,'path':self.path})
  name=self.path.rsplit('/',1)[-1]
  if name=='install.sh':data=script
  elif name==bundle.name and mode!='missing':data=bundle.read_bytes()
  elif name==bundle.name+'.sha256':data=(b'0'*64+b'  '+bundle.name.encode()+b'\n') if mode=='bad-sha' else sha
  else:self.send_error(404);return
  self.send_response(200);self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Server);context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(out/'cert',out/'key');server.socket=context.wrap_socket(server.socket,server_side=True)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();base=f'https://127.0.0.1:{server.server_port}/v0.10.0'
traps=out/'forbidden';traps.mkdir();marker=out/'forbidden-invocations.log'
for tool in ['python','python3','gcc','cc','clang','make','cmake','cargo','rustc','phpize','php-config']:
 f=traps/tool;f.write_text('#!/bin/sh\necho "$0" >> '+shlex.quote(str(marker))+'\nexit 97\n');f.chmod(0o755)
results=[]
try:
 for mode in ['good','bad-sha','missing']:
  home=out/mode;home.mkdir();(home/'bin').mkdir();ini=home/'php.ini';ini.write_text('; must remain unchanged\nmemory_limit=64M\n');before=hashlib.sha256(ini.read_bytes()).hexdigest()
  env=dict(os.environ,HOME=str(home),PATH=str(traps)+':'+str(home/'bin')+':'+os.environ['PATH'],ASYNCARA_PHP=a.php,ASYNCARA_RELEASE_BASE_URL=base,CURL_CA_BUNDLE=str(out/'cert'),PHPRC=str(ini),PHP_INI_SCAN_DIR='')
  curl=subprocess.Popen(['/usr/bin/curl','-fsSL',base+'/install.sh'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env)
  installer=subprocess.Popen(['/bin/sh'],stdin=curl.stdout,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env);curl.stdout.close()
  try:stdout,stderr=installer.communicate(timeout=90);curl_stderr=curl.stderr.read();curl_code=curl.wait(timeout=10)
  except BaseException:
   for child in (installer,curl):
    if child.poll() is None:child.terminate();child.wait(timeout=10)
   raise
  finally:curl.stderr.close()
  (out/(mode+'.log')).write_bytes(stdout+stderr+curl_stderr)
  assert curl_code==0,curl_stderr
  assert hashlib.sha256(ini.read_bytes()).hexdigest()==before
  assert not marker.exists(),'Installation invoked Python or a build tool'
  if mode=='good':
   assert installer.returncode==0,stderr
   executable=shutil.which('asyncara',path=env['PATH']);assert executable==str(home/'bin/asyncara')
   check=subprocess.run(['asyncara','--check'],env=env,capture_output=True,text=True,timeout=30)
   assert check.returncode==0,check.stderr;assert json.loads(check.stdout)['status']=='PASS'
  else:
   assert installer.returncode!=0
   assert not (home/'bin/asyncara').exists()
   assert (b'SHA256 mismatch' if mode=='bad-sha' else b'No accessible matching') in stderr,stderr
  results.append({'case':mode,'expected':'PASS'if mode=='good'else'REJECT','exit':installer.returncode,'iniUnchanged':True,'log':str(out/(mode+'.log'))})
 # An interrupted download must not invoke the function holding the installer.
 incomplete=script[:len(script)//2];r=subprocess.run(['/bin/sh'],input=incomplete,capture_output=True,env=env,timeout=10);assert r.returncode!=0
 (out/'truncated-script.log').write_bytes(r.stdout+r.stderr)
 report={'status':'PASS','seconds':time.monotonic()-start,'php':a.php,'scriptSha256':hashlib.sha256(script).hexdigest(),'bundleSha256':hashlib.sha256(bundle.read_bytes()).hexdigest(),'cases':results,'requests':requests,'pythonOrCompilerInvocations':0,'bareCommandReadyOnPath':True,'originalIniUnchanged':True,'truncatedScriptRejected':True,'scope':'Local HTTPS with a trusted test CA; not proof of GitHub publication'}
except BaseException as error:
 (out/'report.json').write_text(json.dumps({'status':'FAIL','error':repr(error),'cases':results,'requests':requests},indent=2)+'\n');raise
finally:
 server.shutdown();server.server_close();thread.join(timeout=5)
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report))
