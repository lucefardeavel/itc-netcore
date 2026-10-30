#!/usr/bin/env bash
# ITC NetCore V1.0 - verify a release artifact on the target BEFORE it is unpacked
# (docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md section 1, step 4).
#
#   nuxr-artifact-verify.sh --artifact=<itc-netcore-*.tar.gz> --expected-sha=<40-hex release SHA given by ITC out-of-band (handover index)>
#                           [--sums=<SHA256SUMS>] [--extract-to=<new empty directory>]
#
# Checks, in this order (the first failure stops the run):
#   1. the artifact's SHA-256 equals its line in SHA256SUMS (default: SHA256SUMS next to the artifact);
#   2. the archive is safe to unpack: one top-level directory, no absolute path, no "..", no link or device entry;
#   3. <top>/RELEASE_SHA inside the archive equals --expected-sha (and, when git is installed, the commit id that
#      `git archive` stored in the tar header equals it too);
#   4. content rules of the runbook: no .git/, dev/, tests/, owner-review/, docs/claude-development-pack/,
#      system/vendor/yosiazwan/php-facedetection/lena512color.jpg and no config.php; LICENSE,
#      docs/THIRD_PARTY_LICENSES.md and the installer sources are present.
# With --extract-to the archive is unpacked only after every check passed, into a directory that does not exist yet
# (or is empty), without the archive's owners, and RELEASE_SHA is checked again in the unpacked tree.
#
# Exit codes: 0 verified; 2 verification failed (nothing unpacked); 5 usage; 1 internal error.
set -euo pipefail
umask 022

readonly EXIT_FAIL=2 EXIT_USAGE=5
ARTIFACT="" EXPECTED="" SUMS="" EXTRACT=""

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit "$EXIT_USAGE"; }
fail() { printf 'FAIL  %s\n' "$*" >&2; printf 'artifact_verify=FAIL\n' >&2; exit "$EXIT_FAIL"; }
pass() { printf 'PASS  %s\n' "$*"; }

for a in "$@"; do
  case $a in
    --artifact=*) ARTIFACT=${a#*=} ;;
    --expected-sha=*) EXPECTED=${a#*=} ;;
    --sums=*) SUMS=${a#*=} ;;
    --extract-to=*) EXTRACT=${a#*=} ;;
    -h|--help) usage ;;
    *) printf 'unknown argument: %s\n' "$a" >&2; usage ;;
  esac
done
[[ -n $ARTIFACT && -n $EXPECTED ]] || usage
[[ $EXPECTED =~ ^[0-9a-f]{40}$ ]] || { printf -- '--expected-sha must be a full 40-character lower-case commit SHA\n' >&2; exit "$EXIT_USAGE"; }
for t in sha256sum tar gzip awk; do command -v "$t" >/dev/null || { printf 'required tool missing: %s\n' "$t" >&2; exit 1; }; done
[[ -f $ARTIFACT && ! -L $ARTIFACT ]] || fail "artifact not found (or a symbolic link): $ARTIFACT"
ARTIFACT=$(cd "$(dirname "$ARTIFACT")" && pwd)/$(basename "$ARTIFACT")
[[ -n $SUMS ]] || SUMS=$(dirname "$ARTIFACT")/SHA256SUMS
[[ -f $SUMS ]] || fail "checksum file not found: $SUMS"
NAME=$(basename "$ARTIFACT")

# 1. checksum ------------------------------------------------------------------------------------------------------
want=$(awk -v n="$NAME" '{ f=$2; sub(/^\*/, "", f); if (f == n) print $1 }' "$SUMS")
[[ $(printf '%s\n' "$want" | grep -c .) -eq 1 ]] || fail "SHA256SUMS must list $NAME exactly once"
[[ $want =~ ^[0-9a-f]{64}$ ]] || fail "SHA256SUMS entry for $NAME is not a SHA-256 value"
have=$(sha256sum "$ARTIFACT" | awk '{print $1}')
[[ $have == "$want" ]] || fail "SHA-256 mismatch for $NAME: file $have, SHA256SUMS $want"
pass "sha256 $have = SHA256SUMS ($NAME, $(stat -c %s "$ARTIFACT") bytes)"

