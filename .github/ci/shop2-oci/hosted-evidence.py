"""Bounded, read-only setup observations. No raw host configuration is retained."""
import ctypes
import hashlib
import io
import json
import os
import platform
from pathlib import Path
import re
import stat
import selectors
import signal
import shutil
import subprocess
import time
import sys


MEMBER = 256 * 1024
TOTAL = 2 * 1024**2
CAP = 8 * 1024**3

def require(condition, reason):
    if not condition:
        raise ValueError(reason)

# Per invocation, including subprocess and filesystem observation time.
COLLECT_SECONDS = 8
COMMAND_SECONDS = 2
COMMANDS = 40
STREAM_BYTES = 2 * 1024**2
DIR_ENTRIES = 160
DEPTH = 6
PROCESSES = 24

class CaptureError(ValueError):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status

class Budget:
    def __init__(self):
        self.deadline = time.monotonic() + COLLECT_SECONDS
        self.commands = COMMANDS
        self.bytes = STREAM_BYTES
        self.entries = DIR_ENTRIES

    def remaining(self):
        left = self.deadline - time.monotonic()
        if left <= 0:
            raise CaptureError("DEADLINE", "aggregate collector deadline")
        return left

    def use(self, category, count, stage=None):
        self.remaining()
        left = getattr(self, category) - count
        if left < 0:
            raise CaptureError("LIMIT", (stage + ": " if stage else "") + category + " aggregate limit")
        setattr(self, category, left)

budget = Budget()

def bounded_entries(path, limit):
    # Charge failed and irrelevant entries before filtering. No scandir sort.
    with os.scandir(path) as entries:
        for n, entry in enumerate(entries, 1):
            budget.use("entries", 1)
            if n > limit:
                raise CaptureError("LIMIT", "directory entry limit")
            yield entry

CGROUP_DIRECTORIES = 24
CGROUP_ENTRIES_PER_DIRECTORY = 64


def cgroup_files(root):
    # At most 24 directories x 64 raw entries. Charge directories, not
    # kernel-generated control files, to the shared traversal budget.
    count = 0
    def walk(directory, depth):
        nonlocal count
        budget.remaining()
        count += 1
        if depth > DEPTH or count > CGROUP_DIRECTORIES:
            raise CaptureError("LIMIT", "cgroup discovery depth or directory count")
        budget.use("entries", 1, "cgroup discovery")
        yield directory / "cgroup.procs"
        with os.scandir(directory) as entries:
            for index, entry in enumerate(entries, 1):
                budget.remaining()
                if index > CGROUP_ENTRIES_PER_DIRECTORY:
                    raise CaptureError("LIMIT", "cgroup discovery per-directory entries")
                if entry.is_dir(follow_symlinks=False):
                    yield from walk(Path(entry.path), depth+1)
    yield from walk(root, 0)


def cgroup_members(root, main_pid):
    members = {}
    for path in cgroup_files(root):
        if not path.is_file() or path.is_symlink():
            raise CaptureError("UNAVAILABLE", "cgroup membership file missing or invalid")
        pids = set()
        for value in command("sudo", "-n", "cat", str(path)).split():
            require(re.fullmatch(r"[1-9][0-9]{0,9}", value), "invalid PID")
            pid = int(value)
            require(pid not in members, "duplicate cgroup PID")
            members[pid] = path
            pids.add(pid)
            if len(members) > PROCESSES:
                raise CaptureError("LIMIT", "cgroup membership process count")
        # Retain empty groups in the snapshot to detect inconsistent coverage.
        yield path, frozenset(pids)
    require(main_pid in members, "missing private main PID")


def data(path, limit=MEMBER):
    budget.remaining()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        identity = os.fstat(stream.fileno())
        if not stat.S_ISREG(identity.st_mode) or identity.st_size > limit:
            raise CaptureError("TRUNCATED_OR_OVERSIZED", "not a bounded regular input")
        raw = stream.read(limit+1)
        after = os.fstat(stream.fileno())
    if len(raw)>limit or len(raw)!=identity.st_size or (identity.st_size,identity.st_mtime_ns,identity.st_ctime_ns)!=(after.st_size,after.st_mtime_ns,after.st_ctime_ns):
        raise CaptureError("INCOMPLETE_READ", "changed or incomplete input")
    budget.use("bytes",len(raw))
    return raw

def subreaper():
    # Linux: adopt descendants from our own timed-out probe group for reaping.
    libc=ctypes.CDLL(None,use_errno=True)
    if libc.prctl(36, 1, 0, 0, 0) != 0:
        raise CaptureError("UNAVAILABLE", "cannot establish scoped process reaping")

def stop_probe(process):
    if process.returncode is not None:
        while True:
            try:
                pid,_=os.waitpid(-process.pid,os.WNOHANG)
            except ChildProcessError:
                return
            if pid==0:
                break
    # This group was created by this collector's Popen(start_new_session=True).
    # Never infer a PID from host data for signalling.
    try:
        os.killpg(process.pid,signal.SIGKILL)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=0.25)
    except subprocess.TimeoutExpired:
        raise CaptureError("REAP_INCOMPLETE","owned probe did not stop") from None
    end=time.monotonic()+0.35
    while True:
        try:
            pid,_=os.waitpid(-process.pid,os.WNOHANG)
        except ChildProcessError:
            return
        if pid==0:
            if time.monotonic()>=end:
                raise CaptureError("REAP_INCOMPLETE","owned probe descendants remain")
            time.sleep(0.01)

