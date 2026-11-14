<?php
require_once __DIR__ . '/../ews/src/SqliteSyncStore.php';

function sqlCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function sqlFails(callable $f, string $class): void {
    try { $f(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException('Expected ' . $class);
}
function sqlRemove(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) if ($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
    rmdir($dir);
}
$root = sys_get_temp_dir() . '/sqlite-sync-test-' . bin2hex(random_bytes(8));
$now = 1000000; $clock = function () use (&$now): int { return $now; };
$limits = ['ttl' => 100, 'initialWindow' => 10, 'principalBytes' => 2000000, 'folderBytes' => 2000000, 'buildBytes' => 500000, 'objectBytes' => 10000];
$scope = ['server' => 'server', 'principal' => 'alice', 'account' => 'account', 'folder' => 'folder'];
$req = ['shape' => 'IdOnly', 'properties' => ['subject']];
try {
    $s = new SqliteSyncStore($root, $limits, $clock);
    $records = []; $changes = [];
    for ($i = 0; $i < 205; $i++) {
        $id = 'id' . $i;
        $records[] = ['id' => $id, 'changeKey' => 'key' . $i, 'object' => ['id' => $id, 'subject' => str_repeat('private-', 40) . $i]];
        $changes[] = ['kind' => 'Create', 'id' => $id];
    }
    $start = $s->advance($scope, null, $req, 17, $records, $changes);
    sqlCheck(strlen($start) === 43, 'opaque random token');
    $retry = $s->advance($scope, null, ['properties' => ['subject'], 'shape' => 'IdOnly'], 17, [], []);
    sqlCheck($retry === $start, 'initial bounded reuse and canonical request');
    $page = $s->page($scope, $start, $req, 17);
    sqlCheck($page === $s->page($scope, $start, $req, 17), 'deterministic retry');
    sqlFails(fn() => $s->baseline($scope, $start), SqliteSyncInvalidState::class);
    sqlFails(fn() => $s->advance($scope, $start, $req, 17, [], []), SqliteSyncInvalidState::class);
    sqlCheck(count($s->page($scope, $start, ['shape' => 'Default'], 18)['changes']) === 18, 'changed shape and page size');
    foreach ([1, 4, 31, 512] as $size) {
        $variant = $s->page($scope, $start, ['shape' => 'AllProperties'], $size);
        sqlCheck(count($variant['changes']) === min(205, $size) && $variant === $s->page($scope, $start, ['shape' => 'AllProperties'], $size), 'variable page retry ' . $size);
    }
    $all = $page['changes']; $cursor = $page['nextToken'];
    while (!$page['done']) { $page = $s->page($scope, $cursor, $req, 17); $cursor = $page['nextToken']; $all = array_merge($all, $page['changes']); }
    sqlCheck(count($all) === 205 && $all[1]['kind'] === 'Create', 'indexed paging');
    sqlCheck(count($s->baseline($scope, $cursor)) === 205, 'complete baseline');
    foreach (['server', 'principal', 'account', 'folder'] as $field) {
        $bad = $scope; $bad[$field] .= '-other';
        sqlFails(fn() => $s->baseline($bad, $cursor), SqliteSyncInvalidState::class);
    }
    sqlFails(fn() => $s->advance($scope, $cursor, $req, 17, [], []), InvalidArgumentException::class);
    $next = $s->advance($scope, $cursor, $req, 17, $records, []);
    sqlCheck($next !== $cursor && $s->page($scope, $next, $req, 17)['done'], 'empty diff creates complete new state');
    sqlCheck($next === $s->advance($scope, $cursor, $req, 18, $records, $changes), 'lost response and competing build');
    $now += 11;
    $newInitial = $s->advance($scope, null, $req, 17, [], []);
    sqlCheck($newInitial !== $start, 'initial reuse window expires');
    $before = $next;
    for ($i = 0; $i < 1001; $i++) {
        $after = $s->advance($scope, $before, $req, 17, $records, []);
        sqlCheck($after !== $before && $after === $s->advance($scope, $before, $req, 17, $records, []), 'idempotent generation ' . $i);
        $before = $after;
    }
    sqlCheck($s->baseline($scope, $cursor) !== [], 'no early eviction after 1001 generations');
    $inspect = new PDO('sqlite:' . $root . '/sync.sqlite3');
    sqlCheck((int)$inspect->query('SELECT count(*) FROM snapshots')->fetchColumn() === 2, 'unchanged polls reuse initial snapshot; second is separate empty initial');
    sqlCheck((int)$inspect->query('SELECT count(*) FROM item_versions')->fetchColumn() === 205 && (int)$inspect->query('SELECT count(*) FROM snapshot_items')->fetchColumn() === 205, 'unchanged polls do not duplicate items');
    unset($inspect);

    $updated = $records; array_shift($updated);
    $updated[0]['changeKey'] = 'changed'; $updated[0]['object']['subject'] = 'updated';
    $updated[] = ['id' => 'new', 'changeKey' => 'newkey', 'object' => ['id' => 'new', 'subject' => 'new']];
    $delta = [['kind' => 'Delete', 'id' => 'id0'], ['kind' => 'Update', 'id' => 'id1'], ['kind' => 'Create', 'id' => 'new']];
    sqlFails(fn() => $s->advance($scope, $before, ['missing-update' => true], 17, $updated, [$delta[0], $delta[2]]), InvalidArgumentException::class);
    $unchangedKey = $records; $unchangedKey[0]['object']['subject'] = 'different';
    sqlFails(fn() => $s->advance($scope, $before, ['same-key' => true], 17, $unchangedKey, [['kind' => 'Update', 'id' => 'id0']]), InvalidArgumentException::class);

    $failure = new SqliteSyncStore($root, $limits, $clock, function ($point): void { if ($point === 'afterItems') throw new RuntimeException('injected'); });
    $freshReq = ['shape' => 'Default'];
    sqlFails(fn() => $failure->advance($scope, $before, $freshReq, 17, $updated, $delta), RuntimeException::class);
    $afterFailure = $s->advance($scope, $before, $freshReq, 17, $updated, $delta);
    $deltaPage = $s->page($scope, $afterFailure, $freshReq, 17);
    sqlCheck(count($deltaPage['changes']) === 3 && $deltaPage['changes'][0]['kind'] === 'Delete' && $deltaPage['changes'][1]['kind'] === 'Update' && $deltaPage['changes'][2]['kind'] === 'Create', 'verified diff and rollback after item inserts');
    unset($failure, $s);

    // Connections are closed before forking; each child opens its own connection.
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) throw new RuntimeException('Fork tests require pcntl and posix');
    $children = [];
    for ($i = 0; $i < 6; $i++) {
        $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            try {
                $db = new SqliteSyncStore($root, $limits, $clock);
                $t = $db->advance($scope, $before, ['parallel' => true], 10, $records, []);
                file_put_contents($root . '/child-' . $i, $t); exit(0);
            } catch (Throwable $e) { fwrite(STDERR, get_class($e) . " in parallel test\n"); exit(1); }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); sqlCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'parallel worker'); }
    $tokens = [];
    for ($i = 0; $i < 6; $i++) { $tokens[] = file_get_contents($root . '/child-' . $i); unlink($root . '/child-' . $i); }
    sqlCheck(count(array_unique($tokens)) === 1, 'parallel publication idempotent');
    $s = new SqliteSyncStore($root, $limits, $clock);
    sqlCheck($s->advance($scope, $before, ['parallel' => true], 10, [], []) === $tokens[0], 'parallel lost-response retry');
    unset($s);
    $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        $db = new SqliteSyncStore($root, $limits, $clock);
        $db->advance($scope, $before, ['lost' => true], 10, $updated, $delta);
        posix_kill(getmypid(), SIGKILL); usleep(500000); exit(99);
    }
    pcntl_waitpid($pid, $status); sqlCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'killed after committed response');
    $s = new SqliteSyncStore($root, $limits, $clock);
    $lost = $s->advance($scope, $before, ['lost' => true], 10, [], []);
    sqlCheck(count($s->page($scope, $lost, ['lost' => true], 10)['changes']) === 3, 'lost committed response returns winning snapshot');
    unset($s);
    $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        $db = new SqliteSyncStore($root, $limits, $clock, function ($phase): void { if ($phase === 'beforeCommit') { posix_kill(getmypid(), SIGKILL); usleep(500000); } });
        $db->advance($scope, $before, ['crash' => true], 10, $updated, $delta); exit(99);
    }
    pcntl_waitpid($pid, $status); sqlCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'killed during transaction');
    $s = new SqliteSyncStore($root, $limits, $clock);
    $afterCrash = $s->advance($scope, $before, ['crash' => true], 10, $records, []);
    sqlCheck($s->page($scope, $afterCrash, ['crash' => true], 10)['done'], 'crashed transaction rolled back');
    $other = $scope; $other['principal'] = 'bob';
    $small = new SqliteSyncStore($root, $limits + [], $clock);
    $now += 50;
    $laterChild = $small->advance($scope, $before, ['later' => true], 10, $records, []);
    $bob = $small->advance($other, null, [], 10, [], []);
    $now += 51; $s->gcAll();
    sqlFails(fn() => $s->baseline($scope, $start), SqliteSyncInvalidState::class);
    sqlCheck($s->baseline($other, $bob) === [], 'GC preserves live independent snapshot');
    sqlCheck(count($s->baseline($scope, $laterChild)) === 205, 'GC preserves live child after parent expiry');

    $quotaRoot = $root . '-quota';
    try {
        $q = new SqliteSyncStore($quotaRoot, ['ttl' => 100, 'initialWindow' => 10, 'principalBytes' => 1800, 'folderBytes' => 1800], $clock);
        $qa = $q->advance($scope, null, [], 1, [], []);
        $qb = $q->advance($scope, $qa, [], 1, [], []);
        sqlFails(fn() => $q->advance($scope, $qb, [], 1, [], []), SqliteSyncQuotaExceeded::class);
        sqlCheck($q->baseline($scope, $qa) === [] && $q->baseline($scope, $qb) === [], 'quota does not evict live states');
        $otherToken = $q->advance($other, null, [], 1, [], []);
        sqlCheck($q->baseline($other, $otherToken) === [], 'quota isolated by principal');
        $now += 100; $q->gc('server', 'alice');
        $q->advance($scope, null, [], 1, [], []);
    } finally { unset($q); sqlRemove($quotaRoot); }
    $folderRoot = $root . '-folder-quota';
    try {
        $q = new SqliteSyncStore($folderRoot, ['ttl' => 100, 'initialWindow' => 10, 'principalBytes' => 10000, 'folderBytes' => 900], $clock);
        $a = $q->advance($scope, null, [], 1, [], []);
        sqlFails(fn() => $q->advance($scope, $a, [], 1, [], []), SqliteSyncQuotaExceeded::class);
        $anotherFolder = $scope; $anotherFolder['folder'] = 'other';
        $b = $q->advance($anotherFolder, null, [], 1, [], []);
        sqlCheck($q->baseline($scope, $a) === [] && $q->baseline($anotherFolder, $b) === [], 'folder quota isolated');
    } finally { unset($q); sqlRemove($folderRoot); }
    $resourceRoot = $root . '-resource';
    try {
        $q = new SqliteSyncStore($resourceRoot, ['ttl' => 100, 'initialWindow' => 10, 'buildRows' => 2, 'pageBytes' => 300], $clock);
        sqlFails(fn() => $q->advance($scope, null, [], 1, array_slice($records, 0, 3), array_slice($changes, 0, 3)), RuntimeException::class);
        $tiny = array_slice($records, 0, 2);
        foreach ($tiny as &$record) $record['object']['subject'] = 'x';
        unset($record);
        $limited = $q->advance($scope, null, [], 2, $tiny, array_slice($changes, 0, 2));
        $short = $q->page($scope, $limited, [], 2);
        sqlCheck(count($short['changes']) === 1 && !$short['done'], 'byte limit returns short progressing page');
        sqlCheck($q->page($scope, $short['nextToken'], [], 2)['done'], 'short page completes');
        unset($q);
        $q = new SqliteSyncStore($resourceRoot, ['ttl' => 100, 'initialWindow' => 10, 'buildRows' => 2, 'pageBytes' => 100], $clock);
        sqlFails(fn() => $q->page($scope, $limited, [], 2), RuntimeException::class);
    } finally { unset($q); sqlRemove($resourceRoot); }
    $tokenRoot = $root . '-token-ttl';
    try {
        $q = new SqliteSyncStore($tokenRoot, ['ttl' => 100, 'initialWindow' => 10], $clock);
        $first = $q->advance($scope, null, [], 1, array_slice($records, 0, 2), array_slice($changes, 0, 2));
        $now += 90; $second = $q->page($scope, $first, [], 1)['nextToken'];
        $now += 9; sqlCheck($second === $q->page($scope, $first, [], 1)['nextToken'], 'retry reissues same token');
        $now += 92; $q->gcAll();
        sqlFails(fn() => $q->page($scope, $first, [], 1), SqliteSyncInvalidState::class);
        $final = $q->page($scope, $second, [], 1);
        sqlCheck($final['done'] && count($q->baseline($scope, $final['nextToken'])) === 2, 'reissued token retains snapshot for full TTL');
        $complete = $final['nextToken'];
        $now += 99;
        sqlCheck($q->page($scope, $complete, [], 1)['nextToken'] === $complete, 'complete token reissued');
        $now += 2; $q->gcAll();
        sqlCheck(count($q->baseline($scope, $complete)) === 2, 'reissued complete token remains valid');
    } finally { unset($q); sqlRemove($tokenRoot); }
    foreach (['page', 'baseline'] as $kind) {
        $raceRoot = $root . '-race-' . $kind;
        try {
            $raceClock = 2000000; $raceNow = function () use (&$raceClock): int { return $raceClock; };
            $gc = new SqliteSyncStore($raceRoot, ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 1], $raceNow);
            $two = array_slice($records, 0, 2); $twoChanges = array_slice($changes, 0, 2);
            $raceToken = $gc->advance($scope, null, [], 2, $two, $twoChanges);
            if ($kind === 'baseline') $raceToken = $gc->page($scope, $raceToken, [], 2)['nextToken'];
            $sawRead = false; $completedRead = false;
            $racer = new SqliteSyncStore($raceRoot, ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 1], $raceNow,
                function ($phase) use ($kind, &$raceClock, &$sawRead, &$completedRead, $gc): void {
                    if ($phase === ($kind === 'page' ? 'duringPageRead' : 'duringBaselineRead')) {
                        $sawRead = true; $raceClock += 10; $gc->gcAll();
                    }
                    if ($phase === ($kind === 'page' ? 'afterPageRead' : 'afterBaselineRead')) $completedRead = true;
                });
            sqlFails(fn() => $kind === 'page' ? $racer->page($scope, $raceToken, [], 2) : $racer->baseline($scope, $raceToken), SqliteSyncInvalidState::class);
            sqlCheck($sawRead && $completedRead, 'WAL snapshot survives GC during ' . $kind . ' read; expiry rechecked');
        } finally { unset($racer, $gc); sqlRemove($raceRoot); }
    }
    $buildRoot = $root . '-race-build';
    try {
        $buildClock = 2500000; $buildNow = function () use (&$buildClock): int { return $buildClock; };
        $gc = new SqliteSyncStore($buildRoot, ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 1], $buildNow);
        $baseToken = $gc->advance($scope, null, [], 1, [], []);
        $builder = new SqliteSyncStore($buildRoot, ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 1], $buildNow,
            function ($phase) use (&$buildClock, $gc): void { if ($phase === 'afterBuildRead') { $buildClock += 10; $gc->gcAll(); } });
        sqlFails(fn() => $builder->advance($scope, $baseToken, [], 1, [], []), SqliteSyncInvalidState::class);
        $probe = new PDO('sqlite:' . $buildRoot . '/sync.sqlite3');
        sqlCheck((int)$probe->query('SELECT count(*) FROM runs')->fetchColumn() === 0, 'expired build source cannot publish');
        unset($probe);
    } finally { unset($builder, $gc); sqlRemove($buildRoot); }
    $sourceRoot = $root . '-source-retention';
    try {
        $sourceNow = 3000000; $sourceClock = function () use (&$sourceNow): int { return $sourceNow; };
        $q = new SqliteSyncStore($sourceRoot, ['ttl' => 100, 'initialWindow' => 10, 'gcBatch' => 1], $sourceClock);
        $parent = $q->advance($scope, null, [], 1, [], []);
        $child = $q->advance($scope, $parent, [], 1, [], []);
        $sourceNow += 90; $q->page($scope, $parent, [], 1); // refresh only parent
        $sourceNow += 11; $q->gcAll();
        $probe = new PDO('sqlite:' . $sourceRoot . '/sync.sqlite3');
        sqlCheck((int)$probe->query('SELECT count(*) FROM runs')->fetchColumn() === 2, 'live source retains idempotent run');
        unset($probe);
        $reissued = $q->advance($scope, $parent, [], 1, [], []);
        sqlCheck($reissued !== $child && $q->baseline($scope, $reissued) === [], 'expired output safely reissued for live source');
    } finally { unset($q); sqlRemove($sourceRoot); }
    $batchRoot = $root . '-batch';
    try {
        $batchTime = 4000000; $batchClock = function () use (&$batchTime): int { return $batchTime; };
        $q = new SqliteSyncStore($batchRoot, ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 3, 'gcBytes' => 1], $batchClock);
        $q->advance($scope, null, [], 10, array_slice($records, 0, 20), array_slice($changes, 0, 20));
        $batchTime += 10; $q->gc('server', 'alice');
        $probe = new PDO('sqlite:' . $batchRoot . '/sync.sqlite3');
        sqlCheck((int)$probe->query('SELECT count(*) FROM changes')->fetchColumn() === 17, 'GC changes bounded per transaction');
        while ((int)$probe->query('SELECT count(*) FROM runs')->fetchColumn() > 0) $q->gc('server', 'alice');
        $beforeCount = (int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn();
        $q->gc('server', 'alice');
        sqlCheck($beforeCount - (int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === 1, 'GC bytes bounded except one oversized item');
        unset($probe); $q->gcAll();
    } finally { unset($q); sqlRemove($batchRoot); }
    $gcCrashRoot = $root . '-gc-crash';
    try {
        $gcTime = 5000000; $gcClock = function () use (&$gcTime): int { return $gcTime; };
        $gcLimits = ['ttl' => 10, 'initialWindow' => 5, 'gcBatch' => 1];
        $q = new SqliteSyncStore($gcCrashRoot, $gcLimits, $gcClock);
        $q->advance($scope, null, [], 2, array_slice($records, 0, 2), array_slice($changes, 0, 2));
        $gcTime += 10; $probe = new PDO('sqlite:' . $gcCrashRoot . '/sync.sqlite3');
        while ((int)$probe->query('SELECT count(*) FROM runs')->fetchColumn() > 0) $q->gc('server', 'alice');
        $remaining = (int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn();
        sqlCheck($remaining > 0, 'GC crash setup'); unset($q, $probe);
        $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            $child = new SqliteSyncStore($gcCrashRoot, $gcLimits, $gcClock, function ($phase): void {
                if ($phase === 'gcAfterItemDelete') { posix_kill(getmypid(), SIGKILL); usleep(500000); }
            });
            $child->gc('server', 'alice'); exit(99);
        }
        pcntl_waitpid($pid, $status); sqlCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'GC child killed inside transaction');
        $q = new SqliteSyncStore($gcCrashRoot, $gcLimits, $gcClock); $probe = new PDO('sqlite:' . $gcCrashRoot . '/sync.sqlite3');
        sqlCheck((int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === $remaining, 'GC deletion rolled back after crash');
        unset($probe); $q->gcAll();
    } finally { unset($q, $probe); sqlRemove($gcCrashRoot); }
    // More than 100 changed generations must retain old versions without copying
    // every large object into every snapshot or exhausting this deliberately low quota.
    $sharingRoot = $root . '-sharing';
    try {
        $shareStart = 7000000; $shareTime = $shareStart;
        $shareClock = function () use (&$shareTime): int { return $shareTime; };
        $shareLimits = ['ttl' => 10000, 'initialWindow' => 10, 'folderBytes' => 18000000,
            'principalBytes' => 36000000, 'buildBytes' => 6000000, 'objectBytes' => 20000, 'gcBatch' => 512];
        $share = new SqliteSyncStore($sharingRoot, $shareLimits, $shareClock);
        $large = []; $initialDiff = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = 'object-' . sprintf('%04d', $i);
            $large[] = ['id' => $id, 'changeKey' => 'initial', 'object' => ['id' => $id, 'text' => str_repeat('payload-', 250), 'n' => 0]];
            $initialDiff[] = ['kind' => 'Create', 'id' => $id];
        }
        $token = $share->advance($scope, null, [], 512, $large, $initialDiff);
        do { $page = $share->page($scope, $token, [], 512); $token = $page['nextToken']; } while (!$page['done']);
        $initialComplete = $token;
        $probe = new PDO('sqlite:' . $sharingRoot . '/sync.sqlite3');
        $chargeSql = 'SELECT (SELECT COALESCE(SUM(charge),0) FROM runs)+(SELECT COALESCE(SUM(charge),0) FROM snapshots)+(SELECT COALESCE(SUM(charge),0) FROM item_versions)';
        $initialBytes = (int)$probe->query($chargeSql)->fetchColumn();
        for ($generation = 1; $generation <= 110; $generation++) {
            $shareTime++;
            $large[0]['changeKey'] = 'version-' . $generation;
            $large[0]['object']['n'] = $generation;
            $input = $token;
            $run = $share->advance($scope, $input, [], 512, $large, [['kind' => 'Update', 'id' => $large[0]['id']]]);
            sqlCheck($share->advance($scope, $input, [], 512, [], []) === $run, 'changed run retry ' . $generation);
            $page = $share->page($scope, $run, [], 512); sqlCheck($page['done'] && count($page['changes']) === 1, 'one change');
            $token = $page['nextToken'];
        }
        $finalBytes = (int)$probe->query($chargeSql)->fetchColumn();
        sqlCheck((int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === 1110, 'only changed versions added');
        $references = (int)$probe->query('SELECT count(*) FROM snapshot_items')->fetchColumn();
        sqlCheck($references === 4107, 'delta references and periodic checkpoints');
        sqlCheck($finalBytes < 18000000 && $finalBytes < $initialBytes * 2, 'logical growth bounded by deltas and checkpoints');
        $old = $share->baseline($scope, $initialComplete);
        sqlCheck(count($old) === 1000 && $old[0]['object']['n'] === 0, 'old complete token retains old version');
        $conflict = $large; $conflict[0]['changeKey'] = 'version-42'; $conflict[0]['object']['n'] = 9999;
        sqlFails(fn() => $share->advance($scope, $token, ['conflict' => true], 512, $conflict, [['kind' => 'Update', 'id' => $large[0]['id']]]), InvalidArgumentException::class);
        $otherFolder = $scope; $otherFolder['folder'] = 'other-folder';
        $otherUser = $scope; $otherUser['principal'] = 'other-user';
        $separate = [['id' => 'object-0000', 'changeKey' => 'version-42', 'object' => ['id' => 'object-0000', 'n' => 9999]]];
        $share->advance($otherFolder, null, [], 1, $separate, [['kind' => 'Create', 'id' => 'object-0000']]);
        $share->advance($otherUser, null, [], 1, $separate, [['kind' => 'Create', 'id' => 'object-0000']]);
        sqlCheck((int)$probe->query("SELECT count(DISTINCT scope) FROM item_versions WHERE item_id='object-0000' AND change_key='version-42'")->fetchColumn() === 3, 'versions isolated by folder and principal');
        $shareTime = $shareStart + 10109;
        $share->page($scope, $token, [], 512); // keep only the final complete state alive
        $shareTime += 2; $share->gcAll();
        sqlFails(fn() => $share->baseline($scope, $initialComplete), SqliteSyncInvalidState::class);
        sqlCheck(count($share->baseline($scope, $token)) === 1000, 'live snapshot survives shared-version GC');
        sqlCheck((int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === 1014, 'GC retains only live checkpoint ancestry');
        $shareTime += 10000; $share->gcAll();
        sqlCheck((int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === 0, 'all versions collected once final state expires');
        printf("sharing generations=111 items=1000 versions=1110 references=%d initial_logical=%d final_logical=%d\n", $references, $initialBytes, $finalBytes);
        unset($probe);
    } finally { unset($share, $probe); sqlRemove($sharingRoot); }
    // 10k realistic JSON objects, real concurrent principal and deliberately held
    // writer: the WAL SELECT must finish before the writer is allowed to commit.
    $benchRoot = $root . '-bench';
    $pid = null;
    try {
        $benchScope = $scope; $benchScope['principal'] = 'bulk-writer';
        $readScope = $scope; $readScope['principal'] = 'other-reader';
        $setup = new SqliteSyncStore($benchRoot);
        $readToken = $setup->advance($readScope, null, [], 1, [
            ['id' => 'read', 'changeKey' => 'read-key', 'object' => ['id' => 'read', 'subject' => 'reader']]
        ], [['kind' => 'Create', 'id' => 'read']]);
        $baselineToken = $setup->page($readScope, $readToken, [], 1)['nextToken'];
        unset($setup, $s, $small);
        $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('benchmark fork failed');
        if ($pid === 0) {
            try {
                $rows = []; $edits = []; $payloadBytes = 0;
                for ($i = 0; $i < 10000; $i++) {
                    $id = 'mail-' . sprintf('%05d', $i);
                    $object = ['id' => $id, 'subject' => 'A realistic message subject ' . $i,
                        'from' => [['name' => 'Sender', 'email' => 'sender@example.test']],
                        'to' => [['name' => 'Recipient', 'email' => 'recipient@example.test']],
                        'receivedAt' => '2026-01-01T12:34:56Z', 'keywords' => ['$seen' => true],
                        'preview' => str_repeat('Representative message preview. ', 25)];
                    $payloadBytes += strlen(json_encode($object, JSON_THROW_ON_ERROR));
                    $rows[] = ['id' => $id, 'changeKey' => 'key-' . $i, 'object' => $object];
                    $edits[] = ['kind' => 'Create', 'id' => $id];
                }
                $begin = 0; $hold = 0;
                $writer = new SqliteSyncStore($benchRoot, [], null, function ($phase) use ($benchRoot, &$begin, &$hold): void {
                    if ($phase === 'beforePublishWrites') $begin = hrtime(true);
                    if ($phase === 'afterItems') {
                        file_put_contents($benchRoot . '/writer-ready', '1'); $at = hrtime(true);
                        while (!is_file($benchRoot . '/release')) {
                            if (hrtime(true) - $at > 10000000000) throw new RuntimeException('reader did not release writer');
                            usleep(1000);
                        }
                        $hold = hrtime(true) - $at;
                    }
                });
                while (!is_file($benchRoot . '/start')) usleep(1000);
                $start = hrtime(true); $writer->advance($benchScope, null, [], 100, $rows, $edits); $end = hrtime(true);
                file_put_contents($benchRoot . '/metrics', json_encode(['payload_bytes' => $payloadBytes,
                    'total_ms' => ($end - $start - $hold) / 1000000, 'writer_ms' => ($end - $begin - $hold) / 1000000,
                    'hold_ms' => $hold / 1000000], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $e) { fwrite(STDERR, 'bulk test: ' . get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1); }
        }
        $readStart = 0; $readMs = null;
        $reader = new SqliteSyncStore($benchRoot, [], null, function ($phase) use ($benchRoot, &$readStart, &$readMs): void {
            if ($phase === 'afterPageRead') { $readMs = (hrtime(true) - $readStart) / 1000000; file_put_contents($benchRoot . '/release', '1'); }
        });
        file_put_contents($benchRoot . '/start', '1');
        $wait = hrtime(true);
        while (!is_file($benchRoot . '/writer-ready')) {
            if (hrtime(true) - $wait > 15000000000) throw new RuntimeException('bulk writer did not start');
            usleep(1000);
        }
        // Uncommitted snapshot/items/runs must not be observable on another connection.
        $probe = new PDO('sqlite:' . $benchRoot . '/sync.sqlite3');
        sqlCheck((int)$probe->query('SELECT count(*) FROM item_versions')->fetchColumn() === 1, 'partial publish is invisible'); unset($probe);
        $baselineStart = hrtime(true); $baselineRows = $reader->baseline($readScope, $baselineToken);
        $baselineMs = (hrtime(true) - $baselineStart) / 1000000;
        sqlCheck(count($baselineRows) === 1 && $baselineMs < 2000, 'baseline completes while writer holds lock');
        $readStart = hrtime(true); $pageResult = $reader->page($readScope, $readToken, [], 1);
        $pageMs = (hrtime(true) - $readStart) / 1000000;
        pcntl_waitpid($pid, $status); $pid = null;
        sqlCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'bulk publisher committed');
        sqlCheck($pageResult['done'] && count($pageResult['changes']) === 1 && $readMs !== null && $readMs < 2000 && $pageMs < 10000, 'reader completed under writer contention');
        $metrics = json_decode(file_get_contents($benchRoot . '/metrics'), true, 32, JSON_THROW_ON_ERROR);
        printf("bulk10k payload_bytes=%d total_ms=%.1f writer_ms=%.1f held_ms=%.1f baseline_ms=%.1f read_stage_ms=%.1f page_total_ms=%.1f\n", $metrics['payload_bytes'], $metrics['total_ms'], $metrics['writer_ms'], $metrics['hold_ms'], $baselineMs, $readMs, $pageMs);
    } finally {
        if (is_int($pid) && $pid > 0) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
        unset($reader); sqlRemove($benchRoot);
    }
    $oldRoot = $root . '-v1';
    try {
        mkdir($oldRoot, 0700); $old = new PDO('sqlite:' . $oldRoot . '/sync.sqlite3');
        chmod($oldRoot . '/sync.sqlite3', 0600); $old->exec('PRAGMA user_version=1'); unset($old);
        sqlFails(fn() => new SqliteSyncStore($oldRoot), RuntimeException::class);
    } finally { sqlRemove($oldRoot); }
    $path = $root . '/sync.sqlite3'; clearstatcache(true, $path);
    sqlCheck((fileperms($root) & 0777) === 0700 && (fileperms($path) & 0777) === 0600, 'private permissions');
    foreach (glob($path . '*') as $file) sqlCheck((fileperms($file) & 0777) === 0600, 'private database sidecar');
    echo "sqlite_sync_store: OK\n";
} finally { unset($s, $small); sqlRemove($root); }
