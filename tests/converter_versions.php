<?php

if (!defined('CONVERTER_TEST_EMBEDDED')) {
    require __DIR__ . '/../ews/src/Soap.php';
    require __DIR__ . '/../jmap/jmap_calendar.php';
    require __DIR__ . '/../ews/src/Converter.php';
}

function versionCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function xmlChangeKey(string $xml): string {
    $dom = new DOMDocument();
    versionCheck($dom->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>'), 'invalid item XML');
    return $dom->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId')->item(0)->getAttribute('ChangeKey');
}

$item = ['id' => '1', 'nested' => ['z' => ['b' => 2, 'a' => 1], 'a' => [['y' => 2, 'x' => 1], 'v']], 'keywords' => ['$seen' => true, '$flagged' => true]];
$reordered = ['keywords' => ['$flagged' => true, '$seen' => true], 'nested' => ['a' => [['x' => 1, 'y' => 2], 'v'], 'z' => ['a' => 1, 'b' => 2]], 'id' => '1'];
$key = EwsConverter::itemChangeKey($item);
versionCheck((bool)preg_match('/^[0-9a-f]{64}$/', $key), 'SHA-256 format');
versionCheck($key === EwsConverter::itemChangeKey($reordered), 'recursive map order independence');
versionCheck($key !== EwsConverter::itemChangeKey($item + ['extra' => null]), 'unknown fields included');
$changed = $item; $changed['nested']['a'] = array_reverse($changed['nested']['a']);
versionCheck($key !== EwsConverter::itemChangeKey($changed), 'array order matters');
$changed = $item; $changed['nested']['z']['a'] = 3;
versionCheck($key !== EwsConverter::itemChangeKey($changed), 'nested values matter');
versionCheck(EwsConverter::itemChangeKey(['map' => [1 => 'b', 0 => 'a']]) === EwsConverter::itemChangeKey(['map' => [0 => 'a', 1 => 'b']]), 'numeric map keys normalized after associative decoding');
versionCheck(EwsConverter::itemChangeKey(['map' => (object)[]]) !== EwsConverter::itemChangeKey(['map' => []]), 'explicit empty object differs from array');
versionCheck(EwsConverter::itemChangeKey(['bodyValues' => ['1' => ['value' => 'full']], 'blobId' => 'blob']) === EwsConverter::itemChangeKey(['blobId' => 'blob']), 'fetch-dependent bodyValues excluded');
versionCheck(EwsConverter::itemChangeKey(['blobId' => 'blob']) !== EwsConverter::itemChangeKey(['blobId' => 'other']), 'blobId included');
try {
    EwsConverter::itemChangeKey(['invalid' => "\xff"]);
    throw new RuntimeException('invalid JSON silently hashed');
} catch (JsonException $expected) {}

$dom = new DOMDocument();
$email = ['id' => 'm1', 'subject' => 'Subject', 'receivedAt' => '2025-01-01T00:00:00Z', 'keywords' => ['$seen' => true], 'blobId' => 'blob'];
$card = ['id' => 'c1', 'name' => ['components' => [['kind' => 'given', 'value' => 'Jane']]], 'phones' => ['p1' => ['number' => '1']]];
$event = ['id' => 'e1', 'start' => '2025-01-01T12:00:00', 'duration' => 'PT1H', 'title' => 'Title', 'participants' => ['p1' => ['name' => 'A']]];
foreach ([[$email, EwsConverter::emailToXml($dom, $email, 'folder')], [$card, EwsConverter::contactToXml($dom, $card, 'folder')], [$event, EwsConverter::calendarToXml($dom, $event, 'folder')]] as [$object, $xml]) {
    versionCheck(xmlChangeKey($xml) === EwsConverter::itemChangeKey($object), 'item XML uses shared version');
}
$changed = $email; $changed['keywords']['$flagged'] = true;
versionCheck(xmlChangeKey(EwsConverter::emailToXml($dom, $email, 'folder')) !== xmlChangeKey(EwsConverter::emailToXml($dom, $changed, 'folder')), 'email keyword update');
$changed = $card; $changed['phones']['p1']['number'] = '2';
versionCheck(xmlChangeKey(EwsConverter::contactToXml($dom, $card, 'folder')) !== xmlChangeKey(EwsConverter::contactToXml($dom, $changed, 'folder')), 'contact phone update');
$changed = $event; $changed['participants']['p1']['name'] = 'B';
versionCheck(xmlChangeKey(EwsConverter::calendarToXml($dom, $event, 'folder')) !== xmlChangeKey(EwsConverter::calendarToXml($dom, $changed, 'folder')), 'calendar participant update');
versionCheck(EwsConverter::modHash($email) === EwsConverter::itemChangeKey($email), 'legacy hash alias');

versionCheck(EwsConverter::bodyToXml([]) === '', 'missing body omitted');
versionCheck(EwsConverter::bodyToXml(['textBody' => [['partId' => 'a']]]) === '', 'unfetched body omitted');
versionCheck(EwsConverter::bodyToXml(['textBody' => [['partId' => 'a']]], ['other' => ['value' => 'wrong']]) === '', 'unrelated body omitted');
versionCheck(EwsConverter::bodyToXml(['textBody' => [['partId' => 'a']]], ['a' => ['value' => '']]) === '<t:Body BodyType="Text"></t:Body>', 'fetched empty body');
versionCheck(EwsConverter::bodyToXml(['textBody' => [['value' => '']]]) === '<t:Body BodyType="Text"></t:Body>', 'inline empty body');
versionCheck(EwsConverter::bodyToXml(['htmlBody' => [['partId' => 'h']], 'textBody' => [['partId' => 't']]], ['h' => ['value' => '<b>&</b>'], 't' => ['value' => 'text']]) === '<t:Body BodyType="HTML">&lt;b&gt;&amp;&lt;/b&gt;</t:Body>', 'HTML preference and escaping');
versionCheck(EwsConverter::bodyToXml(['htmlBody' => [['partId' => 'h']], 'textBody' => [['partId' => 't']], 'bodyValues' => ['t' => ['value' => 'hello &']]]) === '<t:Body BodyType="Text">hello &amp;</t:Body>', 'fetched text fallback');
versionCheck(EwsConverter::bodyToXml(['htmlBody' => [['value' => '']]]) === '<t:Body BodyType="HTML"></t:Body>', 'inline HTML empty');
versionCheck(!str_contains(EwsConverter::emailToXml($dom, $email, 'folder'), '<t:Body '), 'email XML omits missing body');
echo "converter version and body tests passed\n";
