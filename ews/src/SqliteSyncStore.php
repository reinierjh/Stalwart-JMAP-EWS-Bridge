<?php

class SqliteSyncInvalidState extends RuntimeException {}
class SqliteSyncQuotaExceeded extends RuntimeException {}

/**
 * Independent storage; does not authenticate or call JMAP. All methods take the
 * authenticated server, principal, account and folder supplied by the caller.
 *
 * advance($scope, $completeTokenOrNull, $request, $pageSize, $records, $changes)
 * accepts a complete immutable snapshot and its caller-computed ordered diff.
 * Records: [['id'=>string,'changeKey'=>string,'object'=>array], ...]. Changes:
 * [['kind'=>'Create'|'Update'|'Delete','id'=>string], ...]. Create/Update must
 * reference snapshot items and Delete must not. The store verifies the complete
 * diff against the baseline; JMAP scan consistency is the caller's responsibility. Build outside
 * this store and its transactions. The returned token starts at offset zero.
 *
 * advance is idempotent for a valid complete source token and canonical request
 * parameters. A competing publisher wins under BEGIN IMMEDIATE; later calls
 * return its run and ignore their supplied data. Null-source requests reuse a run
 * with the same scope/request created within initialWindow seconds (default 300):
 * independent new clients may therefore share that initial snapshot. After the
 * window a new initial run may be made. Every run, including an empty diff, has a
 * new run and token; unchanged snapshots are shared. Page tokens are not valid as baselines until complete.
 *
 * page($scope, $token, $request, $pageSize) reads only the indexed page and its
 * corresponding snapshot items. Repeating a valid token and request returns the
 * same data and next token while retained; page size and shape may change between
 * requests. Reissuing a token extends its expiry to at least now plus the TTL.
 * baseline($scope,$completeToken)
 * returns the complete snapshot; it is intentionally an in-memory operation.
 * gc($server,$principal) performs one bounded collection batch; gcAll() repeats
 * batches as administrative maintenance for inactive principals. Runs retain
 * snapshots, and bounded-depth delta chains retain immutable per-folder versions.
 * Every 32nd changed generation is a full reference checkpoint to cap traversal;
 * ancestor snapshots remain until no descendant or valid retry needs them.
 * The same item/ChangeKey with different canonical JSON is rejected while its
 * version is retained. Logical quotas count version payload once and references
 * per snapshot; they include
 * all live run data, indexes' fixed accounting allowance, reservation for every
 * page token and transactional writes, but not SQLite page/WAL overhead; monitor
 * physical disk separately. Space for one token per change plus the initial token
 * is reserved before publishing a run, regardless of page size.
 *
 * The root is private and dedicated (0700; files 0600), outside every webroot,
 * stable across restarts and shared by all relevant workers on a filesystem that
 * supports SQLite WAL locking and durable fsync. PDO SQLite and ext-posix required.
 * Per-call record arrays, encoded objects and baseline() consume memory; configure
 * buildBytes, buildRows, objectBytes and pageBytes conservatively and size PHP-FPM memory
 * accordingly. Keep limits and synchronized clocks consistent across workers.
 * SQLite has one writer. Snapshot construction and verification use no write lock;
 * large atomic publications still hold it while inserting all rows. Pages use a WAL
 * read transaction followed by a short token-write transaction with revalidation;
 * this write can wait behind a publication. GC limits row counts and item bytes,
 * except that it must delete one oversized item to progress; candidate scans and
 * fsync still have no hard time bound. One batch per publish may be insufficient to
 * reclaim expired data for quota; call gc repeatedly or gcAll. No FPM
 * contention/load bound is established. This is not a deployment approval.
 */
class SqliteSyncStore {
    private PDO $db;
    private array $limits;
    private Closure $clock;
    private ?Closure $fault;

