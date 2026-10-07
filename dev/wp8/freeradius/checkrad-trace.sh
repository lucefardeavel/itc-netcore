#!/bin/sh
# WP8 lab only (OD-W8-16 checkrad gate): runs the lab copy of the stock checkrad with the exact arguments FreeRADIUS
# passed (nas_type nas_ip nas_port login session_id) and records them with the exit code and duration on stderr (the
# FreeRADIUS -X log). The SNMP community is never an argument, so it is never logged. The exit code is passed through
# unchanged: 0 = not logged in, 1 = logged in (double), 2 = error.
t0=$(date +%s%N)
/etc/freeradius/wp8-checkrad "$@"
rc=$?
echo "[wp8-checkrad] type=$1 nas=$2 port=$3 user=$4 session=$5 exit=$rc ms=$(( ($(date +%s%N) - t0) / 1000000 ))" >&2
exit $rc
