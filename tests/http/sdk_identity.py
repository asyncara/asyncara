#!/usr/bin/env python3
"""Module loader must reject a mismatched patch SDK before initializing engines."""
import argparse,hashlib,json,pathlib,subprocess
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--mismatched-php',required=True);p.add_argument('--extension',required=True);p.add_argument('--output',required=True);a=p.parse_args()
out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False);ext=pathlib.Path(a.extension).resolve();rows=[]
for name,php,match in [('matched',a.php,True),('mismatched',a.mismatched_php,False)]:
 version=subprocess.check_output([php,'-n','-r','echo PHP_VERSION;'],text=True)
 code='if (!extension_loaded("asyncara")) exit(43); asyncara_submit("dns",["localhost"]); $r=asyncara_poll([],[],1000); if (!isset($r["results"][0])) exit(42); echo json_encode(["build"=>asyncara_info()["buildPhpVersion"],"runtime"=>PHP_VERSION]),"\\n";'
 command=[php,'-n','-d','extension='+str(ext),'-r',code];r=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=10)
 (out/(name+'.log')).write_bytes(r.stdout);rows.append({'name':name,'php':php,'phpVersion':version,'command':command,'exit':r.returncode,'logSha256':hashlib.sha256(r.stdout).hexdigest()})
 if match:
  assert r.returncode==0,r.stdout.decode();v=json.loads(r.stdout);assert v['build']==v['runtime']
 else:assert r.returncode!=0 and b'Rebuild with the exact matching PHP SDK'in r.stdout and b'Undefined array key'not in r.stdout,r.stdout.decode()
assert rows[0]['phpVersion']!=rows[1]['phpVersion']
(out/'report.json').write_text(json.dumps({'status':'PASS','moduleSha256':hashlib.sha256(ext.read_bytes()).hexdigest(),'cases':rows},indent=2)+'\n');print('PASS matching patch accepted; mismatched patch rejected before native submission')
