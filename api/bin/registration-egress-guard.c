#define _GNU_SOURCE
#include <sys/socket.h>
#include <sys/un.h>
#include <netdb.h>
#include <errno.h>
#include <dlfcn.h>
#include <string.h>
int connect(int fd,const struct sockaddr *a,socklen_t n){
 if(!a||a->sa_family!=AF_UNIX){errno=EPERM;return -1;}
 const struct sockaddr_un *u=(const struct sockaddr_un *)a;
 if(strcmp(u->sun_path,"/tmp/vaak-library-facts-pg-socket/.s.PGSQL.5432")!=0){errno=EPERM;return -1;}
 int (*real)(int,const struct sockaddr*,socklen_t)=dlsym(RTLD_NEXT,"connect");return real(fd,a,n);
}
int getaddrinfo(const char *node,const char *service,const struct addrinfo *h,struct addrinfo **r){
 if(node){return EAI_FAIL;}
 int (*real)(const char*,const char*,const struct addrinfo*,struct addrinfo**)=dlsym(RTLD_NEXT,"getaddrinfo");return real(node,service,h,r);
}