def command(*argv):
    budget.use("commands",1)
    stop_at=time.monotonic()+min(COMMAND_SECONDS,budget.remaining())
    # sudo can use a separate monitor/session. Its only allowed probes also
    # have their own root-side timeout, with no caller-supplied timeout target.
    if argv[:2]==("sudo","-n"):
        argv=(*argv[:2],"timeout","--foreground","--kill-after=0.2s","1.5s",*argv[2:])
    subreaper()
    process=subprocess.Popen(argv,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                             stdin=subprocess.DEVNULL,start_new_session=True)
    output=bytearray()
    try:
        with selectors.DefaultSelector() as sel:
            sel.register(process.stdout,selectors.EVENT_READ)
            while True:
                left=min(stop_at-time.monotonic(),budget.remaining())
                if left<=0 or not sel.select(left):
                    raise CaptureError("TIMEOUT","probe command or inherited output timed out")
                chunk=os.read(process.stdout.fileno(),min(8192,MEMBER+1-len(output)))
                if not chunk:
                    break
                output.extend(chunk)
                if len(output)>MEMBER:
                    raise CaptureError("TRUNCATED_OR_OVERSIZED","probe output overflow")
                budget.use("bytes",len(chunk))
        status=process.wait(timeout=max(0.001,min(stop_at-time.monotonic(),budget.remaining())))
        if status in (124,137):
            raise CaptureError("TIMEOUT","bounded probe ended on timeout")
        if status:
            raise CaptureError("INCOMPLETE_READ","probe exited before complete read")
        return output.decode()
    except subprocess.TimeoutExpired:
        raise CaptureError("TIMEOUT","probe did not exit") from None
    finally:
        try:
            stop_probe(process)
        finally:
            process.stdout.close()

def sysprops(unit, fields):
    raw = command("systemctl", "show", "--all", "--no-pager", unit,
                  *["--property="+name for name in fields])
    props = {}
    for row in raw.splitlines():
        key, separator, value = row.partition("=")
        if not separator or key not in fields or key in props:
            raise CaptureError("INCOMPLETE_READ", "malformed or duplicate systemd property")
        props[key] = value
    return props


def fsid(number):
    return f"{os.major(number)}:{os.minor(number)}"

def identity(info):
    return {"device":fsid(info.st_dev), "inode":info.st_ino, "uid":info.st_uid,
            "gid":info.st_gid, "mode":oct(stat.S_IMODE(info.st_mode)), "bytes":info.st_size,"allocated_blocks_512":info.st_blocks}

def loop_gate(record):
    b, d, m = [record[k] for k in ("backing", "loop", "mount")]
    require(re.fullmatch(r"/dev/loop[0-9]+", d["path"]) and d["major_minor"] == m["major_minor"],
            "ambiguous loop device")
    require(d["backing"] == b["path"] and d["back_device"] == b["device"]
            and d["back_inode"] == b["inode"], "wrong or stale backing")
    require(d["bytes"] == b["bytes"] == CAP and d["offset"] == d["size_limit"] == 0
            and b["mode"] == "0o600" and b["allocated_blocks_512"]*512 >= CAP, "incorrect bounded device")
    require(m["source"] == d["path"] and m["type"] == "ext4"
            and re.fullmatch(r"[0-9a-f-]{36}", m["uuid"])
            and set(m["options"])=={"vfs-options","fs-options"}
            and all(isinstance(v,str) and re.fullmatch(r"[A-Za-z0-9,._=:-]{1,512}",v) for v in m["options"].values())
            and "rw" in m["options"]["vfs-options"].split(","), "unexpected or incomplete mount")
    return record

def roots():
    key, attempt = os.environ["GITHUB_RUN_ID"], os.environ["GITHUB_RUN_ATTEMPT"]
    require(re.fullmatch(r"[0-9]{1,16}",key) and re.fullmatch(r"[0-9]{1,6}",attempt), "invalid job identity")
    label=f"df-oci-{key}-{attempt}"
    temp=Path(os.environ["RUNNER_TEMP"])
    require(temp.resolve(strict=True)==temp and re.fullmatch(r"/[A-Za-z0-9._/-]+", str(temp)), "unexpected runner path")
    return label, Path("/mnt")/label, temp/(label+".ext4"), temp/(label+".started")

def loop_rows(backing):
    # Read-only enumeration, retain only exact run-owned path or its deleted spelling.
    rows=json.loads(command("sudo","-n","losetup","--json","--list","--output",
        "NAME,MAJ:MIN,BACK-FILE,BACK-INO,BACK-MAJ:MIN,OFFSET,SIZELIMIT"))["loopdevices"] or []
    return [r for r in rows if r.get("back-file") in {str(backing),str(backing)+" (deleted)"}]

def read_loop(path, backing):
    rows=loop_rows(backing)
    require(len(rows)==1 and backing.resolve(strict=True)==backing and not backing.is_symlink(), "loop or backing ambiguous")
    b=backing.stat()
    require(stat.S_ISREG(b.st_mode) and b.st_uid==os.getuid() and b.st_nlink==1, "invalid backing identity")
    d=rows[0]
    require(re.fullmatch(r"/dev/loop[0-9]+",d["name"]), "invalid loop name")
    node=Path(d["name"]).stat()
    require(stat.S_ISBLK(node.st_mode) and fsid(node.st_rdev)==d["maj:min"], "loop node disagrees")
    mounts=json.loads(command("findmnt","--json","--mountpoint",str(path),"--output","SOURCE,TARGET,FSTYPE,MAJ:MIN,UUID,VFS-OPTIONS,FS-OPTIONS"))["filesystems"]
    require(len(mounts)==1 and mounts[0]["target"]==str(path), "mount absent or ambiguous")
    m=mounts[0]
    options={k:m[k] for k in ("vfs-options","fs-options")}
    require(all(isinstance(v,str) and re.fullmatch(r"[A-Za-z0-9,._=:-]{1,512}",v) for v in options.values()),
            "mount options unavailable or outside sanitized format")
    return loop_gate({"backing":{**identity(b),"path":str(backing)},
      "loop":{"path":d["name"],"major_minor":d["maj:min"],"backing":d["back-file"],
              "back_device":d["back-maj:min"],"back_inode":int(d["back-ino"]),
              "offset":int(d["offset"]),"size_limit":int(d["sizelimit"]),
              "bytes":int(command("sudo","-n","blockdev","--getsize64",d["name"]))},
      "mount":{"source":m["source"],"target":m["target"],"type":m["fstype"],"major_minor":m["maj:min"],"uuid":m["uuid"],"options":options}})

