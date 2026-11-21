"""Materialize only locked public input bytes into an isolated build context."""
import concurrent.futures
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
from urllib.parse import urlparse
import zipfile

source = Path(__file__).resolve().parent
context, downloads = map(Path, sys.argv[1:])
lock_path = source / "input-lock.json"
lock = json.loads(lock_path.read_bytes())
if lock.get("schema") != 1 or lock.get("approved_for_execution") is not True:
    raise SystemExit("Input lock is inert and requires independent approval")
if lock.get("platform") != "linux/amd64":
    raise SystemExit("Unsupported runtime platform")

def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()

def sha(value):
    return isinstance(value, str) and bool(re.fullmatch(r"[0-9a-f]{64}", value))

def download(item):
    url = urlparse(item["url"])
    if (url.scheme != "https" or url.hostname not in {"deb.debian.org", "security.debian.org", "codeload.github.com"}
            or url.username or url.password or not sha(item["sha256"])):
        raise ValueError("Unapproved input URL or digest")
    path = downloads / item["sha256"]
    if not path.exists():
        subprocess.run(["curl", "--fail", "--silent", "--show-error", "--proto", "=https",
                        "--retry", "2", "--max-time", "120", "--max-filesize", "134217728",
                        "-o", str(path), item["url"]], check=True)
    if digest(path) != item["sha256"]:
        raise ValueError("Input digest mismatch")
    return path

def path_parts(name):
    parts = name.rstrip("/").split("/")
    if (len(name) > 400 or any(ord(char) < 32 or char == chr(92) for char in name)
            or any(p in ("", ".", "..") for p in parts) or PurePosixPath(name).is_absolute()):
        raise ValueError("Unsafe source archive path")
    return parts

def write(stream, dest, size, executable=False):
    if size > 64 * 1024**2:
        raise ValueError("Oversized source member")
    dest.parent.mkdir(parents=True, exist_ok=True)
    with dest.open("xb") as out:
        shutil.copyfileobj(stream, out)
    if dest.stat().st_size != size:
        raise ValueError("Source member size mismatch")
    dest.chmod(0o755 if executable else 0o644)

dockerfile = (source / "Dockerfile").read_text()
if dockerfile.splitlines()[1] != "FROM --platform=linux/amd64 " + lock["base"]["ref"]:
    raise SystemExit("Base image does not match input lock")
if digest(source / "composer.lock") != lock["composer"]["lock_sha256"]:
    raise SystemExit("Composer lock mismatch")
context.mkdir(mode=0o700)
downloads.mkdir(mode=0o700)
records = lock["debian"] + list(lock["wordpress"].values()) + lock["composer"]["distributions"]
unique = {r["sha256"]: r for r in records}
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    list(pool.map(download, unique.values()))
(context / "debs").mkdir()
with (context / "debs.tsv").open("w") as out:
    for item in lock["debian"]:
        if not re.fullmatch(r"[a-z0-9][a-z0-9+.-]+", item["name"]) or not re.fullmatch(r"[0-9][A-Za-z0-9.+:~\-]+", item["version"]):
            raise ValueError("Invalid Debian lock entry")
        shutil.copyfile(downloads / item["sha256"], context / "debs" / (item["name"] + ".deb"))
        out.write(item["name"] + "\t" + item["version"] + "\n")
for component in ("core", "tests"):
    record = lock["wordpress"][component]
    if not record["url"].endswith("/" + record["revision"]):
        raise ValueError("WordPress revision mismatch")
    root = context / ("wordpress-" + component)
    total = count = 0
    with tarfile.open(downloads / record["sha256"], "r:gz") as archive:
        for member in archive:
            parts = path_parts(member.name)
            if component == "tests" and parts[1:3] != ["tests", "phpunit"]:
                continue
            if not member.isfile():
                if not member.isdir():
                    raise ValueError("Unsafe WordPress archive member")
                continue
            if component == "tests":
                parts = parts[2:]
            count += 1
            total += member.size
            if count > 10000 or total > 512 * 1024**2:
                raise ValueError("WordPress archive expansion exceeds limits")
            with archive.extractfile(member) as entry:
                write(entry, root.joinpath(*parts[1:]), member.size)
for r in lock["composer"]["distributions"]:
    if not re.fullmatch(r"[a-z0-9-]+/[a-z0-9-]+", r["name"]) or not r["url"].endswith("/" + r["revision"]):
        raise ValueError("Invalid Composer identity")
    root = context / "vendor" / r["name"]
    total = 0
    with zipfile.ZipFile(downloads / r["sha256"]) as archive:
        if len(archive.infolist()) > 10000:
            raise ValueError("Too many Composer archive members")
        for member in archive.infolist():
            parts = path_parts(member.filename)
            if member.is_dir():
                continue
            if ((member.external_attr >> 16) & 0o170000) not in {0, 0o100000}:
                raise ValueError("Composer symlink denied")
            total += member.file_size
            if total > 256 * 1024**2:
                raise ValueError("Composer archive expansion exceeds limits")
            with archive.open(member) as entry:
                write(entry, root.joinpath(*parts[1:]), member.file_size)
for name, expected in lock["composer"]["generated"].items():
    path_parts(name)
    local = source / "vendor-generated" / name
    if local.is_symlink() or digest(local) != expected:
        raise ValueError("Generated Composer file mismatch")
    with local.open("rb") as stream:
        write(stream, context / "vendor" / name, local.stat().st_size, name.startswith("bin/"))
for name in ("wordpress-core/wp-load.php", "wordpress-tests/includes/bootstrap.php", "wordpress-tests/includes/install.php",
             "vendor/bin/phpunit", "vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php"):
    if not (context / name).is_file():
        raise ValueError("Required runtime entry missing: " + name)
for file in sorted((source / "fixture").iterdir()):
    if file.is_symlink() or not file.is_file():
        raise ValueError("Unsafe fixture source")
    with file.open("rb") as stream:
        write(stream, context / "fixture" / file.name, file.stat().st_size)
shutil.copyfile(source / "Dockerfile", context / "Dockerfile")
members = {p.relative_to(context).as_posix(): digest(p) for p in sorted(context.rglob("*")) if p.is_file()}
manifest = {"schema": 1, "platform": lock["platform"], "base": lock["base"],
            "input_lock_sha256": digest(lock_path), "members": members}
(context / "source-manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
