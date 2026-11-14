# Source SBOM

`SBOM.cdx.json` is a CycloneDX 1.6 inventory of this source repository, not an inventory of a deployed host. It records the standalone EWS bridge, optional Z-Push backend, tests and generator, their known requirements, and SHA-256 hashes of in-scope PHP/Python source files. Dependency versions, package URLs and the overall project license are not established and are deliberately omitted. An omitted dependency graph entry does not assert that a component has no dependencies.

## Evidence and scope

- `ews/bootstrap.php` loads the bridge and shared JMAP client/converters; `ews/src/Soap.php` uses DOM/libxml and `jmap/jmap_client.php` uses PHP cURL.
- `ews/src/SqliteSyncStore.php` requires PDO SQLite, POSIX and PHP `fsync`, plus a filesystem supporting durable fsync and SQLite WAL locking.
- `jmap/jmap.php` implements a backend for an **external, optional** Z-Push framework. The standalone bridge uses compatibility stubs and does not require Z-Push. Stalwart is a separate external JMAP service, not an included software dependency.
- nginx and PHP-FPM are optional deployment choices (see `docs/INSTALL.md`), not bundled software. Python 3 is needed for the generator and the optional live test; fork-based SQLite tests need PHP PCNTL and POSIX; the live test invokes the curl command-line tool.
- The headers of `jmap/jmap.php`, `jmap/jmap_client.php`, `jmap/jmap_contacts.php`, `jmap/jmap_calendar.php` and the excluded `jmap/config.php` claim AGPL-3.0 or AGPL version 3 and refer to a LICENSE file. No LICENSE file was found. This is file-level evidence, not proof of the license of the whole project, precise license variant, or upstream provenance. Obtain and review authoritative licensing information; no project-wide license is asserted here.
- No Composer manifest/lockfile, vendor directory or package lock was found in the inspected repository. The SBOM does not claim that all transitive or system dependencies are known.

## Updating and checking

After changing source or dependencies, review the requirements and license evidence, update `tools/generate_sbom.py` and this document as appropriate, then run:

```sh
python3 tools/generate_sbom.py
python3 tools/generate_sbom.py --check
```

Commit the regenerated SBOM with the changes. When enabled, CI runs `--check` on pushes and pull requests. Generation uses Python's standard library, a stable ordering and no timestamp or commit identifier. It does not inspect host packages or read configuration, credentials or server data.

Discovery scans the repository tree for PHP and Python files, including untracked files. It excludes symlinks, `.git`, virtual environments, caches, configuration and credential/secret directories, and names containing `config`, `credential`, `secret`, `password`, `passwd`, `token` or `.env` (case-insensitive). Excluded files, other file types and changes within them are not hashed or detected; review them separately without publishing sensitive data. Source file symlinks outside excluded directories cause an error; directory symlinks are not traversed.

The generator fails on recognized new dependency manifests, lockfiles, vendor/node_modules directories or LICENSE/LICENCE/COPYING files outside excluded paths. Review their contents securely, update the inventory strategy and dependency/license evidence before regenerating; it does not automatically parse them. This detector covers common filenames only, does not detect changes inside excluded paths, and cannot discover every dependency or license change. Dependency changes in source require human review even when hashes change. Do not resolve a detector error by merely ignoring evidence.

For an actual deployment, separately generate and maintain a deployment SBOM from the host's installed packages, runtimes and extensions, with versions and licenses supported by package-manager or other authoritative evidence. Review operational configuration privately; never add credentials or server details to a public SBOM.