def canonical_config(raw):
    def no_duplicates(pairs):
        out={}
        for key,value in pairs:
            require(key not in out,"duplicate configuration key")
            out[key]=value
        return out
    return json.dumps(json.loads(raw,object_pairs_hook=no_duplicates),sort_keys=True,separators=(",",":")).encode()

def hashes(path, daemon=False):
    p=Path(path)
    try:
        p.lstat()
    except FileNotFoundError:
        return {"state":"absent","verified":True}
    except OSError:
        return {"state":"unavailable"}
    try:
        require(not p.is_symlink() and p.is_file(), "nonregular configuration")
        raw=data(p)
        result={"state":"hashed","sha256":hashlib.sha256(raw).hexdigest()}
        if daemon:
            result["canonical_json_sha256"]=hashlib.sha256(canonical_config(raw)).hexdigest()
        return result
    except (OSError,ValueError) as error:
        return {"state":getattr(error,"status","unavailable")}

GLOBAL_SCOPE = "docker-default-systemd-allowlist-v3"
UNIT_NAMES = ("docker.service","docker.socket")
COMMON_FIELDS = ("Id","LoadState","ActiveState","FragmentPath","DropInPaths")
SERVICE_FIELDS = COMMON_FIELDS + ("MainPID","Type","KillMode","Restart","ExecStart")
SOCKET_FIELDS = COMMON_FIELDS + ("Listen",)


class MissingUnitProperties(CaptureError):
    def __init__(self, missing, present_empty):
        super().__init__("UNAVAILABLE", "missing mandatory unit properties")
        self.details = {"missing_keys": missing, "present_empty_keys": present_empty}


def valid_hash(value):
    return isinstance(value,str) and bool(re.fullmatch(r"[0-9a-f]{64}",value))

def valid_unit_path(unit,path):
    return isinstance(path,str) and bool(re.fullmatch(
        r"/(?:(?:usr/)?lib|etc|run)/systemd/system/"+re.escape(unit)+r"(?:\.d/[A-Za-z0-9._-]+\.conf)?",path))

def collect_unit(unit, files):
    fields = SERVICE_FIELDS if unit.endswith(".service") else SOCKET_FIELDS
    props = sysprops(unit, fields)
    missing = sorted(set(fields)-props.keys())
    if missing:
        raise MissingUnitProperties(missing, sorted(key for key,value in props.items() if value==""))
    require(props["Id"]==unit and props["LoadState"]=="loaded" and props["ActiveState"] in {"active","inactive","failed"},
            "invalid effective unit identity")
    fragment=props["FragmentPath"]
    require(valid_unit_path(unit,fragment) and fragment.endswith("/"+unit),"invalid or missing fragment")
    dropins=props["DropInPaths"].split()
    require(len(dropins)<=12 and len(set(dropins))==len(dropins)
            and all(valid_unit_path(unit,p) and f"/{unit}.d/" in p for p in dropins),"invalid drop-in scope")
    for path in [fragment,*dropins]:
        digest=hashes(path)
        require(digest.get("state")=="hashed","unit fragment or drop-in unavailable")
        files[path]=digest
    # systemctl v255 omits an empty EnvironmentFiles struct array even
    # with --all. Query that specific typed property, never all environment.
    interface = "Service" if unit.endswith(".service") else "Socket"
    object_path = "/org/freedesktop/systemd1/unit/" + unit.replace(".", "_2e")
    try:
        environment_files = command("busctl","--system","get-property","org.freedesktop.systemd1",
                                    object_path,"org.freedesktop.systemd1."+interface,"EnvironmentFiles")
    except (OSError,CaptureError) as error:
        failure = MissingUnitProperties(["EnvironmentFiles"], [])
        failure.status = getattr(error,"status","UNAVAILABLE")
        raise failure from None
    require(environment_files.strip()=="a(sb) 0","environment files nonempty or unsupported typed format")
    info={"id":unit,"loaded":True,"active":props["ActiveState"],"fragment":fragment,
          "dropins":{"state":"verified","paths":dropins},"environment_files":"VERIFIED_EMPTY"}
    if unit.endswith(".service"):
        require(re.fullmatch(r"[0-9]{1,10}",props["MainPID"])
                and all(re.fullmatch(r"[a-z-]{1,30}",props[k]) for k in ("Type","KillMode","Restart")),
                "invalid daemon properties")
        exec_start=props["ExecStart"]
        require(exec_start and re.search(r"path=/usr/(?:s)?bin/dockerd\s*;",exec_start)
                and "--config-file" not in exec_start and "--host" not in exec_start
                and not re.search(r"(?:^|\s)-H\s+(?!fd://(?:\s|;|$))",exec_start),
                "daemon selection outside allowlist")
        # Whole-field digest only. No raw command argument values retained.
        info.update(pid=int(props["MainPID"]),type=props["Type"],kill_mode=props["KillMode"],restart=props["Restart"],
                    exec_start_sha256=hashlib.sha256(exec_start.encode()).hexdigest())
    else:
        require(re.fullmatch(r"/(?:var/)?run/docker\.sock \(Stream\)",props["Listen"]),"unknown listener")
        info["listener"]="/run/docker.sock"
    return info

