#!/usr/bin/env bash
# Preserve command failure and expose its tail through public check annotations.
set -euo pipefail
log="$1"; shift
mkdir -p "$(dirname "$log")"
set +e
"$@" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}
set -e
if [[ "$status" != 0 ]]; then
  python3 - "$log" <<'PY'
from pathlib import Path
import sys
text='\n'.join(Path(sys.argv[1]).read_text(errors='replace').splitlines()[-60:])[-6000:]
print('::error::'+text.replace('%','%25').replace('\r','%0D').replace('\n','%0A'))
PY
fi
exit "$status"
