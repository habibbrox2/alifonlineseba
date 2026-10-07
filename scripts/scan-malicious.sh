#!/usr/bin/env bash
# Heuristic scan for malicious code (webshells, backdoors, obfuscated payloads).
#
#   bash scripts/scan-malicious.sh
#
# Exit status: 0 = nothing found, 1 = something needs a human look.
# False positives are expected on a few patterns; every hit is a lead, not a
# verdict. Generated trees (vendor, node_modules, build output) are skipped.
set -uo pipefail
cd "$(dirname "$0")/.."

EXCLUDE=(--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=runtime
         --exclude-dir=cache --exclude-dir=storage --exclude-dir=.kilo
         --exclude-dir=android-admin --exclude-dir=.gradle --exclude-dir=.git
         --exclude-dir=_upcom_raw --exclude-dir=.freebuff --exclude-dir=twa
         --exclude-dir=build --exclude-dir=_output --exclude-dir=deliverables \
         --exclude=scan-malicious.sh --exclude=scan-injection.sh)

hits=0
run() {
  local title="$1"; shift
  local out
  out=$(grep -rnIE "${EXCLUDE[@]}" "$@" 2>/dev/null | cut -c1-240)
  if [[ -n "$out" ]]; then
    hits=1
    echo "=== $title ==="
    printf '%s\n' "$out" | head -50
    echo
  fi
}

run "SQL built from request input" \
    '(\$_GET|\$_POST|\$_REQUEST|\$_COOKIE).*(SELECT|INSERT|UPDATE|DELETE|WHERE)|(SELECT|INSERT INTO|UPDATE .* SET|DELETE FROM).*(\$_GET|\$_POST|\$_REQUEST)'

run "raw SQL string interpolation" \
    '->(query|createCommand)\s*\(\s*["'"'"'].*\$'

run "echo/print of request input (reflected XSS)" \
    '(echo|print)\s+.*\$_(GET|POST|REQUEST|COOKIE)'

run "eval / dynamic code execution" \
    '(^|[^A-Za-z_])(eval|assert|create_function)\s*\(|preg_replace\s*\(.{0,20}/[a-z]*e["'"'"']'

run "decode-then-execute payload chain" \
    '(base64_decode|gzinflate|gzuncompress|str_rot13|rawurldecode)\s*\([^)]*\).{0,120}(eval|assert|\$[A-Za-z_]+\()'

run "shell command built from request input" \
    '(system|exec|shell_exec|passthru|popen|proc_open)\s*\(.*\$_(GET|POST|REQUEST|COOKIE)'

run "file write from request input" \
    'file_put_contents\s*\(.*\$_(GET|POST|REQUEST)|move_uploaded_file\s*\(.*\$_(GET|POST)'

run "long base64 blob" \
    '[A-Za-z0-9+/]{300,}={0,2}'

run "JS obfuscation markers" \
    'document\.write\s*\(\s*unescape|String\.fromCharCode|window\["\\x'

run "hidden iframe / encoded payload in markup" \
    '<(iframe|script)[^>]*\b(src|srcdoc)=[^>]*(\\x|\\u|%[0-9a-f]{2}\.|base64,)'

run "exfiltration endpoints" \
    '(pastebin|transfer\.sh|requestbin|webhook\.site|ngrok|burpcollaborator|\boast\b|interact\.sh|paste\.ee|discord\.com/api/webhooks)'

run "credential exfiltration" \
    '(curl|wget|file_get_contents|fetch)\s*\(?.{0,80}https?://.{0,80}(\$_(GET|POST|REQUEST)|DB_PASSWORD|APP_KEY)'

echo "=== npm / composer lifecycle scripts ==="
grep -nE '"(preinstall|postinstall|prepare|prepublish)"' package.json composer.json 2>/dev/null || echo "none"
echo

echo "=== PHP entry points outside the framework's own trees ==="
find . -maxdepth 2 -type f -name '*.php' \
  -not -path './vendor/*' -not -path './node_modules/*' -not -path './runtime/*' \
  -not -path './src/*' -not -path './config/*' -not -path './tests/*' \
  -not -path './migrations/*' -not -path './scripts/*' -printf '  %p (%s bytes)\n' 2>/dev/null | sort
echo

echo "=== untracked files (excluding docs/android) ==="
git ls-files --others --exclude-standard | grep -viE '^(android-admin/|docs/)' | sed 's/^/  /' | head -40
echo

if [[ $hits -eq 0 ]]; then
  echo "RESULT: clean — no heuristic hits"
else
  echo "RESULT: hits above need a human look"
fi
exit $hits
