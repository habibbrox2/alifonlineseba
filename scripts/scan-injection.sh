#!/usr/bin/env bash
# Injection-surface scan: templates, deserialization, LFI, redirects, supply chain.
#
#   bash scripts/scan-injection.sh
#
# Exit status: 0 = nothing found, 1 = something needs a human look.
set -uo pipefail
cd "$(dirname "$0")/.."

EXCLUDE=(--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=runtime
         --exclude-dir=cache --exclude-dir=storage --exclude-dir=.kilo
         --exclude-dir=android-admin --exclude-dir=.gradle --exclude-dir=.git
         --exclude-dir=twa --exclude-dir=build --exclude-dir=_output
         --exclude-dir=deliverables --exclude-dir=.github
         --exclude=scan-malicious.sh --exclude=scan-injection.sh)

hits=0

# note() prints a list the operator eyeballs on every run without failing it.
note() {
  local title="$1"; shift
  local out
  out=$(grep -rnIE "${EXCLUDE[@]}" "$@" src config web public resources scripts tests 2>/dev/null | cut -c1-240)
  if [[ -n "$out" ]]; then
    echo "=== $title (informational — review anything new) ==="
    printf '%s\n' "$out" | head -50
    echo
  fi
}

run() {
  local title="$1"; shift
  local out
  out=$(grep -rnIE "${EXCLUDE[@]}" "$@" src config web public resources scripts tests 2>/dev/null | cut -c1-240)
  if [[ -n "$out" ]]; then
    hits=1
    echo "=== $title ==="
    printf '%s\n' "$out" | head -50
    echo
  fi
}

# Every |raw below was reviewed on 2026-10-07: json_encode output, an internal
# HTML slot, a status badge that escapes through StatusPresenter, a generated
# QR SVG. Listed on every run so a *new* one cannot arrive unnoticed.
note "raw / unescaped output in Twig" \
    '\|raw|autoescape false'

run "request input flowing into a render context" \
    'render\([^)]*\$_(GET|POST|REQUEST)'

run "unsafe deserialization" \
    'unserialize\s*\(|yaml_parse\s*\('

run "dynamic include / LFI" \
    '(include|require)(_once)?\s*\(?\s*["'"'"']?\s*\$_(GET|POST|REQUEST|COOKIE)|file_get_contents\s*\(\s*\$_(GET|POST|REQUEST)'

run "callback resolved from request" \
    '(call_user_func|call_user_func_array)\s*\(\s*\$_(GET|POST|REQUEST)|\$[A-Za-z_]+\s*\(\s*\$_(GET|POST)'

run "open redirect" \
    '(header\s*\(\s*["'"'"']Location:|->redirect\s*\().*\$_(GET|POST|REQUEST)'

run "auth bypass markers" \
    'alg.*none|verify\s*=>\s*false|verifySignature\s*=>\s*false'

echo "=== GitHub workflows: pipe-to-shell or secret-fed download ==="
grep -rnE 'curl .*[|] *(sudo )?(ba)?sh|\$\{\{ secrets\.[A-Z_]+ \}\}.*(curl|wget)' .github/workflows 2>/dev/null | cut -c1-240 | head -20 || true
echo "  (checked $(ls .github/workflows | wc -l) workflows)"
echo

echo "=== composer.json scripts ==="
grep -A15 '"scripts"' composer.json 2>/dev/null | head -20
echo

echo "=== .env tracked in git? ==="
git ls-files | grep -E '(^|/)\.env$' || echo "  no"
echo

echo "=== setuid / world-writable files ==="
find . -maxdepth 3 \( -path ./vendor -o -path ./node_modules -o -path ./.git -o -path ./twa \) -prune \
     -o \( -perm -4000 -o -perm -2000 -o -perm -0002 \) -print 2>/dev/null | head -20
echo "  (checked)"
echo

echo "=== PHP files changed in the last 7 days ==="
find src web public resources config scripts -type f -name '*.php' -mtime -7 -printf '  %TY-%Tm-%Td %p\n' 2>/dev/null | sort | tail -20
echo

echo "=== commits mentioning backdoor/webshell/injection ==="
git log --oneline --all -i --grep='backdoor\|webshell\|exploit\|miner' | sed 's/^/  /' | head -20
echo "  (checked $(git rev-list --count HEAD) commits)"
echo

echo "=== added lines in history matching payload chains ==="
git log -p --all --unified=0 -- '*.php' '*.js' '*.twig' 2>/dev/null \
  | grep -E '^\+' \
  | grep -EI 'eval\s*\(|base64_decode\s*\(\s*\$|gzinflate|str_rot13|shell_exec\s*\(\s*\$' \
  | grep -v '^+++' | cut -c1-200 | head -20
echo "  (history checked)"
echo

if [[ $hits -eq 0 ]]; then
  echo "RESULT: clean — no injection-surface hits"
else
  echo "RESULT: hits above need a human look"
fi
exit $hits