def global_docker():
    units={}
    files={}
    incomplete=[]
    for unit in UNIT_NAMES:
        try:
            units[unit]=collect_unit(unit,files)
        except (CaptureError,OSError,ValueError) as error:
            incomplete.append({"unit":unit,"status":getattr(error,"status","UNAVAILABLE"),
                               "reason":str(error) if isinstance(error,CaptureError) or type(error) is ValueError else "unit probe read failed",
                               **getattr(error,"details",{})})
    cfg=hashes("/etc/docker/daemon.json",daemon=True)
    if cfg["state"] not in {"absent","hashed"}:
        incomplete.append({"unit":"daemon config","status":cfg["state"]})
    try:
        path=Path("/run/docker.sock")
        info=path.lstat()
        require(stat.S_ISSOCK(info.st_mode),"nonstandard Docker endpoint")
        socket={"path":str(path),**identity(info)}
    except (OSError,ValueError):
        socket=None
        incomplete.append({"unit":"socket identity","status":"UNAVAILABLE"})
    return {"scope":GLOBAL_SCOPE,"units":units,"files":files,"daemon_json":cfg,"socket":socket,"incomplete":incomplete,
            "environment_values":"NOT_COLLECTED"}

def complete_global(record):
    if (record.get("scope")!=GLOBAL_SCOPE or record.get("incomplete")!=[]
            or record.get("environment_values")!="NOT_COLLECTED"
            or set(record.get("units",{}))!=set(UNIT_NAMES)):
        return False
    files=record.get("files",{})
    referenced=set()
    for name,unit in record["units"].items():
        if (unit.get("id")!=name or unit.get("loaded") is not True or
                unit.get("environment_files")!="VERIFIED_EMPTY" or
                unit.get("active") not in {"active","inactive","failed"}):
            return False
        fragment=unit.get("fragment")
        drop=unit.get("dropins",{})
        paths=drop.get("paths")
        if (not valid_unit_path(name,fragment) or not fragment.endswith("/"+name) or drop.get("state")!="verified"
                or not isinstance(paths,list) or len(paths)>12 or len(set(paths))!=len(paths)
                or not all(valid_unit_path(name,p) and f"/{name}.d/" in p for p in paths)):
            return False
        referenced.update([fragment,*paths])
        if name.endswith(".service"):
            if (type(unit.get("pid")) is not int or unit["pid"]<0 or not valid_hash(unit.get("exec_start_sha256"))
                 or not all(isinstance(unit.get(k),str) and re.fullmatch(r"[a-z-]{1,30}",unit[k])
                            for k in ("type","kill_mode","restart"))):
                return False
        elif unit.get("listener")!="/run/docker.sock":
            return False
    if referenced!=set(files) or not all(x.get("state")=="hashed" and valid_hash(x.get("sha256")) for x in files.values()):
        return False
    sock=record.get("socket") or {}
    if (sock.get("path")!="/run/docker.sock" or not re.fullmatch(r"[0-9]+:[0-9]+",sock.get("device",""))
            or not re.fullmatch(r"0o[0-7]{1,4}",sock.get("mode",""))
            or not all(type(sock.get(k)) is int and sock[k]>=0 for k in ("inode","uid","gid","bytes")) or not sock["inode"]):
        return False
    config=record.get("daemon_json",{})
    if config.get("state")=="absent":
        return config.get("verified") is True
    return config.get("state")=="hashed" and valid_hash(config.get("sha256")) and valid_hash(config.get("canonical_json_sha256"))

MOUNT_DETAIL_ROWS = 64
MOUNT_DETAIL_BYTES = 16 * 1024


def mount_details():
    return {"schema": 1, "correlation": "within_record_only", "omitted_rows": 0, "rows": []}


def mount_detail_add(details, row):
    # Prioritize unexpected rows over context rows without changing the verdict.
    rows = details["rows"]
    rows.append(row)
    while len(rows) > MOUNT_DETAIL_ROWS or len(json.dumps(details, sort_keys=True).encode()) > MOUNT_DETAIL_BYTES - 16:
        index = next((i for i in range(len(rows)-1, -1, -1)
                      if rows[i]["disposition"] != "UNEXPECTED_WRITABLE"), len(rows)-1)
        rows.pop(index)
        details["omitted_rows"] += 1


def mount_access(options):
    modes = {"ro", "rw"}.intersection(options.split(","))
    require(len(modes) == 1, "ambiguous mount access flags")
    return modes.pop()


def mount_propagation(tags):
    result = {"unknown_tag_count": 0}
    for tag in tags:
        name, separator, value = tag.partition(":")
        if name in {"shared", "master", "propagate_from"}:
            require(separator and re.fullmatch(r"[1-9][0-9]{0,9}", value)
                    and name not in result, "invalid mount propagation")
            result[name] = int(value)
        elif tag == "unbindable":
            require("unbindable" not in result, "duplicate mount propagation")
            result["unbindable"] = True
        else:
            result["unknown_tag_count"] += 1
    if "unbindable" in result:
        result["role"] = "unbindable"
    elif "shared" in result:
        result["role"] = "shared_slave" if "master" in result else "shared"
    elif "master" in result:
        result["role"] = "slave"
    else:
        result["role"] = "unknown" if result["unknown_tag_count"] or "propagate_from" in result else "private"
    return result


def decode_mount_field(field):
    require(not re.search(r"\\(?!040|011|012|134)", field), "unsupported mount escape")
    return re.sub(r"\\([0-7]{3})", lambda match: chr(int(match[1], 8)), field)


