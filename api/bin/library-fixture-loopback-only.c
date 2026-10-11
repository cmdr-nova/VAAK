/* Test-only before-wire transport boundary. Not linked into VAAK. */
#define _GNU_SOURCE
#include <dlfcn.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <netdb.h>
#include <errno.h>
#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <arpa/inet.h>
static int allowed(const struct sockaddr *a){
 if(!a)return 1;
 if(a->sa_family==AF_UNIX)return 1;
 if(a->sa_family==AF_INET)return (ntohl(((const struct sockaddr_in*)a)->sin_addr.s_addr)>>24)==127;
 if(a->sa_family==AF_INET6)return IN6_IS_ADDR_LOOPBACK(&((const struct sockaddr_in6*)a)->sin6_addr);
 return 0;
}
static void denied(const char *call,const char *host){const char*p=getenv("VAAK_FIXTURE_NET_DENIAL_LOG");if(p){FILE*f=fopen(p,"a");if(f){fprintf(f,"%s %s\n",call,host?host:"remote-address");fclose(f);}}}
int connect(int fd,const struct sockaddr*a,socklen_t n){if(!allowed(a)){denied("connect",NULL);errno=EACCES;return -1;}return ((int(*)(int,const struct sockaddr*,socklen_t))dlsym(RTLD_NEXT,"connect"))(fd,a,n);}
ssize_t sendto(int fd,const void*b,size_t n,int flags,const struct sockaddr*a,socklen_t z){if(a&&!allowed(a)){denied("sendto",NULL);errno=EACCES;return -1;}return ((ssize_t(*)(int,const void*,size_t,int,const struct sockaddr*,socklen_t))dlsym(RTLD_NEXT,"sendto"))(fd,b,n,flags,a,z);}
int getaddrinfo(const char*n,const char*s,const struct addrinfo*h,struct addrinfo**r){if(n&&strcmp(n,"localhost")&&strcmp(n,"::1")&&strncmp(n,"127.",4)){denied("getaddrinfo",n);return EAI_NONAME;}return ((int(*)(const char*,const char*,const struct addrinfo*,struct addrinfo**))dlsym(RTLD_NEXT,"getaddrinfo"))(n,s,h,r);}
