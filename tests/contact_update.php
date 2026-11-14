<?php

if (!class_exists('EwsOperations')) {
    require_once __DIR__ . '/../ews/src/Soap.php';
    require_once __DIR__ . '/../ews/src/Converter.php';
    require_once __DIR__ . '/../ews/src/Operations.php';
}

class JmapClient {
    const CAP_CORE = 'core', CAP_MAIL = 'mail', CAP_CONTACTS = 'contacts';
    public array $card = ['id' => 'c1'];
    public array $calls = [];
    public string $failure = '';
    public function getAccountId(): string { return 'account'; }
    public function getCapabilityAccountId(string $cap): string { return 'account'; }
    public function call(array $calls, array $using = []): array {
        [$method, $args, $tag] = $calls[0];
        $this->calls[] = $calls[0];
        if ($method === 'ContactCard/get') {
            if ($this->failure === 'getError') return [['error', ['type' => 'serverFail'], $tag]];
            if ($this->failure === 'missingState') return [[$method, ['list' => [$this->card]], $tag]];
            if ($this->failure === 'notFound') return [[$method, ['state' => 's1', 'list' => [], 'notFound' => ['c1']], $tag]];
            return [[$method, ['state' => 's1', 'list' => [$this->card], 'notFound' => []], $tag]];
        }
        if ($this->failure === 'race') return [['error', ['type' => 'stateMismatch'], $tag]];
        if ($this->failure === 'setError') return [['error', ['type' => 'serverFail'], $tag]];
        if ($this->failure === 'notUpdated') return [[$method, ['notUpdated' => ['c1' => ['type' => 'invalidProperties']]], $tag]];
        return [[$method, ['updated' => ['c1' => null]], $tag]];
    }
}

function checkUpdate(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function updateRequest(JmapClient $client, string $fields): string {
    $dom = new DOMDocument();
    $dom->loadXML('<m:UpdateItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemChanges><t:ItemChange><t:ItemId Id="ab_c1"/><t:Updates>' . $fields . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>');
    return (new EwsOperations($client))->updateItem($dom->documentElement);
}
function setName(string $field, string $value): string {
    return '<t:SetItemField><t:FieldURI FieldURI="contacts:' . $field . '"/><t:Contact><t:' . $field . '>' . htmlspecialchars($value, ENT_XML1) . '</t:' . $field . '></t:Contact></t:SetItemField>';
}
function expectUpdateCode(string $xml, string $code): void {
    checkUpdate(str_contains($xml, '<m:ResponseCode>' . $code . '</m:ResponseCode>'), 'expected ' . $code . ': ' . $xml);
}

// Use the real create converter, then update the created JSContact shape.
$dom = new DOMDocument();
$dom->loadXML('<t:Contact xmlns:t="' . EwsSoap::NS_T . '"><t:GivenName>Jane</t:GivenName><t:MiddleName>Q</t:MiddleName><t:Surname>Doe</t:Surname><t:DisplayName>Jane Q Doe</t:DisplayName></t:Contact>');
$client = new JmapClient();
$client->card = EwsConverter::ewsContactToCard($dom->documentElement, 'book') + ['id' => 'c1'];
$client->card['name']['components'][2]['example.com:metadata'] = 'preserved';
$client->card['name']['example.com:name'] = ['keep' => true];
$client->card['name']['phoneticSystem'] = 'ipa';
$client->card['name']['sortAs'] = ['surname' => 'Doe'];
$original = $client->card['name'];
expectUpdateCode(updateRequest($client, setName('GivenName', 'Jan & Jo') . setName('MiddleName', 'R') . setName('Title', 'Dr.') . setName('Suffix', 'Jr.')), 'NoError');
checkUpdate(count($client->calls) === 2 && $client->calls[0][0] === 'ContactCard/get', 'read before set');
$args = $client->calls[1][1];
checkUpdate(($args['ifInState'] ?? '') === 's1', 'ifInState');
$name = $args['update']['c1']['name'];
checkUpdate(array_column($name['components'], 'kind') === ['title', 'given', 'given2', 'surname', 'credential'], 'component kinds and order');
checkUpdate(array_column($name['components'], 'value') === ['Dr.', 'Jan & Jo', 'R', 'Doe', 'Jr.'], 'component values');
checkUpdate($name['full'] === 'Dr. Jan & Jo R Doe Jr.', 'full rebuilt');
checkUpdate($name['components'][3]['example.com:metadata'] === 'preserved' && $name['example.com:name'] === $original['example.com:name'] && $name['sortAs'] === $original['sortAs'] && $name['phoneticSystem'] === 'ipa', 'metadata preserved');

$client = new JmapClient();
$client->card['name'] = ['given' => 'Old', 'surname' => 'Last', 'full' => 'Old Last', 'example.com:x' => 'keep'];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), 'NoError');
$name = $client->calls[1][1]['update']['c1']['name'];
checkUpdate($name['given'] === 'New' && $name['surname'] === 'Last' && $name['full'] === 'New Last' && $name['example.com:x'] === 'keep', 'legacy migration');
checkUpdate(array_column($name['components'], 'value') === ['New', 'Last'], 'legacy components');

