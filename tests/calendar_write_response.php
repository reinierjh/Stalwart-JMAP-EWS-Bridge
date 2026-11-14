<?php
// Fake JMAP only: no network or account writes.
require __DIR__ . '/../jmap/jmap_client.php';
require __DIR__ . '/../ews/src/Soap.php';
require __DIR__ . '/../ews/src/Operations.php';
require_once __DIR__ . '/../jmap/jmap_calendar.php';

class CalendarWriteClient extends JmapClient {
    public array $calls = [];
    public array $event = ['id' => 'e1', 'uid' => 'uid', 'calendarIds' => ['folder' => true], 'title' => 'Old', 'start' => '2032-01-02T10:00:00', 'timeZone' => 'UTC', 'duration' => 'PT1H'];
    public string $mode = '';
    public int $gets = 0;
    public function __construct() {}
    public function getAccountId(): string { return 'mail-account'; }
    public function getCapabilityAccountId(string $capability): string { return $capability === self::CAP_CALENDARS ? 'calendar-account' : 'contact-account'; }
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        checkWrite(count($methodCalls) === 1, 'single method call');
        [$method, $args, $tag] = $methodCalls[0];
        $this->calls[] = [$method, $args, $using];
        checkWrite($args['accountId'] === 'calendar-account' && $using === [self::CAP_CORE, self::CAP_CALENDARS], 'capability account and using');
        if ($method === 'CalendarEvent/get') {
            $this->gets++;
            checkWrite($args['properties'] === null && $args['ids'] === [$this->event['id']], 'default object read');
            if ($this->mode === 'read-throws' && $this->gets > 1 || $this->mode === 'create-read-throws') throw new RuntimeException('secret backend detail');
            $data = ['accountId' => 'calendar-account', 'state' => $this->gets === 1 && !isset($this->created) ? 's1' : 's2', 'list' => [$this->event], 'notFound' => []];
            if ($this->mode === 'read-race' && ($this->gets > 1 || isset($this->created))) $data['state'] = 's3';
            if ($this->mode === 'read-wrong-id') $data['list'][0]['id'] = 'wrong';
            if ($this->mode === 'read-wrong-account') $data['accountId'] = 'wrong';
            if ($this->mode === 'read-notfound') $data['notFound'] = ['e1'];
            return [[$method, $data, $tag]];
        }
        checkWrite($method === 'CalendarEvent/set', 'unexpected method');
        if ($this->mode === 'set-throws') throw new RuntimeException('secret backend detail');
        if ($this->mode === 'state-mismatch') return [['error', ['type' => 'stateMismatch'], $tag]];
        $create = isset($args['create']);
        if ($create) {
            $this->event = ['id' => 'created'] + $args['create']['ev1'];
            $this->created = true;
            $data = ['accountId' => 'calendar-account', 'oldState' => 's1', 'newState' => 's2', 'created' => ['ev1' => ['id' => 'created']], 'notCreated' => null];
        } else {
            checkWrite($args['ifInState'] === 's1' && $args['update'] === ['e1' => ['title' => 'Changed']], 'guarded subject patch');
            $this->event['title'] = 'Changed';
            $data = ['accountId' => 'calendar-account', 'oldState' => 's1', 'newState' => 's2', 'updated' => ['e1' => null], 'notUpdated' => null];
        }
        if ($this->mode === 'set-error') return [['error', ['type' => 'serverFail', 'description' => 'secret backend detail'], $tag]];
        if ($this->mode === 'no-success') unset($data[$create ? 'created' : 'updated']);
        if ($this->mode === 'contradictory') $data[$create ? 'notCreated' : 'notUpdated'] = [$create ? 'ev1' : 'e1' => ['type' => 'invalidProperties']];
        if ($this->mode === 'object-conflict' && !$create) { $data['updated'] = null; $data['notUpdated'] = ['e1' => ['type' => 'conflict']]; }
        if ($this->mode === 'wrong-account') $data['accountId'] = 'other';
        if ($this->mode === 'wrong-state') $data['oldState'] = 'other';
        if ($this->mode === 'no-new-state') unset($data['newState']);
        if ($this->mode === 'wrong-tag') $tag = 'other';
        if ($this->mode === 'wrong-method') $method = 'Email/set';
        if ($this->mode === 'wrong-title') $this->event['title'] = 'wrong';
        if ($this->mode === 'invalid-created-id' && $create) $data['created']['ev1']['id'] = "bad\x01id";
        if ($this->mode === 'extra-response') return [[$method, $data, $tag], [$method, $data, $tag]];
        return [[$method, $data, $tag]];
    }
    private bool $created;
}
function checkWrite(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function requestWrite(string $operation, string $body, string $attributes = ''): DOMElement {
    $doc = new DOMDocument();
    checkWrite($doc->loadXML('<m:' . $operation . ' xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '" ' . $attributes . '>' . $body . '</m:' . $operation . '>'), 'request XML');
    return $doc->documentElement;
}
function createWrite(string $attributes = ''): DOMElement {
    return requestWrite('CreateItem', '<m:SavedItemFolderId><t:FolderId Id="cal_folder"/></m:SavedItemFolderId><m:Items><t:CalendarItem><t:Subject>Example</t:Subject><t:Start>2032-01-02T10:00:00Z</t:Start><t:End>2032-01-02T11:00:00Z</t:End></t:CalendarItem></m:Items>', $attributes);
}
function updateWrite(?string $key, string $attributes = ''): DOMElement {
    $keyAttr = $key === null ? '' : ' ChangeKey="' . htmlspecialchars($key, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
    return requestWrite('UpdateItem', '<m:ItemChanges><t:ItemChange><t:ItemId Id="cal_e1"' . $keyAttr . '/><t:Updates><t:SetItemField><t:FieldURI FieldURI="item:Subject"/><t:CalendarItem><t:Subject>Changed</t:Subject></t:CalendarItem></t:SetItemField></t:Updates></t:ItemChange></m:ItemChanges>', $attributes);
}
function assertWrite(string $xml, string $operation, string $code, ?string $id = null, ?string $key = null): void {
    $doc = new DOMDocument(); checkWrite($doc->loadXML($xml, LIBXML_NONET), 'valid SOAP');
    $xp = new DOMXPath($doc); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T); $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
    $path = '/s:Envelope/s:Body/m:' . $operation . 'Response/m:ResponseMessages/m:' . $operation . 'ResponseMessage';
    checkWrite($xp->query($path)->length === 1 && $xp->evaluate('string(' . $path . '/m:ResponseCode)') === $code, 'response and code: ' . $xml);
    checkWrite($xp->evaluate('string(' . $path . '/@ResponseClass)') === ($code === 'NoError' ? 'Success' : 'Error'), 'response class');
    if ($code === 'NoError') {
        checkWrite($xp->query($path . '/m:Items/t:CalendarItem/t:ItemId')->length === 1 && $xp->query($path . '/m:Items/*')->length === 1, 'calendar item wrapper');
        checkWrite($xp->evaluate('string(' . $path . '/m:Items/t:CalendarItem/t:ItemId/@Id)') === $id && $xp->evaluate('string(' . $path . '/m:Items/t:CalendarItem/t:ItemId/@ChangeKey)') === $key, 'id and actual version');
        $children = []; foreach ($xp->query($path)->item(0)->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->localName;
        checkWrite($children === ['ResponseCode', 'Items'], 'response element order');
    } else checkWrite($xp->query('//t:ItemId')->length === 0 && !str_contains($xml, 'secret backend detail') && !str_contains($xml, 'NoError'), 'sanitized error without ID or success');
}
function writeCount(CalendarWriteClient $client): int { return count(array_filter($client->calls, fn($c) => $c[0] === 'CalendarEvent/set')); }

$client = new CalendarWriteClient(); $ops = new EwsOperations($client);
$response = $ops->createItem(createWrite('SendMeetingInvitations="SendToNone"'));
assertWrite($response, 'CreateItem', 'NoError', 'cal_created', EwsConverter::itemChangeKey($client->event));
checkWrite(writeCount($client) === 1 && $client->gets === 1, 'create write and read');
foreach (['set-error', 'no-success', 'contradictory', 'wrong-account', 'no-new-state', 'wrong-tag', 'wrong-method', 'invalid-created-id', 'extra-response', 'set-throws', 'create-read-throws', 'read-race', 'read-wrong-id', 'read-wrong-account', 'read-notfound'] as $mode) {
    $client = new CalendarWriteClient(); $client->mode = $mode;
    assertWrite((new EwsOperations($client))->createItem(createWrite()), 'CreateItem', 'ErrorInternalServerError');
    checkWrite(writeCount($client) === 1, 'no create retry: ' . $mode);
}
foreach (['', 'ConflictResolution="NeverOverwrite"', 'ConflictResolution="AutoResolve" SendMeetingInvitationsOrCancellations="SendToNone"'] as $attributes) {
    $client = new CalendarWriteClient(); $key = EwsConverter::itemChangeKey($client->event);
    assertWrite((new EwsOperations($client))->updateItem(updateWrite($key, $attributes)), 'UpdateItem', 'NoError', 'cal_e1', EwsConverter::itemChangeKey(array_replace($client->event, ['title' => 'Changed'])));
    checkWrite(writeCount($client) === 1 && $client->gets === 2, 'update write and reread');
}
foreach (['NeverOverwrite', 'AutoResolve'] as $resolution) {
    foreach ([null, '', 'stale'] as $key) {
        $client = new CalendarWriteClient();
        assertWrite((new EwsOperations($client))->updateItem(updateWrite($key, 'ConflictResolution="' . $resolution . '"')), 'UpdateItem', $key ? 'ErrorIrresolvableConflict' : 'ErrorChangeKeyRequired');
        checkWrite(writeCount($client) === 0, 'no write without current key');
    }
}
foreach (['ConflictResolution="AlwaysOverwrite"', 'ConflictResolution="bogus"', 'SendMeetingInvitationsOrCancellations="SendToAllAndSaveCopy"', 'SendMeetingInvitationsOrCancellations="SendToAll"'] as $attributes) {
    $client = new CalendarWriteClient();
    assertWrite((new EwsOperations($client))->updateItem(updateWrite(EwsConverter::itemChangeKey($client->event), $attributes)), 'UpdateItem', 'ErrorInvalidPropertySet');
    checkWrite(writeCount($client) === 0, 'unsupported update option rejected');
}
$client = new CalendarWriteClient(); assertWrite((new EwsOperations($client))->createItem(createWrite('SendMeetingInvitations="SendToAllAndSaveCopy"')), 'CreateItem', 'ErrorInvalidOperation'); checkWrite(writeCount($client) === 0, 'no scheduling create');
foreach (['state-mismatch', 'object-conflict', 'set-error', 'no-success', 'contradictory', 'wrong-account', 'wrong-state', 'no-new-state', 'wrong-tag', 'wrong-method', 'extra-response', 'set-throws', 'read-throws', 'read-race', 'wrong-title'] as $mode) {
    $client = new CalendarWriteClient(); $client->mode = $mode; $key = EwsConverter::itemChangeKey($client->event);
    $code = in_array($mode, ['state-mismatch', 'object-conflict', 'read-race', 'wrong-title'], true) ? 'ErrorIrresolvableConflict' : 'ErrorInternalServerError';
    assertWrite((new EwsOperations($client))->updateItem(updateWrite($key, 'ConflictResolution="AutoResolve"')), 'UpdateItem', $code);
    checkWrite(writeCount($client) === 1, 'no update retry: ' . $mode);
}
foreach (['read-wrong-id', 'read-wrong-account', 'read-notfound'] as $mode) {
    $client = new CalendarWriteClient(); $client->mode = $mode;
    assertWrite((new EwsOperations($client))->updateItem(updateWrite(EwsConverter::itemChangeKey($client->event))), 'UpdateItem', 'ErrorInternalServerError');
    checkWrite(writeCount($client) === 0, 'invalid pre-read cannot write');
}
$client = new CalendarWriteClient(); $client->event['recurrenceRule'] = ['frequency' => 'weekly'];
assertWrite((new EwsOperations($client))->updateItem(updateWrite(EwsConverter::itemChangeKey($client->event))), 'UpdateItem', 'ErrorInvalidPropertySet'); checkWrite(writeCount($client) === 0, 'recurrence guard');
foreach (['participants' => ['p' => ['email' => 'someone@example.test']], 'organizerCalendarAddress' => 'mailto:someone@example.test', 'replyTo' => ['imip' => 'mailto:someone@example.test']] as $field => $value) {
    $client = new CalendarWriteClient(); $client->event[$field] = $value;
    assertWrite((new EwsOperations($client))->updateItem(updateWrite(EwsConverter::itemChangeKey($client->event))), 'UpdateItem', 'ErrorInvalidPropertySet');
    checkWrite(writeCount($client) === 0, 'no scheduling writes');
}
foreach (['cal_e1', 'ab_c1', 'ewsocc_invalid'] as $id) {
    $client = new CalendarWriteClient();
    assertWrite((new EwsOperations($client))->sendItem(requestWrite('SendItem', '<m:ItemIds><t:ItemId Id="mail"/><t:ItemId Id="' . $id . '"/></m:ItemIds>')), 'SendItem', 'ErrorInvalidOperation');
    checkWrite($client->calls === [], 'whole send batch validated');
}
echo "calendar write response tests passed\n";
