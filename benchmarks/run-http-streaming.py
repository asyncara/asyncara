#!/usr/bin/env python3
"""Fresh PHP process per sample; preserve every sample, including slow consumers."""
import argparse,datetime,json,os,pathlib,platform,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);args=parser.parse_args()
output=pathlib.Path(args.output)
if output.exists():parser.error('Choose a new result file')
subprocess.run([str(root/'scripts/prepare-test-certs.sh')],check=True)
server=subprocess.Popen(['python3',str(root/'tests/fixtures/http_stream_server.py'),str(root/'build/test-certs/cert.pem'),str(root/'build/test-certs/key.pem')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
try:
    ports=json.loads(server.stdout.readline());url='http://127.0.0.1:'+str(ports['http'])
    data={'startedUtc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'platform':platform.platform(),'php':subprocess.check_output(['php','-v'],text=True),'samples':[],'note':'Local HTTP/1.1; three samples per mode, new interpreter each time. No cross-host ranking.'}
    with tempfile.TemporaryDirectory(prefix='asyncara-stream-bench-')as tmp:
        path=pathlib.Path(tmp)/'data.bin'
        with path.open('wb')as file:file.truncate(33554432)
        for mode in ['buffered-8m','streamed-8m','streamed-32m','slow-consumer-32m','download-32m','upload-32m']:
            for _ in range(3):
                result=subprocess.check_output(['php','-n','-d','memory_limit=64M','-d','extension='+str(root/'build/asyncara.so'),str(root/'benchmarks/http_streaming.php'),mode,url,str(path)],text=True,timeout=120)
                data['samples'].append(json.loads(result));output.parent.mkdir(parents=True,exist_ok=True)
                output.write_text(json.dumps(data,indent=2)+'\n')
                print(mode,data['samples'][-1]['seconds'],flush=True)
finally:
    server.stdin.close();server.wait(timeout=5)
