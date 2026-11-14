<?php

// May also run with sources preloaded via stdin.
if (!class_exists('EwsOperations')) {
    require_once __DIR__ . '/../ews/src/Soap.php';
    require_once __DIR__ . '/../ews/src/Converter.php';
    require_once __DIR__ . '/../ews/src/SyncState.php';
    require_once __DIR__ . '/../ews/src/SqliteSyncStore.php';
    require_once __DIR__ . '/../ews/src/Operations.php';
}

$syncActiveDir = sys_get_temp_dir() . '/ews-sync-active-' . bin2hex(random_bytes(8));
define('EWS_SYNC_STATE_DIR', $syncActiveDir);

class JmapClient {
    const CAP_CORE = 'core', CAP_MAIL = 'mail', CAP_CONTACTS = 'contacts', CAP_CALENDARS = 'calendars';
    public array $items = [];
    public string $user = 'active';
    public string $failure = '';
    public bool $mutateAfterRead = false;
    public int $queryCalls = 0;
    public int $getCalls = 0;
    public int $folderCalls = 0;
    public function getAccountId(): string { return 'mail-account'; }
    public function getCapabilityAccountId(string $cap): string { return $cap === self::CAP_CONTACTS ? 'contact-account' : ($cap === self::CAP_CALENDARS ? 'calendar-account' : 'mail-account'); }
    public function getSession(): array { return ['username' => $this->user]; }
    public function getApiUrl(): string { return 'https://example.test/jmap'; }
    public function resetCalls(): void { $this->queryCalls = $this->getCalls = $this->folderCalls = 0; }
    public function call(array $calls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $calls[0];
        if ($args['accountId'] !== 'mail-account') throw new RuntimeException('Wrong account');
        if ($method === 'Mailbox/get') {
            $this->folderCalls++;
            $list = $this->failure === 'folder' && $this->folderCalls > 1 ? [] : [['id' => 'folder']];
            $result = ['accountId' => 'mail-account', 'list' => $list, 'notFound' => $list ? [] : ['folder']];
        } elseif ($method === 'Email/query') {
            $this->queryCalls++;
            $ids = array_keys($this->items);
            // 205 items produce three pages per full query round.
            if ($this->queryCalls > 3 && $this->failure === 'membership') $ids = array_reverse($ids);
            $result = ['accountId' => 'mail-account', 'queryState' => $this->queryCalls > 3 && $this->failure === 'queryState' ? 'changed' : 'stable',
                'position' => $args['position'], 'total' => count($ids), 'ids' => array_slice($ids, $args['position'], $args['limit'])];
        } elseif ($method === 'Email/get') {
            $this->getCalls++;
            $list = [];
            foreach ($args['ids'] as $id) if (isset($this->items[$id])) $list[] = $this->items[$id];
            $result = ['accountId' => 'mail-account', 'state' => 'collection-' . $this->getCalls, 'list' => $list, 'notFound' => []];
            if ($this->failure === 'notFound') $result['notFound'] = [$args['ids'][0]];
            if ($this->failure === 'wrongId') $result['list'][0]['id'] = 'unexpected';
            if ($this->failure === 'emptyState') $result['state'] = '';
            if ($this->mutateAfterRead && $this->getCalls === 1) $this->items['id000']['subject'] = 'changed after read';
        } else throw new RuntimeException('Unexpected method ' . $method);
        return [[$method, $result, $tag]];
    }
}

function activeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function activeRequest(EwsOperations $ops, ?string $token = null): DOMXPath {
    $doc = new DOMDocument();
    $doc->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId>' .
        ($token === null ? '' : '<m:SyncState>' . htmlspecialchars($token, ENT_XML1) . '</m:SyncState>') . '<m:MaxChangesReturned>512</m:MaxChangesReturned></m:SyncFolderItems>');
    $response = new DOMDocument(); activeCheck($response->loadXML($ops->syncFolderItems($doc->documentElement)), 'valid XML');
    $xp = new DOMXPath($response); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T); return $xp;
}
function activeValue(DOMXPath $xp, string $field): string { return $xp->evaluate('string(//m:' . $field . ')'); }
function activeClient(string $user): JmapClient {
    $client = new JmapClient(); $client->user = $user;
    for ($i = 0; $i < 205; $i++) { $id = sprintf('id%03d', $i); $client->items[$id] = ['id' => $id, 'subject' => 'original']; }
    return $client;
}

try {
    $client = activeClient('convergence'); $ops = new EwsOperations($client); $client->mutateAfterRead = true;
    $first = activeRequest($ops);
    activeCheck(activeValue($first, 'ResponseCode') === 'NoError' && $first->query('//t:Create')->length === 205, 'different batch states accepted');
    activeCheck($client->getCalls === 3 && $client->queryCalls === 6 && $client->folderCalls === 2, 'two full query rounds and final folder verification; no final empty get');
    $token = activeValue($first, 'SyncState'); activeCheck($token !== '', 'initial token');
    $client->mutateAfterRead = false; $client->resetCalls();
    $second = activeRequest($ops, $token);
    activeCheck(activeValue($second, 'ResponseCode') === 'NoError' && $second->query('//t:Update/t:Message/t:ItemId[@Id="id000"]')->length === 1, 'next scan converges after an item changes after its batch');

    foreach (['queryState', 'membership', 'notFound', 'wrongId', 'emptyState', 'folder'] as $failure) {
        $client = activeClient('failure-' . $failure); $ops = new EwsOperations($client);
        $baseline = activeRequest($ops); activeCheck(activeValue($baseline, 'ResponseCode') === 'NoError', 'baseline');
        $token = activeValue($baseline, 'SyncState'); $client->items['id000']['subject'] = 'updated';
        $client->resetCalls(); $client->failure = $failure;
        $bad = activeRequest($ops, $token);
        $expected = $failure === 'folder' ? 'ErrorInvalidSyncFolderId' : 'ErrorInternalServerError';
        activeCheck(activeValue($bad, 'ResponseCode') === $expected && activeValue($bad, 'SyncState') === '' && $bad->query('//t:Create|//t:Update|//t:Delete')->length === 0, "$failure publishes no state or changes");
        $client->resetCalls(); $client->failure = '';
        $retry = activeRequest($ops, $token);
        activeCheck(activeValue($retry, 'ResponseCode') === 'NoError' && activeValue($retry, 'SyncState') !== '' && $retry->query('//t:Update/t:Message/t:ItemId[@Id="id000"]')->length === 1, "$failure retry publishes complete diff");
    }
    echo "sync active state tests passed\n";
} finally {
    if (is_dir($syncActiveDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($syncActiveDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        rmdir($syncActiveDir);
    }
}
