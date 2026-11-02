#!/usr/bin/env python3
"""Freeze the tested source, run a mixed workload, retain samples and trends."""
import argparse, hashlib, http.server, json, os, pathlib, platform, shutil, statistics, subprocess, threading, time
root=pathlib.Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser()
parser.add_argument('--seconds',type=float,default=60)
parser.add_argument('--tasks',type=int,default=1000)
parser.add_argument('--databases',action='store_true')
parser.add_argument('--output',required=True)
args=parser.parse_args()
if args.seconds<=0 or not 1<=args.tasks<=10000:parser.error('Invalid duration/task count')
out=pathlib.Path(args.output).resolve()
if out.exists():parser.error('Output already exists; use a new evidence directory')
subprocess.run(['python3',str(root/'scripts/check-build.py'),str(root/'build/asyncara.so')],check=True)
out.mkdir(parents=True);snapshot=out/'source';snapshot.mkdir()
paths=subprocess.check_output(['git','-C',str(root),'ls-files','--cached','--others','--exclude-standard','-z']).decode().split('\0')
manifest={}
for name in sorted(set(paths)-{''}):
    source=root/name
    if not source.is_file() or source.is_symlink():raise RuntimeError('Unexpected source path '+name)
    target=snapshot/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source,target)
    manifest[name]=hashlib.sha256(source.read_bytes()).hexdigest()
(snapshot/'.local').symlink_to(root/'.local',target_is_directory=True)
(snapshot/'build').mkdir();shutil.copy2(root/'build/asyncara.so',snapshot/'build/asyncara.so')
(out/'source-manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
metadata={'commit':subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip(),'gitStatus':subprocess.check_output(['git','-C',str(root),'status','--short'],text=True),'sourceManifestSha256':hashlib.sha256((out/'source-manifest.json').read_bytes()).hexdigest(),'extensionSha256':hashlib.sha256((snapshot/'build/asyncara.so').read_bytes()).hexdigest(),'platform':platform.platform(),'cpuCount':os.cpu_count(),'php':subprocess.check_output([os.environ.get('PHP_BIN','php'),'-n','-v'],text=True),'requestedSeconds':args.seconds,'tasksPerCycle':args.tasks,'databases':args.databases,'startedUtc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
(out/'metadata.json').write_text(json.dumps(metadata,indent=2)+'\n')
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_GET(self):
        time.sleep(.01);body=b'x'*8192
        self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers()
        try:self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError):pass
class Server(http.server.ThreadingHTTPServer):
    daemon_threads=True
    request_queue_size=128
server=Server(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
env=os.environ|{'ASYNCARA_SOAK_SECONDS':str(args.seconds),'ASYNCARA_SOAK_TASKS':str(args.tasks),'ASYNCARA_SOAK_URL':f'http://127.0.0.1:{server.server_port}/slow'}
command=([str(snapshot/'scripts/test-databases.py'),'--test-file','tests/soak/workload.php','--timeout',str(args.seconds+120)] if args.databases else [env.get('PHP_BIN','php'),'-n','-d','extension='+str(snapshot/'build/asyncara.so'),'tests/soak/workload.php'])
start=time.monotonic()
try:
    with (out/'samples.jsonl').open('w')as stdout,(out/'stderr.log').open('w')as stderr:
        result=subprocess.run(command,cwd=snapshot,env=env,stdout=stdout,stderr=stderr,timeout=args.seconds+180)
finally:server.shutdown();server.server_close()
records=[];parse_errors=[]
for line in (out/'samples.jsonl').read_text().splitlines():
    try:records.append(json.loads(line))
    except json.JSONDecodeError:parse_errors.append(line)
samples=[r for r in records if r.get('type')=='sample'];complete=[r for r in records if r.get('type')=='complete']
steady=samples[max(1,len(samples)//5):] or samples
def trend(path):
    def get(sample):
        for key in path:sample=sample[key]
        return sample
    if not steady:return None
    ys=[get(s)for s in steady];xs=[s['seconds']for s in steady];mean=statistics.mean(xs)
    denominator=sum((x-mean)**2 for x in xs)
    slope=sum((x-mean)*(y-statistics.mean(ys))for x,y in zip(xs,ys))/denominator if denominator else 0
    width=min(5,max(1,len(ys)//3))
    return {'min':min(ys),'max':max(ys),'median':statistics.median(ys),'endMinusStartMedian':statistics.median(ys[-width:])-statistics.median(ys[:width]),'slopePerHour':slope*3600}
trends={name:trend(path)for name,path in {'rssKiB':['os','rssKiB'],'phpBytes':['phpBytes'],'nativeBytes':['native','nativeMemoryBytes'],'fds':['os','fds'],'threads':['os','threads'],'sockets':['os','sockets']}.items()}
failures=[]
if result.returncode or parse_errors or not complete:failures.append('Workload did not finish cleanly')
if (out/'stderr.log').stat().st_size:failures.append('Unexpected stderr; inspect log')
if complete and complete[-1]['seconds']<args.seconds:failures.append('Requested duration not reached')
for key,limit in [('rssKiB',32768),('phpBytes',8388608),('nativeBytes',2097152),('fds',4),('threads',0)]:
    if trends[key] and trends[key]['endMinusStartMedian']>limit:failures.append(key+' growth exceeds investigation threshold')
report={'status':'PASS'if not failures else'FAIL','wallSeconds':time.monotonic()-start,'workloadExit':result.returncode,'samples':len(samples),'complete':complete,'steadyTrends':trends,'failures':failures,'parseErrors':parse_errors,'notes':['Sampling at completed-cycle boundaries; peaks during cycles require separate instrumentation.','RSS contains library caches and allocator arenas; trend thresholds trigger investigation, not proof of absence of leaks.','Socket count includes cached connections; native HTTP readiness registrations do not count all pooled idle connections.','Active Tasks count scheduled Fibers, not arbitrary application-created Fibers.']}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({'status':report['status'],'report':str(out/'report.json'),'wallSeconds':report['wallSeconds'],'failures':failures}))
raise SystemExit(bool(failures))
