<?php

// Can also run with the real bridge sources preloaded via stdin.
if (!class_exists('EwsOperations')) {
    require_once __DIR__ . '/../ews/src/Soap.php';
    require_once __DIR__ . '/../jmap/jmap_calendar.php';
    require_once __DIR__ . '/../ews/src/Converter.php';
    require_once __DIR__ . '/../ews/src/Operations.php';
}

class JmapClient {
    const CAP_CORE = 'core', CAP_MAIL = 'mail', CAP_CONTACTS = 'contacts', CAP_CALENDARS = 'calendars';
    public array $items = [];
    public array $calls = [];
    public array $downloads = [];
    public bool $failDownload = false;
    public function getAccountId(): string { return 'account'; }
    public function getCapabilityAccountId(string $capability): string { return 'account'; }
    public function call(array $calls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        [$method, $args, $tag] = $calls[0];
        $this->calls[] = [$method, $args, $using];
        $list = [];
        foreach ($args['ids'] as $id) {
            if (!isset($this->items[$method][$id])) continue;
            $item = $this->items[$method][$id];
            // Model default JMAP properties as well as explicit projections.
            if (($args['properties'] ?? null) !== null) $item = array_intersect_key($item, array_flip($args['properties']));
            if ($method === 'Email/get') {
                $item['bodyValues'] = !empty($args['fetchAllBodyValues']) ? ['p1' => ['value' => '<b>Body & text</b>']] : [];
            }
            $list[] = $item;
        }
        return [[$method, ['list' => $list, 'notFound' => [], 'state' => 'state'], $tag]];
    }
    public function downloadBlob(string $blobId, string $name, string $accept): string {
        $this->downloads[] = [$blobId, $name, $accept];
        if ($this->failDownload) throw new RuntimeException('synthetic download failure');
        return "Subject: Test\r\n\r\nMessage";
    }
}

function getitemCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function getitemRequest(JmapClient $client, array $ids, bool $mime): DOMXPath {
    $shape = '<m:ItemShape><t:BaseShape>Default</t:BaseShape>' . ($mime ? '<t:AdditionalProperties><t:FieldURI FieldURI="item:MimeContent"/></t:AdditionalProperties>' : '') . '</m:ItemShape>';
    $itemIds = '';
    foreach ($ids as $id) $itemIds .= '<t:ItemId Id="' . htmlspecialchars($id, ENT_XML1 | ENT_QUOTES) . '"/>';
    $request = new DOMDocument();
    getitemCheck($request->loadXML('<m:GetItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '">' . $shape . '<m:ItemIds>' . $itemIds . '</m:ItemIds></m:GetItem>'), 'request XML');
    $xml = (new EwsOperations($client))->getItem($request->documentElement);
    $response = new DOMDocument();
    getitemCheck($response->loadXML($xml), 'response XML');
    $xpath = new DOMXPath($response);
    $xpath->registerNamespace('m', EwsSoap::NS_M);
    $xpath->registerNamespace('t', EwsSoap::NS_T);
    return $xpath;
}

$client = new JmapClient();
$client->items = [
    'Email/get' => ['m1' => [
        'id' => 'm1', 'blobId' => 'blob1', 'mailboxIds' => ['folder' => true], 'keywords' => [],
        'subject' => 'Test', 'sentAt' => '2025-01-01T00:00:00Z', 'receivedAt' => '2025-01-02T00:00:00Z',
        'threadId' => 'thread1', 'preview' => 'previously omitted', 'sender' => [['email' => 'sender@example.invalid']],
        'htmlBody' => [['partId' => 'p1', 'type' => 'text/html']], 'textBody' => [],
    ]],
    'ContactCard/get' => ['c1' => ['id' => 'c1', 'addressBookIds' => ['book' => true], 'name' => ['components' => [['kind' => 'given', 'value' => 'Test']]], 'example.com:extra' => ['b' => 2, 'a' => 1]]],
    'CalendarEvent/get' => ['e1' => ['id' => 'e1', 'calendarIds' => ['cal' => true], 'title' => 'Meeting', 'start' => '2025-01-01T12:00:00', 'duration' => 'PT1H', 'example.com:extra' => 'value']],
];
$xp = getitemRequest($client, ['m1', 'ab_c1', 'cal_e1'], true);
getitemCheck($xp->query('//m:GetItemResponseMessage[m:ResponseCode="NoError"]')->length === 3, 'three successful items');
getitemCheck(count($client->calls) === 3, 'three get calls');
foreach ($client->calls as [$method, $args, $using]) {
    getitemCheck(array_key_exists('properties', $args) && $args['properties'] === null, $method . ' must fetch default properties');
    if ($method === 'Email/get') getitemCheck(($args['fetchAllBodyValues'] ?? false) === true, 'email body values requested');
}
foreach (['m1' => ['Email/get', 'm1'], 'ab_c1' => ['ContactCard/get', 'c1'], 'cal_e1' => ['CalendarEvent/get', 'e1']] as $ewsId => [$method, $id]) {
    $idNode = $xp->query('//t:ItemId[@Id="' . $ewsId . '"]')->item(0);
    getitemCheck($idNode !== null && $idNode->getAttribute('ChangeKey') === EwsConverter::itemChangeKey($client->items[$method][$id]), 'complete object ChangeKey for ' . $ewsId);
}
getitemCheck($xp->evaluate('string(//t:Message/t:Body)') === '<b>Body & text</b>', 'body returned and escaped');
getitemCheck($xp->evaluate('string(//t:Message/t:Body/@BodyType)') === 'HTML', 'HTML body type');
getitemCheck(base64_decode($xp->evaluate('string(//t:Message/t:MimeContent)'), true) === "Subject: Test\r\n\r\nMessage", 'MIME returned');
getitemCheck($client->downloads === [['blob1', 'message.eml', 'message/rfc822']], 'MIME download requested');

$originalKey = $xp->evaluate('string(//t:Message/t:ItemId/@ChangeKey)');
$client->calls = []; $client->downloads = [];
$xp = getitemRequest($client, ['m1'], false);
getitemCheck($xp->evaluate('string(//t:Message/t:ItemId/@ChangeKey)') === $originalKey, 'MIME request does not affect ChangeKey');
getitemCheck($xp->query('//t:MimeContent')->length === 0 && !$client->downloads, 'no unsolicited MIME download');
getitemCheck($xp->evaluate('string(//t:Message/t:Body)') === '<b>Body & text</b>', 'body without MIME request');
$client->items['Email/get']['m1']['preview'] = 'changed';
$xp = getitemRequest($client, ['m1'], false);
getitemCheck($xp->evaluate('string(//t:Message/t:ItemId/@ChangeKey)') !== $originalKey, 'default-only property changes ChangeKey');
$client->failDownload = true;
$xp = getitemRequest($client, ['m1'], true);
getitemCheck($xp->evaluate('string(//m:ResponseCode)') === 'NoError' && $xp->query('//t:MimeContent')->length === 0 && $xp->query('//t:Body')->length === 1, 'MIME download failure preserves GetItem and body');
echo "getitem version tests passed\n";
