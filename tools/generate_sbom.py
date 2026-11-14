#!/usr/bin/env python3
"""Generate the repository's deterministic CycloneDX source SBOM."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / "SBOM.cdx.json"
SKIP_DIRS = {".git", ".venv", "venv", "__pycache__", ".pytest_cache",
             "config", "configs", "credential", "credentials", "secret", "secrets"}
VENDOR_DIRS = {"vendor", "node_modules"}
MANIFESTS = {"composer.json", "composer.lock", "package.json", "package-lock.json",
             "npm-shrinkwrap.json", "yarn.lock", "pnpm-lock.yaml", "pyproject.toml",
             "poetry.lock", "pipfile", "pipfile.lock", "pdm.lock", "uv.lock",
             "setup.py", "setup.cfg", "cargo.toml", "cargo.lock", "go.mod", "go.sum"}
SENSITIVE_WORDS = ("config", "credential", "secret", "password", "passwd", "token", ".env")


def is_sensitive(name):
    return any(word in name.lower() for word in SENSITIVE_WORDS)


def is_review_file(name):
    name = name.lower()
    return (name in MANIFESTS or name.startswith("requirements") and name.endswith((".txt", ".in"))
            or name in {"license", "licence", "copying"}
            or name.startswith(("license.", "licence.", "copying.")))


def discover():
    sources = []
    review = []
    for directory, dirs, files in os.walk(ROOT, followlinks=False):
        base = Path(directory)
        kept = []
        for name in sorted(dirs):
            path = base / name
            lower = name.lower()
            if lower in VENDOR_DIRS:
                review.append(path.relative_to(ROOT).as_posix() + "/")
            elif lower not in SKIP_DIRS and not is_sensitive(name) and not path.is_symlink():
                kept.append(name)
        dirs[:] = kept
        for name in sorted(files):
            path = base / name
            relative = path.relative_to(ROOT).as_posix()
            if is_review_file(name) and not is_sensitive(name):
                review.append(relative)
            elif not is_sensitive(name) and path.suffix.lower() in {".php", ".py"}:
                if path.is_symlink():
                    raise ValueError("Source file is a symlink; review it before inventorying: " + relative)
                sources.append((relative, hashlib.sha256(path.read_bytes()).hexdigest()))
    if review:
        raise ValueError("Dependency, vendor, or license evidence requires manual review: "
                         + ", ".join(sorted(review)) + ". Update the SBOM generator and documentation.")
    return sorted(sources)


def component(ref, name, description, scope="required", kind="library"):
    return {"type": kind, "bom-ref": ref, "name": name, "description": description, "scope": scope}


def make_bom():
    items = [
        component("bridge", "Standalone EWS bridge", "Repository implementation of the standalone EWS bridge.", kind="application"),
        component("backend", "Z-Push JMAP backend", "Repository backend for use with an external Z-Push installation.", "optional", "application"),
        component("tests", "Repository tests", "Optional repository tests, including live integration checks.", "optional", "application"),
        component("generator", "SBOM generator", "Repository SBOM generation tool.", "optional", "application"),
        component("php", "PHP", "PHP runtime; installed version is not established by this source inventory.", kind="framework"),
        component("php-curl", "PHP cURL extension", "HTTP transport used by the JMAP client."),
        component("php-dom-libxml", "PHP DOM and libxml", "XML parsing and generation used by the EWS bridge."),
        component("pdo-sqlite", "PHP PDO SQLite", "PDO SQLite driver used by the sync store."),
        component("php-posix", "PHP POSIX extension", "POSIX functions used by the sync store."),
        component("php-fsync", "PHP fsync support", "PHP fsync function and a filesystem supporting durable fsync and SQLite WAL locking.", kind="platform"),
        component("z-push", "Z-Push", "External framework required only when running the Z-Push JMAP backend; not bundled.", "optional", "framework"),
        component("nginx", "nginx", "Optional deployment choice; not bundled.", "optional", "application"),
        component("php-fpm", "PHP-FPM", "Optional deployment choice; not bundled.", "optional", "application"),
        component("python", "Python 3", "Standard-library runtime for the generator and optional live test.", "optional", "framework"),
        component("php-pcntl", "PHP PCNTL extension", "Required by fork-based SQLite tests only.", "optional"),
        component("curl-cli", "curl command-line tool", "Required by the optional live integration test.", "optional", "application"),
    ]
    graph = {
        "repository": ["bridge", "backend", "tests", "generator", "nginx", "php-fpm"],
        "bridge": ["php", "php-curl", "php-dom-libxml", "pdo-sqlite", "php-posix", "php-fsync"],
        "backend": ["php", "php-curl", "z-push"],
        "tests": ["bridge", "python", "php-pcntl", "php-posix", "curl-cli"],
        "generator": ["python"],
    }
    for path, digest in discover():
        ref = "file:" + path
        description = "Repository source file."
        if path in {"jmap/jmap.php", "jmap/jmap_client.php", "jmap/jmap_calendar.php", "jmap/jmap_contacts.php"}:
            description += " Header claims AGPL-3.0; see docs/SBOM.md for limits of this evidence."
        items.append({"type": "file", "bom-ref": ref, "name": path, "description": description,
                      "hashes": [{"alg": "SHA-256", "content": digest}]})
        if path.startswith("ews/") or path in {"jmap/jmap_client.php", "jmap/jmap_calendar.php", "jmap/jmap_contacts.php"}:
            graph["bridge"].append(ref)
        if path.startswith("jmap/"):
            graph["backend"].append(ref)
        if path.startswith("tests/"):
            graph["tests"].append(ref)
        if path == "tools/generate_sbom.py":
            graph["generator"].append(ref)
        if not path.startswith(("ews/", "jmap/", "tests/")) and path != "tools/generate_sbom.py":
            graph["repository"].append(ref)
    return {
        "bomFormat": "CycloneDX",
        "specVersion": "1.6",
        "version": 1,
        "metadata": {"component": {"type": "application", "bom-ref": "repository",
                                   "name": "Stalwart JMAP-EWS Bridge source repository",
                                   "description": "Source inventory; project license and exact dependency versions are unknown."}},
        "components": items,
        "dependencies": [{"ref": ref, "dependsOn": sorted(set(deps))} for ref, deps in sorted(graph.items())],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="fail if the committed SBOM is stale")
    args = parser.parse_args()
    try:
        content = json.dumps(make_bom(), indent=2, ensure_ascii=True) + "\n"
        if args.check:
            if not OUTPUT.is_file() or OUTPUT.read_bytes() != content.encode("utf-8"):
                print("SBOM is missing or stale. Run python3 tools/generate_sbom.py and commit SBOM.cdx.json.", file=sys.stderr)
                return 1
            print("SBOM is up to date.")
        else:
            OUTPUT.write_text(content, encoding="utf-8", newline="\n")
            print("Updated SBOM.cdx.json.")
    except (OSError, ValueError) as error:
        print("SBOM generation failed: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
