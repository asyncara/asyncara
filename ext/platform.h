#ifndef ASYNCARA_PLATFORM_H
#define ASYNCARA_PLATFORM_H
#include <stddef.h>
#include <stdint.h>
#include <time.h>
#ifdef _WIN32
#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#include <winsock2.h>
#include <ws2tcpip.h>
#include <windows.h>
#include <errno.h>
typedef SOCKET ar_fd;
#define AR_INVALID_FD INVALID_SOCKET
int ar_platform_clock(struct timespec *now);
int ar_wake_pair(ar_fd fds[2]);
int ar_wake_send(ar_fd fd, const void *p, size_t size);
int ar_wake_receive(ar_fd fd, void *p, size_t size);
void ar_wake_close(ar_fd fd);
int ar_socket_noinherit(ar_fd fd);
int ar_platform_poll(struct pollfd *fds, size_t count, int timeout);
#define poll ar_platform_poll
#define strcasecmp _stricmp
#define strncasecmp _strnicmp
#ifndef ENOTSUP
#define ENOTSUP EINVAL
#endif
#ifndef EOVERFLOW
#define EOVERFLOW ERANGE
#endif
#else
#include <unistd.h>
#include <fcntl.h>
typedef int ar_fd;
#define AR_INVALID_FD (-1)
static inline int ar_platform_clock(struct timespec *now) { return clock_gettime(CLOCK_MONOTONIC, now); }
static inline int ar_wake_pair(ar_fd fds[2]) { return pipe2(fds, O_NONBLOCK | O_CLOEXEC); }
static inline int ar_wake_send(ar_fd fd, const void *p, size_t n) { return (int)write(fd,p,n); }
static inline int ar_wake_receive(ar_fd fd, void *p, size_t n) { return (int)read(fd,p,n); }
static inline void ar_wake_close(ar_fd fd) { close(fd); }
static inline int ar_socket_noinherit(ar_fd fd) { return fcntl(fd,F_SETFD,FD_CLOEXEC); }
#endif
#endif
