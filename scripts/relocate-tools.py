#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parent.parent
prefix=root/'.local/sysroot/usr'
bindir=root/'.local/bin';bindir.mkdir(exist_ok=True)
for name in ['phpize8.5','php-config8.5']:
    source=prefix/'bin'/name
    if not source.exists():raise SystemExit('Local PHP 8.5 development package is missing')
    text=source.read_text().replace("prefix='/usr'",f"prefix='{prefix}'").replace('prefix="/usr"',f'prefix="{prefix}"')
    text=text.replace('"/usr/share/libtool/build-aux"',f'"{prefix}/share/libtool/build-aux"').replace('"/usr/share/libtool/config"',f'"{prefix}/share/libtool/build-aux"')
    text=text.replace('/usr/bin/shtool',str(prefix/'bin/shtool'))
    # Package version lookup is irrelevant for extracted libtool 2.4.7.
    text=text.replace("libtool_version=$(dpkg-query -f'${Version}' -W libtool)","libtool_version=2.4.7")
    target=bindir/name.removesuffix('8.5');target.write_text(text);target.chmod(0o755)

config=prefix/'share/autoconf/autom4te.cfg'
(bindir/'autom4te.cfg').write_text(config.read_text().replace("/usr/share/autoconf",str(prefix/'share/autoconf')))
libdir=root/'.local/lib';libdir.mkdir(exist_ok=True)
import subprocess
for short,soname in [('sqlite3','libsqlite3.so.0'),('curl','libcurl.so.4'),('pq','libpq.so.5'),('mariadb','libmariadb.so.3')]:
    target=Path(subprocess.check_output(['cc','-print-file-name='+soname],text=True).strip()).resolve()
    if not target.exists():raise SystemExit('Runtime library missing: '+soname)
    link=libdir/('lib'+short+'.so')
    if link.is_symlink():link.unlink()
    link.symlink_to(target)
