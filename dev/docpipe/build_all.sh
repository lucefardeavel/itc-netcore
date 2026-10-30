#!/usr/bin/env bash
# Build every document folder (any directory holding meta.json + content.md) under <root> into <outroot>.
# usage: build_all.sh <root> <outroot>
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
[ $# -eq 2 ] || { echo "usage: build_all.sh <root> <outroot>" >&2; exit 2; }
ROOT="$(realpath "$1")"; OUT="$(realpath -m "$2")"
fail=0; n=0
while IFS= read -r meta; do
  d="$(dirname "$meta")"
  [ -f "$d/content.md" ] || continue
  n=$((n+1))
  if ! bash "$HERE/build.sh" "$d" "$OUT"; then echo "!! FAILED: $d" >&2; fail=$((fail+1)); fi
done < <(find "$ROOT" -name meta.json -not -path '*/_work/*' | sort)
echo "build_all: $n document(s), $fail failed"
[ "$fail" -eq 0 ]
