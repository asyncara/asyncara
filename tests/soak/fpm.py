#!/usr/bin/env python3
"""Reuse audit FastCGI/concurrency assertions; extend process lifecycle faults."""
import argparse, hashlib, concurrent.futures, importlib.util, json, os, pathlib, signal, socket, subprocess, time
root=pathlib.Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser();parser.add_argument('--seconds',type=float,default=60);parser.add_argument('--output',required=True);args=parser.parse_args()
if args.seconds<=0:parser.error('Duration must be positive')
out=pathlib.Path(args.output).resolve()
if out.exists():parser.error('Preserve evidence: choose a new output directory')
os.environ['ASYNCARA_FPM_DIR']=str(out)
spec=importlib.util.spec_from_file_location('audit_fpm',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
def launch(name,workers,recycle=0,timeout=0):
    with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    folder=out/name;folder.mkdir();cfg=folder/'fpm.conf'
    cfg.write_text(f'[global]\ndaemonize=no\nprocess_control_timeout=5s\npid={folder}/pid\nerror_log={folder}/error.log\n[soak]\nlisten=127.0.0.1:{port}\npm=static\npm.max_children={workers}\npm.max_requests={recycle}\nrequest_terminate_timeout={timeout}\nclear_env=yes\ncatch_workers_output=yes\nsecurity.limit_extensions=.php\nphp_admin_flag[log_errors]=on\nphp_admin_value[error_log]={folder}/php-errors.log\n')
    log=(folder/'startup.log').open('w')
    process=subprocess.Popen([os.environ.get('FPM_BIN','php-fpm8.5'),'-n','-F','-d','extension='+str(root/'build/asyncara.so'),'-d','display_errors=0','-y',str(cfg)],stdout=log,stderr=log,start_new_session=True)
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
    log.close()
def children(process):
    path=pathlib.Path(f'/proc/{process.pid}/task/{process.pid}/children')
    return {int(v)for v in path.read_text().split()}
provenance={'commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(), 'gitStatus':subprocess.check_output(['git','status','--short'],cwd=root,text=True), 'files':{name:hashlib.sha256((root/name).read_bytes()).hexdigest() for name in ['build/asyncara.so','tests/soak/fpm.py','scripts/audit-fpm.py','tests/audit/fpm_request.php']}, 'mixedModes':['buffered HTTP','streamed HTTP','SQLite','filesystem','pending shutdown'], 'requestedSeconds':args.seconds}
(out/'provenance.json').write_text(json.dumps(provenance,indent=2)+'\n')
results=[]
try:
    # Preserve the original three distinct assertions as the first phase.
    for case in [('A-lifecycle',1,100,'lifecycle',False),('B-within-request',1,10,'io',False),('C-multiple-workers',4,12,'io',True),('D-streaming',4,12,'stream',True)]:
        results.append(audit.run(*case));print(case[0]+' PASS',flush=True)
    process,port,log=launch('recycling',1,recycle=10)
    try:
        responses=[audit.request(port,i,'io'if i%3==0 else'pending')for i in range(25)]
        pids={r['pid']for r in responses}
        assert len(pids)>=3,'pm.max_requests did not recycle workers'
        results.append({'name':'recycling','status':'PASS','requests':25,'pids':sorted(pids)})
    finally:stop(process,log)
    process,port,log=launch('mixed-reload-kill',4)
    try:
        start=time.monotonic();responses=[];samples=[];reloads=0;kills=0
        with concurrent.futures.ThreadPoolExecutor(max_workers=4)as executor:
            while time.monotonic()-start<args.seconds:
                base=len(responses)
                futures=[executor.submit(audit.request,port,base+i,('io'if i<2 else 'stream'if i==2 else'pending'))for i in range(4)]
                batch=[f.result()for f in futures];responses+=batch
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
                else:
                    for pid in children(process):
                        sample=audit.idle(pid);sample['seconds']=time.monotonic()-start;samples.append(sample)
                        with (out/'mixed-samples.jsonl').open('a')as evidence:evidence.write(json.dumps(sample)+'\n')
                if len(responses)%80==0:
                    audit.request(port,200000+len(responses),'fatal')
                    audit.request(port,300000+len(responses),'lifecycle')
            assert samples and all(s['threads']==1 and s['fds']==6 for s in samples),'Idle resource escape'
            per_pid={pid:[s['rssKiB']for s in samples if s['pid']==pid]for pid in {s['pid']for s in samples}}
            ranges={pid:max(values[3:])-min(values[3:])for pid,values in per_pid.items()if len(values)>4}
            rises={}
            for pid,values in per_pid.items():
                if len(values)<=4:continue
                low=values[3];rise=0
                for value in values[3:]:low=min(low,value);rise=max(rise,value-low)
                rises[pid]=rise
            warm_peaks={pid:max(s['rssKiB']for s in warmup if s['pid']==pid)for pid in {s['pid']for s in warmup}}
            assert set(per_pid)<=set(warm_peaks),'Unexpected worker replacement after warmup'
            growth={pid:max(0,max(values)-warm_peaks[pid])for pid,values in per_pid.items()}
            (out/'mixed-growth.json').write_text(json.dumps({'rssRangeKiBAfterWarmup':ranges,'maxRssIncreaseKiB':rises,'warmupPeakKiB':warm_peaks,'growthBeyondWarmupPeakKiB':growth,'samples':samples},indent=2)+'\n')
            assert all(v<8192 for v in growth.values()),'Persistent worker RSS growth'
            results.append({'name':'mixed-reload-kill','status':'PASS','seconds':time.monotonic()-start,'requests':len(responses),'reloads':reloads,'killedWorkers':kills,'samples':samples,'rssRangeKiBAfterWarmup':ranges,'maxRssIncreaseKiB':rises,'warmupPeakKiB':warm_peaks,'growthBeyondWarmupPeakKiB':growth})
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
    (out/'report.json').write_text(json.dumps(results,indent=2)+'\n');print('FPM recycling/reload/kill/timeout/fatal/pending isolation: PASS',flush=True)
except BaseException as error:
    (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':str(error),'completedCases':results,'provenance':provenance},indent=2)+'\n');raise
finally:audit.server.shutdown();audit.server.server_close()
