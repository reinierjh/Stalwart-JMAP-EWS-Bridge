<?php
// Real bootstrap, real converter and real SQLite; only JMAP is replaced.
$root = sys_get_temp_dir() . '/ews-sqlite-integration-' . bin2hex(random_bytes(8));
define('EWS_SYNC_STATE_DIR', $root);
require __DIR__ . '/../ews/bootstrap.php';

class SyncIntegrationClient extends JmapClient {
    public array $items = [];
    public string $state = 's1';
    public string $user = 'user';
    public string $folder = 'folder';
    public bool $folderError = false;
    public bool $itemError = false;
    public bool $missing = false;
    public bool $notFound = false;
    public bool $race = false;
    public bool $folderRace = false;
    public int $itemCalls = 0;
    public string $accountSuffix = '';
    public string $api = 'https://example.test/jmap';
    public array $seenAccounts = [];
    public function __construct() {}
    public function getSession(): array { return ['username' => $this->user]; }
    public function getApiUrl(): string { return $this->api; }
    public function getAccountId(): string { return 'mail-account'; }
    public function getCapabilityAccountId(string $capability): string {
        return (match ($capability) { self::CAP_CONTACTS => 'contact-account', self::CAP_CALENDARS => 'calendar-account', default => 'mail-account' }) . $this->accountSuffix;
    }
    public function call(array $calls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $calls[0]; $account = $args['accountId']; $this->seenAccounts[] = [$method, $account];
        if (in_array($method, ['Mailbox/get', 'AddressBook/get', 'Calendar/get'], true)) {
            if ($this->folderError) return [['error', ['type' => 'serverFail'], $tag]];
            $found = $args['ids'] === null || $args['ids'] === [$this->folder];
            $result = ['accountId' => $account, 'list' => $found ? [['id' => $this->folder, 'role' => 'inbox', 'isDefault' => true]] : [], 'notFound' => $found ? [] : $args['ids']];
        } else {
            $this->itemCalls++;
            if ($this->itemError) return [['error', ['type' => 'serverFail'], $tag]];
            if (str_ends_with($method, '/query')) {
                $ids = array_keys($this->items); $position = $args['position'];
                $result = ['accountId' => $account, 'queryState' => $this->state, 'position' => $position, 'total' => count($ids), 'ids' => array_slice($ids, $position, $args['limit'])];
                if ($this->missing) unset($result['total']);
                if ($this->folderRace) { $this->folder = 'moved'; $this->folderRace = false; }
            } else {
                $list = []; $notFound = [];
                foreach ($args['ids'] as $id) if (isset($this->items[$id])) $list[] = $this->items[$id]; else $notFound[] = $id;
                $result = ['accountId' => $account, 'state' => $this->state, 'list' => $list, 'notFound' => $notFound];
                if ($this->notFound && $args['ids']) $result['notFound'] = [$args['ids'][0]];
                if ($this->race && $args['ids']) { $this->state .= 'x'; $this->race = false; }
            }
        }
        return [[$method, $result, $tag]];
    }
}
function syncCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function syncRequest(EwsOperations $ops, string $folder, ?string $token = null, int $max = 37, string $shape = 'IdOnly', bool $dist = false, string $extra = ''): DOMXPath {
    $fid = $dist ? 'DistinguishedFolderId' : 'FolderId';
    $xml = '<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>' . $shape . '</t:BaseShape>' . $extra . '</m:ItemShape><m:SyncFolderId><t:' . $fid . ' Id="' . htmlspecialchars($folder, ENT_XML1) . '"/></m:SyncFolderId>' .
        ($token === null ? '' : '<m:SyncState>' . htmlspecialchars($token, ENT_XML1) . '</m:SyncState>') . '<m:MaxChangesReturned>' . $max . '</m:MaxChangesReturned></m:SyncFolderItems>';
    $doc = new DOMDocument(); $doc->loadXML($xml); $res = new DOMDocument();
    syncCheck($res->loadXML($ops->syncFolderItems($doc->documentElement)), 'valid response XML');
    $xp = new DOMXPath($res); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T); return $xp;
}
function syncValue(DOMXPath $xp, string $name): string { return $xp->evaluate('string(//m:' . $name . ')'); }
function syncCode(DOMXPath $xp, string $expected): void { syncCheck(syncValue($xp, 'ResponseCode') === $expected, 'expected ' . $expected . ', got ' . syncValue($xp, 'ResponseCode')); }
function syncClean(string $root): void {
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) if ($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
    rmdir($root);
}
try {
    foreach (['folder' => '', 'ab_folder' => 'ab_', 'cal_folder' => 'cal_'] as $folder => $prefix) {
        $client = new SyncIntegrationClient();
        for ($i = 0; $i < 205; $i++) {
            $id = 'id' . sprintf('%03d', $i);
            $client->items[$id] = ['id' => $id, 'subject' => 'old ' . $id, 'name' => ['full' => 'old ' . $id], 'title' => 'old ' . $id,
                'start' => '2026-01-01T12:00:00', 'duration' => 'PT1H', 'description' => 'secret', 'notes' => [['note' => 'secret']], 'bodyValues' => ['part' => ['value' => 'secret']]];
        }
        $ops = new EwsOperations($client); $xp = syncRequest($ops, $folder); syncCode($xp, 'NoError');
        syncCheck($xp->query('//t:Create/t:*/t:ItemId')->length === 37 && $xp->query('//t:Create/t:*/*[not(self::t:ItemId)]')->length === 0, 'typed IdOnly');
        $oldKey = EwsConverter::itemChangeKey($client->items['id100']);
        $token = syncValue($xp, 'SyncState'); $firstPageToken = $token; $calls = $client->itemCalls; $seen = [];
        $client->itemError = true;
        $offlinePage = syncRequest($ops, $folder, $firstPageToken, 19); syncCode($offlinePage, 'NoError');
        syncCheck($client->itemCalls === $calls, 'page survives item JMAP failure'); $client->itemError = false;
        unset($client->items['id000']);
        $client->items['id100']['subject'] = 'updated'; $client->items['id100']['name']['full'] = 'updated'; $client->items['id100']['title'] = 'updated';
        $client->items['new'] = ['id' => 'new', 'subject' => 'new', 'name' => ['full' => 'new'], 'title' => 'new', 'start' => '2026-01-01T12:00:00']; $client->state = 's2';
        do {
            $xp = syncRequest($ops, $folder, $token, 19, 'AllProperties'); syncCode($xp, 'NoError');
            syncCheck($xp->query('//t:Body|//t:Attachments')->length === 0, 'sync omits body and attachments');
            foreach ($xp->query('//t:Create/t:*/t:ItemId') as $node) {
                $seen[] = $node->getAttribute('Id');
                if ($node->getAttribute('Id') === $prefix . 'id100') syncCheck($node->getAttribute('ChangeKey') === $oldKey, 'stored old object version');
            }
            $token = syncValue($xp, 'SyncState');
        } while (syncValue($xp, 'IncludesLastItemInRange') === 'false');
        syncCheck(count($seen) === 168 && count(array_unique($seen)) === 168 && $client->itemCalls === $calls, 'immutable pages without JMAP item calls');
        $baseline = $token;
        $xp = syncRequest($ops, $folder, $baseline, 512, 'Default'); syncCode($xp, 'NoError');
        syncCheck($xp->query('//t:Delete/t:ItemId[@Id="' . $prefix . 'id000"]')->length === 1, 'delete');
        syncCheck($xp->query('//t:Update/t:*/t:ItemId[@Id="' . $prefix . 'id100"]')->length === 1, 'update');
        syncCheck($xp->query('//t:Create/t:*/t:ItemId[@Id="' . $prefix . 'new"]')->length === 1, 'create');
        syncCheck($xp->query('//t:Body|//t:Attachments')->length === 0, 'default shape omits body/attachments');
        $expectedKey = EwsConverter::itemChangeKey($client->items['id100']);
        syncCheck($xp->evaluate('string(//t:Update/t:*/t:ItemId/@ChangeKey)') === $expectedKey, 'stored ChangeKey matches converter');
        if ($prefix === '') {
            $get = new DOMDocument();
            $get->loadXML('<m:GetItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>Default</t:BaseShape></m:ItemShape><m:ItemIds><t:ItemId Id="id100"/></m:ItemIds></m:GetItem>');
            $res = new DOMDocument(); $res->loadXML($ops->getItem($get->documentElement));
            $getXp = new DOMXPath($res); $getXp->registerNamespace('t', EwsSoap::NS_T);
            syncCheck($getXp->evaluate('string(//t:ItemId/@ChangeKey)') === $expectedKey, 'GetItem and Sync use same real converter ChangeKey');
        }
        $next = syncValue($xp, 'SyncState'); $calls = $client->itemCalls;
        $client->itemError = true;
        $retry = syncRequest($ops, $folder, $baseline, 512, 'Default'); syncCode($retry, 'NoError');
        syncCheck(syncValue($retry, 'SyncState') === $next && $client->itemCalls === $calls, 'idempotent retry uses stored run');
        $oldPage = syncRequest($ops, $folder, $firstPageToken, 19, 'AllProperties'); syncCode($oldPage, 'NoError');
        syncCheck($client->itemCalls === $calls, 'old page retry uses stored run');
        $client->itemError = false;
        $client->user = 'other'; syncCode(syncRequest($ops, $folder, $baseline), 'ErrorInvalidSyncStateData'); $client->user = 'user';
        $client->user = ''; syncCode(syncRequest($ops, $folder, $baseline), 'ErrorInternalServerError'); $client->user = 'user';
        $client->accountSuffix = '-other'; syncCode(syncRequest($ops, $folder, $baseline), 'ErrorInvalidSyncStateData'); $client->accountSuffix = '';
        $client->api = 'https://other.test/jmap'; syncCode(syncRequest($ops, $folder, $baseline), 'ErrorInvalidSyncStateData'); $client->api = 'https://example.test/jmap';
        syncCode(syncRequest($ops, $folder, '../bad'), 'ErrorInvalidSyncStateData');
        syncCode(syncRequest($ops, $folder, null, 0), 'ErrorInvalidParameter');
        syncCode(syncRequest($ops, $folder, null, 513), 'ErrorInvalidParameter');
        syncCode(syncRequest($ops, $folder, null, 1, 'Unsupported'), 'ErrorInvalidParameter');
        syncCode(syncRequest($ops, $folder, null, 1, 'Default', false, '<t:AdditionalProperties><t:FieldURI FieldURI="item:Subject"/></t:AdditionalProperties>'), 'ErrorInvalidParameter');
        $client->folderError = true; syncCode(syncRequest($ops, $folder, $firstPageToken), 'ErrorInternalServerError'); $client->folderError = false;
        syncCode(syncRequest($ops, 'missing'), 'ErrorInvalidSyncFolderId');
        foreach ($client->seenAccounts as [$method, $account]) {
            if (str_starts_with($method, 'ContactCard/')) syncCheck($account === 'contact-account', 'contacts account');
            if (str_starts_with($method, 'CalendarEvent/')) syncCheck($account === 'calendar-account', 'calendar account');
        }
        if ($prefix === '') {
            $xp = syncRequest($ops, 'inbox', $firstPageToken, 19, 'IdOnly', true); syncCode($xp, 'NoError');
            $client->folderError = true; syncCode(syncRequest($ops, 'inbox', $firstPageToken, 19, 'IdOnly', true), 'ErrorInternalServerError'); $client->folderError = false;
            $client->folder = 'moved'; syncCode(syncRequest($ops, 'inbox', $firstPageToken, 19, 'IdOnly', true), 'ErrorInvalidSyncStateData');
        }
    }
    foreach (['itemError', 'missing', 'notFound', 'race'] as $failure) {
        $client = new SyncIntegrationClient(); $client->user = 'failure-' . $failure;
        $client->items['x'] = ['id' => 'x']; $client->$failure = true;
        $xp = syncRequest(new EwsOperations($client), 'folder'); syncCode($xp, 'ErrorInternalServerError');
        syncCheck(syncValue($xp, 'SyncState') === '', 'no state on JMAP error');
    }
    $folderRace = new SyncIntegrationClient(); $folderRace->user = 'folder-race'; $folderRace->folderRace = true;
    $xp = syncRequest(new EwsOperations($folderRace), 'folder'); syncCode($xp, 'ErrorInvalidSyncFolderId');
    syncCheck(syncValue($xp, 'SyncState') === '', 'no state when folder disappears during scan');
    $bulk = new SyncIntegrationClient(); $bulk->user = 'bulk';
    for ($i = 0; $i < 205; $i++) { $id = 'i' . sprintf('%03d', $i); $bulk->items[$id] = ['id' => $id]; }
    $xp = syncRequest(new EwsOperations($bulk), 'folder', null, 512); syncCode($xp, 'NoError');
    syncCheck($xp->query('//t:Create')->length === 205 && syncValue($xp, 'IncludesLastItemInRange') === 'true', '512 does not cap at 100');
    $invalid = new DOMDocument();
    $invalid->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId><t:MaxChangesReturned>10</t:MaxChangesReturned></m:SyncFolderItems>');
    $res = new DOMDocument(); $res->loadXML((new EwsOperations($bulk))->syncFolderItems($invalid->documentElement));
    $xp = new DOMXPath($res); $xp->registerNamespace('m', EwsSoap::NS_M); syncCode($xp, 'ErrorInvalidParameter');
    $invalid->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId><t:SyncState>bad</t:SyncState><m:MaxChangesReturned>10</m:MaxChangesReturned></m:SyncFolderItems>');
    $res->loadXML((new EwsOperations($bulk))->syncFolderItems($invalid->documentElement));
    $xp = new DOMXPath($res); $xp->registerNamespace('m', EwsSoap::NS_M); syncCode($xp, 'ErrorInvalidParameter');
    $invalid->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId></m:SyncFolderItems>');
    $res->loadXML((new EwsOperations($bulk))->syncFolderItems($invalid->documentElement));
    $xp = new DOMXPath($res); $xp->registerNamespace('m', EwsSoap::NS_M); syncCode($xp, 'ErrorInvalidParameter');
    echo "sync_sqlite_integration: OK\n";
} finally { syncClean($root); }
