<?php
// Real converter and SQLite, fake JMAP only. All state is confined to this test.
$root = sys_get_temp_dir() . '/ews-sync-calendar-read-' . bin2hex(random_bytes(8));
define('EWS_SYNC_STATE_DIR', $root);
require __DIR__ . '/../ews/bootstrap.php';

class CalendarSyncClient extends JmapClient {
    public array $items = [];
    public array $calls = [];
    public string $user = 'calendar-reader';
    public string $state = 'q1';
    public bool $offline = false;
    public bool $race = false;
    public function __construct() {}
    public function getSession(): array { return ['username' => $this->user]; }
    public function getApiUrl(): string { return 'https://example.test/jmap'; }
    public function getCapabilityAccountId(string $capability): string { return $capability === self::CAP_CALENDARS ? 'calendar-account' : 'mail-account'; }
    public function call(array $calls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $calls[0];
        $this->calls[] = [$method, $args['accountId'], $using];
        if ($args['accountId'] !== 'calendar-account' || $using !== [self::CAP_CORE, self::CAP_CALENDARS]) throw new RuntimeException('Wrong calendar account/capability');
        if ($method === 'Calendar/get') $result = ['accountId' => 'calendar-account', 'list' => [['id' => 'folder', 'isDefault' => true]], 'notFound' => []];
        elseif ($this->offline) return [['error', ['type' => 'serverFail'], $tag]];
        elseif ($method === 'CalendarEvent/query') {
            $ids = array_keys($this->items);
            $result = ['accountId' => 'calendar-account', 'queryState' => $this->state, 'position' => $args['position'], 'total' => count($ids), 'ids' => array_slice($ids, $args['position'], $args['limit'])];
        } elseif ($method === 'CalendarEvent/get') {
            $list = []; $notFound = [];
            foreach ($args['ids'] as $id) if (isset($this->items[$id])) $list[] = $this->items[$id]; else $notFound[] = $id;
            $result = ['accountId' => 'calendar-account', 'state' => $this->state, 'list' => $list, 'notFound' => $notFound];
            if ($this->race) { $this->state .= 'x'; $this->race = false; }
        } else throw new RuntimeException('Unexpected method ' . $method);
        return [[$method, $result, $tag]];
    }
}
function checkCalendarSync(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function calendarSyncRequest(EwsOperations $ops, ?string $token = null, int $max = 512, string $shape = 'Default', bool $badNamespace = false): DOMXPath {
    $maxTag = $badNamespace ? 't' : 'm';
    $xml = '<m:SyncFolderItems xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemShape><t:BaseShape>' . $shape . '</t:BaseShape></m:ItemShape><m:SyncFolderId><t:DistinguishedFolderId Id="calendar"/></m:SyncFolderId>' .
        ($token === null ? '' : '<m:SyncState>' . htmlspecialchars($token, ENT_XML1) . '</m:SyncState>') . '<' . $maxTag . ':MaxChangesReturned>' . $max . '</' . $maxTag . ':MaxChangesReturned></m:SyncFolderItems>';
    $request = new DOMDocument(); checkCalendarSync($request->loadXML($xml), 'request XML');
    $response = new DOMDocument(); checkCalendarSync($response->loadXML($ops->syncFolderItems($request->documentElement)), 'response XML');
    $xp = new DOMXPath($response); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T); return $xp;
}
function calendarSyncValue(DOMXPath $xp, string $path): string { return $xp->evaluate('string(' . $path . ')'); }
function calendarSyncCode(DOMXPath $xp, string $code): void { checkCalendarSync(calendarSyncValue($xp, '//m:ResponseCode') === $code, 'expected ' . $code . ', got ' . calendarSyncValue($xp, '//m:ResponseCode')); }
function calendarSyncCounts(string $root): array {
    $db = new PDO('sqlite:' . $root . '/sync.sqlite3');
    $counts = []; foreach (['runs', 'snapshots', 'item_versions', 'tokens'] as $table) $counts[$table] = (int)$db->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    return $counts;
}
function calendarSyncClean(string $root): void {
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) if ($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
    rmdir($root);
}
try {
    $base = ['id' => '', 'title' => 'original', 'start' => '2025-03-29T10:00:00', 'timeZone' => 'UTC', 'duration' => 'PT1H'];
    $client = new CalendarSyncClient();
    foreach ([
        'a-utc' => [],
        'b-etc' => ['timeZone' => 'Etc/UTC'],
        'c-dst-before' => ['timeZone' => 'Europe/Amsterdam'],
        'd-dst-after' => ['timeZone' => 'Europe/Amsterdam', 'start' => '2025-03-30T10:00:00'],
        'e-recurring' => ['timeZone' => 'Europe/Amsterdam', 'recurrenceRule' => ['frequency' => 'daily', 'count' => 4]],
        'f-allday' => ['timeZone' => 'Europe/Amsterdam', 'start' => '2025-03-30T00:00:00', 'duration' => 'P1D', 'showWithoutTime' => true],
    ] as $id => $change) $client->items[$id] = array_replace($base, $change, ['id' => $id]);
    $ops = new EwsOperations($client);
    $x = calendarSyncRequest($ops, null, 512, 'AllProperties'); calendarSyncCode($x, 'NoError');
    checkCalendarSync($x->query('//t:Create/t:CalendarItem')->length === 6, 'six calendar creates');
    foreach ([
        'a-utc' => ['2025-03-29T10:00:00Z', '2025-03-29T11:00:00Z', 'UTC'],
        'b-etc' => ['2025-03-29T10:00:00Z', '2025-03-29T11:00:00Z', 'UTC'],
        'c-dst-before' => ['2025-03-29T09:00:00Z', '2025-03-29T10:00:00Z', 'W. Europe Standard Time'],
        'd-dst-after' => ['2025-03-30T08:00:00Z', '2025-03-30T09:00:00Z', 'W. Europe Standard Time'],
        'f-allday' => ['2025-03-29T23:00:00Z', '2025-03-30T22:00:00Z', 'W. Europe Standard Time'],
    ] as $id => [$start, $end, $zone]) {
        $path = '//t:CalendarItem[t:ItemId/@Id="cal_' . $id . '"]';
        checkCalendarSync(calendarSyncValue($x, $path . '/t:Start') === $start && calendarSyncValue($x, $path . '/t:End') === $end && calendarSyncValue($x, $path . '/t:StartTimeZone/@Id') === $zone, 'times/zone ' . $id);
        checkCalendarSync(calendarSyncValue($x, $path . '/t:ItemId/@ChangeKey') === EwsConverter::itemChangeKey($client->items[$id]), 'change key ' . $id);
    }
    checkCalendarSync(calendarSyncValue($x, '//t:CalendarItem[t:ItemId/@Id="cal_f-allday"]/t:IsAllDayEvent') === 'true', 'all-day');
    checkCalendarSync(calendarSyncValue($x, '//t:CalendarItem[t:ItemId/@Id="cal_e-recurring"]/t:CalendarItemType') === 'RecurringMaster' && calendarSyncValue($x, '//t:CalendarItem[t:ItemId/@Id="cal_e-recurring"]/t:Recurrence/t:NumberedRecurrence/t:NumberOfOccurrences') === '4', 'recurrence count');
    $baseline = calendarSyncValue($x, '//m:SyncState');
    calendarSyncCode(calendarSyncRequest($ops, null, 1, 'IdOnly', true), 'ErrorInvalidParameter');

    // An initial run is reused: change shape and page size while offline, then
    // mutate the source to prove pages contain the immutable old versions.
    $x = calendarSyncRequest($ops, null, 1, 'IdOnly'); calendarSyncCode($x, 'NoError');
    checkCalendarSync($x->query('//t:CalendarItem/*[not(self::t:ItemId)]')->length === 0 && $x->query('//t:CalendarItem/t:ItemId')->length === 1, 'IdOnly schema');
    $page = calendarSyncValue($x, '//m:SyncState'); $client->items['e-recurring']['title'] = 'changed'; $client->state = 'q2'; $client->offline = true;
    $x = calendarSyncRequest($ops, $page, 512, 'Default'); calendarSyncCode($x, 'NoError');
    checkCalendarSync(calendarSyncValue($x, '//t:CalendarItem[t:ItemId/@Id="cal_e-recurring"]/t:Subject') === 'original', 'immutable page');
    checkCalendarSync(calendarSyncValue($x, '//m:IncludesLastItemInRange') === 'true', 'last page');
    $client->offline = false;
    $x = calendarSyncRequest($ops, $baseline); calendarSyncCode($x, 'NoError');
    checkCalendarSync($x->query('//t:Update/t:CalendarItem/t:ItemId[@Id="cal_e-recurring"]')->length === 1, 'update diff');
    $next = calendarSyncValue($x, '//m:SyncState'); $client->offline = true;
    $retry = calendarSyncRequest($ops, $baseline); calendarSyncCode($retry, 'NoError');
    checkCalendarSync(calendarSyncValue($retry, '//m:SyncState') === $next, 'idempotent retry'); $client->offline = false;
    $client->user = 'other'; calendarSyncCode(calendarSyncRequest($ops, $baseline), 'ErrorInvalidSyncStateData'); $client->user = 'calendar-reader';

    // Each invalid scan is attempted with both shapes and must not publish a
    // run, snapshot, version or token, even if earlier items were valid.
    $badCases = [
        'floating' => ['timeZone' => null], 'unmapped' => ['timeZone' => 'Europe/Paris'],
        'invalid date' => ['start' => '2025-02-30T10:00:00'], 'invalid duration' => ['duration' => 'PT0S'],
        'all-day mismatch' => ['showWithoutTime' => true], 'override' => ['recurrenceOverrides' => ['2025-03-30T10:00:00' => ['excluded' => true]]],
        'empty override' => ['recurrenceOverrides' => ['2025-03-30T10:00:00' => []]],
        'occurrence' => ['recurrenceId' => '2025-03-30T10:00:00'], 'monthly' => ['recurrenceRule' => ['frequency' => 'monthly']],
        'DST unsafe recurrence' => ['timeZone' => 'Europe/Amsterdam', 'start' => '2025-03-29T01:30:00', 'recurrenceRule' => ['frequency' => 'daily']],
        'cancelled' => ['status' => 'cancelled'], 'privacy' => ['privacy' => 'secret'],
        'unsupported alert' => ['alerts' => ['a' => ['action' => 'email', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M']]]],
        'bad participant' => ['participants' => ['p' => ['name' => 'No address']]],
        'malformed categories' => ['categories' => 'invalid'],
    ];
    foreach ($badCases as $label => $change) foreach (['IdOnly', 'Default'] as $shape) {
        $client->user = 'bad-' . $label . '-' . $shape;
        $client->items = ['a-valid' => array_replace($base, ['id' => 'a-valid']), 'z-invalid' => array_replace($base, $change, ['id' => 'z-invalid'])];
        $before = calendarSyncCounts($root);
        $x = calendarSyncRequest($ops, null, 1, $shape);
        calendarSyncCode($x, $label === 'malformed categories' ? 'ErrorInternalServerError' : 'ErrorInvalidOperation');
        checkCalendarSync(calendarSyncValue($x, '//m:SyncState') === '' && calendarSyncCounts($root) === $before, 'invalid scan published state: ' . $label . '/' . $shape);
    }
    // Failure from an existing complete baseline must not consume it or publish
    // a run; correcting the event can then advance that same baseline.
    $client->user = 'calendar-reader';
    $client->items = ['x' => array_replace($base, ['id' => 'x', 'recurrenceOverrides' => ['x' => []]])];
    $before = calendarSyncCounts($root); $x = calendarSyncRequest($ops, $next, 512, 'IdOnly'); calendarSyncCode($x, 'ErrorInvalidOperation');
    checkCalendarSync(calendarSyncValue($x, '//m:SyncState') === '' && calendarSyncCounts($root) === $before, 'failed baseline scan changed state');
    unset($client->items['x']['recurrenceOverrides']);
    $x = calendarSyncRequest($ops, $next, 512, 'IdOnly'); calendarSyncCode($x, 'NoError');
    checkCalendarSync($x->query('//t:Create/t:CalendarItem/t:ItemId[@Id="cal_x"]')->length === 1 && $x->query('//t:CalendarItem/*[not(self::t:ItemId)]')->length === 0, 'baseline retry after correction');
    $client->user = 'race'; $client->race = true; $before = calendarSyncCounts($root);
    $x = calendarSyncRequest($ops); calendarSyncCode($x, 'ErrorInternalServerError');
    checkCalendarSync(calendarSyncValue($x, '//m:SyncState') === '' && calendarSyncCounts($root) === $before, 'query race published state');
    echo "sync_calendar_read: OK\n";
} finally { calendarSyncClean($root); }
