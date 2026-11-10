/* Independent libpq unload diagnostic. No PHP or Asyncara is linked.
 * Supply only a private fixture connection string without secrets in argv.
 * Every connection must authenticate, unlike the TLS-attempt-only probe. */
#include <assert.h>
#include <dlfcn.h>
#include <stdio.h>

int main(int argc, char **argv) {
    assert(argc == 2);
    setvbuf(stdout, NULL, _IONBF, 0);
    void *library = dlopen("libpq.so.5", RTLD_NOW | RTLD_LOCAL);
    assert(library);
    void *(*connect_db)(const char *) = dlsym(library, "PQconnectdb");
    void (*finish)(void *) = dlsym(library, "PQfinish");
    int (*status)(const void *) = dlsym(library, "PQstatus");
    int (*version)(void) = dlsym(library, "PQlibVersion");
    assert(connect_db && finish && status && version);
    printf("libpq version %d\n", version());
    for (int i = 0; i < 20; i++) {
        void *connection = connect_db(argv[1]);
        assert(connection && status(connection) == 0);
        finish(connection);
    }
    assert(dlclose(library) == 0);
    puts("20 authenticated libpq-only connections closed; library unloaded");
    return 0;
}
