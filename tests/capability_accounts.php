<?php

// Sources may also be preloaded when running this test over stdin.
if (!class_exists('EwsOperations')) {
    require_once __DIR__ . '/../ews/src/Soap.php';
    require_once __DIR__ . '/../jmap/jmap_calendar.php';
    require_once __DIR__ . '/../ews/src/Converter.php';
    require_once __DIR__ . '/../ews/src/Operations.php';
}
if (!defined('JMAP_MAX_OBJECTS_PER_REQUEST')) define('JMAP_MAX_OBJECTS_PER_REQUEST', 100);

class JmapClient {
    const CAP_CORE = 'core', CAP_MAIL = 'mail', CAP_CONTACTS = 'contacts', CAP_CALENDARS = 'calendars';
    public array $calls = [];
    public bool $noBooks = false;
    public bool $noCalendars = false;
    public bool $noDefaultCalendar = false;
    public function getAccountId(): string { return 'mail-account'; }
    public function getCapabilityAccountId(string $cap): string {
        return match ($cap) { self::CAP_CONTACTS => 'contact-account', self::CAP_CALENDARS => 'calendar-account', default => 'mail-account' };
    }
    public function call(array $calls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        $results = [];
        foreach ($calls as [$method, $args, $tag]) {
            $this->calls[] = [$method, $args, $using];
            $account = str_starts_with($method, 'ContactCard/') || str_starts_with($method, 'AddressBook/') ? 'contact-account'
                : (str_starts_with($method, 'Calendar') ? 'calendar-account' : 'mail-account');
            if (($args['accountId'] ?? null) !== $account) throw new RuntimeException("Wrong account for $method");
            $cap = $account === 'contact-account' ? self::CAP_CONTACTS : ($account === 'calendar-account' ? self::CAP_CALENDARS : self::CAP_MAIL);
            if (!in_array(self::CAP_CORE, $using, true) || !in_array($cap, $using, true)) throw new RuntimeException("Wrong capabilities for $method");
            $contact = ['id' => 'c1', 'addressBookIds' => ['book-id' => true], 'name' => ['full' => 'Jane'], 'emails' => ['e1' => ['address' => 'jane@example.test']]];
            $event = ['id' => 'e1', 'calendarIds' => ['calendar-id' => true], 'title' => 'Meeting', 'start' => '2025-01-01T12:00:00', 'duration' => 'PT1H'];
            $list = match ($method) {
                'Mailbox/get' => [['id' => 'mailbox-id', 'name' => 'Inbox', 'role' => 'inbox']],
                'AddressBook/get' => $this->noBooks ? [] : [['id' => 'other-book'], ['id' => 'book-id', 'name' => 'Contacts', 'isDefault' => true]],
                'Calendar/get' => $this->noCalendars ? [] : ($this->noDefaultCalendar ? [['id' => 'other-calendar', 'name' => 'Other']] : [['id' => 'other-calendar', 'name' => 'Other'], ['id' => 'calendar-id', 'name' => 'Calendar', 'isDefault' => true]]),
                'ContactCard/get' => ($args['ids'] ?? null) === [] ? [] : [$contact],
                'CalendarEvent/get' => ($args['ids'] ?? null) === [] ? [] : [$event],
                'Email/get' => [['id' => 'm1', 'mailboxIds' => ['mailbox-id' => true], 'subject' => 'Mail']],
                default => [],
            };
            if (str_ends_with($method, '/query')) {
                $ids = str_starts_with($method, 'ContactCard/') ? ['c1'] : ['e1'];
                $result = ['accountId' => $account, 'ids' => $ids, 'queryState' => 'q1', 'position' => 0, 'total' => 1];
            } elseif (str_ends_with($method, '/set')) {
                $result = ['accountId' => $account];
                foreach ($args['create'] ?? [] as $key => $_) $result['created'][$key] = ['id' => 'new-id'];
                foreach ($args['update'] ?? [] as $key => $_) $result['updated'][$key] = new stdClass();
                if (isset($args['destroy'])) $result['destroyed'] = $args['destroy'];
            } else {
                if (isset($args['ids']) && $args['ids'] !== null && !str_ends_with($method, '/query')) {
                    $list = array_values(array_filter($list, fn($item) => in_array($item['id'], $args['ids'], true)));
                }
                $result = ['accountId' => $account, 'list' => $list, 'notFound' => [], 'state' => 's1'];
            }
            $results[] = [$method, $result, $tag];
        }
        return $results;
    }
}

