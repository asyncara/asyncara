/* Test-only resolver barrier. Compiled only into a separately named test module.
 * Real native worker blocks here; the Event Loop controls release independently.
 * No symbol or branch is added to the production resolver. */
#include <arpa/inet.h>
#include <errno.h>
#include <netdb.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>
extern int __real_getaddrinfo(const char *, const char *, const struct addrinfo *, struct addrinfo **);
int __wrap_getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **result) {
    if (node && !strcmp(node, "dns-gated.invalid")) {
        const char *port = getenv("ASYNCARA_DNS_TEST_PORT");
        if (!port) return EAI_FAIL;
        int fd = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0);
        if (fd < 0) return EAI_SYSTEM;
        struct timeval timeout = {.tv_sec = 10};
        struct sockaddr_in addr = {.sin_family = AF_INET, .sin_port = htons((unsigned short)atoi(port))};
        inet_pton(AF_INET, "127.0.0.1", &addr.sin_addr);
        int rc = setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
        if (!rc) rc = connect(fd, (struct sockaddr *)&addr, sizeof(addr));
        char byte = 0; ssize_t got = -1;
        if (!rc) do { got = recv(fd, &byte, 1, 0); } while (got < 0 && errno == EINTR);
        close(fd);
        if (got != 1 || byte != 'R') return EAI_FAIL;
        node = "localhost";
    }
    return __real_getaddrinfo(node, service, hints, result);
}
