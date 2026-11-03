#!/usr/bin/env python3
"""Compare large buffered worker reads; preserve all samples and artifact hashes."""
import argparse,hashlib,json,pathlib,platform,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--baseline',required=True);p.add_argument('--candidate',required=True);p.add_argument('--php',required=True);p.add_argument('--output',required=True);a=p.parse_args()
out=pathlib.Path(a.output)
if out.exists():p.error('Use a new output file')
driver=root/'benchmarks/native_mappings.php'
report={'workload':'24 sequential worker-backed 12MiB file reads per sample; three samples; warm sparse-file page cache; shared host','platform':platform.platform(),'driverSha256':hashlib.sha256(driver.read_bytes()).hexdigest(),'php':subprocess.check_output([a.php,'-n','-v'],text=True),'cases':{}}
with tempfile.NamedTemporaryFile(prefix='asyncara-mapping-benchmark-')as large:
    large.truncate(12582912);large.flush()
    for name,path in [('baseline',a.baseline),('candidate',a.candidate)]:
        module=pathlib.Path(path).resolve()
        result=subprocess.check_output([a.php,'-n','-d','memory_limit=1G','-d','extension='+str(module),str(driver),large.name],text=True,cwd=root)
        report['cases'][name]={'moduleSha256':hashlib.sha256(module.read_bytes()).hexdigest(),'samples':json.loads(result)}
out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,indent=2)+'\n')
print({k:[round(x['seconds'],4)for x in v['samples']]for k,v in report['cases'].items()})
