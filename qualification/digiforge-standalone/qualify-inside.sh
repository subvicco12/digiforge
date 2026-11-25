#!/usr/bin/env bash
set -euo pipefail
export PATH=/usr/local/bin:/usr/bin:/bin
# The pinned OCI image provides the exact runtime binaries.
[[ "$(id -u)" == 65532 ]]
[[ "$(awk '/^CapEff:/ {print $2}' /proc/self/status)" == 0000000000000000 ]]
[[ "$(awk '/^NoNewPrivs:/ {print $2}' /proc/self/status)" == 1 ]]
[[ ! -e /var/run/docker.sock && ! -e /github/workspace ]]
check_network() {
  local stage=$1
  ip -j link show > "/evidence/$stage-links.json"
  ip -j -4 route show > "/evidence/$stage-v4.json"
  ip -j -6 route show > "/evidence/$stage-v6.json"
  jq -e 'length == 1 and .[0].ifname == "lo"' "/evidence/$stage-links.json" >/dev/null
  for family in 4 6; do jq -e 'all(.[]; .dev == "lo")' "/evidence/$stage-v$family.json" >/dev/null; done
  python3 - <<'PY' > "/evidence/$stage-probes.json"
import json, socket
results={}
for family,address in [(socket.AF_INET,'192.0.2.1'),(socket.AF_INET6,'2001:db8::1')]:
    try:
        with socket.socket(family,socket.SOCK_STREAM) as s:
            s.settimeout(1)
            s.connect((address,443))
    except OSError as error:
        results[address]=type(error).__name__
    else:
        raise SystemExit('Unexpected outside connection')
print(json.dumps(results))
PY
}
check_network before
bash /admission/workload.sh
check_network after
jq -e '.fixture_only==true and .mariadb_socket_query==true and .mariadb_tcp_listener==false and .browser_loopback==true
  and (.incumbents_unchanged==true) and .woocommerce_absent==true and .standalone_only==true and .coexistence==true
  and .deactivate_reactivate==true and .rollback_reinstall==true and .unknown_preserved==true and .replay_denied==true
  and .protected_get_passed==10' /evidence/driver-receipt.json >/dev/null
jq -e --slurpfile admitted /admission/approved.json '.versions==$admitted[0].versions' /evidence/driver-receipt.json >/dev/null
printf '%s\n' '{"status":"PASS","fixture_only":true,"mariadb_socket_only":true,"isolated":true}' > /evidence/qualification.json
