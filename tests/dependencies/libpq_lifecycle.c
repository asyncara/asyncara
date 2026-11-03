/* Standalone dependency diagnostic. No PHP or Asyncara runtime is linked.
 * Connection information is read from a private file, never printed.
 */
#include <dlfcn.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <libpq-fe.h>

static unsigned number(const char *text) {
    char *end;
    errno = 0;
    unsigned long n = strtoul(text, &end, 10);
    if (errno || !*text || *end || !n || n > 2000) exit(2);
    return (unsigned)n;
}

static long rss_kib(void) {
    FILE *f = fopen("/proc/self/status", "r");
    char line[256];
    long value = -1;
    if (!f) return value;
    while (fgets(line, sizeof(line), f)) {
        if (sscanf(line, "VmRSS: %ld", &value) == 1) break;
    }
    fclose(f);
    return value;
}

int main(int argc, char **argv) {
    if (argc != 6) return 2;
    unsigned loads = number(argv[3]), connections = number(argv[4]);
    int unload = !strcmp(argv[5], "unload");
    if ((!unload && strcmp(argv[5], "resident")) || (!unload && loads != 1) ||
        loads * connections > 2000) return 2;
    FILE *f = fopen(argv[2], "r");
    char conninfo[8192];
    if (!f) return 2;
    size_t length = fread(conninfo, 1, sizeof(conninfo) - 1, f);
    int invalid = ferror(f) || !feof(f);
    fclose(f);
    if (invalid || !length) return 2;
    conninfo[length] = '\0';

    for (unsigned iteration = 0; iteration < loads; iteration++) {
        void *library = dlopen(argv[1], RTLD_NOW | RTLD_LOCAL);
        if (!library) { fputs("dlopen failed\n", stderr); return 2; }
#define SYMBOL(type, name, arguments) \
        type (*name) arguments = (type (*) arguments)dlsym(library, #name); \
        if (!name) { fputs("missing public libpq API\n", stderr); return 2; }
        SYMBOL(PGconn *, PQconnectdb, (const char *));
        SYMBOL(ConnStatusType, PQstatus, (const PGconn *));
        SYMBOL(int, PQsslInUse, (const PGconn *));
        SYMBOL(int, PQlibVersion, (void));
        SYMBOL(char *, PQerrorMessage, (const PGconn *));
        SYMBOL(PGresult *, PQexec, (PGconn *, const char *));
        SYMBOL(ExecStatusType, PQresultStatus, (const PGresult *));
        SYMBOL(char *, PQgetvalue, (const PGresult *, int, int));
        SYMBOL(void, PQclear, (PGresult *));
        SYMBOL(void, PQfinish, (PGconn *));
#undef SYMBOL
        int version = PQlibVersion();
        for (unsigned n = 0; n < connections; n++) {
            PGconn *connection = PQconnectdb(conninfo);
            if (!connection || PQstatus(connection) != CONNECTION_OK || !PQsslInUse(connection)) {
                /* Only this private local test fixture's diagnostics; conninfo is never printed. */
                if (connection) fprintf(stderr, "local TLS diagnostic: %.2048s\n", PQerrorMessage(connection));
                if (connection) PQfinish(connection);
                fputs("verified TLS connection failed\n", stderr); return 3;
            }
            PGresult *result = PQexec(connection, "SELECT 42");
            int ok = result && PQresultStatus(result) == PGRES_TUPLES_OK &&
                !strcmp(PQgetvalue(result, 0, 0), "42");
            if (result) PQclear(result);
            PQfinish(connection);
            if (!ok) { fputs("query failed\n", stderr); return 3; }
            if ((n + 1) % 100 == 0) {
                printf("{\"type\":\"sample\",\"version\":%d,\"load\":%u,\"connections\":%u,\"rssKiB\":%ld}\n",
                    version, iteration + 1, n + 1, rss_kib());
                fflush(stdout);
            }
        }
        if (unload && dlclose(library)) return 2;
        printf("{\"type\":\"cycle\",\"version\":%d,\"load\":%u,\"connections\":%u,\"unloaded\":%s,\"rssKiB\":%ld}\n",
            version, iteration + 1, connections, unload ? "true" : "false", rss_kib());
        fflush(stdout);
    }
    return 0;
}
