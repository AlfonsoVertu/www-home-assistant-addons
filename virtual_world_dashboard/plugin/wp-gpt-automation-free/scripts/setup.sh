#!/bin/bash
# Setup environment to use bundled PyYAML
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
export PYTHONPATH="$SCRIPT_DIR/../pyyaml:$PYTHONPATH"
echo "PYTHONPATH set to use bundled PyYAML"
