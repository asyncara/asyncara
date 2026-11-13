#ifndef ASYNCARA_WINDOWS_MAPPING_H
#define ASYNCARA_WINDOWS_MAPPING_H
#include <windows.h>
#include <errno.h>
#define PROT_READ 1
#define PROT_WRITE 2
#define MAP_PRIVATE 1
#define MAP_ANONYMOUS 2
#define MAP_FAILED ((void *)-1)
#define _SC_PAGESIZE 30
static inline long sysconf(int which) {
    if(which!=_SC_PAGESIZE) {errno=EINVAL;return -1;}
    SYSTEM_INFO info;GetSystemInfo(&info);return (long)info.dwPageSize;
}
static inline void *mmap(void *address,size_t bytes,int protection,int flags,int fd,int64_t offset) {
    if(address || protection!=(PROT_READ|PROT_WRITE) || flags!=(MAP_PRIVATE|MAP_ANONYMOUS) || fd!=-1 || offset) {errno=EINVAL;return MAP_FAILED;}
    void *p=VirtualAlloc(NULL,bytes,MEM_RESERVE|MEM_COMMIT,PAGE_READWRITE);
    if(!p) {errno=ENOMEM;return MAP_FAILED;}return p;
}
static inline int munmap(void *address,size_t bytes) {
    (void)bytes;if(!VirtualFree(address,0,MEM_RELEASE)) {errno=EINVAL;return -1;}return 0;
}
#endif
