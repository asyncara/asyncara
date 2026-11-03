#include <netdb.h>
#include <string.h>
#include <time.h>
/* Fault fixture only: bounded delay in the native resolver worker. */
extern int __real_getaddrinfo(const char *,const char *,const struct addrinfo *,struct addrinfo **);
int __wrap_getaddrinfo(const char *node,const char *service,const struct addrinfo *hints,struct addrinfo **result)
{
    if(node && strcmp(node,"asyncara-delay.invalid")==0){
        struct timespec delay={.tv_sec=0,.tv_nsec=200000000};
        while(nanosleep(&delay,&delay)<0){}
        *result=NULL;return EAI_NONAME;
    }
    return __real_getaddrinfo(node,service,hints,result);
}
