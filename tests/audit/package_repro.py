#!/usr/bin/env python3
"""Compare actual packaging with a private-umask clean Git checkout."""
import argparse
import hashlib
import os
import pathlib
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--output', required=True)
args = parser.parse_args()
output = pathlib.Path(args.output).resolve()
if output.exists():
    parser.error('Use a new evidence directory')
if subprocess.check_output(['git', 'status', '--porcelain'], cwd=root):
    parser.error('Commit the source checkpoint before the clean-checkout comparison')
output.mkdir(parents=True)
checkout = output / 'checkout'
old_mask = os.umask(0o077)
try:
    subprocess.run(['git', 'worktree', 'add', '--detach', str(checkout), 'HEAD'], cwd=root, check=True)
finally:
    os.umask(old_mask)
try:
    archives = []
    for name, source in [('working', root), ('private-checkout', checkout)]:
        archive = output / (name + '.tar.gz')
        subprocess.run([sys.executable, str(source / 'scripts/package.py'), '--output', str(archive)], check=True)
        archives.append(archive)
    assert archives[0].read_bytes() == archives[1].read_bytes(), 'Checkout permissions changed source archive bytes'
    print('Clean/private-umask packaging: PASS', hashlib.sha256(archives[0].read_bytes()).hexdigest())
finally:
    # No force: preserve any unexpected checkout modifications for inspection.
    subprocess.run(['git', 'worktree', 'remove', str(checkout)], cwd=root, check=True)
