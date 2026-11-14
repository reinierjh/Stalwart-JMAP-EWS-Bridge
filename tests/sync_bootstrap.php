<?php
// Real bootstrap, both autoload entry points, converter, success and invalid-state
// error paths; only the JMAP transport is fake. Uses and removes a private temp dir.
// Run twice: php tests/sync_bootstrap.php store-first / exception-first.
ob_start();
$dir = sys_get_temp_dir() . '/ews-sync-bootstrap-' . bin2hex(random_bytes(8));
define('EWS_SYNC_STATE_DIR', $dir);
require __DIR__ . '/../ews/bootstrap.php';

$first = ($argv[1] ?? 'store-first') === 'exception-first' ? 'EwsSyncException' : 'EwsSyncStateStore';
if (!class_exists($first) || !class_exists('EwsSyncStateStore') || !class_exists('EwsSyncException')) throw new RuntimeException('Sync autoload failed');

class SyncBootstrapClient extends JmapClient {
    public function __construct() {}
    public function getSession(): array { return ['username' => 'principal']; }
    public function getApiUrl(): string { return 'https://example.test/jmap'; }
    public function getAccountId(): string { return 'account'; }
    public function getCapabilityAccountId(string $capability): string { return 'account'; }
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $methodCalls[0];
        if ($method === 'Mailbox/get') $result = ['accountId' => 'account', 'list' => [['id' => 'folder']], 'notFound' => []];
        elseif ($method === 'Email/query') $result = ['accountId' => 'account', 'queryState' => 'q', 'position' => 0, 'total' => 1, 'ids' => ['item']];
        elseif ($method === 'Email/get') $result = ['accountId' => 'account', 'state' => 's', 'list' => $args['ids'] ? [['id' => 'item', 'subject' => 'hello']] : [], 'notFound' => []];
        else throw new RuntimeException('Unexpected JMAP method');
        return [[$method, $result, $tag]];
    }
}
try {
    $ops = new EwsOperations(new SyncBootstrapClient());
    $dom = new DOMDocument();
    $dom->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId><m:MaxChangesReturned>1</m:MaxChangesReturned></m:SyncFolderItems>');
    $response = new DOMDocument();
    if (!$response->loadXML($ops->syncFolderItems($dom->documentElement))) throw new RuntimeException('Invalid response');
    $xp = new DOMXPath($response); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T);
    if ($xp->evaluate('string(//m:ResponseCode)') !== 'NoError' || $xp->query('//t:Create/t:Message/t:ItemId[@Id="item"]')->length !== 1 ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $xp->evaluate('string(//m:SyncState)'))) throw new RuntimeException('Bootstrap sync failed');
    $dom->loadXML('<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:SyncFolderId><t:FolderId Id="folder"/></m:SyncFolderId><m:SyncState>invalid</m:SyncState></m:SyncFolderItems>');
    $response->loadXML($ops->syncFolderItems($dom->documentElement));
    $xp = new DOMXPath($response); $xp->registerNamespace('m', EwsSoap::NS_M);
    if ($xp->evaluate('string(//m:ResponseCode)') !== 'ErrorInvalidSyncStateData') throw new RuntimeException('Bootstrap error path failed');
    if (ob_get_clean() !== '') throw new RuntimeException('Bootstrap emitted unexpected output');
    echo "sync_bootstrap ($first): OK\n";
} finally {
    foreach (glob($dir . '/{*,.*}', GLOB_BRACE) ?: [] as $path) if (is_file($path) || is_link($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
