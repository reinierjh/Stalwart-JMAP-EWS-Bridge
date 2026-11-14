<?php
require_once __DIR__ . '/../ews/src/SyncState.php';

function genCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function genFails(callable $f, string $class = RuntimeException::class): void {
    try { $f(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException('Expected ' . $class);
}
function genRemove(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($dir);
}
function genFiles(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $out[] = [$file->getPathname(), $file->getSize()];
    sort($out); return $out;
}

$root = sys_get_temp_dir() . '/ews-generation-test-' . bin2hex(random_bytes(8));
$now = 1000000; $clock = function () use (&$now): int { return $now; };
$limits = ['ttl' => 100, 'principalBytes' => 1000000, 'generationBytes' => 500000, 'principalGenerations' => 4, 'folderGenerations' => 3, 'folders' => 4, 'scanEntries' => 100];
try {
    $store = new EwsSyncGenerationStore($root, $limits, $clock);
    $records = []; $diff = [];
    for ($i = 0; $i < 205; $i++) {
        $id = 'id-' . $i;
        $records[] = ['id' => $id, 'changeKey' => 'key-' . $i, 'object' => ['id' => $id, 'subject' => 'private-' . $i]];
        $diff[] = ['kind' => $i === 1 ? 'Update' : 'Create', 'id' => $id];
    }
    $diff[] = ['kind' => 'Delete', 'id' => 'deleted'];
    $token = $store->publish('principal-a', 'account-a', 'folder-a', $records, $diff);
    genCheck(strlen($token) === 48, 'small checkpoint');
    $matching = glob($root . '/p-*/f-*/g-*/diff.json');
    genCheck(count($matching) === 1 && (fileperms($matching[0]) & 0777) === 0600 && (fileperms(dirname($matching[0])) & 0777) === 0700, 'private permissions');
    $diffFile = $matching[0]; $original = file_get_contents($diffFile);
    file_put_contents($diffFile, '[]');
    genFails(fn() => $store->readGeneration('principal-a', 'account-a', 'folder-a', $token));
    file_put_contents($diffFile, $original);
    $changedToken = substr($token, 0, -1) . ($token[strlen($token) - 1] === 'A' ? 'B' : 'A');
    genFails(fn() => $store->readGeneration('principal-a', 'account-a', 'folder-a', $changedToken), EwsSyncException::class);
    $files = genFiles($root); $page = $store->readPage('principal-a', 'account-a', 'folder-a', $token, 17);
    genCheck($page === $store->readPage('principal-a', 'account-a', 'folder-a', $token, 17), 'same checkpoint and size retry');
    $next = $page['nextToken']; $all = $page['changes'];
    while (!$page['done']) {
        $page = $store->readPage('principal-a', 'account-a', 'folder-a', $next, 17);
        $next = $page['nextToken']; $all = array_merge($all, $page['changes']);
    }
    genCheck(count($all) === 206 && $all[1]['kind'] === 'Update' && $all[205]['kind'] === 'Delete' && $all[205]['record'] === null, 'all pages and delete');
    genCheck($store->readPage('principal-a', 'account-a', 'folder-a', $next, 17)['done'], 'final checkpoint');
    genCheck($files === genFiles($root), 'paging creates no files and does not change stored bytes');
    genCheck($store->readGeneration('principal-a', 'account-a', 'folder-a', $token)['records'] === $records, 'stored immutable object versions');
    $changedRecords = $records; $changedRecords[0]['object']['subject'] = 'changed later';
    genCheck($store->readGeneration('principal-a', 'account-a', 'folder-a', $token)['records'][0]['object']['subject'] === 'private-0', 'later writes cannot alter generation');
    foreach ([['principal-b', 'account-a', 'folder-a'], ['principal-a', 'account-b', 'folder-a'], ['principal-a', 'account-a', 'folder-b']] as $scope) {
        genFails(fn() => $store->readPage($scope[0], $scope[1], $scope[2], $token, 17), EwsSyncException::class);
    }
    genFails(fn() => $store->readPage('principal-a', 'account-a', 'folder-a', '../invalid', 17), EwsSyncException::class);
    genFails(fn() => $store->readPage('principal-a', 'account-a', 'folder-a', $token, 0), InvalidArgumentException::class);
    genFails(fn() => $store->publish('principal-a', 'account-a', 'folder-a', [], [['kind' => 'Create', 'id' => 'missing']]), InvalidArgumentException::class);
    $store->publish('principal-a', 'account-a', 'folder-a', [], []);
    $store->publish('principal-a', 'account-a', 'folder-a', [], []);
    genFails(fn() => $store->publish('principal-a', 'account-a', 'folder-a', [], []));
    $store->publish('principal-a', 'account-a', 'other-folder', [], []);
    genFails(fn() => $store->publish('principal-a', 'account-a', 'third-folder', [], []));
    $other = $store->publish('principal-b', 'account-a', 'folder-a', [], []);
    genCheck($store->readPage('principal-b', 'account-a', 'folder-a', $other, 1)['done'], 'quota isolated by principal');
    $now += 100;
    genFails(fn() => $store->readGeneration('principal-a', 'account-a', 'folder-a', $token), EwsSyncException::class);
    $store->gc('principal-a');
    $new = $store->publish('principal-a', 'account-a', 'folder-a', [], []);
    genCheck($store->readPage('principal-a', 'account-a', 'folder-a', $new, 1)['done'], 'quota recovered by expiry GC');
    $small = new EwsSyncGenerationStore($root . '/bytes', ['principalBytes' => 1000, 'generationBytes' => 10000], $clock);
    $large = [['id' => 'x', 'changeKey' => 'key', 'object' => ['id' => 'x', 'body' => str_repeat('x', 1200)]]];
    genFails(fn() => $small->publish('c', 'account', 'folder', $large, [['kind' => 'Create', 'id' => 'x']]));
    $smallToken = $small->publish('d', 'account', 'folder', [], []);
    genCheck($small->readPage('d', 'account', 'folder', $smallToken, 1)['done'], 'byte quota isolated');

    // Real process death while holding the lock: stage is not visible as a generation.
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) throw new RuntimeException('Crash test requires pcntl and posix');
    foreach (['beforeRename', 'afterRename'] as $point) {
        $crashRoot = $root . '/crash-' . $point;
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            $crashing = new EwsSyncGenerationStore($crashRoot, $limits, $clock, function ($phase) use ($point): void {
                if ($phase === $point) { posix_kill(getmypid(), SIGKILL); usleep(500000); }
            });
            $crashing->publish('principal', 'account', 'folder', [], []);
            exit(99);
        }
        pcntl_waitpid($pid, $status);
        genCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'child crashed at ' . $point);
        $dirs = glob($crashRoot . '/p-*/f-*/g-*') ?: [];
        $stages = glob($crashRoot . '/p-*/f-*/tmp-*') ?: [];
        genCheck(count($dirs) === ($point === 'afterRename' ? 1 : 0) && count($stages) === ($point === 'beforeRename' ? 1 : 0), 'atomic publication ' . $point);
        $recovered = new EwsSyncGenerationStore($crashRoot, $limits, $clock);
        $recovered->gc('principal');
        genCheck(count(glob($crashRoot . '/p-*/f-*/tmp-*') ?: []) === 0, 'abandoned stage collected');
        if ($point === 'afterRename') {
            genCheck(count(glob($crashRoot . '/p-*/f-*/g-*') ?: []) === 1, 'published orphan retained through retry window');
            $now += 100; $recovered->gc('principal');
            genCheck(count(glob($crashRoot . '/p-*/f-*/g-*') ?: []) === 0, 'expired orphan collected');
        }
    }
    foreach (['gcAfterRename', 'gcAfterUnlink'] as $point) {
        $crashRoot = $root . '/crash-' . $point;
        $recovered = new EwsSyncGenerationStore($crashRoot, $limits, $clock);
        $recovered->publish('principal', 'account', 'folder', [], []); $now += 100;
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            $crashing = new EwsSyncGenerationStore($crashRoot, $limits, $clock, function ($phase) use ($point): void {
                if ($phase === $point) { posix_kill(getmypid(), SIGKILL); usleep(500000); }
            });
            $crashing->gc('principal'); exit(99);
        }
        pcntl_waitpid($pid, $status);
        genCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'GC crashed at ' . $point);
        genCheck(count(glob($crashRoot . '/p-*/f-*/g-*') ?: []) === 0 && count(glob($crashRoot . '/p-*/f-*/tmp-*') ?: []) === 1, 'GC crash leaves staged garbage');
        $recovered->gc('principal');
        genCheck(count(glob($crashRoot . '/p-*/f-*/tmp-*') ?: []) === 0, 'GC crash recovery');
        $fresh = $recovered->publish('principal', 'account', 'folder', [], []);
        genCheck($recovered->readPage('principal', 'account', 'folder', $fresh, 1)['done'], 'publish after GC crash');
    }
    $parallelRoot = $root . '/parallel'; $pids = []; $parallelLimits = ['principalGenerations' => 2, 'folderGenerations' => 2];
    for ($i = 0; $i < 6; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            try { (new EwsSyncGenerationStore($parallelRoot, $parallelLimits, $clock))->publish('principal', 'account', 'folder', [], []); exit(0); }
            catch (RuntimeException $e) { exit(1); }
        }
        $pids[] = $pid;
    }
    $succeeded = 0;
    foreach ($pids as $pid) { pcntl_waitpid($pid, $status); genCheck(pcntl_wifexited($status), 'parallel publisher exited'); if (pcntl_wexitstatus($status) === 0) $succeeded++; }
    genCheck($succeeded === 2 && count(glob($parallelRoot . '/p-*/f-*/g-*') ?: []) === 2, 'atomic concurrent quota');
    echo "sync_generation: OK\n";
} finally { genRemove($root); }
