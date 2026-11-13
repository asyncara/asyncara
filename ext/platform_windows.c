#ifdef _WIN32
#include "platform.h"
#include <limits.h>
#include <stdlib.h>
#include <string.h>
/* Windows sockets are pointer-sized handles, never CRT file descriptors. The
 * wake pair is a connected loopback datagram pair so it participates in WSAPoll
 * alongside curl/PHP sockets. Each engine owns and closes exactly two handles. */
static int socket_error(void) {
    switch (WSAGetLastError()) {
    case WSAEWOULDBLOCK: errno=EAGAIN; break;
    case WSAEINTR: errno=EINTR; break;
    case WSAENOBUFS: errno=ENOMEM; break;
    case WSAEINVAL: errno=EINVAL; break;
    case WSAENOTSOCK: errno=EBADF; break;
    default: errno=EIO; break;
    }
    return -1;
}
int ar_platform_clock(struct timespec *now) {
    LARGE_INTEGER ticks, frequency;
    if (!QueryPerformanceFrequency(&frequency) || !QueryPerformanceCounter(&ticks) ||
        ticks.QuadPart<0 || frequency.QuadPart<=0 || frequency.QuadPart>INT64_MAX/1000000000) {
        errno=EIO; return -1;
    }
    now->tv_sec=(time_t)(ticks.QuadPart/frequency.QuadPart);
    now->tv_nsec=(long)((ticks.QuadPart%frequency.QuadPart)*1000000000/frequency.QuadPart);
    return 0;
}
int ar_socket_noinherit(ar_fd fd) {
    if (!SetHandleInformation((HANDLE)fd,HANDLE_FLAG_INHERIT,0)) { errno=EIO; return -1; }
    return 0;
}
int ar_wake_pair(ar_fd fds[2]) {
    fds[0]=fds[1]=INVALID_SOCKET;
    struct sockaddr_in addr[2]; int len=sizeof(addr[0]); u_long nonblocking=1;
    memset(addr,0,sizeof(addr));
    for (int i=0;i<2;i++) {
        fds[i]=socket(AF_INET,SOCK_DGRAM,IPPROTO_UDP);
        if (fds[i]==INVALID_SOCKET) goto fail;
        if (ar_socket_noinherit(fds[i]) || ioctlsocket(fds[i],FIONBIO,&nonblocking)) goto fail;
        addr[i].sin_family=AF_INET; addr[i].sin_addr.s_addr=htonl(INADDR_LOOPBACK);
        if (bind(fds[i],(struct sockaddr *)&addr[i],sizeof(addr[i])) ||
            getsockname(fds[i],(struct sockaddr *)&addr[i],&len)) goto fail;
    }
    for (int i=0;i<2;i++) if(connect(fds[i],(struct sockaddr *)&addr[1-i],sizeof(addr[i]))) goto fail;
    return 0;
fail:
    socket_error();
    for(int i=0;i<2;i++) if(fds[i]!=INVALID_SOCKET) { closesocket(fds[i]); fds[i]=INVALID_SOCKET; }
    return -1;
}
int ar_wake_send(ar_fd fd,const void *p,size_t n) {
    if(n>INT_MAX) {errno=EINVAL;return -1;}
    int rc=send(fd,(const char *)p,(int)n,0);return rc==SOCKET_ERROR?socket_error():rc;
}
int ar_wake_receive(ar_fd fd,void *p,size_t n) {
    if(n>INT_MAX) {errno=EINVAL;return -1;}
    int rc=recv(fd,(char *)p,(int)n,0);return rc==SOCKET_ERROR?socket_error():rc;
}
void ar_wake_close(ar_fd fd) { if(fd!=INVALID_SOCKET) closesocket(fd); }
int ar_platform_poll(struct pollfd *fds,size_t n,int timeout) {
    if(n>ULONG_MAX) {errno=EOVERFLOW;return -1;}
    int rc=WSAPoll(fds,(ULONG)n,timeout);return rc==SOCKET_ERROR?socket_error():rc;
}
#endif
