"""Readiness policy for the private TLS tunnel fixture, not production I/O."""
import select

def ready_for_tunnel(remote,client,select_call=select.select):
    # SSL may have decrypted input even when its underlying fd is not readable.
    # Do not impose a 100ms select wait before checking that userspace state.
    buffered=client.pending()>0
    ready,_,_=select_call([remote,client],[],[],0 if buffered else .1)
    if buffered and client not in ready:ready.append(client)
    return ready
