"""Publisher command controls; fake git/gh never contact or mutate GitHub."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class Publisher(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.bin = self.root/'bin'; self.bin.mkdir()
        self.assets = self.root/'assets'; self.assets.mkdir()
        self.log = self.root/'upload.json'
        scripts = {
            'git': 'print("a"*40+"\\trefs/tags/v0.10.0")',
            'gh': '''import json, os, pathlib, sys
if sys.argv[1:3] == ['release','view']:
 print(json.dumps({'assets':[{'name':'install.sh'}] if os.environ.get('TEST_EXISTING') else []}))
elif sys.argv[1:3] == ['release','upload']:
 pathlib.Path(os.environ['TEST_UPLOAD']).write_text(json.dumps(sys.argv[1:]))
else: raise SystemExit(91)
''',
        }
        for name, code in scripts.items():
            p = self.bin/name; p.write_text('#!'+sys.executable+'\n'+code+'\n'); p.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin)+':'+os.environ['PATH'], TEST_UPLOAD=str(self.log))
    def run_publisher(self, *extra):
        return subprocess.run([sys.executable, ROOT/'scripts/distribution/publish.py', '--tag','v0.10.0','--assets',self.assets,*extra],env=self.env,capture_output=True,text=True,timeout=20)
    def test_installer_only_uploads_exact_two_assets(self):
        r = self.run_publisher('--installer-only'); self.assertEqual(r.returncode,0,r.stderr)
        argv=json.loads(self.log.read_text())
        self.assertEqual({Path(x).name for x in argv[-2:]},{'install.sh','install.sh.sha256'})
        self.assertNotIn('--clobber',argv)
        self.assertEqual((self.assets/'install.sh').read_bytes(),(ROOT/'scripts/install.sh').read_bytes())
    def test_remote_asset_is_not_overwritten(self):
        self.env['TEST_EXISTING']='1'; r=self.run_publisher('--installer-only')
        self.assertNotEqual(r.returncode,0);self.assertIn('conflicts',r.stderr);self.assertFalse(self.log.exists())
    def test_staging_file_is_preserved(self):
        p=self.assets/'install.sh';p.write_text('existing work')
        r=self.run_publisher('--installer-only');self.assertNotEqual(r.returncode,0)
        self.assertEqual(p.read_text(),'existing work');self.assertFalse(self.log.exists())
    def test_missing_bundles_fail_without_opt_in(self):
        r=self.run_publisher();self.assertNotEqual(r.returncode,0)
        self.assertIn('No binary assets',r.stderr);self.assertFalse(self.log.exists())

if __name__=='__main__': unittest.main()
