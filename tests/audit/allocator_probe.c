#include <php.h>
#include <malloc.h>
/* Test module only: allocator observation on the interpreter thread. */
PHP_FUNCTION(asyncara_test_allocator)
{
    zend_bool trim=0;
    ZEND_PARSE_PARAMETERS_START(0,1) Z_PARAM_OPTIONAL Z_PARAM_BOOL(trim) ZEND_PARSE_PARAMETERS_END();
    if(trim)malloc_trim(0);
    struct mallinfo2 info=mallinfo2();
    array_init(return_value);
    add_assoc_long(return_value,"arena",info.arena);
    add_assoc_long(return_value,"used",info.uordblks);
    add_assoc_long(return_value,"free",info.fordblks);
    add_assoc_long(return_value,"mapped",info.hblkhd);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_probe,0,0,IS_ARRAY,0)
 ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0,trim,_IS_BOOL,0,"false")
ZEND_END_ARG_INFO()
static const zend_function_entry functions[]={PHP_FE(asyncara_test_allocator,arginfo_probe) PHP_FE_END};
zend_module_entry asyncara_allocator_probe_module_entry={STANDARD_MODULE_HEADER,"asyncara_allocator_probe",functions,NULL,NULL,NULL,NULL,NULL,"0.0.0-test",STANDARD_MODULE_PROPERTIES};
ZEND_GET_MODULE(asyncara_allocator_probe)
