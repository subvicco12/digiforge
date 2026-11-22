#!/usr/bin/env bash
# Starts only workflow-local daemons. No changes to the runner's installed Docker.
set -euo pipefail
umask 077

mode=${1:?setup, verify, cleanup or check-info}
check_info() {
  local root=$1
  jq -e --arg root "$root/docker" '
    .DockerRootDir == $root and .OSType == "linux" and
    (.DriverStatus | type == "array" and any(.[]; . == ["driver-type", "io.containerd.snapshotter.v1"]))
  ' >/dev/null
}
# A pure predicate used by the offline validation suite.
if [[ "$mode" == check-info ]]; then
  check_info "${2:?expected root}"
  exit
fi

[[ ${GITHUB_RUN_ID:-} =~ ^[0-9]{1,16}$ && ${GITHUB_RUN_ATTEMPT:-} =~ ^[0-9]{1,6}$ ]]
[[ ${RUNNER_TEMP:-} =~ ^/[A-Za-z0-9._/-]+$ && $(realpath -e -- "$RUNNER_TEMP") == "$RUNNER_TEMP" ]]
key="df-oci-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
mount_path="/mnt/$key"
backing="$RUNNER_TEMP/$key.ext4"
marker="$RUNNER_TEMP/$key.started"
docker_unit="$key-docker.service"
containerd_unit="$key-ctrd.service"
socket="unix://$mount_path/s/docker.sock"
cap=$((8 * 1024**3))
reserve=$((3 * 1024**3))
# Docker is only reached at this workflow-local endpoint.
dock() {
  env -u DOCKER_CONTEXT DOCKER_HOST="$socket" DOCKER_CONFIG="$mount_path/job/docker-config" \
    BUILDX_CONFIG="$mount_path/job/buildx" docker "$@"
}
check_mount() {
  local device
  [[ $(findmnt -n -o TARGET,FSTYPE --mountpoint "$mount_path") == "$mount_path ext4" ]] || return 1
  device=$(findmnt -n -o SOURCE --mountpoint "$mount_path") || return 1
  [[ "$device" =~ ^/dev/loop[0-9]+$ ]] || return 1
  [[ $(sudo -n losetup --list --noheadings --output BACK-FILE "$device") == "$backing" ]] || return 1
  [[ $(sudo -n blockdev --getsize64 "$device") == "$cap" ]] || return 1
  [[ $(stat -c %s -- "$backing") == "$cap" ]]
}
verify() {
  check_mount
  sudo -n systemctl is-active --quiet "$containerd_unit"
  sudo -n systemctl is-active --quiet "$docker_unit"
  # contains the same exact data-root and socket used to start the daemons
  dock info --format '{{json .}}' | check_info "$mount_path"
  sudo -n ctr --address "$mount_path/s/containerd.sock" plugins ls |
    awk '$1=="io.containerd.snapshotter.v1" && $2=="overlayfs" && $NF=="ok" {ready=1} END {exit !ready}'
  # The existing producer uses --builder default. Validate its local Docker driver.
  dock buildx inspect default | awk '$1=="Driver:" && $2=="docker" {ready=1} END {exit !ready}'
  [[ $(dock context inspect default --format '{{.Endpoints.docker.Host}}') == "$socket" ]]
  local unit
  for unit in "$containerd_unit" "$docker_unit"; do
    [[ $(systemctl show -p ProtectSystem --value "$unit") == strict ]]
    [[ $(systemctl show -p ReadWritePaths --value "$unit") == "$mount_path" ]]
  done
}
# Lifecycle ownership is evidence, not proof of absence. No destructive action
# uses a missing marker, a marker alone, or a resource name alone.
backing_id() {
  [[ -f "$backing" && ! -L "$backing" && -O "$backing" ]] || return 1
  local identity
  identity=$(stat -c '%d:%i:%u:%h:%a' -- "$backing") || return 1
  [[ $identity =~ ^[0-9]+:[0-9]+:[0-9]+:1:600$ ]] || return 1
  printf '%s\n' "$identity"
}
read_marker() {
  [[ -f "$marker" && ! -L "$marker" && -O "$marker" ]] || return 1
  [[ $(stat -c '%a:%h' "$marker") == 600:1 && $(stat -c %s "$marker") -le 160 ]] || return 1
  local label phase data extra
  read -r label phase data extra < "$marker" || return 1
  [[ "$label" == "$key" && -z "$extra" ]] || return 1
  case "$phase" in
    reserved) [[ "$data" == - ]] ;;
    backing) [[ "$data" =~ ^[0-9]+:[0-9]+:[0-9]+:1:600$ ]] ;;
    *) return 1 ;;
  esac || return 1
  printf '%s %s\n' "$phase" "$data"
}
unit_read() {
  timeout 5s systemctl show "$1" -p LoadState -p ActiveState -p MainPID \
    -p ControlGroup -p Transient -p FragmentPath -p ExecStart
}
unit_property() {
  awk -F= -v name="$1" '$1==name {print substr($0,length(name)+2)}' <<< "$2"
}
unit_owned() {
  local unit=$1 raw=$2 cmd
  [[ $(unit_property LoadState "$raw") == loaded &&
     $(unit_property Transient "$raw") == yes &&
     $(unit_property FragmentPath "$raw") == "/run/systemd/transient/$unit" ]] || return 1
  cmd=$(unit_property ExecStart "$raw")
  case "$unit" in
    "$docker_unit")
      [[ "$cmd" == *'path='*'/dockerd ;'* &&
         "$cmd" == *"--config-file $mount_path/docker.json --host $socket "* &&
         "$cmd" == *"--data-root $mount_path/docker --exec-root $mount_path/exec "* &&
         "$cmd" == *"--containerd $mount_path/s/containerd.sock "* ]] ;;
    "$containerd_unit")
      [[ "$cmd" == *'path='*'/containerd ;'* &&
         "$cmd" == *" --config $mount_path/containerd.toml ;"* ]] ;;
    *) return 1 ;;
  esac
}
cgroup_empty() {
  local unit=$1 path=$2 default="/system.slice/$1" files f count
  # Default systemd-run places these units under system.slice. Unknown paths
  # are ambiguous and never inspected or acted on as another unit.
  [[ -z "$path" || "$path" == "$default" ]] || return 1
  [[ -f /sys/fs/cgroup/cgroup.controllers ]] || return 1
  [[ ! -e "/sys/fs/cgroup$default" ]] && return 0
  files=$(sudo -n timeout 5s find "/sys/fs/cgroup$default" -type f -name cgroup.procs -print) || return 1
  [[ -n "$files" ]] || return 1
  while IFS= read -r f; do
    count=$(sudo -n timeout 5s cat -- "$f") || return 1
    [[ -z "$count" ]] || return 1
  done <<< "$files"
}
own_processes() {
  local snapshot
  snapshot=$(ps -eo pid=,args= -ww) || return 1
  # Take the snapshot before passing the paths to awk, so the probe does not
  # identify its own awk command as a process using the fixture.
  awk -v m="$mount_path" -v b="$backing" '
    { for (i=2;i<=NF;i++) if ($i==m || index($i,m "/")==1 || $i==b || index($i,"unix://" m "/")==1 || index($i,"=" m "/")>0) {print $1;break} }
  ' <<< "$snapshot"
}
own_mounts() {
  local snapshot
  snapshot=$(findmnt --raw --noheadings --output TARGET) || return 1
  awk -v m="$mount_path" '$0==m || index($0,m "/")==1' <<< "$snapshot"
}
own_loops() {
  local snapshot aliases
  snapshot=$(sudo -n timeout 5s losetup --list --noheadings --raw --output NAME,BACK-FILE) || return 1
  aliases=
  if [[ -e "$backing" && ! -L "$backing" ]]; then
    aliases=$(sudo -n timeout 5s losetup -j "$backing" --list --noheadings --raw --output NAME) || return 1
  fi
  {
    awk -v b="$backing" '$2==b || $2==b"\134040(deleted)" {print $1}' <<< "$snapshot"
    printf '%s\n' "$aliases"
  } | awk 'NF && !seen[$0]++'
}
stop_owned() {
  sudo -n timeout --kill-after=3s 35s systemctl stop "$1"
}
verify_detached_loop() {
  local loop=$1 device_id mounted
  [[ "$loop" =~ ^/dev/loop[0-9]+$ ]] || return 1
  [[ $(sudo -n timeout 5s losetup --list --noheadings --raw --output BACK-FILE "$loop") == "$backing" ]] || return 1
  [[ $(sudo -n timeout 5s blockdev --getsize64 "$loop") == "$cap" ]] || return 1
  device_id=$(lsblk -dn -o MAJ:MIN "$loop") || return 1
  [[ "$device_id" =~ ^[0-9]+:[0-9]+$ ]] || return 1
  mounted=$(findmnt --raw --noheadings --output MAJ:MIN) || return 1
  ! grep -Fxq "$device_id" <<< "$mounted"
}
cleanup() {
  local lifecycle='' phase='' recorded='' unit raw load active pid cgroup processes mounts loops now
  local -a errors=()
  local can_act=false
  if lifecycle=$(read_marker); then
    read -r phase recorded <<< "$lifecycle"
    can_act=true
  elif [[ -e "$marker" || -L "$marker" ]]; then
    errors+=("invalid lifecycle marker")
  fi

  # Stop each independently identified unit. One error must not skip the other.
  for unit in "$docker_unit" "$containerd_unit"; do
    if ! raw=$(unit_read "$unit"); then errors+=("$unit initial read failed"); continue; fi
    load=$(unit_property LoadState "$raw")
    if [[ "$load" == not-found ]]; then continue; fi
    if [[ "$can_act" != true ]] || ! unit_owned "$unit" "$raw"; then
      errors+=("$unit exists with uncertain lifecycle/ownership"); continue
    fi
    active=$(unit_property ActiveState "$raw")
    if [[ "$active" != inactive && "$active" != failed ]]; then
      stop_owned "$unit" || errors+=("$unit stop failed")
    fi
  done
  for unit in "$docker_unit" "$containerd_unit"; do
    if ! raw=$(unit_read "$unit"); then errors+=("$unit final read failed"); continue; fi
    load=$(unit_property LoadState "$raw")
    active=$(unit_property ActiveState "$raw")
    pid=$(unit_property MainPID "$raw")
    cgroup=$(unit_property ControlGroup "$raw")
    if [[ "$load" != not-found ]]; then
      if [[ "$can_act" != true ]] || ! unit_owned "$unit" "$raw" ||
         [[ "$pid" != 0 || "$active" != inactive && "$active" != failed ]]; then
        errors+=("$unit not verified stopped")
      fi
    elif [[ "$pid" != 0 || "$active" != inactive ]]; then
      errors+=("$unit absence readback inconsistent")
    fi
    cgroup_empty "$unit" "$cgroup" || errors+=("$unit cgroup not proven empty")
  done
  if processes=$(own_processes); then [[ -z "$processes" ]] || errors+=("owned-path processes remain")
  else errors+=("process observation failed"); fi
  mounts=''
  loops=''
  mounts=$(own_mounts) || errors+=("mount observation failed")
  loops=$(own_loops) || errors+=("loop observation failed")

  if [[ "$can_act" != true ]]; then
    if [[ -n "$mounts" || -n "$loops" || -e "$backing" || -L "$backing" || -e "$mount_path" || -L "$mount_path" ]]; then
      errors+=("storage resources survive without proven lifecycle ownership")
    fi
    if (("${#errors[@]}" == 0)); then printf '%s\n' 'ABSENT: independently checked units, cgroups, processes and storage.'; return 0; fi
  else
    if [[ "$phase" == backing ]]; then
      if ! now=$(backing_id) || [[ "$now" != "$recorded" ]]; then errors+=("backing ownership mismatch"); fi
    elif [[ -e "$backing" || -L "$backing" || -n "$mounts" || -n "$loops" || -e "$mount_path" || -L "$mount_path" ]]; then
      errors+=("reserved marker does not identify backing resources")
    fi
    if [[ -n "$mounts" ]] && (("${#errors[@]}" == 0)); then
      if [[ "$mounts" != "$mount_path" ]] || ! check_mount; then errors+=("mount or submount ownership uncertain")
      elif ! sudo -n timeout --kill-after=3s 20s umount "$mount_path"; then errors+=("owned mount busy or unmount failed")
      fi
    fi
    mounts=$(own_mounts) || errors+=("post-unmount observation failed")
    [[ -z "$mounts" ]] || errors+=("mounts remain")
    loops=$(own_loops) || errors+=("post-unmount loop observation failed")
    if [[ -n "$loops" ]] && (("${#errors[@]}" == 0)); then
      if ! verify_detached_loop "$loops"; then errors+=("loop ownership or attachment uncertain")
      elif ! sudo -n timeout --kill-after=3s 10s losetup --detach "$loops"; then errors+=("owned loop detach failed")
      fi
    fi
    loops=$(own_loops) || errors+=("post-detach observation failed")
    [[ -z "$loops" ]] || errors+=("loop remains")
    if processes=$(own_processes); then [[ -z "$processes" ]] || errors+=("process remains before storage deletion")
    else errors+=("final process observation failed"); fi
    if (("${#errors[@]}" == 0)); then
      if [[ -L "$mount_path" ]]; then errors+=("unsafe mount directory")
      elif [[ -d "$mount_path" ]] && ! sudo -n timeout 5s rmdir -- "$mount_path"; then errors+=("mount directory removal failed")
      elif [[ -e "$mount_path" ]]; then errors+=("mount path remains")
      fi
    fi
    if (("${#errors[@]}" == 0)) && [[ "$phase" == backing ]]; then
      if ! now=$(backing_id) || [[ "$now" != "$recorded" ]]; then errors+=("backing identity changed before deletion")
      elif ! rm -- "$backing"; then errors+=("owned backing removal failed")
      fi
    fi
    if (("${#errors[@]}" == 0)); then
      if [[ -e "$backing" || -L "$backing" ]]; then errors+=("backing remains after cleanup")
      elif rm -- "$marker"; then printf '%s\n' 'CLEAN: owned units, cgroups, processes and storage reconciled.'; return 0
      else errors+=("marker removal failed")
      fi
    fi
  fi
  printf 'PARTIAL/FAILED cleanup: %s\n' "${errors[@]}" >&2
  return 1
}
setup() {
  [[ ${RUNNER_OS:-} == Linux && ${RUNNER_ARCH:-} == X64 ]]
  grep -qx 'VERSION_ID="24.04"' /etc/os-release
  [[ $(ps -p 1 -o comm=) == systemd ]]
  local bin docker_bin containerd_bin free blocks i
  for bin in docker dockerd containerd ctr systemd-run systemctl mkfs.ext4 mount mountpoint \
    umount fallocate findmnt losetup blockdev lsblk jq timeout ps nsenter; do
    command -v "$bin" >/dev/null
  done
  docker_bin=$(command -v dockerd)
  containerd_bin=$(command -v containerd)
  [[ "$docker_bin" == /* && "$containerd_bin" == /* ]]
  sudo -n true
  [[ ! -e "$marker" && ! -L "$marker" && ! -e "$backing" && ! -L "$backing" && ! -e "$mount_path" && ! -L "$mount_path" ]]
  cleanup  # With the marker absent, this independently rejects stale owned paths.
  [[ $(systemctl show -p LoadState --value "$docker_unit") == not-found ]]
  [[ $(systemctl show -p LoadState --value "$containerd_unit") == not-found ]]
  free=$(df -B1 --output=avail "$RUNNER_TEMP" | awk 'NR==2 {print $1}')
  [[ "$free" =~ ^[0-9]+$ && "$free" -ge $((cap + reserve)) ]]
  (set -o noclobber; printf '%s reserved -\n' "$key" > "$marker")
  (set -o noclobber; : > "$backing")
  local reserved_id
  reserved_id=$(backing_id)
  printf '%s backing %s\n' "$key" "$reserved_id" > "$marker"
  fallocate -l "$cap" -- "$backing"
  blocks=$(stat -c %b -- "$backing")
  [[ $((blocks * 512)) -ge "$cap" ]]
  [[ $(backing_id) == "$reserved_id" ]]
  sudo -n mkfs.ext4 -q -F -m 0 -E nodiscard,lazy_itable_init=0,lazy_journal_init=0 "$backing"
  sudo -n mkdir -m 0755 "$mount_path"
  [[ $(backing_id) == "$reserved_id" ]]
  sudo -n mount -o loop,nodev,nosuid "$backing" "$mount_path"
  check_mount
  [[ $(stat -c %b -- "$backing") -ge $((cap / 512)) ]]
  sudo -n install -d -m 0755 "$mount_path/s"
  sudo -n install -d -m 0700 "$mount_path/docker" "$mount_path/containerd" "$mount_path/exec" "$mount_path/daemon-tmp"
  sudo -n install -d -o "$(id -u)" -g "$(id -g)" -m 0700 "$mount_path/job"
  mkdir -m 0700 "$mount_path/job/"{tmp,docker-config,buildx,cache}
  cat <<CONFIG | sudo -n tee "$mount_path/containerd.toml" >/dev/null
version = 2
root = "$mount_path/containerd"
state = "$mount_path/s/containerd-state"
disabled_plugins = ["io.containerd.grpc.v1.cri"]
[grpc]
  address = "$mount_path/s/containerd.sock"
CONFIG
  cat <<CONFIG | sudo -n tee "$mount_path/docker.json" >/dev/null
{"features":{"containerd-snapshotter":true}}
CONFIG
  local -a service=(
    --property=ProtectSystem=strict "--property=ReadWritePaths=$mount_path"
    '--property=InaccessiblePaths=-/var/lib/docker -/var/lib/containerd -/tmp -/var/tmp'
    --property=KillMode=control-group --property=TimeoutStopSec=30
    --property=RuntimeMaxSec=2700 "--setenv=TMPDIR=$mount_path/daemon-tmp"
    "--setenv=DOCKER_TMPDIR=$mount_path/daemon-tmp"
  )
  sudo -n systemd-run --quiet --unit="$containerd_unit" "${service[@]}" \
    "--property=StandardOutput=append:$mount_path/containerd.log" --property=StandardError=inherit \
    "$containerd_bin" --config "$mount_path/containerd.toml"
  for ((i=0; i<30; i++)); do
    if [[ -S "$mount_path/s/containerd.sock" ]]; then break; fi
    sleep 1
  done
  [[ -S "$mount_path/s/containerd.sock" ]]
  sudo -n systemd-run --quiet --unit="$docker_unit" "${service[@]}" \
    "--property=StandardOutput=append:$mount_path/dockerd.log" --property=StandardError=inherit \
    "$docker_bin" --config-file "$mount_path/docker.json" --host "$socket" \
    --group "$(id -gn)" --data-root "$mount_path/docker" --exec-root "$mount_path/exec" \
    --pidfile "$mount_path/s/docker.pid" --containerd "$mount_path/s/containerd.sock" \
    --bridge none --iptables=false --ip6tables=false --ip-forward=false --ip-masq=false
  for ((i=0; i<30; i++)); do
    if dock info >/dev/null 2>&1; then break; fi
    sleep 1
  done
  verify
  {
    printf 'OCI_JOB_DIR=%s/job\n' "$mount_path"
    printf 'DOCKER_HOST=%s\n' "$socket"
    printf 'DOCKER_CONFIG=%s/job/docker-config\n' "$mount_path"
    printf 'BUILDX_CONFIG=%s/job/buildx\n' "$mount_path"
    printf 'TMPDIR=%s/job/tmp\n' "$mount_path"
    printf 'XDG_CACHE_HOME=%s/job/cache\n' "$mount_path"
  } >> "${GITHUB_ENV:?}"
  docker --version
  containerd --version
  dock buildx version
  printf '%s\n' 'Verified private Docker/containerd backend, overlay snapshotter, native builder and 8 GiB loop device.'
}
observe() {
  verify
  local unit pid group root_opts data_opts
  for unit in "$docker_unit" "$containerd_unit"; do
    pid=$(systemctl show "$unit" -p MainPID --value)
    group=$(systemctl show "$unit" -p ControlGroup --value)
    [[ "$pid" =~ ^[1-9][0-9]*$ && "$group" == "/system.slice/$unit" ]]
    root_opts=$(sudo -n timeout 5s nsenter -t "$pid" -m -- findmnt -n -o VFS-OPTIONS --target /)
    data_opts=$(sudo -n timeout 5s nsenter -t "$pid" -m -- findmnt -n -o VFS-OPTIONS --mountpoint "$mount_path")
    [[ ,$root_opts, == *,ro,* && ,$data_opts, == *,rw,* ]]
    # Report only allowlisted identities, never unrelated host mount tables.
    printf 'unit=%s pid=%s cgroup=%s root_ro=true data_rw=true\n' "$unit" "$pid" "$group"
  done
  printf 'backing_identity=%s\n' "$(backing_id)"
  df -B1 --output=size,used,avail "$mount_path" "$RUNNER_TEMP"
}
run_setup() {
  trap 'exit 130' INT
  trap 'exit 143' TERM
  setup
}
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  case "$mode" in
    setup) run_setup ;;
    verify)
      [[ ${OCI_JOB_DIR:-} == "$mount_path/job" && ${DOCKER_HOST:-} == "$socket" ]]
      [[ ${TMPDIR:-} == "$mount_path/job/tmp" && ${DOCKER_CONFIG:-} == "$mount_path/job/docker-config" ]]
      [[ ${BUILDX_CONFIG:-} == "$mount_path/job/buildx" && ${XDG_CACHE_HOME:-} == "$mount_path/job/cache" ]]
      [[ -z ${DOCKER_CONTEXT:-} ]]
      verify
      ;;
    cleanup) cleanup ;;
    observe) observe ;;
    *) exit 2 ;;
  esac
fi