def parse_mounts(raw, owned, device, details=None, record_index=0):
    require(raw.endswith("\n"), "incomplete mountinfo line")
    good_root=good_data=False
    unexpected=[]
    control=[]
    seen_ids=set()
    identities={k:{} for k in ("target","root","source")}

    def opaque(kind,value):
        table=identities[kind]
        return kind[0]+str(table.setdefault(value,len(table)+1))

    for n,line in enumerate(io.StringIO(raw),1):
        budget.remaining()
        require(n<=512,"mountinfo row limit")
        left, separator, right=line.partition(" - ")
        fields=left.split()
        tail=right.split()
        require(separator and len(fields)>=6 and len(tail)==3,"invalid mountinfo row")
        require(all(re.fullmatch(r"[1-9][0-9]{0,9}",value) for value in fields[:2])
                and re.fullmatch(r"[0-9]{1,10}:[0-9]{1,10}",fields[2]),"invalid mount identity")
        mount_id,parent_id=map(int,fields[:2])
        require(mount_id not in seen_ids,"duplicate mount ID")
        seen_ids.add(mount_id)
        kind=tail[0]
        require(re.fullmatch(r"[A-Za-z0-9_.+-]{1,64}",kind),"unsupported filesystem type field")
        root,target,source=map(decode_mount_field,(fields[3],fields[4],tail[1]))
        require(root.startswith("/") and target.startswith("/"),"invalid mount path shape")
        vfs=mount_access(fields[5])
        superblock=mount_access(tail[2])
        propagation=mount_propagation(fields[6:])
        on_data=target==owned or target.startswith(owned+"/")
        owned_device=fields[2]==device
        if target=="/" and vfs=="ro": good_root=True
        if target==owned and vfs=="rw" and owned_device and kind=="ext4": good_data=True
        if vfs=="ro":
            disposition="READ_ONLY_ROW"
        elif on_data and owned_device:
            disposition="OWNED_DEVICE_PATH_MATCH"
        elif (target=="/dev/shm" and kind=="tmpfs" or target=="/dev" and kind in {"devtmpfs","tmpfs"}
                or target=="/dev/pts" and kind=="devpts" or target=="/proc" and kind=="proc"
                or target=="/sys/fs/cgroup" and kind=="cgroup2"):
            disposition="EXISTING_CONTROL_MATCH"
            control.append([target,kind])
        else:
            disposition="UNEXPECTED_WRITABLE"
            unexpected.append({"target_sha256":hashlib.sha256(target.encode()).hexdigest(),"filesystem":kind})
        if details is not None:
            # IDs denote equality within this record only, not host path names.
            mount_detail_add(details,{
                "record_index":record_index,"mount_id":mount_id,"parent_id":parent_id,
                "filesystem":kind,"device":fields[2],"owned_device":owned_device,
                "target_id":opaque("target",target),"root_id":opaque("root",root),
                "source_id":opaque("source",source),"subtree_root":root!="/",
                "target_class":"ROOT" if target=="/" else "OWNED_ROOT" if target==owned
                    else "OWNED_DESCENDANT" if on_data else target if disposition=="EXISTING_CONTROL_MATCH" else "OTHER",
                "vfs_access":vfs,"superblock_access":superblock,"propagation":propagation,
                "disposition":disposition,
                "classification":"UNCLASSIFIED" if disposition=="UNEXPECTED_WRITABLE" else disposition,
            })
    require(good_root and good_data,"missing effective root-RO or owned device-RW")
    return {"root_ro":good_root,"own_data_rw":good_data,"allowed_control_mounts":control,
            "unexpected_writable":unexpected,"consistent":not unexpected}


def namespaces(label, mount, device):
    records=[]
    details=mount_details()
    for suffix in ("docker","ctrd"):
        unit=f"{label}-{suffix}.service"
        props=sysprops(unit,("MainPID","ControlGroup"))
        group=props.get("ControlGroup")
        require(group=="/system.slice/"+unit,"unexpected private cgroup")
        root=Path("/sys/fs/cgroup"+group)
        require(re.fullmatch(r"[1-9][0-9]{0,9}",props.get("MainPID","")),"missing private main PID property")
        snapshot=dict(cgroup_members(root,int(props["MainPID"])))
        pids=set().union(*snapshot.values())
        require(not pids.intersection(r["pid"] for r in records),"duplicate process across private units")
        owned_groups={pid:group+str(path.parent)[len(str(root)):]
                      for path, members in snapshot.items() for pid in members}
        for pid in sorted(pids):
            loc=f"/proc/{pid}"
            ns1=command("sudo","-n","readlink",loc+"/ns/mnt").strip()
            require(re.fullmatch(r"mnt:\[[0-9]+\]",ns1),"invalid mount namespace")
            membership=command("sudo","-n","cat",loc+"/cgroup").splitlines()
            require("0::"+owned_groups[pid] in membership,"cgroup membership changed")
            raw=command("sudo","-n","cat",loc+"/mountinfo")
            view=parse_mounts(raw,str(mount),device,details,len(records))
            require(command("sudo","-n","readlink",loc+"/ns/mnt").strip()==ns1,"namespace changed")
            records.append({"pid":pid,"cgroup":group,"mount_namespace":ns1,
                            "daemon_role":"docker" if suffix=="docker" else "containerd",**view})
        if dict(cgroup_members(root,int(props["MainPID"])))!=snapshot:
            raise CaptureError("INCOMPLETE_READ","cgroup membership changed during observation")
    return {"records":records,"device":device,"mount_details":details,"effective_mount_checks_passed":all(r["consistent"] for r in records),"scope":"owned cgroup membership at observation; detached descendants and races not ruled out"}

