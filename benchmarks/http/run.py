#!/usr/bin/env python3
import argparse,datetime,hashlib,json,os,pathlib,platform,random,subprocess,sys,time
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--output',required=True);p.add_argument('--concurrency',type=int,default=8);p.add_argument('--samples',type=int,default=5);p.add_argument('--count',type=int,default=4000);p.add_argument('--baseline-extension');p.add_argument('--baseline-source-root');a=p.parse_args();
if bool(a.baseline_extension)!=bool(a.baseline_source_root):p.error('Both baseline module and source are required')
if a.concurrency<1 or a.concurrency>256 or a.samples<3 or a.count<1 or a.count%a.concurrency:p.error('At least three samples and a positive count divisible by concurrency are required')
if a.baseline_extension and a.concurrency!=8:p.error('Historical baseline comparison uses its fixed 8-per-host limit')
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(exist_ok=False,parents=True);rows=[]
serverlog=(out/'server.stderr').open('xb');server=subprocess.Popen([sys.executable,str(root/'benchmarks/http/server.py')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=serverlog,text=True)
identity={'phpSourceRoot':os.environ.get('ASYNCARA_SOURCE_ROOT',str(root)),'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'moduleSha256':hashlib.sha256(pathlib.Path(a.extension).read_bytes()).hexdigest(),'php':a.php,'platform':platform.platform(),'compiler':subprocess.check_output(['cc','--version'],text=True).splitlines()[0],'fixturePid':server.pid,'schema':1,'concurrency':a.concurrency,'scope':'HTTP/1.1 matched persistent connections, identical responses; warmup10 per connection; no TLS; every body/status checked; serialized cases with rotating order','manifest':{str(x.relative_to(root)):hashlib.sha256(x.read_bytes()).hexdigest()for directory in ['ext','src','benchmarks/http']for x in (root/directory).rglob('*')if x.is_file() and '__pycache__' not in str(x)}}
if a.baseline_extension:
 identity['baseline']={'sourceRoot':str(pathlib.Path(a.baseline_source_root).resolve()),'source':subprocess.check_output(['git','-C',a.baseline_source_root,'rev-parse','HEAD'],text=True).strip(),'module':str(pathlib.Path(a.baseline_extension).resolve()),'moduleSha256':hashlib.sha256(pathlib.Path(a.baseline_extension).read_bytes()).hexdigest()}
(out/'identity.json').write_text(json.dumps(identity,indent=2)+'\n')
try:
 port=json.loads(server.stdout.readline())['port']
 for size,delay in [(1024,0),(1024,.005),(65536,0)]:
  for sample in range(a.samples):
   modes=['asyncara','curl_multi','swoole']+(['asyncara_baseline']if a.baseline_extension else []);position=sample%len(modes);modes=modes[position:]+modes[:position]
   for mode in modes:
    name=f'{size}-{delay}-{sample}-{mode}';cmd=[a.php,'-n','-d','memory_limit=512M','-d','extension=curl']
    if mode in ['asyncara','asyncara_baseline']:cmd+=['-d','extension='+str(pathlib.Path(a.baseline_extension if mode=='asyncara_baseline'else a.extension).resolve())]
    if mode=='swoole':cmd=[a.php,'-n','-d','memory_limit=512M','-d','extension=pdo','-d','extension=mysqlnd','-d','extension=sockets','-d','extension=curl','-d','extension=swoole']
    cmd+=[str(root/'benchmarks/http/client.php'),'asyncara'if mode=='asyncara_baseline'else mode,f'http://127.0.0.1:{port}/body?size={size}&delay={delay}',str(a.count),str(a.concurrency),str(size)]
    log=out/(name+'.log');start=time.monotonic()
    with log.open('xb')as f:
     env=os.environ.copy();env['ASYNCARA_SOURCE_ROOT']=a.baseline_source_root if mode=='asyncara_baseline'else str(root)
     child=subprocess.Popen(cmd,cwd=root,env=env,stdout=f,stderr=subprocess.STDOUT);(out/'active.json').write_text(json.dumps({'pid':child.pid,'command':cmd,'source':identity['source']}))
     try:rc=child.wait(timeout=120)
     except subprocess.TimeoutExpired:
      child.terminate();child.wait(timeout=10);raise
    assert rc==0,(name,log.read_text());d=json.loads(log.read_text());d.update(mode=mode,sample=sample,delay=delay,command=cmd,logSha256=hashlib.sha256(log.read_bytes()).hexdigest());rows.append(d);(out/'results.json').write_text(json.dumps(rows,indent=2)+'\n');print(name,round(d['requestsPerSecond'],1),flush=True)
finally:
 server.stdin.close();server.wait(timeout=10);(out/'server-summary.log').write_text(server.stdout.read());serverlog.close();(out/'active.json').write_text(json.dumps({'status':'TERMINAL','serverExit':server.returncode}))
(out/'report.json').write_text(json.dumps({'status':'PASS','identity':identity,'samples':rows},indent=2)+'\n')
