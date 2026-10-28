#!/usr/bin/env bash
# Build the PrestaShop module zip. The zip is a release artifact, not a git file.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
version=$(grep -E "this->version = '[0-9.]+'" "$root/kiyoh/kiyoh.php" | head -1 | sed -E "s/.*'([0-9.]+)'.*/\1/")
if [[ -z "$version" ]]; then
  echo "Could not read the module version from kiyoh/kiyoh.php" >&2
  exit 1
fi
out=${1:-"$root/dist/kiyoh-${version}.zip"}
mkdir -p "$(dirname "$out")"
rm -f "$out"
(
  cd "$root"
  zip -r "$out" kiyoh -x '*.git*' -x '*__MACOSX*' -x '*.DS_Store'
)
echo "$out"
