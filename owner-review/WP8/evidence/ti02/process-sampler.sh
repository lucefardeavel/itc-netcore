#!/bin/bash
# every 3 s: live stub_nas.php and php -S processes in the harness runner containers
out=$1
while [ ! -f "$out.stop" ]; do
  for c in $(docker ps --filter name=nuxbill-reseller-test-php82-run -q); do
    docker top "$c" -eo pid,ppid,stat,args 2>/dev/null | awk -v t="$(date -u +%T)" '$3 !~ /Z/ && /stub_nas\.php/ {s++} $3 !~ /Z/ && / -S 127\.0\.0\.1:/ {w++} END{print t, "stub_nas=" s+0, "php_S=" w+0}'
  done >> "$out"
  sleep 3
done
