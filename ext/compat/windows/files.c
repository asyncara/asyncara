#ifdef _WIN32
#define AR_WINDOWS_FILES_IMPLEMENTATION
#include "files.h"
#include <winternl.h>
#include <bcrypt.h>
#include <sddl.h>
#include <errno.h>
#include <limits.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <wchar.h>
#ifndef ENOTSUP
#define ENOTSUP EINVAL
#endif
ar_ssize_t ar_fs_read(int fd,void *p,size_t n) {return _read(fd,p,(unsigned)(n>INT_MAX?INT_MAX:n));}
ar_ssize_t ar_fs_write(int fd,const void *p,size_t n) {return _write(fd,p,(unsigned)(n>INT_MAX?INT_MAX:n));}
static int win_error(DWORD e) {
    SetLastError(e);
    switch(e) {
    case ERROR_FILE_NOT_FOUND:case ERROR_PATH_NOT_FOUND:errno=ENOENT;break;
    case ERROR_ACCESS_DENIED:case ERROR_SHARING_VIOLATION:errno=EACCES;break;
    case ERROR_ALREADY_EXISTS:case ERROR_FILE_EXISTS:errno=EEXIST;break;
    case ERROR_DISK_FULL:case ERROR_HANDLE_DISK_FULL:errno=ENOSPC;break;
    case ERROR_NOT_ENOUGH_MEMORY:case ERROR_OUTOFMEMORY:errno=ENOMEM;break;
    case ERROR_INVALID_HANDLE:errno=EBADF;break;
    case ERROR_DIR_NOT_EMPTY:errno=ENOTEMPTY;break;
    case ERROR_NOT_SAME_DEVICE:errno=EXDEV;break;
    case ERROR_DIRECTORY:errno=ENOTDIR;break;
    case ERROR_INVALID_NAME:case ERROR_INVALID_PARAMETER:errno=EINVAL;break;
    default:errno=EIO;break;
    }return -1;
}
static wchar_t *wide(const char *path) {
    int n=MultiByteToWideChar(CP_UTF8,MB_ERR_INVALID_CHARS,path,-1,NULL,0);
    if(n<=0 || n>32767) {errno=EINVAL;return NULL;}
    wchar_t *p=malloc((size_t)n*sizeof(*p));if(!p){errno=ENOMEM;return NULL;}
    if(!MultiByteToWideChar(CP_UTF8,MB_ERR_INVALID_CHARS,path,-1,p,n)){free(p);errno=EINVAL;return NULL;}
    return p;
}
static PSECURITY_DESCRIPTOR private_acl(int flags,int mode) {
    PSECURITY_DESCRIPTOR sd=NULL;
    if((flags&O_CREAT) && (mode&0777)==0600 && !ConvertStringSecurityDescriptorToSecurityDescriptorW(
        L"D:P(A;;GA;;;OW)(A;;GA;;;SY)",SDDL_REVISION_1,&sd,NULL)) { win_error(GetLastError());return (void *)-1; }
    return sd;
}
static int adopt(HANDLE h,int flags) {
    if(h==INVALID_HANDLE_VALUE)return win_error(GetLastError());
    if(flags&O_NOFOLLOW) {
        FILE_ATTRIBUTE_TAG_INFO tag;
        if(!GetFileInformationByHandleEx(h,FileAttributeTagInfo,&tag,sizeof(tag))) {DWORD e=GetLastError();CloseHandle(h);return win_error(e);}
        if(tag.FileAttributes&FILE_ATTRIBUTE_REPARSE_POINT){CloseHandle(h);errno=EACCES;return -1;}
    }
    int fd=_open_osfhandle((intptr_t)h,_O_BINARY|_O_NOINHERIT|(flags&(_O_RDONLY|_O_WRONLY|_O_RDWR|_O_APPEND)));
    if(fd<0)CloseHandle(h);return fd;
}
int ar_fs_open(const char *path,int flags,...) {
    int mode=0666;if(flags&O_CREAT){va_list ap;va_start(ap,flags);mode=va_arg(ap,int);va_end(ap);}
    /* Device/pipe namespaces do not provide regular-file semantics and can
     * block at open before the regular-file guard. Reject them explicitly. */
    if(!strncmp(path,"\\\\.\\",4)||!strncmp(path,"\\\\?\\GLOBALROOT",14)){errno=EINVAL;return -1;}
    wchar_t *p=wide(path);if(!p)return -1;
    DWORD access=FILE_READ_ATTRIBUTES|((flags&O_RDWR)?GENERIC_READ|GENERIC_WRITE:((flags&O_WRONLY)?GENERIC_WRITE:GENERIC_READ));
    DWORD creation=(flags&O_CREAT)?((flags&O_EXCL)?CREATE_NEW:((flags&O_TRUNC)?CREATE_ALWAYS:OPEN_ALWAYS)):((flags&O_TRUNC)?TRUNCATE_EXISTING:OPEN_EXISTING);
    DWORD attributes=FILE_ATTRIBUTE_NORMAL|((flags&O_DIRECTORY)?FILE_FLAG_BACKUP_SEMANTICS:0)|((flags&O_NOFOLLOW)?FILE_FLAG_OPEN_REPARSE_POINT:0);
    PSECURITY_DESCRIPTOR sd=private_acl(flags,mode);if(sd==(void *)-1){free(p);return -1;}
    SECURITY_ATTRIBUTES sa={sizeof(sa),sd,FALSE};
    HANDLE h=CreateFileW(p,access,FILE_SHARE_READ|FILE_SHARE_WRITE|FILE_SHARE_DELETE,&sa,creation,attributes,NULL);
    DWORD error=GetLastError();if(sd)LocalFree(sd);free(p);SetLastError(error);
    return adopt(h,flags);
}
typedef NTSTATUS (NTAPI *create_fn)(PHANDLE,ACCESS_MASK,POBJECT_ATTRIBUTES,PIO_STATUS_BLOCK,PLARGE_INTEGER,ULONG,ULONG,ULONG,ULONG,PVOID,ULONG);
typedef NTSTATUS (NTAPI *set_fn)(HANDLE,PIO_STATUS_BLOCK,PVOID,ULONG,FILE_INFORMATION_CLASS);
typedef ULONG (NTAPI *error_fn)(NTSTATUS);
static int nt_error(NTSTATUS status) {
    error_fn convert=(error_fn)(void *)GetProcAddress(GetModuleHandleW(L"ntdll.dll"),"RtlNtStatusToDosError");
    return win_error(convert?convert(status):ERROR_GEN_FAILURE);
}
static HANDLE relative_open(int directory,const char *name,ACCESS_MASK access,ULONG disposition,ULONG options,PSECURITY_DESCRIPTOR sd) {
    if(!*name||strchr(name,'/')||strchr(name,'\\')||strchr(name,':')||!strcmp(name,".")||!strcmp(name,"..")){errno=EINVAL;return INVALID_HANDLE_VALUE;}
    HANDLE parent=(HANDLE)_get_osfhandle(directory);if(parent==INVALID_HANDLE_VALUE){errno=EBADF;return INVALID_HANDLE_VALUE;}
    wchar_t *p=wide(name);if(!p)return INVALID_HANDLE_VALUE;
    UNICODE_STRING u={(USHORT)(wcslen(p)*sizeof(wchar_t)),(USHORT)((wcslen(p)+1)*sizeof(wchar_t)),p};
    OBJECT_ATTRIBUTES oa;InitializeObjectAttributes(&oa,&u,OBJ_CASE_INSENSITIVE,parent,sd);
    create_fn create=(create_fn)(void *)GetProcAddress(GetModuleHandleW(L"ntdll.dll"),"NtCreateFile");
    if(!create){free(p);errno=ENOTSUP;return INVALID_HANDLE_VALUE;}
    IO_STATUS_BLOCK ios;HANDLE h=INVALID_HANDLE_VALUE;
    NTSTATUS status=create(&h,access|SYNCHRONIZE,&oa,&ios,NULL,FILE_ATTRIBUTE_NORMAL,FILE_SHARE_READ|FILE_SHARE_WRITE|FILE_SHARE_DELETE,
        disposition,options|FILE_SYNCHRONOUS_IO_NONALERT,NULL,0);
    free(p);if(status<0){nt_error(status);return INVALID_HANDLE_VALUE;}return h;
}
int ar_fs_openat(int directory,const char *name,int flags,...) {
    int mode=0666;if(flags&O_CREAT){va_list ap;va_start(ap,flags);mode=va_arg(ap,int);va_end(ap);}
    ACCESS_MASK access=FILE_READ_ATTRIBUTES|((flags&O_RDWR)?GENERIC_READ|GENERIC_WRITE:((flags&O_WRONLY)?GENERIC_WRITE:GENERIC_READ));
    ULONG disposition=(flags&O_CREAT)?((flags&O_EXCL)?FILE_CREATE:FILE_OPEN_IF):FILE_OPEN;
    PSECURITY_DESCRIPTOR sd=private_acl(flags,mode);if(sd==(void *)-1)return -1;
    HANDLE h=relative_open(directory,name,access,disposition,FILE_NON_DIRECTORY_FILE|FILE_OPEN_REPARSE_POINT,sd);
    if(sd)LocalFree(sd);if(h==INVALID_HANDLE_VALUE)return -1;
    return adopt(h,flags);
}
int ar_fs_unlinkat(int directory,const char *name,int flags) {
    if(flags){errno=ENOTSUP;return -1;}
    HANDLE h=relative_open(directory,name,DELETE,FILE_OPEN,FILE_NON_DIRECTORY_FILE|FILE_OPEN_REPARSE_POINT,NULL);
    if(h==INVALID_HANDLE_VALUE)return -1;
    FILE_DISPOSITION_INFO info={TRUE};BOOL ok=SetFileInformationByHandle(h,FileDispositionInfo,&info,sizeof(info));DWORD e=GetLastError();CloseHandle(h);
    return ok?0:win_error(e);
}
int ar_fs_renameat(int from,const char *oldname,int to,const char *newname) {
    if(!*newname||strchr(newname,'/')||strchr(newname,'\\')||strchr(newname,':')||!strcmp(newname,".")||!strcmp(newname,"..")){errno=EINVAL;return -1;}
    HANDLE target=(HANDLE)_get_osfhandle(to);if(target==INVALID_HANDLE_VALUE){errno=EBADF;return -1;}
    HANDLE h=relative_open(from,oldname,DELETE,FILE_OPEN,FILE_NON_DIRECTORY_FILE|FILE_OPEN_REPARSE_POINT,NULL);if(h==INVALID_HANDLE_VALUE)return -1;
    wchar_t *p=wide(newname);if(!p){CloseHandle(h);return -1;}
    size_t n=wcslen(p)*sizeof(wchar_t);size_t size=offsetof(FILE_RENAME_INFO,FileName)+n;
    FILE_RENAME_INFO *info=calloc(1,size);if(!info){free(p);CloseHandle(h);errno=ENOMEM;return -1;}
    info->ReplaceIfExists=TRUE;info->RootDirectory=target;info->FileNameLength=(DWORD)n;memcpy(info->FileName,p,n);
    set_fn set=(set_fn)(void *)GetProcAddress(GetModuleHandleW(L"ntdll.dll"),"NtSetInformationFile");IO_STATUS_BLOCK ios;
    NTSTATUS status=set?set(h,&ios,info,(ULONG)size,(FILE_INFORMATION_CLASS)10 /* FileRenameInformation (WDK) */):(NTSTATUS)0xc0000002;
    free(info);free(p);CloseHandle(h);return status<0?nt_error(status):0;
}
int ar_fs_fstat(int fd,struct ar_fs_stat *st) {
    HANDLE h=(HANDLE)_get_osfhandle(fd);if(h==INVALID_HANDLE_VALUE){errno=EBADF;return -1;}
    memset(st,0,sizeof(*st));DWORD type=GetFileType(h);
    if(type!=FILE_TYPE_DISK){st->st_mode=type==FILE_TYPE_PIPE?_S_IFIFO:_S_IFCHR;return 0;}
    BY_HANDLE_FILE_INFORMATION i;if(!GetFileInformationByHandle(h,&i))return win_error(GetLastError());
    st->st_size=(int64_t)(((uint64_t)i.nFileSizeHigh<<32)|i.nFileSizeLow);
    st->st_dev=i.dwVolumeSerialNumber;st->st_ino=((uint64_t)i.nFileIndexHigh<<32)|i.nFileIndexLow;
    st->st_mode=(i.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY?_S_IFDIR:_S_IFREG)|0444|((i.dwFileAttributes&FILE_ATTRIBUTE_READONLY)?0:0222);
    uint64_t ticks=((uint64_t)i.ftLastWriteTime.dwHighDateTime<<32)|i.ftLastWriteTime.dwLowDateTime;
    st->st_mtime=(time_t)((int64_t)(ticks/10000000)-11644473600LL);return 0;
}
int ar_fs_stat(const char *path,struct ar_fs_stat *st) {
    int fd=ar_fs_open(path,O_RDONLY|O_DIRECTORY);if(fd<0)return -1;
    int rc=ar_fs_fstat(fd,st);int saved=errno;_close(fd);errno=saved;return rc;
}
int ar_fs_sync(int fd) {if(!FlushFileBuffers((HANDLE)_get_osfhandle(fd)))return win_error(GetLastError());return 0;}
int ar_fs_truncate(int fd,int64_t n) {errno_t e=_chsize_s(fd,n);if(e){errno=e;return -1;}return 0;}
int ar_fs_chmod(int fd,int mode) {
    FILE_BASIC_INFO i;HANDLE h=(HANDLE)_get_osfhandle(fd);
    if(!GetFileInformationByHandleEx(h,FileBasicInfo,&i,sizeof(i)))return win_error(GetLastError());
    i.FileAttributes=(mode&0222)?i.FileAttributes&~FILE_ATTRIBUTE_READONLY:i.FileAttributes|FILE_ATTRIBUTE_READONLY;
    if(!SetFileInformationByHandle(h,FileBasicInfo,&i,sizeof(i)))return win_error(GetLastError());return 0;
}
static int path_operation(const char *a,const char *b,int operation) {
    wchar_t *x=wide(a),*y=b?wide(b):NULL;if(!x||(b&&!y)){free(x);free(y);return -1;}
    BOOL ok=operation==0?DeleteFileW(x):operation==1?MoveFileExW(x,y,MOVEFILE_REPLACE_EXISTING):operation==2?CreateDirectoryW(x,NULL):RemoveDirectoryW(x);
    DWORD e=GetLastError();free(x);free(y);return ok?0:win_error(e);
}
int ar_fs_unlink(const char *p){return path_operation(p,NULL,0);}
int ar_fs_rename(const char *a,const char *b){return path_operation(a,b,1);}
int ar_fs_mkdir(const char *p,int mode){(void)mode;return path_operation(p,NULL,2);}
int ar_fs_rmdir(const char *p){return path_operation(p,NULL,3);}
ar_ssize_t ar_fs_random(void *p,size_t n,unsigned flags) {
    if(flags||n>ULONG_MAX){errno=EINVAL;return -1;}
    if(BCryptGenRandom(NULL,p,(ULONG)n,BCRYPT_USE_SYSTEM_PREFERRED_RNG)<0){errno=EIO;return -1;}return (ar_ssize_t)n;
}
struct ar_directory {HANDLE search;WIN32_FIND_DATAW data;int first,empty;struct dirent entry;};
DIR *ar_fs_opendir(const char *path) {
    wchar_t *p=wide(path);if(!p)return NULL;
    DWORD attr=GetFileAttributesW(p);if(attr==INVALID_FILE_ATTRIBUTES||!(attr&FILE_ATTRIBUTE_DIRECTORY)){free(p);errno=ENOTDIR;return NULL;}
    size_t n=wcslen(p);wchar_t *pattern=realloc(p,(n+3)*sizeof(*p));if(!pattern){free(p);errno=ENOMEM;return NULL;}
    pattern[n]=L'/';pattern[n+1]=L'*';pattern[n+2]=0;
    DIR *dir=calloc(1,sizeof(*dir));if(!dir){free(pattern);errno=ENOMEM;return NULL;}
    dir->search=FindFirstFileW(pattern,&dir->data);DWORD e=GetLastError();free(pattern);dir->first=1;
    if(dir->search==INVALID_HANDLE_VALUE){if(e==ERROR_FILE_NOT_FOUND){dir->empty=1;return dir;}free(dir);win_error(e);return NULL;}return dir;
}
struct dirent *ar_fs_readdir(DIR *dir) {
    if(dir->empty){errno=0;return NULL;}
    if(!dir->first&&!FindNextFileW(dir->search,&dir->data)){DWORD e=GetLastError();if(e==ERROR_NO_MORE_FILES)errno=0;else win_error(e);return NULL;}
    dir->first=0;
    if(!WideCharToMultiByte(CP_UTF8,WC_ERR_INVALID_CHARS,dir->data.cFileName,-1,dir->entry.d_name,sizeof(dir->entry.d_name),NULL,NULL)){errno=EILSEQ;return NULL;}
    return &dir->entry;
}
int ar_fs_closedir(DIR *dir){BOOL ok=dir->search==INVALID_HANDLE_VALUE||FindClose(dir->search);free(dir);return ok?0:win_error(GetLastError());}
#endif