$client = new JmapClient();
$client->card['name'] = ['components' => [['kind' => 'given', 'value' => 'Old'], ['kind' => 'surname', 'value' => 'Last']], 'isOrdered' => true, 'full' => 'Custom'];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), 'ErrorInvalidPropertySet');
checkUpdate(count($client->calls) === 1, 'custom full does not write');
$client->calls = [];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New') . setName('DisplayName', 'New custom')), 'NoError');
checkUpdate($client->calls[1][1]['update']['c1']['name']['full'] === 'New custom', 'explicit display name');

$client = new JmapClient();
$client->card['name'] = ['components' => [['kind' => 'given', 'value' => 'Anne'], ['kind' => 'separator', 'value' => '-'], ['kind' => 'surname', 'value' => 'Smith', 'example.com:flag' => true]], 'isOrdered' => true, 'full' => 'Anne-Smith'];
expectUpdateCode(updateRequest($client, setName('GivenName', 'Anna')), 'NoError');
$name = $client->calls[1][1]['update']['c1']['name'];
checkUpdate($name['full'] === 'Anna-Smith' && $name['components'][1]['value'] === '-' && $name['components'][2]['example.com:flag'] === true, 'separator and metadata retained');
$client->calls = [];
expectUpdateCode(updateRequest($client, setName('MiddleName', 'Q')), 'ErrorInvalidPropertySet');
checkUpdate(count($client->calls) === 1, 'ambiguous insertion not written');

foreach (['getError' => 'ErrorInternalServerError', 'missingState' => 'ErrorInternalServerError', 'notFound' => 'ErrorItemNotFound', 'race' => 'ErrorIrresolvableConflict', 'setError' => 'ErrorInternalServerError', 'notUpdated' => 'ErrorInternalServerError'] as $failure => $code) {
    $client = new JmapClient(); $client->failure = $failure;
    expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), $code);
    if (in_array($failure, ['getError', 'missingState', 'notFound'], true)) checkUpdate(count($client->calls) === 1, 'no write after get failure');
}

$client = new JmapClient();
$client->card['name'] = ['components' => [['kind' => 'surname', 'value' => 'One'], ['kind' => 'surname2', 'value' => 'Two']], 'isOrdered' => true];
expectUpdateCode(updateRequest($client, setName('Surname', 'New')), 'ErrorInvalidPropertySet');
checkUpdate(count($client->calls) === 1, 'ambiguous components not overwritten');
$client = new JmapClient(); $client->card['localizations'] = ['fr' => ['name/full' => 'autre']];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), 'ErrorInvalidPropertySet');
$client = new JmapClient();
$client->card['name'] = ['given' => 'Old', 'surname' => 'Fallback', 'components' => [['kind' => 'given', 'value' => 'Old']], 'isOrdered' => true];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), 'ErrorInvalidPropertySet');
checkUpdate(count($client->calls) === 1, 'mixed legacy name not partially written');
$client = new JmapClient();
$client->card['name'] = ['components' => [['kind' => 'given', 'value' => 'Old', 'phonetic' => 'old']], 'phoneticSystem' => 'ipa'];
expectUpdateCode(updateRequest($client, setName('GivenName', 'New')), 'ErrorInvalidPropertySet');
$client = new JmapClient();
expectUpdateCode(updateRequest($client, '<t:DeleteItemField><t:FieldURI FieldURI="contacts:GivenName"/></t:DeleteItemField>'), 'ErrorInvalidPropertySet');
checkUpdate(count($client->calls) === 0, 'unsupported delete does not write');

