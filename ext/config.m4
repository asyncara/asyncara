PHP_ARG_ENABLE([asyncara], [whether to enable Asyncara],
  [AS_HELP_STRING([--enable-asyncara], [Enable Asyncara])], [no])
PHP_ARG_WITH([asyncara-pq], [whether to include libpq],
  [AS_HELP_STRING([--with-asyncara-pq], [Enable PostgreSQL using libpq])], [no], [no])
PHP_ARG_WITH([asyncara-mysql], [whether to include MariaDB Connector/C],
  [AS_HELP_STRING([--with-asyncara-mysql], [Enable MariaDB/MySQL using Connector/C])], [no], [no])
PHP_ARG_WITH([asyncara-hyper], [prebuilt Rust HTTP bridge directory],
  [AS_HELP_STRING([--with-asyncara-hyper=DIR], [Enable Linux Hyper client/server using DIR/libasyncara_hyper_bridge.a])], [no], [no])
if test "$PHP_ASYNCARA" != "no"; then
  ASYNCARA_HYPER_CFLAGS=""
  if test "$PHP_ASYNCARA_HYPER" != "no"; then
    AS_CASE([$host_os], [linux*], [], [AC_MSG_ERROR([Hyper transport is currently Linux-only])])
    AS_CASE([$PHP_ASYNCARA_HYPER], [/*], [], [AC_MSG_ERROR([--with-asyncara-hyper requires an absolute library directory])])
    if test ! -r "$PHP_ASYNCARA_HYPER/libasyncara_hyper_bridge.a"; then
      AC_MSG_ERROR([Build the matching ext/hyper Cargo.lock with cargo build --release --locked first])
    fi
    ASYNCARA_SHARED_LIBADD="$ASYNCARA_SHARED_LIBADD $PHP_ASYNCARA_HYPER/libasyncara_hyper_bridge.a"
    PHP_ADD_LIBRARY([dl], [1], [ASYNCARA_SHARED_LIBADD])
    PHP_ADD_LIBRARY([m], [1], [ASYNCARA_SHARED_LIBADD])
    AC_CHECK_HEADER([zlib.h], [], [AC_MSG_ERROR([zlib development headers required for Hyper content decoding])])
    PHP_CHECK_LIBRARY([z], [inflateInit2_], [], [AC_MSG_ERROR([zlib library required for Hyper content decoding])])
    PHP_ADD_LIBRARY([z], [1], [ASYNCARA_SHARED_LIBADD])
    PKG_CHECK_MODULES([ASYNCARA_CODECS], [libbrotlidec >= 1.0.0 libzstd >= 1.5.0])
    PHP_EVAL_INCLINE([$ASYNCARA_CODECS_CFLAGS])
    ASYNCARA_CODECS_LIBS=`$PKG_CONFIG --static --libs libbrotlidec libzstd`
    dnl Custom-allocation zstd API is static-only. Do not bind it to a shared
    dnl libzstd or allow another PHP extension to interpose these symbols.
    ASYNCARA_ZSTD_ARCHIVE=""
    ASYNCARA_CODEC_LIBS=""
    for flag in $ASYNCARA_CODECS_LIBS; do
      case "$flag" in
        -L*)
          codec_dir=${flag#-L}
          if test -z "$ASYNCARA_ZSTD_ARCHIVE" && test -r "$codec_dir/libzstd.a"; then
            ASYNCARA_ZSTD_ARCHIVE="$codec_dir/libzstd.a"
          fi
          ASYNCARA_CODEC_LIBS="$ASYNCARA_CODEC_LIBS $flag" ;;
        -lzstd) ;;
        *) ASYNCARA_CODEC_LIBS="$ASYNCARA_CODEC_LIBS $flag" ;;
      esac
    done
    if test -z "$ASYNCARA_ZSTD_ARCHIVE"; then
      ASYNCARA_ZSTD_ARCHIVE=`$CC -print-file-name=libzstd.a`
    fi
    AS_CASE([$ASYNCARA_ZSTD_ARCHIVE], [/*], [], [AC_MSG_ERROR([Matching static libzstd.a required for Hyper content decoding])])
    if test ! -r "$ASYNCARA_ZSTD_ARCHIVE"; then
      AC_MSG_ERROR([Matching static libzstd.a required for Hyper content decoding])
    fi
    PHP_EVAL_LIBLINE([$ASYNCARA_CODEC_LIBS], [ASYNCARA_SHARED_LIBADD])
    ASYNCARA_SHARED_LIBADD="$ASYNCARA_SHARED_LIBADD $ASYNCARA_ZSTD_ARCHIVE -Wl,--exclude-libs,libzstd.a:libbrotlidec.a:libbrotlicommon.a"
    AC_DEFINE([HAVE_ASYNCARA_HYPER], [1], [Enable the Rust HTTP bridge])
    dnl workers/backend are standalone C units and do not include PHP config.h.
    ASYNCARA_HYPER_CFLAGS="-DHAVE_ASYNCARA_HYPER=1"
  fi
  if test "$PHP_ASYNCARA_PQ" != "no"; then
    PKG_CHECK_MODULES([ASYNCARA_PQ], [libpq >= 12])
    PHP_EVAL_INCLINE([$ASYNCARA_PQ_CFLAGS])
    PHP_EVAL_LIBLINE([$ASYNCARA_PQ_LIBS], [ASYNCARA_SHARED_LIBADD])
    AC_DEFINE([HAVE_ASYNCARA_PQ], [1], [Enable PostgreSQL])
  fi
  if test "$PHP_ASYNCARA_MYSQL" != "no"; then
    PKG_CHECK_MODULES([ASYNCARA_MYSQL], [libmariadb >= 3.1])
    PKG_CHECK_MODULES([ASYNCARA_MYSQL_TLS], [openssl >= 3.0])
    PHP_EVAL_INCLINE([$ASYNCARA_MYSQL_TLS_CFLAGS])
    PHP_EVAL_LIBLINE([$ASYNCARA_MYSQL_TLS_LIBS], [ASYNCARA_SHARED_LIBADD])
    PHP_ADD_INCLUDE([$srcdir/compat/mariadb])
    PHP_EVAL_INCLINE([$ASYNCARA_MYSQL_CFLAGS])
    PHP_EVAL_LIBLINE([$ASYNCARA_MYSQL_LIBS], [ASYNCARA_SHARED_LIBADD])
    AC_DEFINE([HAVE_ASYNCARA_MYSQL], [1], [Enable MariaDB Connector/C])
  fi
  PHP_CHECK_LIBRARY([sqlite3], [sqlite3_open_v2], [], [AC_MSG_ERROR([SQLite development library required])])
  PHP_CHECK_LIBRARY([curl], [curl_multi_socket_action], [], [AC_MSG_ERROR([libcurl development library required])])
  PHP_ADD_LIBRARY([pthread], [1], [ASYNCARA_SHARED_LIBADD])
  PHP_ADD_LIBRARY([sqlite3], [1], [ASYNCARA_SHARED_LIBADD])
  PHP_ADD_LIBRARY([curl], [1], [ASYNCARA_SHARED_LIBADD])
  PHP_SUBST([ASYNCARA_SHARED_LIBADD])
  PHP_NEW_EXTENSION([asyncara], [php_asyncara.c workers.c backend.c memory.c hyper_decode.c], [$ext_shared],, [-std=c11 -D_GNU_SOURCE -Wall -Wextra -Werror -Wno-unused-parameter $ASYNCARA_HYPER_CFLAGS])
fi
