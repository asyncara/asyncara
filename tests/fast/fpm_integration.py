#!/usr/bin/env python3
"""B2 short integration gate. Original HTTP progress and lifecycle assertions,
with additional pending socket/timer operations inside each I/O request.
Duration coverage is separate from B1 native operation coverage.
Derived from tests/soak/fpm.py; that historical runner remains unchanged.
"""
import argparse, hashlib, concurrent.futures, importlib.util, json, os, pathlib, shutil, signal, socket, subprocess, sys, time
from collections import deque
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"soak"))
from metrics import FpmGrowth
from monitor import ProcessMonitor
root=pathlib.Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser();parser.add_argument('--seconds',type=float,default=600);parser.add_argument('--responses',type=int,default=0);parser.add_argument('--seeds',type=int,nargs='+',default=[17,271828,104729]);parser.add_argument('--seed',type=int,default=17);parser.add_argument('--output',required=True);args=parser.parse_args()
if args.responses:parser.error('B2 is duration bounded; use fpm_count.py for the historical response-count gate')
import random, resource
rng=random.Random(args.seed)
run_started=time.monotonic()
process_exits=[]
if args.seconds<=0:parser.error('Duration must be positive')
out=pathlib.Path(args.output).resolve()
if out.exists():parser.error('Preserve evidence: choose a new output directory')
os.environ['ASYNCARA_FPM_DIR']=str(out)
spec=importlib.util.spec_from_file_location('audit_fpm',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
selected=(root/os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so')).resolve()
subprocess.run(['python3',str(root/'scripts/check-build.py'),str(selected)],check=True)
extension=out/'asyncara.so';shutil.copy2(selected,extension)
os.environ['ASYNCARA_EXTENSION']=str(extension)
def launch(name,workers,recycle=0,timeout=0):
    with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    folder=out/name;folder.mkdir();cfg=folder/'fpm.conf'
    cfg.write_text(f'[global]\ndaemonize=no\nprocess_control_timeout=5s\npid={folder}/pid\nerror_log={folder}/error.log\n[soak]\nlisten=127.0.0.1:{port}\npm=static\npm.max_children={workers}\npm.max_requests={recycle}\nrequest_terminate_timeout={timeout}\nclear_env=yes\ncatch_workers_output=yes\nsecurity.limit_extensions=.php\nphp_admin_flag[log_errors]=on\nphp_admin_value[error_log]={folder}/php-errors.log\n')
    log=(folder/'startup.log').open('w')
    process=subprocess.Popen([os.environ.get('FPM_BIN','php-fpm8.5'),'-n','-F','-d','extension='+str(extension),'-d','display_errors=0','-y',str(cfg)],stdout=log,stderr=log,start_new_session=True)
    deadline=time.monotonic()+10
    while True:
        if process.poll()is not None:raise RuntimeError('FPM startup failed')
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.1):break
        except OSError:
            if time.monotonic()>deadline:raise
            time.sleep(.01)
    return process,port,log
def stop(process,log):
    if process.poll()is None:process.send_signal(signal.SIGQUIT)
    try:process.wait(timeout=10)
    except subprocess.TimeoutExpired:os.killpg(process.pid,signal.SIGKILL);process.wait();raise RuntimeError('FPM failed graceful shutdown')
    process_exits.append({"pid":process.pid,"exit":process.returncode})
    log.close()
def children(process):
    path=pathlib.Path(f'/proc/{process.pid}/task/{process.pid}/children')
    return {int(v)for v in path.read_text().split()}
provenance={'commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(), 'gitStatus':subprocess.check_output(['git','status','--short'],cwd=root,text=True), 'extension':{'selected':str(selected),'snapshot':str(extension),'sha256':hashlib.sha256(extension.read_bytes()).hexdigest()}, 'files':{name:hashlib.sha256((root/name).read_bytes()).hexdigest() for name in ['tests/soak/fpm.py','tests/soak/metrics.py','tests/soak/monitor.py','scripts/audit-fpm.py','tests/audit/fpm_request.php']}, 'mixedModes':['buffered HTTP','streamed HTTP','SQLite','filesystem','pending shutdown'], 'requestedSeconds':args.seconds, 'targetResponses':None, 'coverage':'B2 duration plus actual dense operation counts', 'seeds':args.seeds, 'denseFixtureSha256':hashlib.sha256((root/'tests/fast/fpm_dense.php').read_bytes()).hexdigest(), 'densitySha256':hashlib.sha256((root/'tests/fast/fpm_density.inc.php').read_bytes()).hexdigest(), 'seed':args.seed, 'countRunnerSha256':hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest()}
(out/'provenance.json').write_text(json.dumps(provenance,indent=2)+'\n')
results=[];monitor=ProcessMonitor(out/'os-processes.jsonl');monitor.__enter__()
try:
    # Preserve the original three distinct assertions as the first phase.
    for case in [('A-lifecycle',1,100,'lifecycle',False),('B-within-request',1,10,'io',False),('C-multiple-workers',4,12,'io',True),('D-streaming',4,12,'stream',True)]:
        results.append(audit.run(*case));print(case[0]+' PASS',flush=True)
    # A/B/C/D above still use the exact historical fixture. Mixed requests add
    # density without removing any original statement or assertion.
    from fpm_client import request as dense_request
    audit.request=lambda port,index,mode: dense_request(audit,port,index,mode,root/'tests/fast/fpm_dense.php')
    process,port,log=launch('recycling',1,recycle=10)
    try:
        responses=[audit.request(port,i,'io'if i%3==0 else'pending')for i in range(25)]
        pids={r['pid']for r in responses}
        assert len(pids)>=3,'pm.max_requests did not recycle workers'
        results.append({'name':'recycling','status':'PASS','requests':25,'pids':sorted(pids)})
    finally:stop(process,log)
    process,port,log=launch('mixed-reload-kill',4)
    try:
        start=time.monotonic();requests=0;growth=None;reloads=0;kills=0
        with concurrent.futures.ThreadPoolExecutor(max_workers=4)as executor, (out/'mixed-responses.jsonl').open('x')as response_log, (out/'mixed-samples.jsonl').open('x')as sample_log:
            while time.monotonic()-start<args.seconds:
                base=requests
                seed=args.seeds[(requests//4)%len(args.seeds)];rng.seed(seed+requests);order=list(range(4));rng.shuffle(order)
                futures=[executor.submit(audit.request,port,base+i,('io'if i<2 else 'stream'if i==2 else'pending'))for i in order]
                batch=[f.result()for f in futures];requests+=len(batch)
                for response in batch:response['arrivalSeed']=seed;response_log.write(json.dumps(response)+'\n')
                response_log.flush()
                if not reloads:
                    old=children(process)
                    pending=[executor.submit(audit.request,port,100000+i,'io')for i in range(4)]
                    deadline=time.monotonic()+10
                    while True:
                        with audit.active_lock:active=all(audit.active_requests.get(str(100000+i),0)==4 for i in range(4))
                        if active:break
                        if time.monotonic()>deadline:raise RuntimeError('Reload barrier: four requests did not enter concurrent I/O')
                        time.sleep(.001)
                    process.send_signal(signal.SIGUSR2)
                    for f in pending:f.result()
                    deadline=time.monotonic()+10
                    while children(process)&old:
                        if time.monotonic()>deadline:raise RuntimeError('Graceful reload did not replace children')
                        time.sleep(.02)
                    reloads+=1
                elif not kills:
                    victim=next(iter(children(process)));os.kill(victim,signal.SIGKILL);kills+=1
                    deadline=time.monotonic()+10
                    while victim in children(process)or len(children(process))<4:
                        if time.monotonic()>deadline:raise RuntimeError('Killed worker not replaced')
                        time.sleep(.02)
                    # Warm the largest workload before applying the unchanged
                    # steady-state growth threshold. Retain every warmup sample.
                    # Freed malloc arenas persist after request/thread teardown.
                    warmup=[]
                    for round in range(24):
                        fatal=[executor.submit(audit.request,port,400000+round*4+i,'fatal-burst')for i in range(4)]
                        for f in fatal:f.result()
                        for pid in children(process):warmup.append(audit.idle(pid)|{'round':round})
                    (out/'fatal-warmup.json').write_text(json.dumps(warmup,indent=2)+'\n')
                    growth=FpmGrowth(warmup)
                else:
                    for pid in children(process):
                        sample=audit.idle(pid);sample['seconds']=time.monotonic()-start
                        sample_log.write(json.dumps(sample)+'\n');sample_log.flush()
                        growth.add(sample)
                if requests%80==0:
                    audit.request(port,200000+requests,'fatal')
                    audit.request(port,300000+requests,'lifecycle')
            assert growth is not None,'No steady-state samples'
            summary=growth.summary()|{'samplesFile':'mixed-samples.jsonl','responsesFile':'mixed-responses.jsonl'}
            (out/'mixed-growth.json').write_text(json.dumps(summary,indent=2)+'\n')
            growth.check()
            results.append({'name':'mixed-reload-kill','status':'PASS','seconds':time.monotonic()-start,'requests':requests,'reloads':reloads,'killedWorkers':kills}|summary)
    finally:stop(process,log)
    process,port,log=launch('request-timeout',1,timeout=1)
    try:
        before=audit.request(port,0,'lifecycle')['pid'];failed=False
        try:audit.request(port,1,'timeout')
        except (RuntimeError,ConnectionError,json.JSONDecodeError):failed=True
        assert failed,'Request timeout unexpectedly completed'
        after=audit.request(port,2,'lifecycle')['pid'];assert before!=after,'Timed-out worker not replaced'
        sample=audit.idle(after);assert sample['threads']==1 and sample['fds']==6
        results.append({'name':'request-timeout','status':'PASS','terminatedPid':before,'replacement':sample})
    finally:stop(process,log)
    monitor.__exit__(None,None,None)
    (out/'report.json').write_text(json.dumps(results,indent=2)+'\n')
    achieved=True
    elapsed=next(r['seconds']for r in results if r['name']=='mixed-reload-kill')
    terminal={'status':'PASS','reason':'B2 duration and original workload/resource assertions completed; not a 200000-response claim','mainResponses':requests,'targetResponses':None, 'coverage':'B2 duration plus actual dense operation counts', 'seeds':args.seeds, 'denseFixtureSha256':hashlib.sha256((root/'tests/fast/fpm_dense.php').read_bytes()).hexdigest(), 'densitySha256':hashlib.sha256((root/'tests/fast/fpm_density.inc.php').read_bytes()).hexdigest(),'mixedSeconds':elapsed,'responsesPerSecond':requests/elapsed,'workloadAssertions':'PASS','seed':args.seed,'seeds':args.seeds,'requestedSeconds':args.seconds,'totalWallSeconds':time.monotonic()-run_started,'runnerCpuSeconds':resource.getrusage(resource.RUSAGE_SELF).ru_utime+resource.getrusage(resource.RUSAGE_SELF).ru_stime,'fpmExitRecords':process_exits}
    (out/'gate.json').write_text(json.dumps(terminal,indent=2)+'\n');print(json.dumps(terminal),flush=True)
except BaseException as error:
    # Preserve the actual worker failure (signal, allocator error, timeout or
    # fatal request) in CI output as well as artifacts. Never retry/reset it.
    for diagnostic in sorted(out.rglob('*log')):
        if diagnostic.name in ('error.log','php-errors.log','startup.log'):
            with diagnostic.open(errors='replace')as stream:lines=deque(stream,maxlen=12)
            if lines:print(str(diagnostic.relative_to(out))+':\n'+''.join(lines),flush=True)
    for counter in ('memory.events','memory.max','memory.peak'):
        path=pathlib.Path('/sys/fs/cgroup')/counter
        if path.is_file():print('cgroup '+counter+': '+path.read_text().strip(),flush=True)
    (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':str(error),'completedCases':results,'provenance':provenance},indent=2)+'\n');raise
finally:
    audit.server.shutdown();audit.server.server_close()
    monitor.__exit__(*sys.exc_info())

# B2 covers real FPM lifecycle; B1 separately measures native operation volume.
raise SystemExit(0 if achieved else 2)
