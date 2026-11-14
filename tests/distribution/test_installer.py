#!/usr/bin/env python3
"""Installer validation only: mocked native execution is never runtime certification."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('installer', Path(__file__).resolve().parents[2]/'scripts/install.py')
i = importlib.util.module_from_spec(spec); spec.loader.exec_module(i)
HOST = {'os':'ubuntu','osVersion':'24.04','arch':'x86_64'}
PHP = {'version':'8.5.12','bits':64,'zts':False,'debug':False}
FILES = ['extension/asyncara.so','lib/libasyncara-mariadb.so.3','runtime/autoload.php',
         'runtime/vendor/autoload.php','runtime/scripts/check-install.php','runtime/tests/release/smoke.php','runtime/LICENSE']

class Installer(unittest.TestCase):
    def test_download_failure_never_invokes_gh(self):
        for status in [401, 403, 404, 500]:
            error = i.urllib.error.HTTPError('https://github.com/asyncara/asyncara', status, 'fixture', {}, None)
            with self.subTest(status=status), patch.object(i.urllib.request, 'urlopen', side_effect=error), patch.object(i, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'No prebuilt package' if status == 404 else f'HTTP {status}'):
                    i.download('0.10.0', 'package.tar.gz', self.root)
                run.assert_not_called()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name); self.dest=self.root/'extract'; self.dest.mkdir()

    def archive(self, entries):
        path=self.root/'bundle.tar.gz'
        with tarfile.open(path, 'w:gz') as tar:
            for name,data,kind in entries:
                item=tarfile.TarInfo(name);item.size=len(data);item.type=kind
                if kind==tarfile.SYMTYPE:item.linkname='/etc/passwd'
                tar.addfile(item,io.BytesIO(data))
        return path

    def valid(self):
        files={name: b'fixture' for name in FILES}
        manifest={'schema':1,'project':'asyncara/runtime','sourceCommit':'a'*40,'version':'0.10.0',
                  'php':PHP,'platform':HOST,'files':{n:hashlib.sha256(d).hexdigest() for n,d in files.items()}}
        files['manifest.json']=json.dumps(manifest).encode()
        return self.archive([('asyncara/'+n,d,tarfile.REGTYPE) for n,d in files.items()])

    def test_all_twenty_targets_present_with_separate_evidence(self):
        rows=i.platforms();self.assertEqual(len(rows),20)
        self.assertEqual(len({(r['os'],r['arch']) for r in rows}),20)
        self.assertTrue(all(r['evidence']=='USER_REPORTED_PASS' for r in rows if r['os']=='Android'))

    def test_architecture_aliases(self):
        for a,b in {'AMD64':'x86_64','x64':'x86_64','arm64':'aarch64','aarch64':'aarch64','386':'i686','i386':'i686','i686':'i686','armv7l':'arm','armv8l':'arm','riscv64':'riscv64'}.items():
            with self.subTest(a=a):self.assertEqual(i.architecture(a),b)

    def test_os_detection(self):
        for system,name in [('Windows','windows'),('Darwin','macos'),('FreeBSD','freebsd')]:
            with patch.object(i.platform,'system',return_value=system),patch.object(i.platform,'machine',return_value='AMD64'):
                self.assertEqual(i.platform_identity()['os'],name)
        with patch.object(i.platform,'system',return_value='Linux'),patch.object(i.platform,'freedesktop_os_release',side_effect=FileNotFoundError),patch.dict(i.os.environ,{'TERMUX_VERSION':'0.118.0'}):
            self.assertEqual(i.platform_identity()['os'],'android')
        with patch.object(i.platform,'system',return_value='Linux'),patch.object(i.platform,'freedesktop_os_release',return_value={'ID':'ubuntu','VERSION_ID':'24.04'}),patch.dict(i.os.environ,{'TERMUX_VERSION':'0.118.0'}):
            self.assertEqual(i.platform_identity()['os'],'ubuntu')

    def test_no_python_traceback_on_unsupported_os(self):
        with patch.object(i,'platform_identity',return_value={'os':'windows','arch':'x86_64','osVersion':'11'}),patch.object(i.sys,'argv',['install.py']),patch.object(i,'php_identity') as php:
            self.assertEqual(i.main(),1);php.assert_not_called()

    def test_valid_inventory(self):
        names=i.extract_bundle(self.valid(),self.dest)
        self.assertEqual(i.validate_bundle(self.dest,names,'0.10.0',PHP,HOST)['sourceCommit'],'a'*40)

    def test_unsafe_members(self):
        for name,kind in [('asyncara/../escape',tarfile.REGTYPE),('/asyncara/absolute',tarfile.REGTYPE),('asyncara//x',tarfile.REGTYPE),('asyncara/x',tarfile.SYMTYPE),('asyncara/x',tarfile.LNKTYPE),('asyncara/x',tarfile.CHRTYPE),('elsewhere/x',tarfile.REGTYPE)]:
            with self.subTest(name=name,kind=kind),self.assertRaises(ValueError):
                i.extract_bundle(self.archive([(name,b'',kind)]),self.dest)

    def test_duplicate_member(self):
        with self.assertRaises(ValueError):i.extract_bundle(self.archive([('asyncara/x',b'x',tarfile.REGTYPE)]*2),self.dest)

    def test_size_bound(self):
        with patch.object(i,'MAX_CONTENT',3),self.assertRaises(ValueError):i.extract_bundle(self.archive([('asyncara/x',b'four',tarfile.REGTYPE)]),self.dest)

    def test_truncated_archive(self):
        p=self.valid();p.write_bytes(p.read_bytes()[:40])
        with self.assertRaises((tarfile.TarError,EOFError)):i.extract_bundle(p,self.dest)

    def test_wrong_php_or_os(self):
        names=i.extract_bundle(self.valid(),self.dest)
        for php,host in [(dict(PHP,version='8.5.11'),HOST),(dict(PHP,zts=True),HOST),(PHP,dict(HOST,arch='aarch64'))]:
            with self.subTest(php=php,host=host),self.assertRaises(ValueError):i.validate_bundle(self.dest,names,'0.10.0',php,host)

    def test_tamper(self):
        names=i.extract_bundle(self.valid(),self.dest);(self.dest/'extension/asyncara.so').write_text('changed')
        with self.assertRaises(ValueError):i.validate_bundle(self.dest,names,'0.10.0',PHP,HOST)

    def test_manifest_invalid_hash_type(self):
        names=i.extract_bundle(self.valid(),self.dest);p=self.dest/'manifest.json';m=json.loads(p.read_text());m['files']['runtime/LICENSE']=[];p.write_text(json.dumps(m))
        with self.assertRaises(ValueError):i.validate_bundle(self.dest,names,'0.10.0',PHP,HOST)

    def test_checksum_name_and_contents(self):
        p=self.root/'sha';p.write_text('0'*64+'  wrong.tar.gz\n')
        with self.assertRaises(ValueError):i.expected_checksum(p,'bundle.tar.gz')
        p.write_text('0'*64+'  bundle.tar.gz\n');self.assertEqual(i.expected_checksum(p,'bundle.tar.gz'),'0'*64)

    def test_bad_checksum_creates_no_prefix(self):
        p=self.valid();prefix=self.root/'prefix'
        with self.assertRaises(ValueError):i.install(p,'0'*64,'0.10.0',Path('/php'),PHP,HOST,prefix,self.root/'bin')
        self.assertFalse(prefix.exists())

    def test_foreign_command_preserved(self):
        p=self.valid();bin=self.root/'bin';bin.mkdir();(bin/'asyncara').write_text('user work')
        with self.assertRaises(ValueError):i.install(p,i.sha(p),'0.10.0',Path('/php'),PHP,HOST,self.root/'prefix',bin)
        self.assertEqual((bin/'asyncara').read_text(),'user work')

    def test_failed_native_probe_does_not_activate(self):
        p=self.valid();bin=self.root/'bin'
        with patch.object(i,'run',side_effect=RuntimeError('dynamic linker failure')),self.assertRaises(RuntimeError):
            i.install(p,i.sha(p),'0.10.0',Path('/php'),PHP,HOST,self.root/'prefix',bin)
        self.assertFalse((bin/'asyncara').exists())
        self.assertFalse(list((self.root/'prefix').glob('.staging-*')))

    def test_mocked_activation_idempotence_and_tamper_refusal(self):
        p=self.valid();args=(p,i.sha(p),'0.10.0',Path('/path with spaces/php'),PHP,HOST,self.root/'prefix space',self.root/'bin space')
        with patch.object(i,'run',return_value=''):
            dest=i.install(*args);self.assertEqual(i.install(*args),dest)
            self.assertTrue((self.root/'bin space/asyncara').is_symlink())
            (dest/'runtime/LICENSE').write_text('tampered')
            with self.assertRaises(ValueError):i.install(*args)
            self.assertEqual((dest/'runtime/LICENSE').read_text(),'tampered')
        subprocess.run(['sh','-n',dest/'bin/asyncara'],check=True)

if __name__=='__main__':unittest.main()
