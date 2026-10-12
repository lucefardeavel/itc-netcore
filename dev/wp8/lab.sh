#!/usr/bin/env bash
# WP8 real-NAS lab: RouterOS CHR (QEMU, TCG) + FreeRADIUS 3.2.8 + application runner + MariaDB + independent PPPoE
# and Hotspot client namespaces, all on isolated private networks. Development/test only - never production.
#
#   dev/wp8/lab.sh fetch-image      download the pinned CHR image from MikroTik and verify both SHA-256 values
#   dev/wp8/lab.sh up               start everything (refuses on address-space conflicts or leftovers)
#   dev/wp8/lab.sh up-chr2          WP8 A2-R: add a second CHR (10.88.0.3) with its own PPPoE client (cross-NAS gate)
#   dev/wp8/lab.sh test [args]      run PHPUnit (suite wp8-integration by default) in the runner
#   dev/wp8/lab.sh php [args]       run php in the runner (same network namespace as FreeRADIUS)
#   dev/wp8/lab.sh status           show lab state
#   dev/wp8/lab.sh down             stop and remove everything (idempotent)
#
# State, secrets, the CHR image, working disk, pcaps and logs live in $NUXR_WP8_STATE (default /var/lib/nuxr-wp8),
# outside Git. Nothing is published except the CHR API management forward on 127.0.0.1:18728.
# Rules: no bridge to any host uplink, no host firewall change, only the nuxbill-reseller-test compose project.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../.." && pwd)"
export NUXR_WP8_STATE="${NUXR_WP8_STATE:-/var/lib/nuxr-wp8}"
export NUXR_WP8_TZ="${NUXR_WP8_TZ:-Asia/Baghdad}"
S="${NUXR_WP8_STATE:?}"
case "$S" in /*/*) ;; *) echo "NUXR_WP8_STATE must be an absolute path at least two levels deep"; exit 1 ;; esac
ROS_VERSION=7.24.5
ZIP_SHA=16f07222a3213352c9c4fbea4f4dec6a8ffb78fdc0fa5e398c23103ed357c6eb
IMG_SHA=e4caf173aceb497433c5fc285bffee09c1e6761dfe0e658a2b178970249fc2d2
IMG="$S/img/chr-$ROS_VERSION.img"
LINKS=(wp8tap1 wp8tap2 wp8tap3 wp8br1 wp8br2 wp8v1h wp8v2h wp8v3h wp8v4h wp8v5h wp8vsh wp8tap4 wp8tap5 wp8br3 wp8v6h wp8v7h wp8v8h)
NETNS=(wp8c1 wp8c2 wp8c3 wp8c4 wp8c5 wp8srv wp8c6 wp8c7 wp8c8)   # wp8c7 / wp8c8: WP8 H-2 Hotspot clients of CHR2 (dev/wp8/h2_gate.py)
log() { echo "[wp8 $(date -u +%T)] $*"; }
dc() { docker compose -f "$REPO/dev/test/compose.yml" -f "$HERE/compose.wp8.yml" --project-directory "$REPO/dev/test" "$@"; }
secrets() {
  mkdir -p "$S"; chmod 700 "$S"
  if [ ! -f "$S/secrets.env" ]; then
    ( umask 077; r() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
      printf 'WP1_DB_ROOT_PASSWORD=%s\nNUXR_RADIUS_SECRET=%s\nNUXR_WP8_CHR_PASSWORD=Wp8%s\n' "$(r 12)" "$(r 16)" "$(r 10)" > "$S/secrets.env" )
    log "generated $S/secrets.env (mode 600)"
  fi
  set -a; . "$S/secrets.env"; set +a
}

fetch_image() {
  mkdir -p "$S/img"; local z="$S/img/chr-$ROS_VERSION.img.zip"
  [ -f "$z" ] || curl -fsSL -o "$z" "https://download.mikrotik.com/routeros/$ROS_VERSION/chr-$ROS_VERSION.img.zip"
  echo "$ZIP_SHA  $z" | sha256sum -c --quiet || { echo "zip SHA-256 mismatch"; exit 1; }
  [ -f "$IMG" ] || (cd "$S/img" && unzip -o -q "$z")
  echo "$IMG_SHA  $IMG" | sha256sum -c --quiet || { echo "image SHA-256 mismatch"; exit 1; }
  log "CHR $ROS_VERSION image verified ($IMG)"
}

preflight() {
  [ "$(id -u)" = 0 ] || { echo "root required (TAP, bridges, namespaces)"; exit 1; }
  for c in qemu-system-x86_64 ip tcpdump pppd docker python3 curl busybox unzip; do command -v "$c" >/dev/null || { echo "missing $c"; exit 1; }; done
  ls /usr/lib/pppd/*/pppoe.so >/dev/null 2>&1 || { echo "missing pppd pppoe.so (package ppp)"; exit 1; }
  # refuse when the deterministic address space or any lab object already exists
  if ip -4 route | grep -qE '(^| )10\.88\.' || ip -4 addr | grep -qE 'inet 10\.88\.'; then echo "REFUSED: 10.88.0.0/16 already in use on the host"; exit 1; fi
  local n
  for n in $(docker network ls -q); do
    docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' "$n" | grep -qE '(^| )10\.88\.' && { echo "REFUSED: docker network $(docker network inspect -f '{{.Name}}' "$n") uses 10.88.x"; exit 1; }
  done
  for n in "${LINKS[@]}" wp8lab0; do ip link show "$n" >/dev/null 2>&1 && { echo "REFUSED: leftover link $n (run down)"; exit 1; }; done
  for n in "${NETNS[@]}"; do ip netns list | grep -qw "$n" && { echo "REFUSED: leftover netns $n (run down)"; exit 1; }; done
  pgrep -f '[q]emu-system-x86_64 -name wp8chr' >/dev/null && { echo "REFUSED: CHR already running"; exit 1; }
  ss -ltn | grep -q '127.0.0.1:18729 ' && { echo "REFUSED: 127.0.0.1:18729 in use"; exit 1; }
  ss -ltn | grep -q '127.0.0.1:18728 ' && { echo "REFUSED: 127.0.0.1:18728 in use"; exit 1; }
  return 0
}