def namespace_device(mount):
    # Anchor namespace mount identity without depending on backing-file/loop
    # qualification. The assessor reconciles the device with the loop receipt.
    rows=json.loads(command("findmnt","--json","--mountpoint",str(mount),
                            "--output","TARGET,FSTYPE,MAJ:MIN"))["filesystems"]
    require(len(rows)==1 and rows[0]["target"]==str(mount) and rows[0]["fstype"]=="ext4",
            "namespace mount anchor unavailable")
    device=rows[0]["maj:min"]
    require(re.fullmatch(r"[0-9]+:[0-9]+", device)
            and fsid(mount.stat().st_dev)==device, "namespace mount anchor disagrees")
    return device


def absence(label,mount,backing,marker):
    mounts=command("findmnt","--raw","--noheadings","--output","TARGET").splitlines()
    require(not any(t==str(mount) or t.startswith(str(mount)+"/") for t in mounts),"owned mounts remain")
    require(not loop_rows(backing),"owned loop remains")
    require(not any(p.exists() or p.is_symlink() for p in (mount,backing,marker)),"owned paths remain")
    for suffix in ("docker","ctrd"):
        unit=f"{label}-{suffix}.service"
        raw=sysprops(unit,("LoadState","ActiveState","MainPID","ControlGroup"))
        require(raw["ActiveState"]=="inactive" and raw["MainPID"]=="0","owned unit still active")
        require(not Path("/sys/fs/cgroup/system.slice",unit).exists(),"owned cgroup survives")
    return {"loop":True,"mount":True,"backing":True,"marker":True,"inactive_units":True,"cgroups_absent":True,
            "detached_process_guarantee":"UNVERIFIED"}

def upload_source(directory):
    directory=Path(directory)
    require(directory.resolve(strict=True)==directory and not directory.is_symlink(),"invalid evidence source")
    files={}
    for entry in bounded_entries(directory,32):
        p=Path(entry.path)
        require(re.fullmatch(r"[A-Za-z0-9._-]{1,100}",p.name) and p.is_file() and not p.is_symlink(),"unsafe evidence member")
        raw=data(p)
        # The existing shell tail caps may truncate at exactly 256 KiB.
        require(len(raw)<MEMBER,"cap-sized output may be truncated")
        files[p.name]={"bytes":len(raw),"sha256":hashlib.sha256(raw).hexdigest(),"device":identity(p.stat())["device"]}
    require(0<len(files)<=32 and sum(x["bytes"] for x in files.values())<=TOTAL,"evidence aggregate invalid")
    return {"source":str(directory),"files":files,"aggregate_bytes":sum(x["bytes"] for x in files.values()),
            "actual_uploader_spool":"UNVERIFIED","classification":"outside data volume (runner temp)"}

def compare_global(before,after):
    if not complete_global(before) or not complete_global(after):
        return "UNAVAILABLE" if before==after else "DIFFERENCE_WITH_INCOMPLETE_COVERAGE"
    if before==after: return "BYTE_IDENTICAL_ALLOWLIST"
    if before.get("files")==after.get("files") and before.get("daemon_json")==after.get("daemon_json"):
        return "CONFIG_BYTES_UNCHANGED_RUNTIME_DIFFERENCE"
    a,b=before.get("daemon_json",{}),after.get("daemon_json",{})
    if (a.get("canonical_json_sha256") and a["canonical_json_sha256"]==b.get("canonical_json_sha256")
        and {k:v for k,v in before.items() if k!="daemon_json"}=={k:v for k,v in after.items() if k!="daemon_json"}):
        return "SEMANTICALLY_UNCHANGED_ALLOWLIST"
    return "CONFIG_DIFFERENCE"

def writer_assessment(trace):
    require(trace.get("complete") is True and not trace.get("dropped_events"),"writer attribution incomplete")
    require(type(trace.get("net_unattributed_bytes")) is int and trace["net_unattributed_bytes"]==0,
            "unattributed host-write delta")
    rows=trace.get("writers")
    require(isinstance(rows,list) and len(rows)<=4096,"invalid writer ledger")
    allowed={"loop-backing","runner-control","systemd-journal","checkout","evidence-control","bounded-uploader-control"}
    for row in rows:
        require(row["kind"] in allowed and row["pid"]>0 and row["bytes"]>=0
                and row.get("reviewed_path") is True,"unexpected host writer")
    return True

def uploader_assessment(trace):
    require(trace.get("complete") is True and trace.get("cleaned") is True,"uploader path evidence missing")
    paths=trace.get("paths")
    require(isinstance(paths,list) and len(paths)<=128,"unbounded uploader path evidence")
    require(paths or trace.get("no_staging_observed") is True,"no measured spool evidence")
    for item in paths:
        require(item.get("device")==trace.get("data_device") and item.get("device") is not None,
                "uploader staging outside the data volume")
    return True

def qualification(summary):
    # Future measured inputs must be independently verified before becoming true.
    required=("source_bound","exact_loop","effective_namespaces","global_identity","writer_attribution",
              "uploader_paths","cleanup","untruncated")
    return "PASS" if all(summary.get(k) is True for k in required) else "PARTIAL/UNVERIFIED"

def host_snapshot(evidence):
    _,_,backing,_=roots()
    runner=Path(os.environ["RUNNER_TEMP"])
    evidence=Path(evidence)
    require(evidence.resolve(strict=True)==runner/"df-oci-setup-evidence","unexpected evidence root")
    def counter(p):
        s=os.statvfs(p)
        return {"device":identity(p.stat())["device"],"available_bytes":s.f_bavail*s.f_frsize,"available_inodes":s.f_favail}
    return {"runner_temp":counter(runner),"evidence_root":counter(evidence),
            "backing_exists":backing.exists(),"writer_identity":"UNVERIFIED"}