function checkAccount(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function requestAccount(string $name, string $body = ''): DOMElement {
    $dom = new DOMDocument();
    checkAccount($dom->loadXML('<m:' . $name . ' xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '">' . $body . '</m:' . $name . '>'), 'request XML');
    return $dom->documentElement;
}
function assertSuccessAccount(string $xml): void { checkAccount(str_contains($xml, '<m:ResponseCode>NoError</m:ResponseCode>'), $xml); }
function callsAccount(JmapClient $client, string $method): array {
    return array_values(array_filter($client->calls, fn($call) => $call[0] === $method));
}

$client = new JmapClient(); $ops = new EwsOperations($client);
foreach (['findFolder', 'syncFolderHierarchy'] as $method) {
    $xml = $ops->$method(requestAccount(ucfirst($method)));
    assertSuccessAccount($xml);
    checkAccount(str_contains($xml, 'ab_book-id') && str_contains($xml, 'cal_calendar-id') && str_contains($xml, 'mailbox-id'), "$method folder IDs");
}
$xml = $ops->getFolder(requestAccount('GetFolder', '<m:FolderIds><t:DistinguishedFolderId Id="contacts"/><t:DistinguishedFolderId Id="calendar"/></m:FolderIds>'));
assertSuccessAccount($xml);
checkAccount(str_contains($xml, 'ab_book-id') && str_contains($xml, 'cal_calendar-id'), 'distinguished folder IDs');
$xml = $ops->getItem(requestAccount('GetItem', '<m:ItemIds><t:ItemId Id="m1"/><t:ItemId Id="ab_c1"/><t:ItemId Id="cal_e1"/></m:ItemIds>'));
checkAccount(substr_count($xml, '<m:ResponseCode>NoError</m:ResponseCode>') === 3, 'mixed GetItem');
checkAccount(str_contains($xml, 'Id="ab_book-id"') && str_contains($xml, 'Id="cal_calendar-id"'), 'item parent IDs');
checkAccount(count(callsAccount($client, 'Email/get')) === 1 && count(callsAccount($client, 'ContactCard/get')) === 1 && count(callsAccount($client, 'CalendarEvent/get')) === 1, 'mixed GetItem calls');

$client->calls = [];
assertSuccessAccount($ops->resolveNames(requestAccount('ResolveNames', '<t:UnresolvedEntry>Jane</t:UnresolvedEntry>')));
$query = callsAccount($client, 'ContactCard/query')[0][1];
checkAccount(($query['filter']['inAddressBook'] ?? null) === 'book-id', 'ResolveNames uses default book ID');
checkAccount(count(callsAccount($client, 'ContactCard/get')) === 1, 'ResolveNames get');
assertSuccessAccount($ops->getUserAvailability(requestAccount('GetUserAvailability')));
checkAccount(count(callsAccount($client, 'CalendarEvent/query')) === 1 && count(callsAccount($client, 'CalendarEvent/get')) === 1, 'availability query/get');

foreach ([['Contact', 'ContactCard/set', 'addressBookIds', 'book-id'], ['CalendarItem', 'CalendarEvent/set', 'calendarIds', 'calendar-id']] as [$type, $method, $membership, $folder]) {
    foreach ([null, $folder . '-explicit'] as $explicit) {
        $client->calls = [];
        $prefix = $type === 'Contact' ? 'ab_' : 'cal_';
        $saved = $explicit === null ? '' : '<m:SavedItemFolderId><t:FolderId Id="' . $prefix . $explicit . '"/></m:SavedItemFolderId>';
        assertSuccessAccount($ops->createItem(requestAccount('CreateItem', $saved . '<m:Items><t:' . $type . '/></m:Items>')));
        $set = callsAccount($client, $method);
        checkAccount(count($set) === 1, "$type create set");
        $created = array_values($set[0][1]['create'])[0];
        checkAccount($created[$membership] === [($explicit ?? $folder) => true], "$type create folder ID");
        if ($type === 'CalendarItem') checkAccount(count(callsAccount($client, 'Calendar/get')) === ($explicit === null ? 1 : 0), 'calendar default lookup only when needed');
    }
}
$client->calls = [];
$updates = '<m:ItemChanges><t:ItemChange><t:ItemId Id="ab_c1"/><t:Updates><t:SetItemField><t:FieldURI FieldURI="contacts:DisplayName"/><t:Contact><t:DisplayName>New</t:DisplayName></t:Contact></t:SetItemField></t:Updates></t:ItemChange><t:ItemChange><t:ItemId Id="cal_e1"/><t:Updates><t:SetItemField><t:FieldURI FieldURI="calendar:Subject"/><t:CalendarItem>New</t:CalendarItem></t:SetItemField></t:Updates></t:ItemChange></m:ItemChanges>';
assertSuccessAccount($ops->updateItem(requestAccount('UpdateItem', $updates)));
checkAccount(count(callsAccount($client, 'ContactCard/get')) === 1 && count(callsAccount($client, 'ContactCard/set')) === 1 && count(callsAccount($client, 'CalendarEvent/set')) === 1, 'update read and writes');
checkAccount((callsAccount($client, 'ContactCard/set')[0][1]['ifInState'] ?? null) === 's1', 'contact update keeps state');
$client->calls = [];
assertSuccessAccount($ops->deleteItem(requestAccount('DeleteItem', '<m:ItemIds><t:ItemId Id="ab_c1"/><t:ItemId Id="cal_e1"/></m:ItemIds>')));
checkAccount(callsAccount($client, 'ContactCard/set')[0][1]['destroy'] === ['c1'] && callsAccount($client, 'CalendarEvent/set')[0][1]['destroy'] === ['e1'], 'delete raw IDs');

// Exercise both current sync resolution/snapshot and legacy query helpers.
foreach ([['contacts', 'book-id', 'contact-account', JmapClient::CAP_CONTACTS], ['calendar', 'calendar-id', 'calendar-account', JmapClient::CAP_CALENDARS]] as [$type, $folder, $account, $cap]) {
    $client->calls = [];
    $resolved = (new ReflectionMethod(EwsOperations::class, 'syncResolveFolder'))->invoke($ops, requestAccount('SyncFolderItems', '<m:SyncFolderId><t:DistinguishedFolderId Id="' . $type . '"/></m:SyncFolderId>'));
    checkAccount($resolved[1] === $folder && $resolved[3] === $account, 'sync folder capability account');
    (new ReflectionMethod(EwsOperations::class, 'syncSnapshot'))->invoke($ops, $type, $folder, $account, [JmapClient::CAP_CORE, $cap]);
    $method = $type === 'contacts' ? 'syncContacts' : 'syncCalendarEvents';
    (new ReflectionMethod(EwsOperations::class, $method))->invoke($ops, new DOMDocument(), $folder, [], null, 10);
    (new ReflectionMethod(EwsOperations::class, $method))->invoke($ops, new DOMDocument(), $folder, [], 0, 10);
    (new ReflectionMethod(EwsOperations::class, 'getCurrentQueryState'))->invoke($ops, $type, $folder);
    foreach ($client->calls as [$methodName, $args]) checkAccount($args['accountId'] === $account, "$methodName capability account");
    $queries = callsAccount($client, $type === 'contacts' ? 'ContactCard/query' : 'CalendarEvent/query');
    checkAccount(count($queries) === 5, 'snapshot and legacy queries executed');
    foreach ($queries as [, $args]) checkAccount(($args['filter'][$type === 'contacts' ? 'inAddressBook' : 'inCalendar'] ?? null) === $folder, 'query uses folder ID');
}
$client->noBooks = true; $client->noCalendars = true; $client->calls = [];
$xml = $ops->createItem(requestAccount('CreateItem', '<m:Items><t:Contact/></m:Items>'));
checkAccount(str_contains($xml, 'ErrorInternalServerError') && !callsAccount($client, 'ContactCard/set'), 'missing book does not write to account ID');
$client->calls = [];
$xml = $ops->createItem(requestAccount('CreateItem', '<m:Items><t:CalendarItem/></m:Items>'));
checkAccount(str_contains($xml, 'ErrorInternalServerError') && !callsAccount($client, 'CalendarEvent/set') && count(callsAccount($client, 'Calendar/get')) === 1, 'missing calendar fails without write');
$client->noCalendars = false; $client->noDefaultCalendar = true; $client->calls = [];
$xml = $ops->createItem(requestAccount('CreateItem', '<m:Items><t:CalendarItem/></m:Items>'));
checkAccount(str_contains($xml, 'ErrorInternalServerError') && !callsAccount($client, 'CalendarEvent/set'), 'calendar list without default fails without write');
$client->calls = [];
$xml = $ops->createItem(requestAccount('CreateItem', '<m:SavedItemFolderId><t:FolderId Id="cal_mail-account"/></m:SavedItemFolderId><m:Items><t:CalendarItem/></m:Items>'));
assertSuccessAccount($xml);
checkAccount(callsAccount($client, 'CalendarEvent/set')[0][1]['create']['ev1']['calendarIds'] === ['mail-account' => true] && !callsAccount($client, 'Calendar/get'), 'explicit calendar ID preserved even if equal to mail account');
$client->calls = [];
$xml = $ops->createItem(requestAccount('CreateItem', '<m:SavedItemFolderId><t:FolderId Id="mail-account"/></m:SavedItemFolderId><m:Items><t:CalendarItem/></m:Items>'));
assertSuccessAccount($xml);
checkAccount(callsAccount($client, 'CalendarEvent/set')[0][1]['create']['ev1']['calendarIds'] === ['mail-account' => true] && !callsAccount($client, 'Calendar/get'), 'explicit unprefixed calendar ID preserved');
$client->calls = [];
assertSuccessAccount($ops->resolveNames(requestAccount('ResolveNames', '<t:UnresolvedEntry>Jane</t:UnresolvedEntry>')));
checkAccount(!callsAccount($client, 'ContactCard/query'), 'no query without address book');
echo "capability account tests passed\n";
