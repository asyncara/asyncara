#ifndef PHP_ASYNCARA_H
#define PHP_ASYNCARA_H
#include "php.h"
/* October 2026 PHP security releases defer OOM until a safe engine boundary.
 * Bulk conversions must use fallible allocations and stop before hard-limit
 * process termination. Older SDKs retain their original bailout behavior. */
static inline bool ar_php_oom(void) {
#ifdef ZEND_HAS_OOM
    return ZEND_HAS_OOM();
#else
    return false;
#endif
}
static inline zend_string *ar_php_string_alloc(size_t length) {
#ifdef ZEND_HAS_OOM
    return zend_string_falloc(length, false, true);
#else
    return zend_string_alloc(length, false);
#endif
}
static inline zend_string *ar_php_string_init(const char *value, size_t length) {
    zend_string *string = ar_php_string_alloc(length);
    if (string) {
        memcpy(ZSTR_VAL(string), value, length);
        ZSTR_VAL(string)[length] = 0;
    }
    return string;
}
#define PHP_ASYNCARA_VERSION "0.1.0-dev"
extern zend_module_entry asyncara_module_entry;
#define phpext_asyncara_ptr &asyncara_module_entry
#endif
