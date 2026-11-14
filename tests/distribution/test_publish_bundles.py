"""Publication modes preserve the installer and all existing release assets."""
import hashlib
import json
from pathlib import Path
import runpy
import shutil
import sys
import tempfile
import unittest
from unittest.mock import patch


class PublishTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.script = root/'scripts/distribution/publish.py'
        self.script.parent.mkdir(parents=True)
        source = Path(__file__).resolve().parents[2]/'scripts'
        for name in ['distribution/publish.py', 'distribution/install-runtime.php', 'install.sh']:
            shutil.copy2(source/name, root/'scripts'/name)
        self.assets = root/'assets'; self.assets.mkdir()
        self.archive = self.assets/'new-package.tar.gz'; self.archive.write_bytes(b'fixture')
        self.sidecar = Path(str(self.archive)+'.sha256')
        self.sidecar.write_text(hashlib.sha256(b'fixture').hexdigest()+'  '+self.archive.name+'\n')
        self.metadata = Path(str(self.archive)+'.manifest.json')
        self.metadata.write_text(json.dumps({'sourceCommit':'a'*40,'version':'0.10.0'}))
        self.existing = ['install.sh', 'install.sh.sha256']

    def output(self, command, **kwargs):
        if command[:2] == ('git', 'ls-remote') or command[:2] == ['git', 'ls-remote']:
            return 'b'*40+'\trefs/tags/v0.10.0\n'+'a'*40+'\trefs/tags/v0.10.0^{}\n'
        self.assertIn('asyncara/asyncara', command)
        return json.dumps({'assets':[{'name':n} for n in self.existing]})

    def invoke(self, mode='--bundles-only'):
        argv = [str(self.script),'--tag','v0.10.0','--assets',str(self.assets),mode]
        with patch.object(sys,'argv',argv), patch('subprocess.check_output',side_effect=self.output), patch('subprocess.run') as run:
            runpy.run_path(str(self.script),run_name='__main__')
            return run.call_args_list

    def test_new_bundles_preserve_published_installer(self):
        calls = self.invoke()
        push = calls[-1].args[0]
        self.assertEqual(push[:6],['gh','release','upload','v0.10.0','--repo','asyncara/asyncara'])
        self.assertEqual(set(push[6:]),set(map(str,[self.archive,self.sidecar,self.metadata])))
        self.assertNotIn('--clobber',push)
        self.assertFalse((self.assets/'install.sh').exists())

    def test_existing_package_is_never_overwritten(self):
        self.existing.append(self.archive.name)
        with self.assertRaisesRegex(SystemExit,'conflicts'):
            self.invoke()

    def test_bad_checksum_cannot_be_uploaded(self):
        self.archive.write_bytes(b'changed')
        with self.assertRaisesRegex(SystemExit,'checksum'):
            self.invoke()

    def test_foreign_source_cannot_be_uploaded(self):
        self.metadata.write_text(json.dumps({'sourceCommit':'c'*40,'version':'0.10.0'}))
        with self.assertRaisesRegex(SystemExit,'mismatched package source'):
            self.invoke()


if __name__ == '__main__': unittest.main()
