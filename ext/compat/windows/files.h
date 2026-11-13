#ifndef ASYNCARA_WINDOWS_FILES_H
#define ASYNCARA_WINDOWS_FILES_H
#include <windows.h>
#include <stdint.h>
#include <stddef.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <io.h>
#include <time.h>
typedef intptr_t ar_ssize_t;
#define ssize_t ar_ssize_t
#define off_t int64_t
#define mode_t int
#ifndef O_CLOEXEC
#define O_CLOEXEC 0x01000000
#define O_NONBLOCK 0x02000000
#define O_NOFOLLOW 0x04000000
#define O_DIRECTORY 0x08000000
#endif
#ifndef S_ISREG
#define S_ISREG(m) (((m)&_S_IFMT)==_S_IFREG)
#endif
struct ar_fs_stat { int64_t st_size; int st_mode; time_t st_mtime; uint64_t st_dev,st_ino; };
struct dirent { char d_name[1024]; };
typedef struct ar_directory DIR;
int ar_fs_open(const char *,int,...);
int ar_fs_openat(int,const char *,int,...);
int ar_fs_stat(const char *,struct ar_fs_stat *);
int ar_fs_fstat(int,struct ar_fs_stat *);
int ar_fs_sync(int);
int ar_fs_truncate(int,int64_t);
int ar_fs_chmod(int,int);
int ar_fs_unlink(const char *);
int ar_fs_rename(const char *,const char *);
int ar_fs_unlinkat(int,const char *,int);
int ar_fs_renameat(int,const char *,int,const char *);
int ar_fs_mkdir(const char *,int);
int ar_fs_rmdir(const char *);
ar_ssize_t ar_fs_random(void *,size_t,unsigned);
DIR *ar_fs_opendir(const char *);
struct dirent *ar_fs_readdir(DIR *);
int ar_fs_closedir(DIR *);
#ifndef AR_WINDOWS_FILES_IMPLEMENTATION
#define open ar_fs_open
#define openat ar_fs_openat
#define stat ar_fs_stat
#define fstat ar_fs_fstat
#define fsync ar_fs_sync
#define ftruncate ar_fs_truncate
#define fchmod ar_fs_chmod
#define unlink ar_fs_unlink
#define rename ar_fs_rename
#define unlinkat ar_fs_unlinkat
#define renameat ar_fs_renameat
#define mkdir ar_fs_mkdir
#define rmdir ar_fs_rmdir
#define getrandom ar_fs_random
#define opendir ar_fs_opendir
#define readdir ar_fs_readdir
#define closedir ar_fs_closedir
#define read _read
#define write _write
#define close _close
#define lseek _lseeki64
#endif
#endif
