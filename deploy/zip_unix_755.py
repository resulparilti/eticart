"""ZIP içindeki her dosya/klasöre Unix 755 izni yazar (cPanel Extract için)."""
from __future__ import annotations

import os
import stat
import sys
import time
import zipfile

MODE_755 = 0o755 << 16
DIR_BIT = 0x10


def add_dir(zf: zipfile.ZipFile, arcname: str, mtime: float) -> None:
    if not arcname.endswith("/"):
        arcname += "/"
    info = zipfile.ZipInfo(arcname, time.localtime(mtime)[:6])
    info.external_attr = MODE_755 | DIR_BIT
    info.compress_type = zipfile.ZIP_STORED
    zf.writestr(info, b"")


def add_file(zf: zipfile.ZipFile, path: str, arcname: str) -> None:
    info = zipfile.ZipInfo(arcname, time.localtime(os.path.getmtime(path))[:6])
    info.external_attr = MODE_755
    info.compress_type = zipfile.ZIP_DEFLATED
    with open(path, "rb") as fh:
        zf.writestr(info, fh.read())


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: zip_unix_755.py <staging_dir> <zip_path>", file=sys.stderr)
        return 2

    staging = os.path.abspath(sys.argv[1])
    zip_path = os.path.abspath(sys.argv[2])
    if not os.path.isdir(staging):
        print(f"staging yok: {staging}", file=sys.stderr)
        return 1

    os.makedirs(os.path.dirname(zip_path), exist_ok=True)
    if os.path.exists(zip_path):
        os.remove(zip_path)

    seen_dirs: set[str] = set()

    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, allowZip64=True) as zf:
        for root, dirs, files in os.walk(staging):
            rel_root = os.path.relpath(root, staging)
            if rel_root == ".":
                dir_arc = ""
            else:
                dir_arc = rel_root.replace("\\", "/")
                parts = dir_arc.split("/")
                acc = []
                for part in parts:
                    acc.append(part)
                    current = "/".join(acc)
                    if current not in seen_dirs:
                        add_dir(zf, current, os.path.getmtime(os.path.join(staging, *acc)))
                        seen_dirs.add(current)

            for name in files:
                full = os.path.join(root, name)
                if dir_arc:
                    arc = f"{dir_arc}/{name}"
                else:
                    arc = name
                add_file(zf, full, arc)

    print(zip_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