# 2. archive safety -------------------------------------------------------------------------------------------------
LIST=$(mktemp); VLIST=$(mktemp); trap 'rm -f "$LIST" "$VLIST"' EXIT
gzip -t "$ARTIFACT" 2>/dev/null || fail "not a valid gzip file"
tar -tzf "$ARTIFACT" > "$LIST" || fail "tar cannot list the archive"
tar -tvzf "$ARTIFACT" > "$VLIST" || fail "tar cannot list the archive"
[[ -s $LIST ]] || fail "archive is empty"
TOP=$(awk -F/ 'NR==1{print $1}' "$LIST")
[[ $TOP =~ ^[A-Za-z0-9._-]+$ ]] || fail "unexpected top-level entry: $TOP"
awk -F/ -v t="$TOP" '$1 != t { bad=1 } END { exit bad }' "$LIST" || fail "more than one top-level directory"
if grep -qE '^/' "$LIST"; then fail "absolute path in the archive"; fi
if grep -qE '(^|/)\.\.(/|$)' "$LIST"; then fail "'..' path component in the archive"; fi
bad_types=$(awk '{ c=substr($1,1,1); if (c != "-" && c != "d") n++ } END { print n+0 }' "$VLIST")
[[ $bad_types -eq 0 ]] || fail "$bad_types link / device / special entries in the archive (only files and directories are allowed)"
pass "archive safe: top-level '$TOP/', $(grep -vc '/$' "$LIST") files, only regular files and directories"

# 3. release SHA ----------------------------------------------------------------------------------------------------
grep -qxF "$TOP/RELEASE_SHA" "$LIST" || fail "$TOP/RELEASE_SHA missing"
rel=$(tar -xzOf "$ARTIFACT" "$TOP/RELEASE_SHA" | tr -d ' \r\n')
[[ $rel == "$EXPECTED" ]] || fail "RELEASE_SHA $rel differs from the expected $EXPECTED"
pass "RELEASE_SHA = expected $EXPECTED"
if command -v git >/dev/null; then
  hdr=$(gzip -dc "$ARTIFACT" | git get-tar-commit-id 2>/dev/null || true)
  if [[ -n $hdr ]]; then
    [[ $hdr == "$EXPECTED" ]] || fail "git archive header commit $hdr differs from the expected $EXPECTED"
    pass "git archive header commit id = expected"
  else
    printf 'INFO  no git archive commit id in the tar header (not built with git archive of a commit)\n'
  fi
else
  printf 'INFO  git not installed: tar-header commit id not checked (RELEASE_SHA and SHA256SUMS were)\n'
fi

# 4. content rules --------------------------------------------------------------------------------------------------
for p in .git/ dev/ tests/ owner-review/ docs/claude-development-pack/; do
  if grep -q "^$TOP/$p" "$LIST"; then fail "excluded path present in the artifact: $p"; fi
done
for f in system/vendor/yosiazwan/php-facedetection/lena512color.jpg config.php CLAUDE.md; do
  if grep -qxF "$TOP/$f" "$LIST"; then fail "excluded file present in the artifact: $f"; fi
done
for f in LICENSE docs/THIRD_PARTY_LICENSES.md index.php init.php install/phpnuxbill.sql install/radius.sql \
         install/freeradius/nuxr_admission.sql install/freeradius/nuxr_hs_admission.sql system/reseller/migrate.php \
         system/reseller/grants.php system/reseller/preflight.php system/reseller/health.php pages_template/; do
  grep -qxF "$TOP/$f" "$LIST" || fail "required path missing from the artifact: $f"
done
pass "content rules: excluded paths absent, LICENSE / THIRD_PARTY_LICENSES / installer sources present"

# extraction (optional) -------------------------------------------------------------------------------------------
if [[ -n $EXTRACT ]]; then
  if [[ -e $EXTRACT ]]; then
    [[ -d $EXTRACT && ! -L $EXTRACT ]] || fail "--extract-to exists and is not a directory: $EXTRACT"
    [[ -z $(ls -A "$EXTRACT") ]] || fail "--extract-to is not empty: $EXTRACT"
  fi
  mkdir -p "$EXTRACT"
  tar -xzf "$ARTIFACT" -C "$EXTRACT" --strip-components=1 --no-same-owner --no-overwrite-dir
  rel2=$(tr -d ' \r\n' < "$EXTRACT/RELEASE_SHA")
  [[ $rel2 == "$EXPECTED" ]] || fail "unpacked RELEASE_SHA $rel2 differs from the expected $EXPECTED"
  pass "unpacked into $EXTRACT (RELEASE_SHA re-checked)"
fi
printf 'artifact_verify=PASS sha=%s\n' "$EXPECTED"
