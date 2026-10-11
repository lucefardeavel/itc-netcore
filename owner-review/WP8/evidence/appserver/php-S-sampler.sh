#!/bin/bash
# samples, every 3 s, the live php -S processes in the harness runner containers: total, distinct docroots, parents
out=$1
while [ ! -f "$out.stop" ]; do
  for c in $(docker ps --filter name=nuxbill-reseller-test-php82-run -q); do
    docker top "$c" -eo pid,ppid,stat,args 2>/dev/null | awk -v t="$(date -u +%T)" -v c="$c" '/ -S 127\.0\.0\.1:/ && $3 !~ /Z/ {n++; for(i=4;i<=NF;i++) if($i=="-t") d[$(i+1)]=1} END{k=0; for(x in d)k++; print t, c, "live_php_S=" n+0, "docroots=" k}'
  done >> "$out"
  sleep 3
done