// Indexed fields cannot be applied safely. Reject the whole item before any
// read or write, even when a supported update appears before or after it.
foreach (['contacts:EmailAddress' => 'EmailAddress1', 'contacts:PhoneNumber' => 'BusinessPhone',
    'contacts:PhysicalAddress' => 'Business', 'contacts:ImAddress' => 'ImAddress1'] as $uri => $index) {
    foreach (['SetItemField', 'DeleteItemField'] as $operation) {
        $indexed = '<t:' . $operation . '><t:IndexedFieldURI FieldURI="' . $uri . '" FieldIndex="' . $index . '"/>' .
            ($operation === 'SetItemField' ? '<t:Contact><t:DisplayName>ignored</t:DisplayName></t:Contact>' : '') . '</t:' . $operation . '>';
        foreach ([$indexed, setName('GivenName', 'New') . $indexed, $indexed . setName('GivenName', 'New'),
            '<t:SetItemField><t:FieldURI FieldURI="contacts:CompanyName"/><t:Contact>Company</t:Contact></t:SetItemField>' . $indexed] as $fields) {
            $client = new JmapClient();
            $xml = updateRequest($client, $fields);
            expectUpdateCode($xml, 'ErrorInvalidPropertySet');
            checkUpdate(str_contains($xml, 'ResponseClass="Error"') && !str_contains($xml, 'NoError') && !$client->calls, "$operation $uri must not partially update");
        }
    }
}
foreach ([
    '<t:SetItemField><t:FieldURI FieldURI="contacts:Unknown"/><t:Contact>value</t:Contact></t:SetItemField>',
    '<t:SetItemField><t:FieldURI FieldURI="contacts:BusinessStreet"/><t:Contact>value</t:Contact></t:SetItemField>',
    '<t:SetItemField><t:ExtendedFieldURI PropertyTag="0x1234" PropertyType="String"/><t:Contact>value</t:Contact></t:SetItemField>',
    '<t:SetItemField><t:FieldURI FieldURI="contacts:CompanyName"/></t:SetItemField>',
    '<t:DeleteItemField><t:FieldURI FieldURI="contacts:CompanyName"/></t:DeleteItemField>',
    '<t:AppendToItemField><t:FieldURI FieldURI="contacts:CompanyName"/><t:Contact>value</t:Contact></t:AppendToItemField>',
    '<t:UnknownItemField/>',
    '',
] as $unsupported) {
    foreach ([$unsupported, setName('GivenName', 'New') . $unsupported] as $fields) {
        // The empty update is tested alone; combined with a name it is a valid update.
        if ($unsupported === '' && $fields !== '') continue;
        $client = new JmapClient();
        expectUpdateCode(updateRequest($client, $fields), 'ErrorInvalidPropertySet');
        checkUpdate(!$client->calls, 'unknown or unsupported update must not issue JMAP calls');
    }
}
$client = new JmapClient();
expectUpdateCode(updateRequest($client, '<t:SetItemField><t:FieldURI FieldURI="contacts:CompanyName"/><t:Contact>Company</t:Contact></t:SetItemField>'), 'NoError');
checkUpdate(count($client->calls) === 1 && $client->calls[0][1]['update']['c1']['organizations']['o1']['name'] === 'Company', 'existing non-indexed set remains supported');
echo "contact update tests passed\n";
