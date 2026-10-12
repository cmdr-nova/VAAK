#include <sys/socket.h>
#include <netinet/in.h>
#include <netdb.h>
#include <errno.h>
#include <stdio.h>
int main(){struct addrinfo *r=0;int dns=getaddrinfo("fixture-denied.example.invalid","443",0,&r);int fd=socket(AF_INET,SOCK_STREAM,0);struct sockaddr_in a={.sin_family=AF_INET,.sin_port=htons(443),.sin_addr.s_addr=htonl(0x7f000001)};int rc=connect(fd,(void*)&a,sizeof a);printf("{\"dns_denied\":%s,\"inet_denied\":%s}\n",dns==EAI_FAIL?"true":"false",rc==-1&&errno==EPERM?"true":"false");return dns==EAI_FAIL&&rc==-1&&errno==EPERM?0:1;}
