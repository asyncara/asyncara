"""Prevent the derived dense fixture silently weakening original assertions."""
import pathlib,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class Fidelity(unittest.TestCase):
 def test_only_additions(self):
  original=(ROOT/'tests/audit/fpm_request.php').read_text();dense=(ROOT/'tests/fast/fpm_dense.php').read_text()
  dense=dense.replace("\nrequire __DIR__.'/fpm_density.inc.php';",'')
  dense=dense.replace("  $density=Async::spawn(static fn()=>densityWork((int)$_SERVER['AUDIT_ID']));\n",'')
  dense=dense.replace('$ticker->await();$densityCounts=$density->await();','$ticker->await();')
  dense=dense.replace(",'density'=>$densityCounts",'')
  self.assertEqual(original,dense)
 def test_transport(self):
  original=(ROOT/'scripts/audit-fpm.py').read_text();original=original[original.index('def request('):original.index('def idle(')]
  changed=(ROOT/'tests/fast/fpm_client.py').read_text();changed=changed[changed.index('def request('):]
  changed=changed.replace('def request(audit,port,index,mode,fixture):\n    server=audit.server;large=audit.large;record=audit.record;exact=audit.exact','def request(port,index,mode):')
  changed=changed.replace('str(fixture)',"str(root/'tests/audit/fpm_request.php')")
  self.assertEqual(original,changed)
if __name__=='__main__':unittest.main()
