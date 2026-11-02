/* Independent upstream diagnostic: no Asyncara code or PHP is linked.
 * argv[1] is a non-secret libpq connection string to a private TLS fixture.
 * libpq 16 retains its process-global BIO_METHOD; dlclose loses that root.
 */
#include <assert.h>
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>

int main(int argc, char **argv) {
    assert(argc == 2);
    void *library = dlopen("libpq.so.5", RTLD_NOW | RTLD_LOCAL);
    assert(library);
    void *(*connect_db)(const char *) = dlsym(library, "PQconnectdb");
    void (*finish)(void *) = dlsym(library, "PQfinish");
    assert(connect_db && finish);
    for (int i = 0; i < 20; i++) {
        void *connection = connect_db(argv[1]);
        assert(connection);
        finish(connection);
    }
    assert(!dlclose(library));
    puts("Twenty libpq-only TLS attempts completed and the library unloaded");
    return 0;
}
