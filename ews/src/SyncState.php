<?php

class EwsSyncException extends RuntimeException {
    public function __construct(public string $ewsCode, string $message) { parent::__construct($message); }
}

/**
 * Private immutable principal/account/folder-bound checkpoints, containing only IDs/hashes.
 * A checkpoint expires 90 days after creation (mtime is never refreshed). Cleanup runs
 * under the same lock as readers/writers, scans at most 20,000 entries and removes at
 * most 128 eligible files per save. Existing checkpoints remain retryable until expiry.
 * An expired/old token is invalid: the client must discard its local folder sync cache
 * and perform a fresh initial sync without SyncState, rather than applying it as a delta.
 * A quiet live sync gets a new immutable checkpoint after 60 days to avoid expiry.
 * The state directory must be private (0700), worker-owned, stable across restarts,
 * shared by workers serving the same clients, and support flock and atomic rename.
 * PHP-FPM needs ext-posix. Failures and storage limits produce an error, not an empty sync.
 */
class EwsSyncStateStore {
    private string $dir;
    private const MAX_BYTES = 8388608;
    private const MAX_FILES = 10000;
    private const MAX_SCAN = 20000;
    private const MAX_CLEAN = 128;
    private const LIFETIME = 90 * 86400;
    private const REFRESH_AFTER = 60 * 86400;

