<?php
// Isolated safety regression suite; no server or test-account writes.
define('EWS_SYNC_STATE_DIR', sys_get_temp_dir() . '/ews-calendar-safety-' . bin2hex(random_bytes(8)));
require __DIR__ . '/../ews/bootstrap.php';
require_once __DIR__ . '/../jmap/jmap_calendar.php';

class CalendarSafetyClient extends JmapClient {
    public array $calls = [];
    public array $event = ['id' => 'e1', 'title' => 'UTC', 'start' => '2032-01-02T10:00:00', 'timeZone' => 'UTC', 'duration' => 'PT1H'];
    public function __construct() {}
    public function getAccountId(): string { return 'account'; }
    public function getCapabilityAccountId(string $capability): string { return 'account'; }
    public function getSession(): array { return ['username' => 'principal']; }
    public function getApiUrl(): string { return 'https://example.test/jmap'; }
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $methodCalls[0];
        $this->calls[] = $methodCalls[0];
        $result = ['accountId' => 'account'];
        switch ($method) {
            case 'Calendar/get': $result += ['list' => [['id' => 'calendar']], 'notFound' => []]; break;
            case 'CalendarEvent/query': $result += ['queryState' => 'q', 'position' => 0, 'total' => 1, 'ids' => ['e1']]; break;
            case 'CalendarEvent/get': $result += ['state' => 's1', 'list' => [$this->event], 'notFound' => []]; break;
            case 'CalendarEvent/set': $result += isset($args['create']) ? ['created' => ['ev1' => ['id' => 'created']]] : ['updated' => ['e1' => null]]; break;
            default: throw new RuntimeException('Unexpected call: ' . $method);
        }
        return [[$method, $result, $tag]];
    }
}
function checkCalendar(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function calendarRequest(string $name, string $body, string $attributes = ''): DOMElement {
    $d = new DOMDocument();
    checkCalendar($d->loadXML('<m:' . $name . ' xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '" ' . $attributes . '>' . $body . '</m:' . $name . '>'), 'request XML');
    return $d->documentElement;
}
function expectCalendar(string $response, string $code): void {
    $d = new DOMDocument(); checkCalendar($d->loadXML($response), 'response XML');
    $xp = new DOMXPath($d); $xp->registerNamespace('m', EwsSoap::NS_M);
    checkCalendar($xp->evaluate('string(//m:ResponseCode)') === $code, 'expected ' . $code . ': ' . $response);
    if ($code !== 'NoError') checkCalendar(!str_contains($response, 'NoError'), 'partial success');
}
function noWrites(CalendarSafetyClient $client): void {
    foreach ($client->calls as $call) checkCalendar(!str_ends_with($call[0], '/set') && !str_ends_with($call[0], '/import'), 'unexpected write');
}
function createBody(string $item): string { return '<m:SavedItemFolderId><t:FolderId Id="cal_calendar"/></m:SavedItemFolderId><m:Items>' . $item . '</m:Items>'; }
function updateField(string $uri, string $element, string $value): string {
    return '<t:SetItemField><t:FieldURI FieldURI="' . $uri . '"/><t:CalendarItem><t:' . $element . '>' . $value . '</t:' . $element . '></t:CalendarItem></t:SetItemField>';
}
function change(string $id, string $fields): string { return '<t:ItemChange><t:ItemId Id="' . $id . '"/><t:Updates>' . $fields . '</t:Updates></t:ItemChange>'; }

$client = new CalendarSafetyClient(); $ops = new EwsOperations($client); $dom = new DOMDocument();
$xml = EwsConverter::calendarToXml($dom, $client->event, 'cal_calendar');
checkCalendar(str_contains($xml, '<t:Start>2032-01-02T10:00:00Z</t:Start>') && str_contains($xml, '<t:End>2032-01-02T11:00:00Z</t:End>') && str_contains($xml, '<t:CalendarItemType>Single</t:CalendarItemType>'), 'UTC conversion');
$unsafe = [
    ['timeZone' => null], ['showWithoutTime' => true],
    ['recurrenceRule' => []], ['recurrenceRule' => (object)[]],
    ['recurrenceRules' => [['frequency' => 'weekly']]], ['recurrenceOverrides' => ['2032-01-03T10:00:00' => ['excluded' => true]]],
    ['excludedRecurrenceRules' => [['frequency' => 'daily']]], ['recurrenceId' => '2032-01-03T10:00:00'],
    ['start' => '2032-03-28T02:30:00Z'], ['start' => '2032-02-30T10:00:00'], ['start' => '2032-01-02'], ['duration' => 'P1M'],
    ['duration' => 'PT0S'], ['duration' => 'PT1.5H'], ['duration' => 'bogus'], ['endTimeZone' => 'Europe/Amsterdam'],
];
try {
    foreach ([['timeZone' => 'Europe/Amsterdam'], ['recurrenceRule' => ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'interval' => 1]]] as $change) {
        $client = new CalendarSafetyClient(); $client->event = array_replace($client->event, $change); $ops = new EwsOperations($client);
        $converted = EwsConverter::calendarToXml($dom, $client->event, null);
        checkCalendar(str_contains($converted, isset($change['recurrenceRule']) ? '<t:CalendarItemType>RecurringMaster</t:CalendarItemType>' : 'W. Europe Standard Time'), 'supported read conversion');
        expectCalendar($ops->getItem(calendarRequest('GetItem', '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:ItemIds><t:ItemId Id="cal_e1"/></m:ItemIds>')), 'NoError');
        // Sync still has its independent, intentionally stricter guard.
        expectCalendar($ops->syncFolderItems(calendarRequest('SyncFolderItems', '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="cal_calendar"/></m:SyncFolderId><m:MaxChangesReturned>1</m:MaxChangesReturned>')), 'ErrorInvalidOperation');
        noWrites($client);
    }
    foreach ($unsafe as $change) {
        $client = new CalendarSafetyClient(); $client->event = array_replace($client->event, $change); $ops = new EwsOperations($client);
        try { EwsConverter::calendarToXml($dom, $client->event, null); throw new RuntimeException('unsafe conversion succeeded'); } catch (EwsCalendarSafetyException $expected) {}
        expectCalendar($ops->getItem(calendarRequest('GetItem', '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:ItemIds><t:ItemId Id="cal_e1"/></m:ItemIds>')), 'ErrorInvalidOperation');
        expectCalendar($ops->syncFolderItems(calendarRequest('SyncFolderItems', '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="cal_calendar"/></m:SyncFolderId><m:MaxChangesReturned>1</m:MaxChangesReturned>')), 'ErrorInvalidOperation');
        noWrites($client);
    }
    $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
    $client->event['recurrenceRule'] = null;
    expectCalendar($ops->getItem(calendarRequest('GetItem', '<m:ItemIds><t:ItemId Id="cal_e1"/></m:ItemIds>')), 'NoError');
    $sync = $ops->syncFolderItems(calendarRequest('SyncFolderItems', '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:SyncFolderId><t:FolderId Id="cal_calendar"/></m:SyncFolderId><m:MaxChangesReturned>1</m:MaxChangesReturned>'));
    expectCalendar($sync, 'NoError'); checkCalendar(str_contains($sync, 'Id="cal_e1"'), 'UTC IdOnly');

    $valid = '<t:CalendarItem><t:Subject>Example</t:Subject><t:Start>2032-01-02T10:00:00Z</t:Start><t:End>2032-01-02T11:00:00Z</t:End></t:CalendarItem>';
    $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
    expectCalendar($ops->createItem(calendarRequest('CreateItem', createBody($valid))), 'NoError');
    $event = $client->calls[0][1]['create']['ev1'];
    checkCalendar($event['timeZone'] === 'UTC' && $event['start'] === '2032-01-02T10:00:00' && $event['duration'] === 'PT1H', 'UTC create');
    foreach ([str_replace('</t:CalendarItem>', '<t:Recurrence/></t:CalendarItem>', $valid), str_replace('</t:CalendarItem>', '<t:StartTimeZone Id="Europe/Amsterdam"/></t:CalendarItem>', $valid), str_replace('</t:CalendarItem>', '<t:IsAllDayEvent>true</t:IsAllDayEvent></t:CalendarItem>', $valid), str_replace('10:00:00Z', '10:00:00', $valid), str_replace('10:00:00Z', '10:00:00+01:00', $valid), str_replace('11:00:00Z', '09:00:00Z', $valid), '<t:Contact/>' . $valid, $valid . $valid] as $item) {
        $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
        expectCalendar($ops->createItem(calendarRequest('CreateItem', createBody($item))), 'ErrorInvalidOperation'); noWrites($client);
    }
    $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
    $doc = new DOMDocument(); $doc->loadXML('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><s:Header><t:TimeZoneContext><t:TimeZoneDefinition Id="Europe/Amsterdam"/></t:TimeZoneContext></s:Header><s:Body><m:CreateItem>' . createBody($valid) . '</m:CreateItem></s:Body></s:Envelope>');
    expectCalendar($ops->createItem($doc->getElementsByTagNameNS(EwsSoap::NS_M, 'CreateItem')->item(0)), 'ErrorInvalidOperation'); noWrites($client);
    $subject = updateField('item:Subject', 'Subject', 'Changed');
    $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
    expectCalendar($ops->updateItem(calendarRequest('UpdateItem', '<m:ItemChanges>' . change('cal_e1', $subject) . '</m:ItemChanges>')), 'NoError');
    checkCalendar($client->calls[1][1]['ifInState'] === 's1' && $client->calls[1][1]['update']['e1']['title'] === 'Changed', 'guarded subject update');
    foreach ([updateField('calendar:Recurrence', 'Recurrence', ''), updateField('calendar:Start', 'Start', '2032-01-02T10:00:00Z'), updateField('calendar:Unknown', 'Unknown', 'x'), '<t:DeleteItemField><t:FieldURI FieldURI="item:Subject"/></t:DeleteItemField>'] as $bad) {
        foreach ([$bad . $subject, $subject . $bad] as $fields) {
            $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
            expectCalendar($ops->updateItem(calendarRequest('UpdateItem', '<m:ItemChanges>' . change('cal_e1', $fields) . '</m:ItemChanges>')), 'ErrorInvalidPropertySet'); noWrites($client);
        }
    }
    foreach (['recurrenceRule' => ['frequency' => 'weekly'], 'recurrenceRules' => [['frequency' => 'weekly']]] as $property => $rule) {
        $client = new CalendarSafetyClient(); $client->event[$property] = $rule; $ops = new EwsOperations($client);
        expectCalendar($ops->updateItem(calendarRequest('UpdateItem', '<m:ItemChanges>' . change('cal_e1', $subject) . '</m:ItemChanges>')), 'ErrorInvalidPropertySet'); noWrites($client);
    }
    $client = new CalendarSafetyClient(); $ops = new EwsOperations($client);
    expectCalendar($ops->updateItem(calendarRequest('UpdateItem', '<m:ItemChanges>' . change('mail', $subject) . change('cal_e1', $subject) . '</m:ItemChanges>')), 'ErrorInvalidPropertySet'); noWrites($client);
    expectCalendar($ops->updateItem(calendarRequest('UpdateItem', '<m:ItemChanges><t:ItemChange><t:OccurrenceItemId RecurringMasterId="cal_e1" InstanceIndex="1"/><t:Updates/></t:ItemChange></m:ItemChanges>')), 'ErrorInvalidPropertySet'); noWrites($client);
    expectCalendar($ops->getItem(calendarRequest('GetItem', '<m:ItemIds><t:OccurrenceItemId RecurringMasterId="cal_e1" InstanceIndex="1"/></m:ItemIds>')), 'ErrorInvalidOperation');
    expectCalendar($ops->deleteItem(calendarRequest('DeleteItem', '<m:ItemIds><t:ItemId Id="mail"/><t:OccurrenceItemId RecurringMasterId="cal_e1" InstanceIndex="1"/></m:ItemIds>')), 'ErrorInvalidOperation'); noWrites($client);
    expectCalendar($ops->deleteItem(calendarRequest('DeleteItem', '<m:ItemIds><t:ItemId Id="mail"/><t:ItemId Id="cal_e1"/></m:ItemIds>')), 'ErrorInvalidOperation'); noWrites($client);
    expectCalendar($ops->findItem(calendarRequest('FindItem', '<m:CalendarView StartDate="2032-01-01T00:00:00Z" EndDate="2032-01-02T00:00:00Z"/>')), 'ErrorInvalidOperation');
    $response = $ops->getUserAvailability(calendarRequest('GetUserAvailabilityRequest', '<m:MailboxDataArray><t:MailboxData/><t:MailboxData/></m:MailboxDataArray>'));
    expectCalendar($response, 'ErrorInvalidOperation'); checkCalendar(substr_count($response, 'ErrorInvalidOperation') === 2 && !str_contains($response, 'FreeBusyView>'), 'availability errors per mailbox'); noWrites($client);
    echo "calendar safety tests passed\n";
} finally {
    foreach (glob(EWS_SYNC_STATE_DIR . '/{*,.*}', GLOB_BRACE) ?: [] as $path) if (is_file($path) || is_link($path)) unlink($path);
    if (is_dir(EWS_SYNC_STATE_DIR)) rmdir(EWS_SYNC_STATE_DIR);
}
