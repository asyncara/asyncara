#ifdef _WIN32
#include "platform.h"
#define AR_WINDOWS_FILES_IMPLEMENTATION
#include "compat/windows/files.h"
#include <assert.h>
#include <stdio.h>
#include <string.h>
#include <io.h>
int main(void) {
    WSADATA wsa;assert(WSAStartup(MAKEWORD(2,2),&wsa)==0);
    ar_fd pair[2];assert(ar_wake_pair(pair)==0);ar_wake_close(pair[0]);ar_wake_close(pair[1]);
    DWORD before,after;assert(GetProcessHandleCount(GetCurrentProcess(),&before));
    for(int i=0;i<1000;i++){
        assert(ar_wake_pair(pair)==0);
        char sent='x',got=0;assert(ar_wake_send(pair[1],&sent,1)==1);
        struct pollfd p={pair[0],POLLIN,0};assert(ar_platform_poll(&p,1,1000)==1);assert(p.revents&POLLIN);
        assert(ar_wake_receive(pair[0],&got,1)==1&&got==sent);
        p.revents=0;assert(ar_platform_poll(&p,1,0)==0);
        ar_wake_close(pair[0]);ar_wake_close(pair[1]);
    }
    assert(GetProcessHandleCount(GetCurrentProcess(),&after));assert(before==after);
    char directory[MAX_PATH];assert(GetTempPathA(sizeof(directory),directory)>0);
    char child[80],renamed[80];snprintf(child,sizeof(child),"asyncara-native-%lu.tmp",GetCurrentProcessId());
    snprintf(renamed,sizeof(renamed),"asyncara-native-%lu.done",GetCurrentProcessId());
    for(int i=0;i<100;i++){
        int dir=ar_fs_open(directory,O_RDONLY|O_DIRECTORY);assert(dir>=0);
        int fd=ar_fs_openat(dir,child,O_WRONLY|O_CREAT|O_EXCL|O_NOFOLLOW,0600);
        if(fd<0)fprintf(stderr,"create iteration %d: errno=%d WinError=%lu\n",i,errno,GetLastError());
        assert(fd>=0);
        assert(_write(fd,"safe",4)==4);assert(ar_fs_sync(fd)==0);assert(_close(fd)==0);
        assert(ar_fs_renameat(dir,child,dir,renamed)==0);
        fd=ar_fs_openat(dir,renamed,O_RDONLY|O_NOFOLLOW);assert(fd>=0);
        char buf[4];assert(_read(fd,buf,4)==4&&!memcmp(buf,"safe",4));assert(_close(fd)==0);
        assert(ar_fs_unlinkat(dir,renamed,0)==0);assert(_close(dir)==0);
    }
    assert(GetProcessHandleCount(GetCurrentProcess(),&after));assert(before==after);
    WSACleanup();puts("PASS: 1000 wake/poll/close cycles, 100 directory-pinned atomic file cycles; handle count unchanged");return 0;
}
#endif
