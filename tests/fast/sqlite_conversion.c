/* Fault-injected documented SQLite access failures, actual worker queue/collect.
 * NULL names and conversion OOM are documented in sqlite3.h. No Zend on workers. */
#include "../../ext/native.h"
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
static atomic_int inject, injected;
const char *__real_sqlite3_column_name(sqlite3_stmt*,int);
const char *__wrap_sqlite3_column_name(sqlite3_stmt *s,int i){
 if(atomic_load(&inject)==1&&i==1&&!atomic_exchange(&injected,1))return NULL;
 return __real_sqlite3_column_name(s,i);
}
const void *__real_sqlite3_column_blob(sqlite3_stmt*,int);
const void *__wrap_sqlite3_column_blob(sqlite3_stmt *s,int i){
 if(atomic_load(&inject)==2&&!atomic_exchange(&injected,1))return NULL;
 return __real_sqlite3_column_blob(s,i);
}
int __real_sqlite3_column_bytes(sqlite3_stmt*,int);
int __wrap_sqlite3_column_bytes(sqlite3_stmt *s,int i){
 if(atomic_load(&inject)==3&&!atomic_exchange(&injected,1))return 0;
 return __real_sqlite3_column_bytes(s,i);
}
int __real_sqlite3_errcode(sqlite3*);
int __wrap_sqlite3_errcode(sqlite3 *db){if(atomic_load(&injected))return SQLITE_NOMEM;return __real_sqlite3_errcode(db);}
static ar_job *query(ar_engine *e,ar_db *db,const char *sql){
 ar_job *j=ar_job_new(e,OP_DB_QUERY);assert(j);j->a=ar_strdup(e,sql);j->db=db;assert(!ar_submit(e,j));
 ar_job *done=NULL;for(int i=0;i<100&&!done;i++){assert(ar_wait(e,NULL,0,10)>=0);done=ar_collect(e);}
 assert(done==j&&!done->done_next&&!e->pending&&!db->busy);return j;
}
int main(int argc,char **argv){
 int mode=argc>1?atoi(argv[1]):1;assert(mode>=0&&mode<=3);assert(curl_global_init(CURL_GLOBAL_DEFAULT)==CURLE_OK);
 ar_engine *e=ar_engine_new();assert(e);ar_db *db=ar_calloc(e,1,sizeof(*db));assert(db);db->id=1;e->dbs=db;assert(sqlite3_open(":memory:",&db->handle)==SQLITE_OK);
 atomic_store(&inject,mode);ar_job *j=query(e,db,"SELECT 42 AS prefix, 'payload' AS value");
 if(mode){assert(atomic_load(&injected));if(!j->error[0]){fprintf(stderr,"conversion OOM accepted as successful data: mode=%d\n",mode);return 2;}}
 else assert(!j->error[0]&&j->result&&j->result->count==1);
 ar_job_free(j);atomic_store(&inject,0);atomic_store(&injected,0);
 j=query(e,db,"SELECT '' AS empty, x'' AS blob, NULL AS nullable, 42 AS number");assert(!j->error[0]);
 ar_value *row=j->result->items[0];assert(row->count==4&&row->items[0]->length==0&&row->items[1]->length==0&&row->items[2]->type==V_NULL&&row->items[3]->integer==42);ar_job_free(j);
 assert(sqlite3_next_stmt(db->handle,NULL)==NULL);assert(sqlite3_close(db->handle)==SQLITE_OK);db->handle=NULL;e->dbs=NULL;ar_free(db);assert(atomic_load(&e->memory_bytes)==0);ar_engine_free(e);curl_global_cleanup();puts("SQLite conversion failure, statement cleanup and connection reuse: PASS");
}