mysql_root() { docker exec -i nuxbill-reseller-test-mariadb-1 sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" '"$1"; }

up() {
  preflight; secrets; fetch_image
  mkdir -p "$S/ctl" "$S/run" "$S/pcap" "$S/evidence"
  mkdir -p "$S/radius-secrets"; chmod 700 "$S/radius-secrets"     # PPPoE session guard credentials (WP8 A1)
  log "compose: mariadb"; dc up -d mariadb >/dev/null
  for _ in $(seq 1 60); do [ "$(docker inspect -f '{{.State.Health.Status}}' nuxbill-reseller-test-mariadb-1 2>/dev/null)" = healthy ] && break; sleep 2; done
  log "RADIUS database nuxr_radius (install/radius.sql + install/freeradius/nuxr_admission.sql + nuxr_hs_admission.sql) + NAS rows (production-style SQL clients)"
  echo 'DROP DATABASE IF EXISTS nuxr_radius; CREATE DATABASE nuxr_radius CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;' | mysql_root ""
  mysql_root nuxr_radius < "$REPO/install/radius.sql"
  mysql_root nuxr_radius < "$REPO/install/freeradius/nuxr_admission.sql"      # WP8 A2-R additive upgrade
  mysql_root nuxr_radius < "$REPO/install/freeradius/nuxr_admission_check.sql" | grep -qx "nuxr_admission=READY" || { echo "nuxr_admission NOT READY"; exit 1; }
  mysql_root nuxr_radius < "$REPO/install/freeradius/nuxr_hs_admission.sql"   # WP8 H-2B additive upgrade (Hotspot slots)
  mysql_root nuxr_radius < "$REPO/install/freeradius/nuxr_hs_admission_check.sql" | grep -qx "nuxr_hs_admission=READY" || { echo "nuxr_hs_admission NOT READY"; exit 1; }
  # 1: the CHR (CoA / Disconnect port 3799 = OD-W8-3 A); 2: the co-located application host itself, used by the
  # WP8 FreeRADIUS contract tests that send their own Access/Accounting-Requests from 10.88.0.10
  mysql_root nuxr_radius <<SQL
INSERT INTO nas (id, nasname, shortname, type, ports, secret, description, routers) VALUES
 (1, '10.88.0.2', 'nuxr_wp8_chr', 'other', 3799, '$NUXR_RADIUS_SECRET', 'WP8 lab RouterOS CHR $ROS_VERSION', ''),
 (2, '10.88.0.10', 'nuxr_wp8_app', 'other', NULL, '$NUXR_RADIUS_SECRET', 'WP8 lab contract runner (co-located app)', '');
SQL
  log "compose: freeradius-wp8 (read_clients = yes)"; dc --profile wp8 up -d freeradius-wp8 >/dev/null
  for _ in $(seq 1 60); do docker logs nuxbill-reseller-test-freeradius-wp8-1 2>&1 | grep -q 'Ready to process requests' && break; sleep 1; done
  docker logs nuxbill-reseller-test-freeradius-wp8-1 2>&1 | grep -E '^\[wp8\]|Client "nuxr_wp8_' || true
  docker image inspect nuxbill-reseller-test-php82 >/dev/null 2>&1 || dc build php82 >/dev/null
  log "links: TAPs, Linux bridges, client namespaces (no uplink, no IP on the segment bridges)"
  for t in wp8tap1 wp8tap2 wp8tap3; do ip tuntap add dev "$t" mode tap; done
  ip link set wp8tap1 master wp8lab0 up
  ip link add wp8br1 type bridge; ip link add wp8br2 type bridge; ip link set wp8br1 up; ip link set wp8br2 up
  ip link set wp8tap2 master wp8br1 up; ip link set wp8tap3 master wp8br2 up
  mkns() { ip netns add "$1"; ip link add "$2" type veth peer name "$3"; ip link set "$3" netns "$1"; ip link set "$2" master "$4" up
           ip netns exec "$1" ip link set lo up; ip netns exec "$1" ip link set "$3" up; }
  mkns wp8c1 wp8v1h wp8v1c wp8br1; mkns wp8c3 wp8v3h wp8v3c wp8br1     # PPPoE clients A, B
  mkns wp8c2 wp8v2h wp8v2c wp8br2; mkns wp8c4 wp8v4h wp8v4c wp8br2     # Hotspot clients A, B
  mkns wp8c5 wp8v5h wp8v5c wp8br2                                      # Hotspot client C (Port-Limit 2 gate)
  mkns wp8srv wp8vsh wp8vsc wp8lab0                                    # traffic peer on the lab network
  ip netns exec wp8srv ip addr add 10.88.0.40/24 dev wp8vsc
  ip netns exec wp8srv ip route add 10.88.10.0/24 via 10.88.0.2; ip netns exec wp8srv ip route add 10.88.2.0/24 via 10.88.0.2
  log "CHR $ROS_VERSION: QEMU TCG, 1 vCPU, 1 GiB, fresh working disk"
  cp -f "$IMG" "$S/img/chr-work.img"; : > "$S/serial.log"; local t0; t0=$(date +%s)
  qemu-system-x86_64 -name wp8chr -machine pc,accel=tcg -cpu max -smp 1 -m 1024 \
    -drive file="$S/img/chr-work.img",format=raw,if=virtio \
    -netdev user,id=mgmt,restrict=on,net=10.0.2.0/24,hostfwd=tcp:127.0.0.1:18728-:8728 -device virtio-net-pci,netdev=mgmt \
    -netdev tap,id=lab,ifname=wp8tap1,script=no,downscript=no -device virtio-net-pci,netdev=lab \
    -netdev tap,id=pppoe,ifname=wp8tap2,script=no,downscript=no -device virtio-net-pci,netdev=pppoe \
    -netdev tap,id=hs,ifname=wp8tap3,script=no,downscript=no -device virtio-net-pci,netdev=hs \
    -display none -serial file:"$S/serial.log" -pidfile "$S/qemu.pid" -daemonize
  for _ in $(seq 1 120); do python3 "$HERE/rosapi.py" 127.0.0.1 18728 admin - /system/identity/print >/dev/null 2>&1 && break; sleep 3; done
  log "CHR API answered after $(( $(date +%s) - t0 ))s"; echo "$(( $(date +%s) - t0 ))" > "$S/boot-seconds"
  python3 "$HERE/configure_chr.py"
  log "client agent"; nohup python3 "$HERE/client_agent.py" >> "$S/agent.out" 2>&1 & echo $! > "$S/agent.pid"
  log "lab up"
}

# WP8 A2-R cross-NAS gate (owner D-A2R-5 / A2-R gate 12): a SECOND disposable CHR on the same isolated lab network
# (10.88.0.3, management forward 127.0.0.1:18729) with its own PPPoE segment (bridge wp8br3, client namespace wp8c6).
# Requires a running lab (`up`). Removed by `down`.
up_chr2() {
  secrets; [ -f "$IMG" ] || { echo "no CHR image (fetch-image)"; exit 1; }
  pgrep -f '[q]emu-system-x86_64 -name wp8chr2' >/dev/null && { echo "REFUSED: CHR2 already running"; exit 1; }
  for n in wp8tap4 wp8tap5 wp8br3 wp8v6h; do ip link show "$n" >/dev/null 2>&1 && { echo "REFUSED: leftover link $n"; exit 1; }; done
  ip netns list | grep -qw wp8c6 && { echo "REFUSED: leftover netns wp8c6"; exit 1; }
  ss -ltn | grep -q '127.0.0.1:18729 ' && { echo "REFUSED: 127.0.0.1:18729 in use"; exit 1; }
  ip tuntap add dev wp8tap4 mode tap; ip tuntap add dev wp8tap5 mode tap
  ip link set wp8tap4 master wp8lab0 up
  ip link add wp8br3 type bridge; ip link set wp8br3 up; ip link set wp8tap5 master wp8br3 up
  ip netns add wp8c6; ip link add wp8v6h type veth peer name wp8v6c; ip link set wp8v6c netns wp8c6; ip link set wp8v6h master wp8br3 up
  ip netns exec wp8c6 ip link set lo up; ip netns exec wp8c6 ip link set wp8v6c up
  ip netns exec wp8srv ip route add 10.88.11.0/24 via 10.88.0.3
  # explicit MACs: QEMU gives every VM the same default NIC MACs, and both CHRs share the lab bridge wp8lab0
  log "CHR2 $ROS_VERSION: QEMU TCG, 1 vCPU, 1 GiB, fresh working disk, own MACs"
  cp -f "$IMG" "$S/img/chr2-work.img"; : > "$S/serial2.log"; local t0; t0=$(date +%s)
  qemu-system-x86_64 -name wp8chr2 -machine pc,accel=tcg -cpu max -smp 1 -m 1024 \
    -drive file="$S/img/chr2-work.img",format=raw,if=virtio \
    -netdev user,id=mgmt,restrict=on,net=10.0.3.0/24,hostfwd=tcp:127.0.0.1:18729-:8728 -device virtio-net-pci,netdev=mgmt,mac=52:54:00:a2:02:01 \
    -netdev tap,id=lab,ifname=wp8tap4,script=no,downscript=no -device virtio-net-pci,netdev=lab,mac=52:54:00:a2:02:02 \
    -netdev tap,id=pppoe,ifname=wp8tap5,script=no,downscript=no -device virtio-net-pci,netdev=pppoe,mac=52:54:00:a2:02:03 \
    -display none -serial file:"$S/serial2.log" -pidfile "$S/qemu2.pid" -daemonize
  for _ in $(seq 1 120); do python3 "$HERE/rosapi.py" 127.0.0.1 18729 admin - /system/identity/print >/dev/null 2>&1 && break; sleep 3; done
  log "CHR2 API answered after $(( $(date +%s) - t0 ))s"
  NUXR_WP8_CHR_ROLE=chr2 python3 "$HERE/configure_chr.py"
  log "chr2 up"
}

down() {
  set +e
  log "down"
  [ -f "$S/agent.pid" ] && kill "$(cat "$S/agent.pid")" 2>/dev/null; rm -f "${S:?}/agent.pid"
  pkill -f '[p]ppd file /' 2>/dev/null; pkill -f '[t]cpdump -i wp8lab0' 2>/dev/null
  [ -f "$S/qemu.pid" ] && kill "$(cat "$S/qemu.pid")" 2>/dev/null; [ -f "$S/qemu2.pid" ] && kill "$(cat "$S/qemu2.pid")" 2>/dev/null
  sleep 2; pkill -9 -f '[q]emu-system-x86_64 -name wp8chr' 2>/dev/null; rm -f "${S:?}/qemu.pid" "${S:?}/qemu2.pid"
  local n
  for n in "${NETNS[@]}"; do ip netns list | grep -qw "$n" && { ip netns pids "$n" | xargs -r kill 2>/dev/null; ip netns del "$n"; }; done
  for n in "${LINKS[@]}"; do ip link show "$n" >/dev/null 2>&1 && ip link del "$n"; done
  [ -f "$S/secrets.env" ] && { set -a; . "$S/secrets.env"; set +a; }
  export WP1_DB_ROOT_PASSWORD="${WP1_DB_ROOT_PASSWORD:-down}" NUXR_RADIUS_SECRET="${NUXR_RADIUS_SECRET:-down}" NUXR_WP8_CHR_PASSWORD="${NUXR_WP8_CHR_PASSWORD:-down}"
  docker rm -f nuxbill-reseller-test-wp8nasb >/dev/null 2>&1      # OD-W8-16 Option G gate: fake second NAS (if any)
  dc --profile wp8 --profile wp8-runner down -v --remove-orphans >/dev/null 2>&1
  docker network rm nuxbill-reseller-test_wp8lab >/dev/null 2>&1
  [ -f "$S/ctl/evidence.jsonl" ] && { mkdir -p "$S/evidence"; mv "$S/ctl/evidence.jsonl" "$S/evidence/evidence-$(date -u +%Y%m%dT%H%M%SZ).jsonl"; }
  rm -rf "${S:?}/ctl" "${S:?}/run"; rm -f "${S:?}/img/chr-work.img" "${S:?}/img/chr2-work.img"
  status
  set -e
}

status() {
  echo "links: $(ip -br link | grep -cE 'wp8') netns: $(ip netns list | grep -c wp8) qemu: $(pgrep -fc '[q]emu-system-x86_64 -name wp8chr') agent: $(pgrep -fc '[c]lient_agent.py') containers: $(docker ps -a --format '{{.Names}}' | grep -c '^nuxbill-reseller-test') networks: $(docker network ls --format '{{.Name}}' | grep -c '^nuxbill-reseller-test')"
}

runner() { secrets; dc --profile wp8 --profile wp8-runner run --rm -T php82-wp8 "$@"; }

case "${1:-}" in
  fetch-image) secrets; fetch_image ;;
  up) up ;;
  up-chr2) up_chr2 ;;
  down) down ;;
  status) status ;;
  php) shift; runner "$@" ;;
  test) shift; if [ $# -eq 0 ]; then set -- --testsuite wp8-integration; fi; runner tests/vendor/bin/phpunit -c tests/phpunit.xml "$@" ;;
  *) sed -n '2,16p' "$0"; exit 2 ;;
esac