    public function __construct(string $root, array $limits = [], ?callable $clock = null, ?callable $fault = null) {
        $defaults = ['ttl' => 604800, 'initialWindow' => 300, 'principalBytes' => 8589934592,
            'folderBytes' => 2147483648, 'buildBytes' => 67108864, 'buildRows' => 100000, 'objectBytes' => 1048576, 'pageBytes' => 16777216, 'busyTimeoutMs' => 10000, 'gcBatch' => 128, 'gcBytes' => 1048576];
        if (array_diff_key($limits, $defaults)) throw new InvalidArgumentException('Unknown SQLite sync limit');
        $this->limits = $limits + $defaults;
        foreach ($this->limits as $value) if (!is_int($value) || $value < 1) throw new InvalidArgumentException('Invalid SQLite sync limit');
        if ($this->limits['gcBatch'] > 512) throw new InvalidArgumentException('GC batch limit exceeded');
        if ($this->limits['initialWindow'] > $this->limits['ttl']) throw new InvalidArgumentException('Initial window exceeds token lifetime');
        if (!extension_loaded('pdo_sqlite') || !function_exists('posix_geteuid') || !function_exists('fsync') || $root === '' || $root[0] !== '/') throw new RuntimeException('SQLite sync prerequisites unavailable');
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn(): int => time();
        $this->fault = $fault ? Closure::fromCallable($fault) : null;
        $root = rtrim($root, '/');
        if ($root === '') throw new RuntimeException('Invalid SQLite sync root');
        clearstatcache(true, $root);
        if (!file_exists($root) && !is_link($root)) {
            $old = umask(0077);
            try { @mkdir($root, 0700); } finally { umask($old); }
        }
        clearstatcache(true, $root); $s = @lstat($root);
        if (!$s || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0777) !== 0700 || $s['uid'] !== posix_geteuid()) throw new RuntimeException('Unsafe SQLite sync directory');
        $root = realpath($root);
        if ($root === false) throw new RuntimeException('Cannot resolve SQLite sync directory');
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($docroot)) throw new RuntimeException('Invalid webroot');
        if ($docroot !== '') {
            $web = realpath($docroot);
            if ($web === false || $root === $web || str_starts_with($root, rtrim($web, '/') . '/')) throw new RuntimeException('Unsafe webroot configuration');
        }
        $path = $root . '/sync.sqlite3';
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) $this->checkFile($file);
        $old = umask(0077);
        try {
            $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $this->db->exec('PRAGMA busy_timeout=' . $this->limits['busyTimeoutMs']);
            if (strtolower((string)$this->db->query('PRAGMA journal_mode=WAL')->fetchColumn()) !== 'wal') throw new RuntimeException('SQLite WAL unavailable');
            $this->db->exec('PRAGMA synchronous=FULL');
            $this->db->exec('PRAGMA foreign_keys=ON');
            $this->db->exec('PRAGMA secure_delete=ON');
            if ((int)$this->db->query('PRAGMA foreign_keys')->fetchColumn() !== 1 || (int)$this->db->query('PRAGMA synchronous')->fetchColumn() !== 2 ||
                (int)$this->db->query('PRAGMA secure_delete')->fetchColumn() !== 1) throw new RuntimeException('SQLite safety pragmas unavailable');
            $this->initSchema();
            foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) $this->checkFile($file);
        } finally { umask($old); }
        $d = @fopen($root, 'r');
        if (!$d) throw new RuntimeException('Cannot sync SQLite directory');
        try { if (!fsync($d)) throw new RuntimeException('Cannot sync SQLite directory'); } finally { fclose($d); }
    }

    private function checkFile(string $path): void {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!$s) return;
        if (($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1) throw new RuntimeException('Unsafe SQLite sync file');
    }

    private function initSchema(): void {
        $this->tx(function (): void {
            $version = (int)$this->db->query('PRAGMA user_version')->fetchColumn();
            if ($version !== 0 && $version !== 3) throw new RuntimeException('Unsupported SQLite sync schema; a fresh store is required');
            if ($version === 0 && (int)$this->db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn() !== 0) throw new RuntimeException('SQLite sync database is not empty');
            $this->db->exec('CREATE TABLE IF NOT EXISTS snapshots (id INTEGER PRIMARY KEY, principal TEXT NOT NULL, scope TEXT NOT NULL, charge INTEGER NOT NULL, parent_id INTEGER REFERENCES snapshots(id), depth INTEGER NOT NULL)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS snapshots_principal ON snapshots(principal,scope)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS snapshots_parent ON snapshots(parent_id)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS runs (id INTEGER PRIMARY KEY, principal TEXT NOT NULL, folder TEXT NOT NULL, scope TEXT NOT NULL, source TEXT, fingerprint TEXT NOT NULL, created INTEGER NOT NULL, change_count INTEGER NOT NULL, charge INTEGER NOT NULL, snapshot_id INTEGER NOT NULL REFERENCES snapshots(id))');
            $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS runs_source ON runs(scope,source,fingerprint) WHERE source IS NOT NULL');
            $this->db->exec('CREATE INDEX IF NOT EXISTS runs_initial ON runs(scope,fingerprint,created) WHERE source IS NULL');
            $this->db->exec('CREATE INDEX IF NOT EXISTS runs_principal ON runs(principal,folder)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS runs_scope ON runs(scope)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS runs_snapshot ON runs(snapshot_id)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS item_versions (id INTEGER PRIMARY KEY, principal TEXT NOT NULL, scope TEXT NOT NULL, item_id TEXT NOT NULL, change_key TEXT NOT NULL, content_hash TEXT NOT NULL, object_json TEXT NOT NULL, charge INTEGER NOT NULL, UNIQUE(scope,item_id,change_key))');
            $this->db->exec('CREATE INDEX IF NOT EXISTS versions_principal ON item_versions(principal,scope)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS snapshot_items (snapshot_id INTEGER NOT NULL REFERENCES snapshots(id), item_id TEXT NOT NULL, version_id INTEGER REFERENCES item_versions(id), PRIMARY KEY(snapshot_id,item_id)) WITHOUT ROWID');
            $this->db->exec('CREATE INDEX IF NOT EXISTS snapshot_items_version ON snapshot_items(version_id)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS changes (run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE, ordinal INTEGER NOT NULL, kind TEXT NOT NULL, item_id TEXT NOT NULL, PRIMARY KEY(run_id,ordinal)) WITHOUT ROWID');
            $this->db->exec('CREATE TABLE IF NOT EXISTS tokens (hash TEXT PRIMARY KEY, token TEXT NOT NULL, run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE, offset INTEGER NOT NULL, expires INTEGER NOT NULL, UNIQUE(run_id,offset))');
            $this->db->exec('CREATE INDEX IF NOT EXISTS tokens_expiry ON tokens(expires)');
            $this->db->exec('PRAGMA user_version=3');
        });
    }

    private function tx(callable $f): mixed {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result = $f(); $this->db->exec('COMMIT'); return $result; }
        catch (Throwable $e) { try { $this->db->exec('ROLLBACK'); } catch (Throwable) {} throw $e; }
    }

    private function readTx(callable $f): mixed {
        $this->db->exec('BEGIN');
        try { $result = $f(); $this->db->exec('COMMIT'); return $result; }
        catch (Throwable $e) { try { $this->db->exec('ROLLBACK'); } catch (Throwable) {} throw $e; }
    }

    private function now(): int {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - $this->limits['ttl']) throw new RuntimeException('Invalid SQLite sync clock');
        return $now;
    }

    private function scope(array $scope): array {
        foreach (['server', 'principal', 'account', 'folder'] as $key) {
            if (!isset($scope[$key]) || !is_string($scope[$key]) || trim($scope[$key]) === '' || strlen($scope[$key]) > 4096) throw new InvalidArgumentException('Invalid SQLite sync identity');
        }
        $hash = static fn(array $v): string => hash('sha256', json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $principal = $hash([$scope['server'], $scope['principal']]);
        $folder = $hash([$scope['server'], $scope['principal'], $scope['account'], $scope['folder']]);
        return [$principal, $folder];
    }

    private function canonical(mixed $value, int $depth = 0): mixed {
        if ($depth > 32) throw new InvalidArgumentException('Request nesting exceeded');
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $v) $value[$key] = $this->canonical($v, $depth + 1);
            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) return $value;
        throw new InvalidArgumentException('Unsupported request parameter');
    }

    private function fingerprint(array $request, int $pageSize): string {
        if ($pageSize < 1 || $pageSize > 512) throw new InvalidArgumentException('Invalid page size');
        $budget = 65536; $this->estimate($request, $budget);
        $json = json_encode($this->canonical($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 65536) throw new InvalidArgumentException('Request parameters too large');
        return hash('sha256', $json);
    }

    private function runQuery(string $sql, array $args = []): PDOStatement {
        $s = $this->db->prepare($sql); $s->execute($args); return $s;
    }

    private function tokenHash(string $token): string {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)) throw new SqliteSyncInvalidState('Invalid sync token');
        $raw = base64_decode(strtr($token, '-_', '+/') . '=', true);
        if ($raw === false || strlen($raw) !== 32 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $token) throw new SqliteSyncInvalidState('Invalid sync token');
        return hash('sha256', $raw);
    }

    private function findToken(string $token, string $folder, int $now): array {
        $row = $this->runQuery('SELECT t.hash,t.token,t.offset,t.expires,r.* FROM tokens t JOIN runs r ON r.id=t.run_id WHERE t.hash=? AND r.scope=? AND t.expires>?', [$this->tokenHash($token), $folder, $now])->fetch();
        if (!$row) throw new SqliteSyncInvalidState('Unknown, expired or mismatched sync token');
        return $row;
    }

    /** One write transaction removes at most gcBatch rows per table. */
    private function cleanupBatch(?string $principal, int $now): int {
        $n = $this->limits['gcBatch']; $scope = $principal === null ? '' : ' AND r.principal=?';
        $args = $principal === null ? [$now] : [$now, $principal];
        $deleted = $this->runQuery('DELETE FROM tokens WHERE hash IN (SELECT t.hash FROM tokens t JOIN runs r ON r.id=t.run_id WHERE t.expires<=?' . $scope . ' LIMIT ' . $n . ')', $args)->rowCount();
        // A live source may still retry a completed run even after its output tokens
        // have expired. Keep that run and its changes until the source expires.
        $orphan = ' NOT EXISTS (SELECT 1 FROM tokens t WHERE t.run_id=r.id) AND (r.source IS NULL OR NOT EXISTS (SELECT 1 FROM tokens s WHERE s.hash=r.source AND s.expires>?))';
        $run = $this->runQuery('SELECT r.id FROM runs r WHERE' . $orphan . ' AND EXISTS (SELECT 1 FROM changes c WHERE c.run_id=r.id)' . $scope . ' LIMIT 1', $args)->fetch();
        if ($run) $deleted += $this->runQuery('DELETE FROM changes WHERE run_id=? AND ordinal IN (SELECT ordinal FROM changes WHERE run_id=? LIMIT ' . $n . ')', [(int)$run['id'], (int)$run['id']])->rowCount();
        $deleted += $this->runQuery('DELETE FROM runs WHERE id IN (SELECT r.id FROM runs r WHERE' . $orphan . ' AND NOT EXISTS (SELECT 1 FROM changes c WHERE c.run_id=r.id)' . $scope . ' LIMIT ' . $n . ')', $args)->rowCount();
        $snapshotScope = $principal === null ? '' : ' AND s.principal=?';
        $snapshotArgs = $principal === null ? [] : [$principal];
        $snapshot = $this->runQuery('SELECT s.id FROM snapshots s WHERE NOT EXISTS (SELECT 1 FROM runs r WHERE r.snapshot_id=s.id) AND NOT EXISTS (SELECT 1 FROM snapshots child WHERE child.parent_id=s.id) AND EXISTS (SELECT 1 FROM snapshot_items i WHERE i.snapshot_id=s.id)' . $snapshotScope . ' LIMIT 1', $snapshotArgs)->fetch();
        if ($snapshot) {
            $id = (int)$snapshot['id'];
            $deleted += $this->runQuery('DELETE FROM snapshot_items WHERE snapshot_id=? AND item_id IN (SELECT item_id FROM snapshot_items WHERE snapshot_id=? LIMIT ' . $n . ')', [$id, $id])->rowCount();
        }
        $deleted += $this->runQuery('DELETE FROM snapshots WHERE id IN (SELECT s.id FROM snapshots s WHERE NOT EXISTS (SELECT 1 FROM runs r WHERE r.snapshot_id=s.id) AND NOT EXISTS (SELECT 1 FROM snapshots child WHERE child.parent_id=s.id) AND NOT EXISTS (SELECT 1 FROM snapshot_items i WHERE i.snapshot_id=s.id)' . $snapshotScope . ' LIMIT ' . $n . ')', $snapshotArgs)->rowCount();
        $versionScope = $principal === null ? '' : ' AND v.principal=?';
        $versions = $this->runQuery('SELECT v.id, v.charge FROM item_versions v WHERE NOT EXISTS (SELECT 1 FROM snapshot_items i WHERE i.version_id=v.id)' . $versionScope . ' LIMIT ' . $n, $snapshotArgs);
        $ids = []; $bytes = 0;
        foreach ($versions as $version) {
            $size = (int)$version['charge'];
            if ($ids && $bytes + $size > $this->limits['gcBytes']) break;
            $ids[] = (int)$version['id']; $bytes += $size;
        }
        $delete = $this->db->prepare('DELETE FROM item_versions WHERE id=? AND NOT EXISTS (SELECT 1 FROM snapshot_items WHERE version_id=?)');
        foreach ($ids as $id) {
            $delete->execute([$id, $id]); $deleted += $delete->rowCount();
            if ($this->fault) ($this->fault)('gcAfterItemDelete');
        }
        return $deleted;
    }

    public function gc(string $server, string $principal): void {
        [$p] = $this->scope(['server' => $server, 'principal' => $principal, 'account' => '-', 'folder' => '-']);
        $this->tx(fn() => $this->cleanupBatch($p, $this->now()));
    }

    /** Administrative: caller must control access to this method. */
    public function gcAll(): void {
        do { $deleted = $this->tx(fn() => $this->cleanupBatch(null, $this->now())); } while ($deleted > 0);
    }

    private function quota(string $principal, string $folder, int $extra): void {
        $row = $this->runQuery('SELECT (SELECT COALESCE(SUM(charge),0) FROM runs WHERE principal=?) + (SELECT COALESCE(SUM(charge),0) FROM snapshots WHERE principal=?) + (SELECT COALESCE(SUM(charge),0) FROM item_versions WHERE principal=?) AS bytes', [$principal, $principal, $principal])->fetch();
        $folderRow = $this->runQuery('SELECT (SELECT COALESCE(SUM(charge),0) FROM runs WHERE scope=?) + (SELECT COALESCE(SUM(charge),0) FROM snapshots WHERE scope=?) + (SELECT COALESCE(SUM(charge),0) FROM item_versions WHERE scope=?) AS bytes', [$folder, $folder, $folder])->fetch();
        if ((int)$row['bytes'] + $extra > $this->limits['principalBytes'] || (int)$folderRow['bytes'] + $extra > $this->limits['folderBytes']) throw new SqliteSyncQuotaExceeded('SQLite sync quota exceeded');
    }

    private function newToken(int $run, int $offset, int $now): string {
        $row = $this->runQuery('SELECT token,expires FROM tokens WHERE run_id=? AND offset=?', [$run, $offset])->fetch();
        if ($row && (int)$row['expires'] > $now) {
            $this->runQuery('UPDATE tokens SET expires=MAX(expires,?) WHERE run_id=? AND offset=?', [$now + $this->limits['ttl'], $run, $offset]);
            return $row['token'];
        }
        if ($row) $this->runQuery('DELETE FROM tokens WHERE run_id=? AND offset=?', [$run, $offset]);
        $raw = random_bytes(32); $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $this->runQuery('INSERT INTO tokens(hash,token,run_id,offset,expires) VALUES(?,?,?,?,?)', [hash('sha256', $raw), $token, $run, $offset, $now + $this->limits['ttl']]);
        return $token;
    }

    private function estimate(mixed $value, int &$budget, int $depth = 0): void {
        if ($depth > 128) throw new RuntimeException('Snapshot nesting limit exceeded');
        if (is_array($value)) {
            $budget -= 2;
            foreach ($value as $key => $v) {
                $budget -= 4 + 6 * strlen((string)$key);
                if ($budget < 0) throw new RuntimeException('Snapshot object limit exceeded');
                $this->estimate($v, $budget, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > intdiv(max(0, $budget), 6)) throw new RuntimeException('Snapshot object limit exceeded');
            $budget -= 2 + 6 * strlen($value);
        } elseif (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) $budget -= 32;
        else throw new InvalidArgumentException('Unsupported snapshot value');
        if ($budget < 0) throw new RuntimeException('Snapshot object limit exceeded');
    }

    private function canonicalObject(mixed $value, int $depth = 0): mixed {
        if ($depth > 128) throw new RuntimeException('Snapshot nesting limit exceeded');
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $v) $value[$key] = $this->canonicalObject($v, $depth + 1);
            return $value;
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) return $value;
        throw new InvalidArgumentException('Unsupported snapshot value');
    }

    private function prepareData(array $records, array $changes): array {
        if (!array_is_list($records) || !array_is_list($changes)) throw new InvalidArgumentException('Invalid snapshot lists');
        if (count($records) > $this->limits['buildRows'] || count($changes) > $this->limits['buildRows']) throw new RuntimeException('Snapshot row limit exceeded');
        $ids = []; $encoded = []; $snapshotCharge = 128; $runCharge = 512; $buildCharge = 640;
        foreach ($records as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || $r['id'] === '' || strlen($r['id']) > 4096 ||
                !is_string($r['changeKey'] ?? null) || $r['changeKey'] === '' || strlen($r['changeKey']) > 4096 ||
                !is_array($r['object'] ?? null) || ($r['object']['id'] ?? null) !== $r['id']) throw new InvalidArgumentException('Invalid snapshot item');
            $key = 'i' . base64_encode($r['id']);
            if (isset($ids[$key])) throw new InvalidArgumentException('Duplicate snapshot item');
            $ids[$key] = true; $budget = $this->limits['objectBytes']; $this->estimate($r['object'], $budget);
            $json = json_encode($this->canonicalObject($r['object']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $referenceCharge = 96 + strlen($r['id']);
            $snapshotCharge += $referenceCharge;
            $buildCharge += $referenceCharge + 192 + strlen($r['id']) + strlen($r['changeKey']) + strlen($json);
            if ($buildCharge > $this->limits['buildBytes']) throw new RuntimeException('Snapshot build limit exceeded');
            $encoded[] = [$r['id'], $r['changeKey'], $json, hash('sha256', $json)];
        }
        $seen = [];
        foreach ($changes as $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null) || $c['id'] === '' || strlen($c['id']) > 4096 || !in_array($c['kind'] ?? null, ['Create', 'Update', 'Delete'], true)) throw new InvalidArgumentException('Invalid snapshot change');
            $key = 'i' . base64_encode($c['id']);
            if (isset($seen[$key]) || (($c['kind'] === 'Delete') === isset($ids[$key]))) throw new InvalidArgumentException('Invalid snapshot change reference');
            $seen[$key] = true; $runCharge += 128 + strlen($c['id']); $buildCharge += 128 + strlen($c['id']);
            if ($buildCharge > $this->limits['buildBytes']) throw new RuntimeException('Snapshot build limit exceeded');
        }
        return [$encoded, $snapshotCharge, $runCharge, $buildCharge];
    }

    /** Verify every Create/Update/Delete against the complete immutable baseline. */
    private function verifyDiff(array $baselineRows, array $encoded, array $changes, string $folder): void {
        $target = []; $given = [];
        foreach ($encoded as $row) $target['i' . base64_encode($row[0])] = $row;
        foreach ($changes as $change) $given['i' . base64_encode($change['id'])] = $change['kind'];
        foreach ($baselineRows as $old) {
            $this->checkVersion($old, $folder);
            $key = 'i' . base64_encode($old['item_id']);
            if (!isset($target[$key])) $expected = 'Delete';
            else {
                $new = $target[$key];
                if ($new[1] === $old['change_key'] && $new[2] !== $old['object_json']) throw new InvalidArgumentException('Changed object has unchanged ChangeKey');
                $expected = ($new[1] !== $old['change_key'] || $new[2] !== $old['object_json']) ? 'Update' : null;
                unset($target[$key]);
            }
            if (($given[$key] ?? null) !== $expected) throw new InvalidArgumentException('Diff does not match baseline');
            unset($given[$key]);
        }
        foreach ($target as $key => $_) {
            if (($given[$key] ?? null) !== 'Create') throw new InvalidArgumentException('Diff does not match baseline');
            unset($given[$key]);
        }
        if ($given) throw new InvalidArgumentException('Diff does not match baseline');
    }

    private function chainCte(): string {
        return 'WITH RECURSIVE chain(id,level) AS (' .
            'SELECT id,0 FROM snapshots WHERE id=? UNION ALL ' .
            'SELECT s.parent_id,chain.level+1 FROM snapshots s JOIN chain ON s.id=chain.id WHERE s.parent_id IS NOT NULL AND chain.level<31)';
    }

    private function materialCte(): string {
        return $this->chainCte() . ', ' .
            'ranked AS (SELECT i.item_id,i.version_id,ROW_NUMBER() OVER (PARTITION BY i.item_id ORDER BY chain.level) AS rn FROM chain JOIN snapshot_items i ON i.snapshot_id=chain.id), ' .
            'material AS (SELECT item_id,version_id FROM ranked WHERE rn=1 AND version_id IS NOT NULL) ';
    }

    private function validateSnapshotChain(int $id, string $folder): int {
        $sql = 'WITH RECURSIVE chain(id,parent_id,scope,depth,level) AS (' .
            'SELECT id,parent_id,scope,depth,0 FROM snapshots WHERE id=? UNION ALL ' .
            'SELECT p.id,p.parent_id,p.scope,p.depth,chain.level+1 FROM snapshots p JOIN chain ON p.id=chain.parent_id WHERE chain.level<31) SELECT * FROM chain ORDER BY level';
        $rows = $this->runQuery($sql, [$id])->fetchAll();
        if (!$rows) throw new RuntimeException('Missing snapshot');
        $depth = (int)$rows[0]['depth'];
        if ($depth < 0 || $depth > 31 || count($rows) !== $depth + 1) throw new RuntimeException('Corrupt snapshot chain');
        foreach ($rows as $level => $row) {
            if ($row['scope'] !== $folder || (int)$row['level'] !== $level || (int)$row['depth'] !== $depth - $level ||
                ($level === $depth && $row['parent_id'] !== null)) throw new RuntimeException('Corrupt snapshot chain');
        }
        return $depth;
    }

    private function snapshotMemoryCharge(int $id, string $folder): int {
        $this->validateSnapshotChain($id, $folder);
        $row = $this->runQuery($this->materialCte() . 'SELECT COUNT(*) AS count,COALESCE(SUM(v.charge + 96 + length(CAST(m.item_id AS BLOB))),0) AS total FROM material m LEFT JOIN item_versions v ON v.id=m.version_id', [$id])->fetch();
        if (!$row || (int)$row['count'] > $this->limits['buildRows']) throw new RuntimeException('Baseline row limit exceeded');
        return (int)$row['total'];
    }

    private function checkVersion(array $row, string $folder): void {
        if (($row['version_scope'] ?? null) !== $folder || ($row['version_item'] ?? null) !== ($row['item_id'] ?? null) ||
            !is_string($row['object_json'] ?? null) || !is_string($row['content_hash'] ?? null) ||
            !hash_equals($row['content_hash'], hash('sha256', $row['object_json']))) throw new RuntimeException('Corrupt snapshot version');
    }

    public function advance(array $scope, ?string $source, array $request, int $pageSize, array $records, array $changes): string {
        [$principal, $folder] = $this->scope($scope); $fp = $this->fingerprint($request, $pageSize);
        $existing = $this->tx(function () use ($principal, $folder, $fp, $source): ?string {
            $now = $this->now();
            $run = $this->findExistingRun($source, $folder, $fp, $now);
            return $run === null ? null : $this->newToken($run, 0, $now);
        });
        if ($existing !== null) return $existing;
        // Preparation is intentionally outside the DB transaction.
        [$encoded, , $runCharge, $buildCharge] = $this->prepareData($records, $changes);
        $runCharge += 192 * (count($changes) + 1);
        if ($buildCharge + 192 * (count($changes) + 1) > $this->limits['buildBytes']) throw new RuntimeException('Snapshot build limit exceeded');
        [$baselineId, $baselineRows, $baselineDepth] = $this->readTx(function () use ($source, $folder): array {
            $prev = $source === null ? null : $this->findToken($source, $folder, $this->now());
            if ($prev !== null && (int)$prev['offset'] !== (int)$prev['change_count']) throw new SqliteSyncInvalidState('Intermediate token is not a baseline');
            $id = $prev === null ? null : (int)$prev['snapshot_id'];
            if ($id === null) return [null, [], 0];
            if ($this->snapshotMemoryCharge($id, $folder) > $this->limits['buildBytes']) throw new RuntimeException('Baseline build limit exceeded');
            $depth = (int)$this->runQuery('SELECT depth FROM snapshots WHERE id=?', [$id])->fetchColumn();
            return [$id, $this->runQuery($this->materialCte() . 'SELECT i.item_id,v.item_id AS version_item,v.scope AS version_scope,v.change_key,v.object_json,v.content_hash FROM material i LEFT JOIN item_versions v ON v.id=i.version_id', [$id])->fetchAll(), $depth];
        });
        $this->verifyDiff($baselineRows, $encoded, $changes, $folder);
        unset($baselineRows);
        $fullSnapshot = $baselineId === null || $baselineDepth >= 31;
        if ($fullSnapshot) $writeRows = $encoded;
        else {
            $byId = [];
            foreach ($encoded as $record) $byId['i' . base64_encode($record[0])] = $record;
            $writeRows = [];
            foreach ($changes as $change) {
                $writeRows[] = $change['kind'] === 'Delete'
                    ? [$change['id'], null, null, null]
                    : $byId['i' . base64_encode($change['id'])];
            }
            unset($byId);
        }
        $writeCharge = 128;
        foreach ($writeRows as $record) $writeCharge += 96 + strlen($record[0]);
        if ($this->fault) ($this->fault)('afterBuildRead');
        // Incremental collection is separate from publication and never evicts
        // live tokens or the idempotent result of a still-live source token.
        $this->tx(fn() => $this->cleanupBatch($principal, $this->now()));
        return $this->tx(function () use ($principal, $folder, $fp, $source, $changes, $runCharge, $baselineId, $baselineDepth, $fullSnapshot, $writeRows, $writeCharge): string {
            $now = $this->now();
            $existing = $this->findExistingRun($source, $folder, $fp, $now);
            if ($existing !== null) return $this->newToken($existing, 0, $now);
            $sourceHash = $source === null ? null : $this->tokenHash($source);
            $prev = $source === null ? null : $this->findToken($source, $folder, $now);
            if (($prev === null ? null : (int)$prev['snapshot_id']) !== $baselineId) throw new SqliteSyncInvalidState('Baseline changed during build');
            $reuse = $baselineId !== null && $changes === [];
            if ($this->fault) ($this->fault)('beforePublishWrites');
            $versions = []; $versionCharge = 0;
            if (!$reuse) {
                $lookup = $this->db->prepare('SELECT id,(content_hash=? AND object_json=?) AS same FROM item_versions WHERE scope=? AND item_id=? AND change_key=?');
                foreach ($writeRows as [$id, $key, $json, $hash]) {
                    if ($key === null) { $versions[] = null; continue; }
                    $lookup->execute([$hash, $json, $folder, $id, $key]); $version = $lookup->fetch();
                    if ($version && (int)$version['same'] !== 1) throw new InvalidArgumentException('ChangeKey reused for different item content');
                    $versions[] = $version ? (int)$version['id'] : null;
                    if (!$version) $versionCharge += 192 + strlen($id) + strlen($key) + strlen($json);
                }
            }
            $this->quota($principal, $folder, $runCharge + ($reuse ? 0 : $writeCharge + $versionCharge));
            if ($reuse) $snapshot = $baselineId;
            else {
                $this->runQuery('INSERT INTO snapshots(principal,scope,charge,parent_id,depth) VALUES(?,?,?,?,?)', [$principal, $folder, $writeCharge, $fullSnapshot ? null : $baselineId, $fullSnapshot ? 0 : $baselineDepth + 1]);
                $snapshot = (int)$this->db->lastInsertId();
                $insertVersion = $this->db->prepare('INSERT INTO item_versions(principal,scope,item_id,change_key,content_hash,object_json,charge) VALUES(?,?,?,?,?,?,?)');
                $link = $this->db->prepare('INSERT INTO snapshot_items(snapshot_id,item_id,version_id) VALUES(?,?,?)');
                foreach ($writeRows as $i => [$id, $key, $json, $hash]) {
                    $versionId = $versions[$i];
                    if ($versionId === null && $key !== null) {
                        $insertVersion->execute([$principal, $folder, $id, $key, $hash, $json, 192 + strlen($id) + strlen($key) + strlen($json)]);
                        $versionId = (int)$this->db->lastInsertId();
                    }
                    $link->execute([$snapshot, $id, $versionId]);
                }
            }
            $this->runQuery('INSERT INTO runs(principal,folder,scope,source,fingerprint,created,change_count,charge,snapshot_id) VALUES(?,?,?,?,?,?,?,?,?)', [$principal, $folder, $folder, $sourceHash, $fp, $now, count($changes), $runCharge, $snapshot]);
            $run = (int)$this->db->lastInsertId();
            if ($this->fault) ($this->fault)('afterItems');
            $change = $this->db->prepare('INSERT INTO changes(run_id,ordinal,kind,item_id) VALUES(?,?,?,?)');
            foreach ($changes as $i => $c) $change->execute([$run, $i, $c['kind'], $c['id']]);
            $token = $this->newToken($run, 0, $this->now());
            if ($this->fault) ($this->fault)('beforeCommit');
            return $token;
        });
    }

    /** Caller holds the write transaction; complete sources are revalidated each time. */
    private function findExistingRun(?string $source, string $folder, string $fp, int $now): ?int {
        if ($source !== null) {
            $prev = $this->findToken($source, $folder, $now);
            if ((int)$prev['offset'] !== (int)$prev['change_count']) throw new SqliteSyncInvalidState('Intermediate token is not a baseline');
            $row = $this->runQuery('SELECT id FROM runs WHERE scope=? AND source=? AND fingerprint=?', [$folder, $prev['hash'], $fp])->fetch();
        } else {
            $row = $this->runQuery('SELECT id FROM runs WHERE scope=? AND source IS NULL AND fingerprint=? AND created>=? ORDER BY created DESC,id DESC LIMIT 1', [$folder, $fp, $now - $this->limits['initialWindow']])->fetch();
        }
        return $row ? (int)$row['id'] : null;
    }

    /** Authenticate and classify a token without loading its snapshot or diff. */
    public function tokenIsComplete(array $scope, string $token): bool {
        [, $folder] = $this->scope($scope);
        return $this->readTx(function () use ($token, $folder): bool {
            $row = $this->findToken($token, $folder, $this->now());
            if ((int)$row['offset'] > (int)$row['change_count']) throw new RuntimeException('Corrupt sync offset');
            return (int)$row['offset'] === (int)$row['change_count'];
        });
    }

    /** Retrieve an already published round before attempting another JMAP scan. */
    public function existingRun(array $scope, ?string $source, array $request, int $pageSize): ?string {
        [, $folder] = $this->scope($scope); $fp = $this->fingerprint($request, $pageSize);
        return $this->tx(function () use ($source, $folder, $fp): ?string {
            $now = $this->now(); $id = $this->findExistingRun($source, $folder, $fp, $now);
            return $id === null ? null : $this->newToken($id, 0, $now);
        });
    }

    public function page(array $scope, string $token, array $request, int $pageSize): array {
        [, $folder] = $this->scope($scope); $this->fingerprint($request, $pageSize);
        [$row, $changes, $next, $count] = $this->readTx(function () use ($token, $folder, $pageSize): array {
            $now = $this->now();
            $row = $this->findToken($token, $folder, $now);
            $offset = (int)$row['offset']; $count = (int)$row['change_count'];
            if ($offset > $count) throw new RuntimeException('Corrupt sync offset');
            $this->validateSnapshotChain((int)$row['snapshot_id'], $folder);
            $stmt = $this->db->prepare($this->chainCte() . ' SELECT c.ordinal,c.kind,c.item_id,v.item_id AS member_id,v.item_id AS version_item,v.scope AS version_scope,v.change_key,v.object_json,v.content_hash FROM changes c LEFT JOIN item_versions v ON v.id=(SELECT i.version_id FROM chain JOIN snapshot_items i ON i.snapshot_id=chain.id WHERE i.item_id=c.item_id ORDER BY chain.level LIMIT 1) WHERE c.run_id=? AND c.ordinal>=? ORDER BY c.ordinal LIMIT ?');
            $stmt->bindValue(1, (int)$row['snapshot_id'], PDO::PARAM_INT); $stmt->bindValue(2, (int)$row['id'], PDO::PARAM_INT); $stmt->bindValue(3, $offset, PDO::PARAM_INT); $stmt->bindValue(4, $pageSize, PDO::PARAM_INT); $stmt->execute();
            $changes = []; $pageBytes = 0; $truncated = false;
            while ($c = $stmt->fetch()) {
                if ((int)$c['ordinal'] !== $offset + count($changes)) throw new RuntimeException('Corrupt sync change ordering');
                if (!in_array($c['kind'], ['Create', 'Update', 'Delete'], true)) throw new RuntimeException('Corrupt sync change kind');
                $bytes = 192 + strlen($c['item_id']) + strlen($c['change_key'] ?? '') + strlen($c['object_json'] ?? '');
                if ($pageBytes + $bytes > $this->limits['pageBytes']) {
                    if (!$changes) throw new RuntimeException('Single sync item exceeds page byte limit');
                    $truncated = true; break;
                }
                $pageBytes += $bytes;
                $record = null;
                if ($c['kind'] !== 'Delete') {
                    if ($c['member_id'] !== $c['item_id'] || $c['change_key'] === null || $c['object_json'] === null) throw new RuntimeException('Missing sync item');
                    $this->checkVersion($c, $folder);
                    $object = json_decode($c['object_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($object) || ($object['id'] ?? null) !== $c['item_id']) throw new RuntimeException('Corrupt sync item');
                    $record = ['id' => $c['item_id'], 'changeKey' => $c['change_key'], 'object' => $object];
                } elseif ($c['member_id'] !== null) throw new RuntimeException('Corrupt sync deletion');
                $changes[] = ['kind' => $c['kind'], 'id' => $c['item_id'], 'record' => $record];
                if (count($changes) === 1 && $this->fault) ($this->fault)('duringPageRead');
            }
            $next = $offset + count($changes);
            if ($next > $count || ($next < $count && !$truncated && count($changes) !== $pageSize)) throw new RuntimeException('Incomplete sync changes');
            return [$row, $changes, $next, $count];
        });
        if ($this->fault) ($this->fault)('afterPageRead');
        $nextToken = $this->tx(function () use ($token, $folder, $row, $next): string {
            $current = $this->findToken($token, $folder, $this->now());
            foreach (['id', 'snapshot_id', 'offset', 'change_count'] as $key) {
                if ((int)$current[$key] !== (int)$row[$key]) throw new SqliteSyncInvalidState('Sync changed during page read');
            }
            return $this->newToken((int)$current['id'], $next, $this->now());
        });
        return ['changes' => $changes, 'nextToken' => $nextToken, 'done' => $next === $count];
    }

    public function baseline(array $scope, string $token): array {
        [, $folder] = $this->scope($scope);
        [$row, $items] = $this->readTx(function () use ($token, $folder): array {
            $row = $this->findToken($token, $folder, $this->now());
            if ((int)$row['offset'] !== (int)$row['change_count']) throw new SqliteSyncInvalidState('Intermediate token is not a baseline');
            if ($this->snapshotMemoryCharge((int)$row['snapshot_id'], $folder) > $this->limits['buildBytes']) throw new RuntimeException('Baseline build limit exceeded');
            $items = [];
            foreach ($this->runQuery($this->materialCte() . 'SELECT i.item_id,v.item_id AS version_item,v.scope AS version_scope,v.change_key,v.object_json,v.content_hash FROM material i LEFT JOIN item_versions v ON v.id=i.version_id ORDER BY i.item_id', [(int)$row['snapshot_id']]) as $item) {
                $this->checkVersion($item, $folder);
                $object = json_decode($item['object_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($object) || ($object['id'] ?? null) !== $item['item_id']) throw new RuntimeException('Corrupt sync item');
                $items[] = ['id' => $item['item_id'], 'changeKey' => $item['change_key'], 'object' => $object];
                if (count($items) === 1 && $this->fault) ($this->fault)('duringBaselineRead');
            }
            return [$row, $items];
        });
        if ($this->fault) ($this->fault)('afterBaselineRead');
        $this->readTx(function () use ($token, $folder, $row): void {
            $current = $this->findToken($token, $folder, $this->now());
            foreach (['id', 'snapshot_id', 'offset', 'change_count'] as $key) {
                if ((int)$current[$key] !== (int)$row[$key]) throw new SqliteSyncInvalidState('Sync changed during baseline read');
            }
        });
        return $items;
    }
}
