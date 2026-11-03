/* Compatibility shim for Connector/C development packages that install
 * ma_tls.h but omit its unused ma_hash.h dependency. Asyncara uses none of
 * the hash constants. The TLS callback structures and declarations always
 * come from the installed Connector/C headers, never a copied ABI layout.
 */
