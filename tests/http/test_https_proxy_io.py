#!/usr/bin/env python3
import unittest
from https_proxy_io import ready_for_tunnel
class Readiness(unittest.TestCase):
    def test_decrypted_data_does_not_wait_for_kernel(self):
        class Client:
            def pending(self):return 16384
        client=Client();remote=object();timeouts=[]
        def select(read,write,error,timeout):
            self.assertEqual(read,[remote,client]);timeouts.append(timeout);return [remote],[],[]
        self.assertEqual(ready_for_tunnel(remote,client,select),[remote,client]);self.assertEqual(timeouts,[0])
    def test_empty_tls_buffer_can_wait(self):
        class Client:
            def pending(self):return 0
        client=Client();remote=object()
        def select(read,write,error,timeout):self.assertEqual(timeout,.1);return [],[],[]
        self.assertEqual(ready_for_tunnel(remote,client,select),[])
    def test_no_duplicate_readiness(self):
        class Client:
            def pending(self):return 1
        client=Client();remote=object()
        self.assertEqual(ready_for_tunnel(remote,client,lambda *args:([client],[],[])),[client])
if __name__=='__main__':unittest.main()
