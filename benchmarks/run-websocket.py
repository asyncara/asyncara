#!/usr/bin/env python3
import argparse,datetime,json,os,pathlib,platform,subprocess
root=pathlib.Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--output',required=True);a=p.parse_args();out=pathlib.Path(a.output)
if out.exists():p.error('Choose a new result file')
env=os.environ|{'PYTHONPATH':str(root/'.local/ws-peer-17.4')}
server=subprocess.Popen(['python3',str(root/'tests/fixtures/websocket_peer.py'),str(root/'build/test-certs/cert.pem'),str(root/'build/test-certs/key.pem')],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
data={'startedUtc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'platform':platform.platform(),'php':subprocess.check_output(['php','-v'],text=True),'sourceCommit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'samples':[],'note':'Local plain ws, 1KiB text echo, 50 serial roundtrips per persistent connection. Three fresh client processes per count/implementation; same Python17.4 server; compression disabled. Shared host also running mixed soak. No deployment ranking.'}
try:
    ports=json.loads(server.stdout.readline());url='ws://127.0.0.1:'+str(ports['ws'])
    for count in [1,16,128]:
        for _ in range(3):
            for command in [['php','-n','-d','extension='+str(root/'build/asyncara.so'),str(root/'benchmarks/websocket.php')],['python3',str(root/'benchmarks/websocket_peer.py')]]:
                result=json.loads(subprocess.check_output(command+[str(count),url],env=env,text=True,timeout=60));data['samples'].append(result)
                out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(data,indent=2)+'\n');print(result['implementation'],count,result['seconds'],flush=True)
finally:server.stdin.close();server.wait(timeout=10)