def tool_identity():
    report={}
    left=256*1024**2
    for name in ("docker","dockerd","containerd","ctr","mkfs.ext4","losetup","findmnt","blockdev"):
        try:
            chosen=shutil.which(name)
            require(chosen,"tool absent")
            resolved=Path(chosen).resolve(strict=True)
            require(re.fullmatch(r"/usr/(?:s)?bin/[A-Za-z0-9._-]+",str(resolved)),"tool path outside allowlist")
            fd=os.open(resolved,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
            with os.fdopen(fd,"rb") as stream:
                before=os.fstat(stream.fileno())
                require(stat.S_ISREG(before.st_mode) and 0<before.st_size<=min(left,128*1024**2),"tool hash byte cap")
                sha=hashlib.sha256()
                total=0
                while True:
                    budget.remaining()
                    chunk=stream.read(1024**2)
                    if not chunk:
                        break
                    total+=len(chunk)
                    require(total<=before.st_size,"tool changed during hash")
                    sha.update(chunk)
                after=os.fstat(stream.fileno())
                require(total==before.st_size and before.st_mtime_ns==after.st_mtime_ns and before.st_ctime_ns==after.st_ctime_ns,
                        "incomplete tool bytes")
            left-=total
            report[name]={"status":"HASHED","path":str(resolved),"size":total,"sha256":sha.hexdigest()}
        except (OSError,ValueError) as err:
            report[name]={"status":getattr(err,"status","UNAVAILABLE")}
    return {"runner_arch":os.environ.get("RUNNER_ARCH"),"kernel_arch":platform.machine(),
            "collector_sha256":hashlib.sha256(data(Path(__file__))).hexdigest(),"tools":report,
            "buildx_plugin_and_dynamic_libraries":"NOT_QUALIFIED"}

OBSERVATIONS = {
    "runtime-baseline.json","global-before.json","global-after.json","global-comparison.json",
    "loop-before.json","loop-recovered.json","namespaces-before.json","namespaces-recovered.json","owned-absence.json","host-before.json","host-after.json","upload-source.json",
}
DIAGNOSTICS = {"baseline.txt","setup.log","backend.log","storage.log","recovered.log","cleanup.log","absence.log",
               "final.txt","global-before.txt","global-after.txt","coverage.txt"}

def assess(observations, outcomes, omitted):
    def records(name):
        value=observations.get(name,{})
        return value.get("result",{}) if value.get("status")=="OBSERVED" else {}
    def state(names, check):
        selected=[observations.get(n,{}) for n in names]
        flags=[x.get("status","MISSING") for x in selected]
        if "CONTRADICTORY" in flags:
            return "OBSERVED_FAIL"
        if all(f=="MISSING" for f in flags):
            return "NOT_OBSERVED"
        if any(f!="OBSERVED" for f in flags):
            return "NOT_QUALIFIED"
        try:
            return "OBSERVED_PASS" if check(*(records(n) for n in names)) else "OBSERVED_FAIL"
        except (KeyError,TypeError,ValueError,AttributeError):
            return "NOT_QUALIFIED"
    def namespace_ok(value):
        return value["effective_mount_checks_passed"] is True and bool(value["records"])
    def baseline_ok(b):
        return (b["runner_arch"]=="X64" and b["kernel_arch"]=="x86_64"
                and b["collector_sha256"]==hashlib.sha256(data(Path(__file__))).hexdigest()
                and set(b["tools"])=={"docker","dockerd","containerd","ctr","mkfs.ext4","losetup","findmnt","blockdev"}
                and all(item["status"]=="HASHED" and valid_hash(item["sha256"]) for item in b["tools"].values()))
    verdicts={
        "baseline_identity":state(["runtime-baseline.json"],baseline_ok),
        "loop_identity":state(["loop-before.json","loop-recovered.json"],
            lambda a,b:loop_gate(a)==loop_gate(b)),
        "scoped_namespaces":state(["namespaces-before.json","namespaces-recovered.json"],
            lambda a,b:namespace_ok(a) and namespace_ok(b) and a["device"]==b["device"]),
        "global_docker_allowlist":state(["global-before.json","global-after.json","global-comparison.json"],
            lambda a,b,c:compare_global(a,b)==c["classification"] and c["classification"] in
            {"BYTE_IDENTICAL_ALLOWLIST","SEMANTICALLY_UNCHANGED_ALLOWLIST"}),
        "final_resource_absence":state(["owned-absence.json"],lambda a:all(a[k] is True for k in
            ("loop","mount","backing","marker","inactive_units","cgroups_absent"))),
        "host_counters":state(["host-before.json","host-after.json"],lambda a,b:
            a["runner_temp"]["device"]==b["runner_temp"]["device"] and b["backing_exists"] is False),
        "uploader_source":state(["upload-source.json"],lambda a:bool(a["files"]) and 0<=a["aggregate_bytes"]<=TOTAL),
        "capture_integrity":"NOT_QUALIFIED" if omitted else "OBSERVED_PASS",
    }
    if verdicts["loop_identity"]==verdicts["scoped_namespaces"]=="OBSERVED_PASS":
        if any(records(f"loop-{stage}.json")["loop"]["major_minor"]!=
               records(f"namespaces-{stage}.json")["device"] for stage in ("before","recovered")):
            verdicts["scoped_namespaces"]="OBSERVED_FAIL"
    for step in ("setup","probe","cleanup"):
        verdicts[step+"_step"]={"success":"OBSERVED_PASS","failure":"OBSERVED_FAIL",
                                "skipped":"NOT_OBSERVED","cancelled":"NOT_QUALIFIED"}.get(outcomes.get(step),"NOT_QUALIFIED")
    mandatory_pass=all(v=="OBSERVED_PASS" for v in verdicts.values())
    verdicts.update(host_writer_attribution="NOT_OBSERVED",transient_detached_descendants="NOT_QUALIFIED",
                    actual_uploader_staging_cleanup="NOT_OBSERVED",multi_gib_upload_containment="NOT_OBSERVED",
                    forced_cancellation_vm_loss="NOT_OBSERVED")
    return {"schema":2,"property_verdicts":verdicts,"mandatory_observations_pass":mandatory_pass,
            "overall_qualification":"NOT_QUALIFIED","technical_step_outcomes":outcomes,
            "captures":{name:{k:observations.get(name,{}).get(k) for k in ("status","reason")} for name in sorted(OBSERVATIONS)},
            "omissions":omitted,"diagnostic_upload_cannot_change_verdict":True}

def prepare_assessment(source,destination,outcomes):
    require(source.resolve(strict=True)==source and not source.is_symlink(),"unexpected evidence source")
    destination.mkdir(mode=0o700)
    observations={}
    omitted=[]
    retained=0
    total=0
    try:
        for entry in bounded_entries(source,32):
            name=entry.name
            if name not in OBSERVATIONS | DIAGNOSTICS:
                omitted.append("unexpected_member")
                continue
            try:
                raw=data(entry.path)
                if len(raw)>=MEMBER or total+len(raw)>TOTAL-16384:
                    raise CaptureError("TRUNCATED_OR_OVERSIZED","diagnostic member or aggregate cap")
                if name in OBSERVATIONS:
                    value=json.loads(raw)
                    require(value.get("schema")==1 and value.get("status") in
                            {"OBSERVED","CONTRADICTORY","UNAVAILABLE","DEADLINE","LIMIT","TIMEOUT",
                             "INCOMPLETE_READ","TRUNCATED_OR_OVERSIZED","REAP_INCOMPLETE","COLLECTOR_EXIT"},"invalid receipt")
                    observations[name]=value
                with (destination/name).open("xb") as stream:
                    stream.write(raw)
                total+=len(raw)
                retained+=1
            except (ValueError,OSError,AttributeError) as error:
                omitted.append(name+":"+getattr(error,"status","INVALID_OR_UNAVAILABLE"))
    except (OSError,CaptureError) as error:
        omitted.append("directory:"+getattr(error,"status","UNAVAILABLE"))
    try:
        result=assess(observations,outcomes,omitted)
    except CaptureError as error:
        result={"schema":2,"overall_qualification":"NOT_QUALIFIED","mandatory_observations_pass":False,
                "assessment_error":error.status,"technical_step_outcomes":outcomes}
    result["diagnostic_members_retained"]=retained
    raw=json.dumps(result,sort_keys=True).encode()+b"\n"
    require(len(raw)<=16384 and total+len(raw)<=TOTAL,"assessment output cap")
    (destination/"assessment.json").write_bytes(raw)
    return result

def main():
    mode=sys.argv[1]
    cutoff=os.environ.get("OBSERVATION_PRE_CLEANUP_CUTOFF")
    if cutoff:
        budget.deadline=min(budget.deadline,float(cutoff))
        budget.remaining()
    if mode=="assess":
        source,destination=map(Path,sys.argv[2:4])
        outcomes={k:os.environ.get(k.upper()+"_OUTCOME","unknown") for k in ("setup","probe","cleanup")}
        prepare_assessment(source,destination,outcomes)
        return
    if mode=="tools":
        result=tool_identity()
    elif mode=="global":
        result=global_docker()
    elif mode=="global-diff":
        entries=[json.loads(data(p)) for p in sys.argv[2:4]]
        require(all(e.get("schema")==1 and e.get("status")=="OBSERVED" for e in entries),"global captures unavailable")
        result={"classification":compare_global(*(e["result"] for e in entries))}
    elif mode=="host":
        result=host_snapshot(sys.argv[2])
    elif mode=="upload-source":
        result=upload_source(sys.argv[2])
    else:
        label,mount,backing,marker=roots()
        if mode=="loop":
            result=read_loop(mount,backing)
        elif mode=="namespaces":
            result=namespaces(label,mount,namespace_device(mount))
        elif mode=="absence":
            result=absence(label,mount,backing,marker)
        else:
            raise ValueError("unknown observation")
    status="OBSERVED"
    if mode=="namespaces" and result["effective_mount_checks_passed"] is False:
        status="CONTRADICTORY"
    elif mode=="global" and not complete_global(result):
        status="UNAVAILABLE"
    elif mode=="global-diff":
        kind=result["classification"]
        if kind in {"CONFIG_DIFFERENCE","CONFIG_BYTES_UNCHANGED_RUNTIME_DIFFERENCE"}:
            status="CONTRADICTORY"
        elif kind in {"UNAVAILABLE","DIFFERENCE_WITH_INCOMPLETE_COVERAGE"}:
            status="UNAVAILABLE"
    raw=json.dumps({"schema":1,"status":status,"result":result},sort_keys=True)+"\n"
    require(len(raw.encode())<MEMBER,"receipt oversized")
    print(raw,end="")
    if status!="OBSERVED":
        raise SystemExit(1)

if __name__=="__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, IndexError, subprocess.SubprocessError) as error:
        status=getattr(error,"status","UNAVAILABLE")
        # Error text can contain host information. Keep only known controlled reasons.
        message=str(error) if isinstance(error,CaptureError) else "probe unavailable or invalid"
        print(json.dumps({"schema":1,"status":status,"reason":message}))
        raise SystemExit(1) from None
