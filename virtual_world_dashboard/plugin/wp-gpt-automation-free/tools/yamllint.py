#!/usr/bin/env python3
import sys
import os

# use bundled PyYAML
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))
import pyyaml as yaml


def lint_file(path: str) -> bool:
    try:
        with open(path, 'r', encoding='utf-8') as fh:
            yaml.safe_load(fh.read())
        print(f"{path}: OK")
        return True
    except Exception as exc:
        print(f"{path}: {exc}")
        return False


def main(paths):
    success = True
    for p in paths:
        if not lint_file(p):
            success = False
    if not success:
        sys.exit(1)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python tools/yamllint.py <file1> [file2 ...]")
        sys.exit(1)
    main(sys.argv[1:])