    public function __construct() {
        if (!defined('EWS_SYNC_STATE_DIR') || !is_string(EWS_SYNC_STATE_DIR) || EWS_SYNC_STATE_DIR === '' || !function_exists('posix_geteuid')) {
            throw new RuntimeException('Sync state storage unavailable');
        }
        $this->dir = rtrim(EWS_SYNC_STATE_DIR, '/');
        if ($this->dir === '') throw new RuntimeException('Invalid sync state directory');
        if (!is_dir($this->dir)) {
            $old = umask(0077);
            try { @mkdir($this->dir, 0700, false); } finally { umask($old); }
        }
        clearstatcache(true, $this->dir);
        $s = @lstat($this->dir);
        if (!$s || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0777) !== 0700 || $s['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Sync state directory must be private and owned by the PHP worker');
        }
    }

    private function lock(int $operation) {
        $path = $this->dir . '/.lock';
        $old = umask(0077);
        try { $lock = @fopen($path, 'c'); } finally { umask($old); }
        if (!$lock) throw new RuntimeException('Cannot open sync state lock');
        $s = fstat($lock);
        clearstatcache(true, $path);
        $p = @lstat($path);
        if (!$s || !$p || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 ||
            $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || $p['dev'] !== $s['dev'] || $p['ino'] !== $s['ino'] ||
            !flock($lock, $operation)) {
            fclose($lock);
            throw new RuntimeException('Unsafe or unavailable sync state lock');
        }
        return $lock;
    }

    public function load(string $token, string $binding): array {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Malformed sync state');
        $path = $this->dir . '/' . $token;
        $lock = $this->lock(LOCK_SH);
        try {
            clearstatcache(true, $path);
            $s = @lstat($path);
            if (!$s) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Unknown or expired sync state');
            if (($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || $s['size'] > self::MAX_BYTES) {
                throw new RuntimeException('Unsafe sync state file');
            }
            $age = time() - $s['mtime'];
            if ($age >= self::LIFETIME) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Expired sync state');
            $json = @file_get_contents($path);
            if ($json === false) throw new RuntimeException('Cannot read sync state');
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
        try { $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new EwsSyncException('ErrorInvalidSyncStateData', 'Corrupt sync state'); }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_string($data['binding'] ?? null) || !hash_equals($binding, $data['binding']) ||
            !isset($data['base'], $data['offset']) || !is_array($data['base']) || !is_int($data['offset']) || $data['offset'] < 0 ||
            !array_key_exists('target', $data) || ($data['target'] !== null && !is_array($data['target'])) ||
            ($data['target'] === null && $data['offset'] !== 0)) {
            throw new EwsSyncException('ErrorInvalidSyncStateData', 'Invalid or mismatched sync state');
        }
        foreach ([$data['base'], $data['target'] ?? []] as $map) {
            if (count($map) > 10000) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Sync state limit exceeded');
            foreach ($map as $key => $hash) {
                if (!is_string($key) || !str_starts_with($key, 'i') || strlen($key) > 1369 ||
                    ($id = base64_decode(substr($key, 1), true)) === false || $id === '' || 'i' . base64_encode($id) !== $key ||
                    !is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                    throw new EwsSyncException('ErrorInvalidSyncStateData', 'Corrupt sync state');
                }
            }
        }
        return ['base' => $data['base'], 'target' => $data['target'], 'offset' => $data['offset'], 'refresh' => $age >= self::REFRESH_AFTER];
    }

    /** Caller holds LOCK_EX. Never remove unfamiliar, modified, or unsafe entries. */
    private function cleanup(): int {
        $dir = @opendir($this->dir);
        if (!$dir) throw new RuntimeException('Cannot scan sync state storage');
        $scanned = 0; $count = 0; $removed = 0; $cutoff = time() - self::LIFETIME;
        try {
            while (($name = readdir($dir)) !== false) {
                if ($name === '.' || $name === '..' || $name === '.lock') continue;
                if (++$scanned > self::MAX_SCAN) throw new RuntimeException('Sync state scan limit exceeded');
                $count++;
                if ($removed >= self::MAX_CLEAN || !preg_match('/\A[a-f0-9]{64}\z/D', $name)) continue;
                $path = $this->dir . '/' . $name;
                clearstatcache(true, $path);
                $first = @lstat($path);
                if (!$first || ($first['mode'] & 0170000) !== 0100000 || ($first['mode'] & 0777) !== 0600 ||
                    $first['uid'] !== posix_geteuid() || $first['nlink'] !== 1 || $first['mtime'] > $cutoff) continue;
                clearstatcache(true, $path);
                $second = @lstat($path);
                if (!$second) continue;
                foreach (['dev', 'ino', 'mode', 'uid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                    if ($first[$field] !== $second[$field]) continue 2;
                }
                if (!@unlink($path)) throw new RuntimeException('Cannot remove expired sync state');
                $removed++; $count--;
            }
        } finally { closedir($dir); }
        return $count;
    }

    public function save(string $binding, array $state): string {
        $json = json_encode(['v' => 1, 'binding' => $binding] + $state, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) throw new RuntimeException('Sync state size limit exceeded');
        $lock = $this->lock(LOCK_EX);
        $temp = null;
        try {
            if ($this->cleanup() >= self::MAX_FILES) throw new RuntimeException('Sync state storage limit exceeded');
            $token = bin2hex(random_bytes(32));
            $temp = $this->dir . '/.' . bin2hex(random_bytes(32));
            $old = umask(0077);
            try { $file = @fopen($temp, 'x'); } finally { umask($old); }
            if (!$file) throw new RuntimeException('Cannot create sync state');
            try {
                $written = 0; $length = strlen($json);
                while ($written < $length) {
                    $n = fwrite($file, substr($json, $written));
                    if ($n === false || $n === 0) throw new RuntimeException('Cannot write sync state');
                    $written += $n;
                }
                if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Cannot flush sync state');
            } finally { fclose($file); }
            if (file_exists($this->dir . '/' . $token) || !@rename($temp, $this->dir . '/' . $token)) throw new RuntimeException('Cannot publish sync state');
            $temp = null;
            return $token;
        } finally {
            if ($temp !== null) @unlink($temp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}

/**
 * Standalone immutable generation storage. Not wired into SyncFolderItems yet.
 *
 * publish(principal, account, folder, records, diff) returns an opaque checkpoint
 * at offset 0. A record is ['id'=>string, 'changeKey'=>string, 'object'=>array];
 * a diff entry is ['kind'=>'Create'|'Update'|'Delete', 'id'=>string]. Records and
 * diff retain their caller-provided order; Create/Update refer to a record, Delete
 * must not, and each ID occurs at most once in each list. The caller must calculate
 * a correct diff, a consistent JMAP scan and the changeKey (e.g. itemChangeKey);
 * this storage layer does not verify the key or a diff against the previous scan.
 * readPage(...) returns changes with their stored record (null for Delete), next
 * checkpoint, and done. readGeneration(...) returns the complete immutable inputs.
 * Repeating a checkpoint and page size yields the same page and next checkpoint.
 *
 * Names must identify an authenticated principal and a capability account uniquely
 * across JMAP servers; include the server identity in these strings if necessary.
 * Checkpoints, including intermediate ones, are retryable until generation expiry.
 * After expiry the caller must reset its local folder cache and start an initial sync.
 * This store does not choose or build the next generation or resolve EWS shapes.
 * Snapshots/diffs have manifest SHA-256 checksums; 48-character checkpoints encode
 * a random generation ID and offset with a truncated HMAC bound to the identity.
 * No per-page files are written. Cleanup runs under the same per-principal lock as
 * publication; it removes abandoned staging directories and expired generations.
 * If a worker dies after rename but before returning a token, its fully published
 * orphan counts against quota until expiry (it cannot safely be distinguished from
 * an acknowledged generation). Expired data may remain until that principal is
 * accessed for publication or gc() is called. No eviction of live retries occurs.
 * Limits are constructor options: ttl seconds, principalBytes/generationBytes of
 * file contents, principalGenerations/folderGenerations, folders, scanEntries.
 * Encoding also applies a conservative escape-size estimate; PHP memory must allow
 * multiple copies of a generation plus decoded objects. Quota excludes filesystem
 * metadata, directories and lock files; capacity monitoring remains necessary.
 *
 * Data contains mailbox contents. Use a dedicated root, not the legacy checkpoint
 * directory. The configured absolute root must be outside the webroot, durable,
 * shared by relevant FPM workers, 0700 and worker-owned. Files are
 * 0600. ext-posix, directory fsync, flock and same-filesystem atomic rename are
 * mandatory. Clocks across workers must be synchronized. Backups and filesystem
 * encryption/access controls are operator duties.
 */
class EwsSyncGenerationStore {
    private string $root;
    private array $limits;
    private Closure $clock;
    private ?Closure $fault;

    public function __construct(string $root, array $limits = [], ?callable $clock = null, ?callable $fault = null) {
        $defaults = ['ttl' => 90 * 86400, 'principalBytes' => 268435456, 'generationBytes' => 67108864,
            'principalGenerations' => 64, 'folderGenerations' => 16, 'folders' => 128, 'scanEntries' => 4096];
        if (array_diff_key($limits, $defaults)) throw new InvalidArgumentException('Unknown generation limit');
        $this->limits = $limits + $defaults;
        foreach ($this->limits as $limit) if (!is_int($limit) || $limit < 1) throw new InvalidArgumentException('Invalid generation limit');
        if (!function_exists('posix_geteuid') || !function_exists('fsync') || $root === '' || $root[0] !== '/') throw new RuntimeException('Generation storage requires an absolute path, posix and fsync');
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn(): int => time();
        $this->fault = $fault ? Closure::fromCallable($fault) : null;
        $root = rtrim($root, '/');
        if ($root === '') throw new RuntimeException('Invalid generation root');
        $this->makeDir($root);
        $real = realpath($root);
        if ($real === false) throw new RuntimeException('Cannot resolve generation root');
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($docroot)) throw new RuntimeException('Invalid webroot');
        if ($docroot !== '') {
            $web = realpath($docroot);
            if ($web === false) throw new RuntimeException('Cannot resolve webroot');
            if ($real === $web || str_starts_with($real, rtrim($web, '/') . '/')) throw new RuntimeException('Generation root is inside webroot');
        }
        $this->syncDir(dirname($real));
        $this->root = $real;
    }

    private function now(): int {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - $this->limits['ttl']) throw new RuntimeException('Invalid generation clock');
        return $now;
    }

    private function makeDir(string $path): void {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $old = umask(0077);
            try { @mkdir($path, 0700); } finally { umask($old); }
        }
        clearstatcache(true, $path);
        $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0777) !== 0700 || $s['uid'] !== posix_geteuid()) throw new RuntimeException('Unsafe generation directory');
    }

    private function syncDir(string $path): void {
        $f = @fopen($path, 'r');
        if (!$f) throw new RuntimeException('Cannot open generation directory for sync');
        try { if (!fsync($f)) throw new RuntimeException('Cannot sync generation directory'); }
        finally { fclose($f); }
    }

    private function identity(string $value): void {
        if ($value === '' || strlen($value) > 4096) throw new InvalidArgumentException('Invalid generation identity');
    }

    private function principalDir(string $principal): string {
        $this->identity($principal);
        $path = $this->root . '/p-' . hash('sha256', $principal);
        $this->makeDir($path);
        $this->syncDir($this->root);
        return $path;
    }

    private function folderName(string $account, string $folder): string {
        $this->identity($account); $this->identity($folder);
        return 'f-' . hash('sha256', json_encode([$account, $folder], JSON_THROW_ON_ERROR));
    }

    private function binding(string $principal, string $account, string $folder): string {
        return hash('sha256', json_encode([$principal, $account, $folder], JSON_THROW_ON_ERROR));
    }

    private function lock(string $principalDir, int $type) {
        $path = $principalDir . '/.lock';
        $old = umask(0077);
        try { $f = @fopen($path, 'c'); } finally { umask($old); }
        if (!$f) throw new RuntimeException('Cannot open principal lock');
        $s = fstat($f); clearstatcache(true, $path); $p = @lstat($path);
        if (!$s || !$p || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 ||
            $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || $s['dev'] !== $p['dev'] || $s['ino'] !== $p['ino'] || !flock($f, $type)) {
            fclose($f); throw new RuntimeException('Unsafe principal lock');
        }
        return $f;
    }

    private function fileStat(string $path): array {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1) throw new RuntimeException('Unsafe generation file');
        return $s;
    }

    private function writeFile(string $path, string $content): void {
        $old = umask(0077);
        try { $f = @fopen($path, 'x'); } finally { umask($old); }
        if (!$f) throw new RuntimeException('Cannot create generation file');
        try {
            $offset = 0; $len = strlen($content);
            while ($offset < $len) {
                $n = fwrite($f, substr($content, $offset, 1048576));
                if ($n === false || $n === 0) throw new RuntimeException('Cannot write generation file');
                $offset += $n;
            }
            if (!fflush($f) || !fsync($f)) throw new RuntimeException('Cannot sync generation file');
        } finally { fclose($f); }
    }

    private function readFile(string $path, int $limit): string {
        $s = $this->fileStat($path);
        if ($s['size'] > $limit) throw new RuntimeException('Generation file exceeds limit');
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) !== $s['size']) throw new RuntimeException('Cannot read generation file');
        return $data;
    }

    private function entries(string $path): array {
        $d = @opendir($path);
        if (!$d) throw new RuntimeException('Cannot scan generation directory');
        $items = [];
        try {
            while (($name = readdir($d)) !== false) {
                if ($name === '.' || $name === '..') continue;
                if (count($items) >= $this->limits['scanEntries']) throw new RuntimeException('Generation scan limit exceeded');
                $items[] = $name;
            }
        } finally { closedir($d); }
        return $items;
    }

    private function manifest(string $dir): array {
        try { $m = json_decode($this->readFile($dir . '/manifest.json', 16384), true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('Corrupt generation manifest', 0, $e); }
        if (!is_array($m) || ($m['version'] ?? null) !== 1 || !is_int($m['expires'] ?? null) || !is_int($m['created'] ?? null) ||
            $m['expires'] <= $m['created'] || !is_string($m['secret'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $m['secret']) ||
            !is_string($m['binding'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $m['binding']) ||
            !is_string($m['objectsHash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $m['objectsHash']) ||
            !is_string($m['diffHash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $m['diffHash'])) throw new RuntimeException('Invalid generation manifest');
        return $m;
    }

    private function removeGeneration(string $dir): void {
        $this->makeDir($dir);
        foreach ($this->entries($dir) as $name) {
            if (!in_array($name, ['manifest.json', 'objects.json', 'diff.json'], true)) throw new RuntimeException('Unexpected generation file');
            $path = $dir . '/' . $name; $this->fileStat($path);
            if (!@unlink($path)) throw new RuntimeException('Cannot remove generation file');
            if ($this->fault) ($this->fault)('gcAfterUnlink');
        }
        if (!@rmdir($dir)) throw new RuntimeException('Cannot remove generation directory');
    }

    /** Caller holds the principal's exclusive lock. */
    private function usage(string $principalDir, int $now): array {
        $bytes = 0; $generations = 0; $folders = 0; $perFolder = []; $scanned = 0;
        foreach ($this->entries($principalDir) as $folder) {
            if ($folder === '.lock') continue;
            if (++$scanned > $this->limits['scanEntries'] || !preg_match('/\Af-[a-f0-9]{64}\z/D', $folder)) throw new RuntimeException('Unexpected principal storage entry');
            $path = $principalDir . '/' . $folder; $this->makeDir($path); $folders++; $perFolder[$folder] = 0;
            foreach ($this->entries($path) as $name) {
                if (++$scanned > $this->limits['scanEntries'] || !preg_match('/\A(g|tmp)-[a-f0-9]{32}\z/D', $name, $matches)) throw new RuntimeException('Unexpected folder storage entry');
                $dir = $path . '/' . $name; $this->makeDir($dir);
                if ($matches[1] === 'tmp') { $this->removeGeneration($dir); $this->syncDir($path); continue; }
                $m = $this->manifest($dir);
                if ($m['expires'] <= $now) {
                    // Publication names always stay complete: a crash during removal
                    // leaves a recoverable staging directory, never a partial g- dir.
                    $garbage = $path . '/tmp-' . substr($name, 2);
                    clearstatcache(true, $garbage);
                    if (file_exists($garbage) || is_link($garbage) || !@rename($dir, $garbage)) throw new RuntimeException('Cannot stage expired generation cleanup');
                    $this->syncDir($path);
                    if ($this->fault) ($this->fault)('gcAfterRename');
                    $this->removeGeneration($garbage); $this->syncDir($path); continue;
                }
                $files = $this->entries($dir); sort($files);
                if ($files !== ['diff.json', 'manifest.json', 'objects.json']) throw new RuntimeException('Incomplete generation');
                foreach ($files as $file) $bytes += $this->fileStat($dir . '/' . $file)['size'];
                $generations++; $perFolder[$folder]++;
            }
            if ($perFolder[$folder] === 0 && $this->entries($path) === []) {
                if (!@rmdir($path)) throw new RuntimeException('Cannot remove empty generation folder');
                $this->syncDir($principalDir); $folders--; unset($perFolder[$folder]);
            }
        }
        return [$bytes, $generations, $folders, $perFolder];
    }

    public function gc(string $principal): void {
        $p = $this->principalDir($principal); $lock = $this->lock($p, LOCK_EX);
        try { $this->usage($p, $this->now()); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function validateData(array $records, array $diff): void {
        if (!array_is_list($records) || !array_is_list($diff) || count($diff) > 0xffffffff) throw new InvalidArgumentException('Invalid generation lists');
        $ids = []; $seen = [];
        foreach ($records as $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null) || $record['id'] === '' || strlen($record['id']) > 4096 ||
                !is_string($record['changeKey'] ?? null) || $record['changeKey'] === '' || strlen($record['changeKey']) > 4096 ||
                !is_array($record['object'] ?? null) || ($record['object']['id'] ?? null) !== $record['id']) throw new InvalidArgumentException('Invalid generation record');
            $key = 'i' . base64_encode($record['id']);
            if (isset($ids[$key])) throw new InvalidArgumentException('Duplicate generation record');
            $ids[$key] = true;
        }
        foreach ($diff as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || $entry['id'] === '' || strlen($entry['id']) > 4096 ||
                !in_array($entry['kind'] ?? null, ['Create', 'Update', 'Delete'], true)) throw new InvalidArgumentException('Invalid generation diff');
            $key = 'i' . base64_encode($entry['id']);
            if (isset($seen[$key]) || (($entry['kind'] === 'Delete') === isset($ids[$key]))) throw new InvalidArgumentException('Invalid generation diff reference');
            $seen[$key] = true;
        }
    }

    private function token(string $generation, int $offset, array $manifest): string {
        $body = hex2bin($generation) . pack('N', $offset);
        $mac = substr(hash_hmac('sha256', $manifest['binding'] . $body, hex2bin($manifest['secret']), true), 0, 16);
        return rtrim(strtr(base64_encode($body . $mac), '+/', '-_'), '=');
    }

    // Bound the allocation of each json_encode call before encoding untrusted data.
    // The six-byte multiplier is conservative for JSON control-character escapes.
    private function encodingBudget(mixed $value, int &$budget, int $depth = 0): void {
        if ($depth > 128) throw new RuntimeException('Generation nesting limit exceeded');
        if (is_array($value)) {
            $budget -= 2;
            foreach ($value as $key => $item) {
                $budget -= 4 + 6 * strlen((string)$key);
                if ($budget < 0) throw new RuntimeException('Generation encoding limit exceeded');
                $this->encodingBudget($item, $budget, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > intdiv(max(0, $budget), 6)) throw new RuntimeException('Generation encoding limit exceeded');
            $budget -= 2 + 6 * strlen($value);
        } elseif (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            $budget -= 32;
        } else throw new InvalidArgumentException('Unsupported generation value');
        if ($budget < 0) throw new RuntimeException('Generation encoding limit exceeded');
    }

    private function encodeList(array $list): string {
        $output = '['; $first = true;
        foreach ($list as $value) {
            $budget = $this->limits['generationBytes'] - strlen($output) - 2;
            $this->encodingBudget($value, $budget);
            $part = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($output) + strlen($part) + 2 > $this->limits['generationBytes']) throw new RuntimeException('Generation byte limit exceeded');
            $output .= ($first ? '' : ',') . $part; $first = false;
        }
        return $output . ']';
    }

    public function publish(string $principal, string $account, string $folder, array $records, array $diff): string {
        $this->validateData($records, $diff);
        $objectsJson = $this->encodeList($records);
        $diffJson = $this->encodeList($diff);
        $now = $this->now();
        $manifest = ['version' => 1, 'binding' => $this->binding($principal, $account, $folder), 'created' => $now, 'expires' => $now + $this->limits['ttl'],
            'secret' => bin2hex(random_bytes(32)), 'objectsHash' => hash('sha256', $objectsJson), 'diffHash' => hash('sha256', $diffJson)];
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
        $size = strlen($objectsJson) + strlen($diffJson) + strlen($manifestJson);
        if ($size > $this->limits['generationBytes']) throw new RuntimeException('Generation byte limit exceeded');
        $name = $this->folderName($account, $folder); $p = $this->principalDir($principal); $lock = $this->lock($p, LOCK_EX); $stage = null;
        try {
            [$bytes, $generations, $folders, $perFolder] = $this->usage($p, $now);
            if ($bytes + $size > $this->limits['principalBytes'] || $generations >= $this->limits['principalGenerations'] ||
                ($perFolder[$name] ?? 0) >= $this->limits['folderGenerations'] || (!isset($perFolder[$name]) && $folders >= $this->limits['folders'])) throw new RuntimeException('Principal generation quota exceeded');
            $folderDir = $p . '/' . $name; $this->makeDir($folderDir); $this->syncDir($p);
            $id = bin2hex(random_bytes(16)); $stage = $folderDir . '/tmp-' . $id;
            if (file_exists($stage) || file_exists($folderDir . '/g-' . $id)) throw new RuntimeException('Generation collision');
            $this->makeDir($stage);
            $this->writeFile($stage . '/objects.json', $objectsJson);
            $this->writeFile($stage . '/diff.json', $diffJson);
            $this->writeFile($stage . '/manifest.json', $manifestJson);
            $this->syncDir($stage);
            if ($this->fault) ($this->fault)('beforeRename');
            if (!@rename($stage, $folderDir . '/g-' . $id)) throw new RuntimeException('Cannot publish generation');
            $stage = null;
            $this->syncDir($folderDir);
            if ($this->fault) ($this->fault)('afterRename');
            return $this->token($id, 0, $manifest);
        } finally {
            if ($stage !== null && is_dir($stage)) { $this->removeGeneration($stage); $this->syncDir($folderDir); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function openCheckpoint(string $principal, string $account, string $folder, string $token): array {
        $p = $this->principalDir($principal); $name = $this->folderName($account, $folder);
        if (!preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $token)) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Malformed generation checkpoint');
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 36 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $token) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Malformed generation checkpoint');
        $id = bin2hex(substr($raw, 0, 16)); $offset = unpack('N', substr($raw, 16, 4))[1];
        $folderDir = $p . '/' . $name; $dir = $folderDir . '/g-' . $id; $lock = $this->lock($p, LOCK_SH);
        try {
            clearstatcache(true, $dir);
            if (!is_dir($dir)) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Unknown or expired generation checkpoint');
            $this->makeDir($folderDir); $this->makeDir($dir);
            $m = $this->manifest($dir);
            $binding = $this->binding($principal, $account, $folder);
            if (!hash_equals($binding, $m['binding']) || !hash_equals($this->token($id, $offset, $m), $token)) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Invalid generation checkpoint');
            if ($m['expires'] <= $this->now()) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Expired generation checkpoint');
            $objectsJson = $this->readFile($dir . '/objects.json', $this->limits['generationBytes']);
            $diffJson = $this->readFile($dir . '/diff.json', $this->limits['generationBytes']);
            if (!hash_equals($m['objectsHash'], hash('sha256', $objectsJson)) || !hash_equals($m['diffHash'], hash('sha256', $diffJson))) throw new RuntimeException('Corrupt generation content');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        try { $records = json_decode($objectsJson, true, 512, JSON_THROW_ON_ERROR); $diff = json_decode($diffJson, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('Corrupt generation content', 0, $e); }
        if (!is_array($records) || !is_array($diff)) throw new RuntimeException('Invalid generation content');
        $this->validateData($records, $diff);
        if ($offset > count($diff)) throw new EwsSyncException('ErrorInvalidSyncStateData', 'Invalid generation offset');
        return [$id, $offset, $m, $records, $diff];
    }

    public function readGeneration(string $principal, string $account, string $folder, string $token): array {
        [, , $m, $records, $diff] = $this->openCheckpoint($principal, $account, $folder, $token);
        return ['records' => $records, 'diff' => $diff, 'expires' => $m['expires']];
    }

    public function readPage(string $principal, string $account, string $folder, string $token, int $limit): array {
        if ($limit < 1 || $limit > 512) throw new InvalidArgumentException('Invalid page size');
        [$id, $offset, $m, $records, $diff] = $this->openCheckpoint($principal, $account, $folder, $token);
        $byId = [];
        foreach ($records as $record) $byId['i' . base64_encode($record['id'])] = $record;
        $page = array_slice($diff, $offset, $limit); $changes = [];
        foreach ($page as $entry) $changes[] = ['kind' => $entry['kind'], 'id' => $entry['id'], 'record' => $byId['i' . base64_encode($entry['id'])] ?? null];
        $next = $offset + count($page);
        return ['changes' => $changes, 'nextToken' => $this->token($id, $next, $m), 'done' => $next === count($diff)];
    }
}
