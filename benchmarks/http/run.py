#!/usr/bin/env python3
import argparse,datetime,hashlib,json,os,pathlib,platform,random,subprocess,sys,time
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--output',required=True);p.add_argument('--concurrency',type=int,default=8);p.add_argument('--samples',type=int,default=5);p.add_argument('--count',type=int,default=4000);a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(exist_ok=False,parents=True);rows=[]
serverlog=(out/'server.stderr').open('xb');server=subprocess.Popen([sys.executable,str(root/'benchmarks/http/server.py')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=serverlog,text=True)
identity={'phpSourceRoot':os.environ.get('ASYNCARA_SOURCE_ROOT',str(root)),'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'moduleSha256':hashlib.sha256(pathlib.Path(a.extension).read_bytes()).hexdigest(),'php':a.php,'platform':platform.platform(),'compiler':subprocess.check_output(['cc','--version'],text=True).splitlines()[0],'fixturePid':server.pid,'schema':1,'concurrency':a.concurrency,'scope':'HTTP/1.1 matched persistent connections, identical responses; warmup10 per connection; no TLS; every body/status checked; serialized cases with rotating order','manifest':{str(x.relative_to(root)):hashlib.sha256(x.read_bytes()).hexdigest()for directory in ['ext','src','benchmarks/http']for x in (root/directory).rglob('*')if x.is_file() and '__pycache__' not in str(x)}}
(out/'identity.json').write_text(json.dumps(identity,indent=2)+'\n')
try:
 port=json.loads(server.stdout.readline())['port']
 for size,delay in [(1024,0),(1024,.005),(65536,0)]:
  for sample in range(a.samples):
   modes=['asyncara','curl_multi','swoole'];modes=modes[sample%3:]+modes[:sample%3]
   for mode in modes:
    name=f'{size}-{delay}-{sample}-{mode}';cmd=[a.php,'-n','-d','memory_limit=512M','-d','extension=curl']
    if mode=='asyncara':cmd+=['-d','extension='+str(pathlib.Path(a.extension).resolve())]
    if mode=='swoole':cmd=[a.php,'-n','-d','memory_limit=512M','-d','extension=pdo','-d','extension=mysqlnd','-d','extension=sockets','-d','extension=curl','-d','extension=swoole']
    cmd+=[str(root/'benchmarks/http/client.php'),mode,f'http://127.0.0.1:{port}/body?size={size}&delay={delay}',str(a.count),str(a.concurrency),str(size)]
    log=out/(name+'.log');start=time.monotonic()
    with log.open('xb')as f:
     child=subprocess.Popen(cmd,cwd=root,stdout=f,stderr=subprocess.STDOUT);(out/'active.json').write_text(json.dumps({'pid':child.pid,'command':cmd,'source':identity['source']}))
     try:rc=child.wait(timeout=120)
     except subprocess.TimeoutExpired:
      child.terminate();child.wait(timeout=10);raise
    assert rc==0,(name,log.read_text());d=json.loads(log.read_text());d.update(sample=sample,delay=delay,command=cmd,logSha256=hashlib.sha256(log.read_bytes()).hexdigest());rows.append(d);(out/'results.json').write_text(json.dumps(rows,indent=2)+'\n');print(name,round(d['requestsPerSecond'],1),flush=True)
finally:
 server.stdin.close();server.wait(timeout=10);(out/'server-summary.log').write_text(server.stdout.read());serverlog.close();(out/'active.json').write_text(json.dumps({'status':'TERMINAL','serverExit':server.returncode}))
(out/'report.json').write_text(json.dumps({'status':'PASS','identity':identity,'samples':rows},indent=2)+'\n')
