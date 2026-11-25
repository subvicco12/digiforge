import gzip
import hashlib
import io
import json
import re
import sys
import tarfile

with tarfile.open(sys.argv[1]) as archive:
    members = {}
    for item in archive:
        name = item.name.removeprefix("./")
        if name in members:
            raise ValueError(f"Duplicate OCI archive member: {name}")
        members[name] = item

    def member(name):
        item = members[name]
        if not item.isreg():
            raise ValueError(f"Expected regular OCI member: {name}")
        return item

    def verified_blob(descriptor):
        digest = descriptor["digest"]
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
            raise ValueError(f"Unsupported OCI digest: {digest}")
        item = member("blobs/sha256/" + digest[7:])
        with archive.extractfile(item) as source:
            if item.size != descriptor["size"] or hashlib.file_digest(source, "sha256").hexdigest() != digest[7:]:
                raise ValueError(f"OCI blob mismatch: {digest}")
        return item

    descriptors = json.load(archive.extractfile(member("index.json")))["manifests"]
    if len(descriptors) != 1:
        raise ValueError("Expected one OCI manifest")
    descriptor = descriptors[0]
    manifest = json.load(archive.extractfile(verified_blob(descriptor)))
    config_item = verified_blob(manifest["config"])
    config = json.load(archive.extractfile(config_item))
    if (config["os"], config["architecture"]) != ("linux", "amd64"):
        raise ValueError("Expected linux/amd64 OCI configuration")
    diff_ids = config["rootfs"]["diff_ids"]
    if len(diff_ids) != len(manifest["layers"]):
        raise ValueError("OCI layer count differs from image configuration")

    def add_bytes(output, name, data):
        item = tarfile.TarInfo(name)
        item.size = len(data)
        output.addfile(item, io.BytesIO(data))

    config_name = manifest["config"]["digest"][7:] + ".json"
    layers = []
    with tarfile.open(sys.argv[2], "w") as output:
        with archive.extractfile(config_item) as source:
            add_bytes(output, config_name, source.read())
        for number, (layer, diff_id) in enumerate(zip(manifest["layers"], diff_ids)):
            if layer["mediaType"] != "application/vnd.oci.image.layer.v1.tar+gzip":
                raise ValueError(f"Unsupported OCI layer media type: {layer['mediaType']}")
            item = verified_blob(layer)
            with archive.extractfile(item) as source, gzip.GzipFile(fileobj=source) as raw:
                actual_diff_id = "sha256:" + hashlib.file_digest(raw, "sha256").hexdigest()
            if actual_diff_id != diff_id:
                raise ValueError(f"OCI layer {number} DiffID mismatch")
            name = f"layer-{number}.tar.gz"
            layer_info = tarfile.TarInfo(name)
            layer_info.size = item.size
            with archive.extractfile(item) as source:
                output.addfile(layer_info, source)
            layers.append(name)
        docker_manifest = [{"Config": config_name, "RepoTags": ["digiforge-runtime:verified"], "Layers": layers}]
        add_bytes(output, "manifest.json", json.dumps(docker_manifest).encode())
    print(json.dumps({"manifest": descriptor["digest"], "image_id": manifest["config"]["digest"]}))
