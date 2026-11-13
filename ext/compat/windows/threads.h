#ifndef ASYNCARA_WINDOWS_THREADS_H
#define ASYNCARA_WINDOWS_THREADS_H
/* Only the worker API used by Asyncara. Workers never enter Zend. */
#include <windows.h>
#include <process.h>
#include <stdlib.h>
#include <errno.h>
typedef SRWLOCK pthread_mutex_t;
typedef CONDITION_VARIABLE pthread_cond_t;
typedef HANDLE pthread_t;
static inline int pthread_mutex_init(pthread_mutex_t *m, const void *a) {(void)a;InitializeSRWLock(m);return 0;}
static inline int pthread_mutex_destroy(pthread_mutex_t *m) {(void)m;return 0;}
static inline int pthread_mutex_lock(pthread_mutex_t *m) {AcquireSRWLockExclusive(m);return 0;}
static inline int pthread_mutex_unlock(pthread_mutex_t *m) {ReleaseSRWLockExclusive(m);return 0;}
static inline int pthread_cond_init(pthread_cond_t *c,const void *a) {(void)a;InitializeConditionVariable(c);return 0;}
static inline int pthread_cond_destroy(pthread_cond_t *c) {(void)c;return 0;}
static inline int pthread_cond_wait(pthread_cond_t *c,pthread_mutex_t *m) {return SleepConditionVariableSRW(c,m,INFINITE,0)?0:EINVAL;}
static inline int pthread_cond_signal(pthread_cond_t *c) {WakeConditionVariable(c);return 0;}
static inline int pthread_cond_broadcast(pthread_cond_t *c) {WakeAllConditionVariable(c);return 0;}
struct ar_thread_start {void *(*fn)(void *);void *arg;};
static unsigned __stdcall ar_thread_entry(void *p) {
    struct ar_thread_start s=*(struct ar_thread_start *)p;free(p);s.fn(s.arg);return 0;
}
static inline int pthread_create(pthread_t *t,const void *a,void *(*fn)(void *),void *arg) {
    (void)a;struct ar_thread_start *s=malloc(sizeof(*s));if(!s)return ENOMEM;s->fn=fn;s->arg=arg;
    uintptr_t h=_beginthreadex(NULL,0,ar_thread_entry,s,0,NULL);if(!h){free(s);return EAGAIN;}*t=(HANDLE)h;return 0;
}
static inline int pthread_join(pthread_t t,void **result) {
    if(result)*result=NULL;
    if(WaitForSingleObject(t,INFINITE)!=WAIT_OBJECT_0)return EINVAL;
    return CloseHandle(t)?0:EINVAL;
}
#endif
