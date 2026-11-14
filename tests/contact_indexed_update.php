<?php
require __DIR__ . '/../ews/src/Soap.php';
require __DIR__ . '/../ews/src/Converter.php';
require __DIR__ . '/../ews/src/Operations.php';

class JmapClient {
    const CAP_CORE = 'core', CAP_MAIL = 'mail', CAP_CONTACTS = 'contacts';
    public array $card = ['id' => 'c1'];
    public array $calls = [];
    public string $failure = '';
    public string $state = 's1';
    public function getAccountId(): string { return 'account'; }
    public function getCapabilityAccountId(string $cap): string { return 'account'; }
    public function call(array $calls, array $using = []): array {
        [$method, $args, $tag] = $calls[0]; $this->calls[] = $calls[0];
        if ($method === 'ContactCard/get') {
            if ($this->failure === 'get') return [['error', ['type' => 'serverFail'], $tag]];
            if ($this->failure === 'postGet' && $this->state === 's2') return [['error', ['type' => 'serverFail'], $tag]];
            $state = $this->failure === 'postRace' && $this->state === 's2' ? 's3' : $this->state;
            return [[$method, ['state' => $state, 'list' => [$this->card], 'notFound' => []], $tag]];
        }
        if ($this->failure === 'race') return [['error', ['type' => 'stateMismatch'], $tag]];
        if ($this->failure === 'notUpdated') return [[$method, ['notUpdated' => ['c1' => ['type' => 'invalidProperties']]], $tag]];
        if ($this->failure === 'notUpdatedRace') return [[$method, ['notUpdated' => ['c1' => ['type' => 'stateMismatch']]], $tag]];
        foreach ($args['update']['c1'] as $path => $value) {
            $parts = array_map(fn($p) => str_replace(['~1', '~0'], ['/', '~'], $p), explode('/', $path));
            $target =& $this->card;
            foreach ($parts as $part) $target =& $target[$part];
            $target = $value;
            unset($target);
            if ($value === null && count($parts) === 2) unset($this->card[$parts[0]][$parts[1]]);
        }
        $this->card = json_decode(json_encode($this->card), true);
        $this->state = 's2';
        return [[$method, ['updated' => ['c1' => null]] + ($this->failure === 'noNewState' ? [] : ['newState' => 's2']), $tag]];
    }
}
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function request(JmapClient $client, string $fields, ?string $changeKey = null): string {
    $doc = new DOMDocument();
    if ($changeKey === null) $changeKey = EwsConverter::itemChangeKey($client->card);
    verify($doc->loadXML('<m:UpdateItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemChanges><t:ItemChange><t:ItemId Id="ab_c1"' . ($changeKey !== '' ? ' ChangeKey="' . htmlspecialchars($changeKey, ENT_XML1) . '"' : '') . '/><t:Updates>' . $fields . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>'), 'request XML');
    return (new EwsOperations($client))->updateItem($doc->documentElement);
}
function setIndexed(string $uri, string $index, string $container, string $value, ?string $element = null): string {
    $value = htmlspecialchars($value, ENT_XML1);
    if ($element !== null) $value = '<t:' . $element . '>' . $value . '</t:' . $element . '>';
    return '<t:SetItemField><t:IndexedFieldURI FieldURI="contacts:' . $uri . '" FieldIndex="' . $index . '"/><t:Contact><t:' . $container . '><t:Entry Key="' . $index . '">' . $value . '</t:Entry></t:' . $container . '></t:Contact></t:SetItemField>';
}
function delIndexed(string $uri, string $index): string { return '<t:DeleteItemField><t:IndexedFieldURI FieldURI="contacts:' . $uri . '" FieldIndex="' . $index . '"/></t:DeleteItemField>'; }
function code(string $response, string $expected): void { verify(str_contains($response, '<m:ResponseCode>' . $expected . '</m:ResponseCode>'), $expected . ': ' . $response); }
function fixture(): JmapClient {
    $c = new JmapClient();
    $c->card += [
        'emails' => ['home' => ['address' => 'home@x', 'contexts' => ['home' => true], 'label' => 'home label'], 'w/~' => ['address' => 'work@x', 'contexts' => ['work' => true], 'label' => 'work label'], 'third' => ['address' => 'third@x'], 'z_hidden' => ['address' => 'hidden@x']],
        'phones' => ['p1' => ['number' => 'work1', 'contexts' => ['work' => true], 'label' => 'primary'], 'p2' => ['number' => 'work2', 'contexts' => ['work' => true], 'pref' => 2], 'p3' => ['number' => 'work3', 'contexts' => ['work' => true]], 'mobile' => ['number' => 'mobile', 'features' => ['cell' => true]]],
        'onlineServices' => ['social' => ['uri' => 'https://example.org'], 'im1' => ['uri' => 'xmpp:first@x', 'label' => 'first'], 'im2' => ['uri' => 'sip:second@x']],
        'addresses' => ['a1' => ['@type' => 'Address', 'contexts' => ['work' => true], 'label' => 'office', 'components' => [['kind' => 'street', 'value' => 'Main', 'example.com:meta' => 'keep'], ['kind' => 'locality', 'value' => 'Old'], ['kind' => 'region', 'value' => 'R'], ['kind' => 'country', 'value' => 'NL'], ['kind' => 'postcode', 'value' => '123'], ['kind' => 'building', 'value' => 'HQ']]], 'a2' => ['contexts' => ['work' => true], 'components' => [['kind' => 'street', 'value' => 'Hidden']]]],
        'notes' => ['n' => ['note' => 'untouched']], 'example.com:extra' => ['keep' => true],
    ];
    return $c;
}
function contactXml(array $card): DOMXPath {
    $doc = new DOMDocument(); verify($doc->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . EwsConverter::contactToXml(new DOMDocument(), $card, 'ab_book') . '</root>'), 'response XML');
    $xp = new DOMXPath($doc); $xp->registerNamespace('t', EwsSoap::NS_T); return $xp;
}
function val(DOMXPath $xp, string $path): string { return $xp->evaluate('string(' . $path . ')'); }

$c = fixture(); $original = $c->card;
$updates = setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'changed&work@x') .
    setIndexed('PhoneNumber', 'BusinessPhone2', 'PhoneNumbers', '222') .
    setIndexed('ImAddress', 'ImAddress2', 'ImAddresses', 'sip:new@x') .
    setIndexed('PhysicalAddress:City', 'Business', 'PhysicalAddresses', 'New City', 'City') .
    setIndexed('PhysicalAddress:Country', 'Business', 'PhysicalAddresses', 'BE', 'CountryOrRegion');
$response = request($c, $updates, EwsConverter::itemChangeKey($c->card));
code($response, 'NoError');
verify(count($c->calls) === 3 && $c->calls[0][0] === 'ContactCard/get' && $c->calls[1][1]['ifInState'] === 's1', 'read and conditional set');
verify(str_contains($response, 'ChangeKey="' . EwsConverter::itemChangeKey($c->card) . '"'), 'response returns updated object hash');
$patch = $c->calls[1][1]['update']['c1'];
verify(isset($patch['emails/w~1~0/address'], $patch['phones/p2/number'], $patch['onlineServices/im2/uri'], $patch['addresses/a1/components']) && !isset($patch['emails'], $patch['phones'], $patch['addresses']), 'specific patch paths and pointer escaping');
verify($c->card['emails']['w/~']['label'] === 'work label' && $c->card['phones']['p2']['pref'] === 2 && $c->card['addresses']['a1']['label'] === 'office' && $c->card['addresses']['a1']['components'][0]['example.com:meta'] === 'keep', 'metadata preserved');
verify($c->card['emails']['z_hidden'] === $original['emails']['z_hidden'] && $c->card['phones']['p3'] === $original['phones']['p3'] && $c->card['addresses']['a2'] === $original['addresses']['a2'] && $c->card['notes'] === $original['notes'], 'other entries retained');
$xp = contactXml($c->card);
verify(val($xp, '//t:EmailAddresses/t:Entry[@Key="EmailAddress1"]') === 'changed&work@x' && val($xp, '//t:EmailAddresses/t:Entry[@Key="EmailAddress2"]') === 'home@x', 'email order');
verify(val($xp, '//t:PhoneNumbers/t:Entry[@Key="BusinessPhone2"]') === '222' && val($xp, '//t:ImAddresses/t:Entry[@Key="ImAddress2"]') === 'sip:new@x', 'phone and IM order');
verify(val($xp, '//t:PhysicalAddresses/t:Entry[@Key="Business"]/t:City') === 'New City' && val($xp, '//t:PhysicalAddresses/t:Entry[@Key="Business"]/t:CountryOrRegion') === 'BE', 'physical fields and namespace');
$order = ['EmailAddresses', 'PhysicalAddresses', 'PhoneNumbers', 'ImAddresses']; $last = -1;
foreach ($xp->query('//t:Contact/*') as $node) if (($pos = array_search($node->localName, $order, true)) !== false) { verify($node->namespaceURI === EwsSoap::NS_T && $pos > $last, 'dictionary XML namespace/order'); $last = $pos; }

$c = fixture(); unset($c->card['emails']['z_hidden']);
code(request($c, delIndexed('EmailAddress', 'EmailAddress3') . delIndexed('PhoneNumber', 'MobilePhone') . delIndexed('ImAddress', 'ImAddress2') . delIndexed('PhysicalAddress:PostalCode', 'Business')), 'NoError');
verify(!isset($c->card['emails']['third'], $c->card['phones']['mobile'], $c->card['onlineServices']['im2']) && count($c->card['addresses']['a1']['components']) === 5, 'selected fields deleted');
verify(isset($c->card['emails']['home'], $c->card['phones']['p3']) && $c->card['addresses']['a1']['components'][0]['value'] === 'Main', 'delete retains unrelated fields');

foreach (['Street' => 'Street', 'City' => 'City', 'State' => 'State', 'Country' => 'CountryOrRegion', 'PostalCode' => 'PostalCode'] as $part => $element) {
    foreach (['Business', 'Home', 'Other'] as $index) {
        $c = new JmapClient();
        code(request($c, setIndexed('PhysicalAddress:' . $part, $index, 'PhysicalAddresses', 'value', $element)), 'NoError');
        $xp = contactXml($c->card);
        verify(val($xp, '//t:PhysicalAddresses/t:Entry[@Key="' . $index . '"]/t:' . $element) === 'value', 'created address ' . $index . '/' . $part);
        $c->calls = [];
        code(request($c, delIndexed('PhysicalAddress:' . $part, $index)), 'NoError');
        verify(val(contactXml($c->card), '//t:PhysicalAddresses/t:Entry[@Key="' . $index . '"]/t:' . $element) === '', 'deleted address component');
    }
}
foreach (['BusinessPhone', 'BusinessPhone2', 'HomePhone', 'HomePhone2', 'MobilePhone', 'BusinessFax', 'HomeFax', 'OtherFax', 'OtherTelephone', 'Pager'] as $index) {
    $c = new JmapClient();
    if (str_ends_with($index, 'Phone2')) {
        $first = str_replace('Phone2', 'Phone', $index);
        code(request($c, setIndexed('PhoneNumber', $first, 'PhoneNumbers', 'first')), 'NoError'); $c->calls = [];
    }
    code(request($c, setIndexed('PhoneNumber', $index, 'PhoneNumbers', 'new')), 'NoError');
    verify(val(contactXml($c->card), '//t:PhoneNumbers/t:Entry[@Key="' . $index . '"]') === 'new', 'phone ' . $index);
}
foreach (['EmailAddress' => ['EmailAddresses', 'e@x'], 'ImAddress' => ['ImAddresses', 'xmpp:e@x']] as $uri => [$container, $value]) {
    $c = new JmapClient();
    for ($i = 1; $i <= 3; $i++) {
        $index = ($uri === 'EmailAddress' ? 'EmailAddress' : 'ImAddress') . $i;
        code(request($c, setIndexed($uri, $index, $container, $value . $i)), 'NoError');
        verify(val(contactXml($c->card), '//t:' . $container . '/t:Entry[@Key="' . $index . '"]') === $value . $i, 'created ' . $index);
    }
}

$bad = [
    setIndexed('EmailAddress', 'EmailAddress4', 'EmailAddresses', 'x'),
    setIndexed('PhoneNumber', 'AssistantPhone', 'PhoneNumbers', 'x'),
    setIndexed('PhysicalAddress', 'Business', 'PhysicalAddresses', 'x'),
    setIndexed('ImAddress', 'ImAddress1', 'ImAddresses', 'not-a-uri'),
    str_replace('Key="EmailAddress1"', 'Key="EmailAddress2"', setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'x')),
    str_replace(['<t:EmailAddresses>', '</t:EmailAddresses>'], ['<EmailAddresses xmlns="urn:wrong">', '</EmailAddresses>'], setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'x')),
    str_replace('Key="EmailAddress1"', 'Key="EmailAddress1" Extra="ignored"', setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'x')),
    '<t:SetItemField><t:Contact/><t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/></t:SetItemField>',
];
foreach ($bad as $invalid) {
    $c = fixture();
    code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x') . $invalid), 'ErrorInvalidPropertySet');
    verify(!$c->calls, 'invalid request rejected before read or write');
}
$c = fixture(); $c->card['addresses']['a1']['components'][0] = ['kind' => 'number', 'value' => '12'];
code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x') . setIndexed('PhysicalAddress:Street', 'Business', 'PhysicalAddresses', 'New', 'Street')), 'ErrorInvalidPropertySet');
verify(count($c->calls) === 1, 'ambiguous address never written');
$c = fixture(); code(request($c, delIndexed('EmailAddress', 'EmailAddress1')), 'ErrorInvalidPropertySet'); verify(count($c->calls) === 1, 'metadata delete rejected');
$c = fixture(); $c->card['localizations'] = ['nl' => ['emails/w~1~0/address' => 'localized']];
code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x')), 'ErrorInvalidPropertySet'); verify(count($c->calls) === 1, 'localized map rejected');
$c = new JmapClient(); code(request($c, setIndexed('EmailAddress', 'EmailAddress3', 'EmailAddresses', 'x@x')), 'ErrorInvalidPropertySet'); verify(count($c->calls) === 1, 'slot gaps rejected');
$c = fixture(); $c->card['addresses']['a1']['full'] = 'formatted';
code(request($c, setIndexed('PhysicalAddress:City', 'Business', 'PhysicalAddresses', 'New', 'City')), 'ErrorInvalidPropertySet'); verify(count($c->calls) === 1, 'formatted address rejected');
foreach (['get' => 'ErrorInternalServerError', 'race' => 'ErrorIrresolvableConflict', 'notUpdatedRace' => 'ErrorIrresolvableConflict', 'notUpdated' => 'ErrorInternalServerError'] as $failure => $error) {
    $c = fixture(); $c->failure = $failure;
    code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x')), $error);
    verify(count($c->calls) === ($failure === 'get' ? 1 : 2), 'JMAP failure calls');
}
$c = fixture();
$simple = '<t:SetItemField><t:FieldURI FieldURI="contacts:GivenName"/><t:Contact><t:GivenName>Jane</t:GivenName></t:Contact></t:SetItemField>';
code(request($c, $simple . setIndexed('EmailAddress', 'EmailAddress2', 'EmailAddresses', 'home2@x')), 'NoError');
verify(count($c->calls) === 3 && isset($c->calls[1][1]['update']['c1']['name'], $c->calls[1][1]['update']['c1']['emails/home/address']), 'mixed update atomic');
$c = fixture();
$legacy = '<t:SetItemField><t:FieldURI FieldURI="contacts:Email1"/><t:Contact>legacy@x</t:Contact></t:SetItemField>';
code(request($c, $legacy . setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x')), 'ErrorInvalidPropertySet'); verify(!$c->calls, 'conflicting legacy and indexed updates rejected');
$c = fixture();
$c->card['phones']['home'] = ['number' => 'home', 'contexts' => ['home' => true], 'label' => 'home label'];
$c->card['phones']['mobile']['label'] = 'mobile label';
$before = $c->card;
$aliases = ['Email1Address' => 'work-new@x', 'Email2' => 'home-new@x', 'BusinessPhone' => 'work-new', 'HomePhone' => 'home-new', 'MobilePhone' => 'mobile-new', 'IMAddress' => 'xmpp:new@x'];
$fields = '';
foreach ($aliases as $field => $value) $fields .= '<t:SetItemField><t:FieldURI FieldURI="contacts:' . $field . '"/><t:Contact><t:' . $field . '>' . $value . '</t:' . $field . '></t:Contact></t:SetItemField>';
code(request($c, $fields), 'NoError');
$p = $c->calls[1][1]['update']['c1'];
verify($c->calls[1][1]['ifInState'] === 's1' && !isset($p['emails'], $p['phones'], $p['onlineServices']), 'legacy sets use conditional field patches');
verify($c->card['emails']['w/~'] === array_replace($before['emails']['w/~'], ['address' => 'work-new@x']) && $c->card['emails']['home'] === array_replace($before['emails']['home'], ['address' => 'home-new@x']), 'legacy emails retain metadata');
foreach (['p1' => 'work-new', 'home' => 'home-new', 'mobile' => 'mobile-new'] as $key => $value) verify($c->card['phones'][$key] === array_replace($before['phones'][$key], ['number' => $value]), 'legacy phone retains metadata');
verify($c->card['onlineServices']['im1'] === array_replace($before['onlineServices']['im1'], ['uri' => 'xmpp:new@x']) && $c->card['onlineServices']['im2'] === $before['onlineServices']['im2'], 'legacy IM preserves metadata and other services');
verify($c->card['emails']['third'] === $before['emails']['third'] && $c->card['emails']['z_hidden'] === $before['emails']['z_hidden'] && $c->card['phones']['p2'] === $before['phones']['p2'] && $c->card['phones']['p3'] === $before['phones']['p3'] && $c->card['notes'] === $before['notes'], 'legacy sets retain other data');
$c = fixture(); $c->failure = 'race';
code(request($c, '<t:SetItemField><t:FieldURI FieldURI="contacts:BusinessPhone"/><t:Value>new</t:Value></t:SetItemField>'), 'ErrorIrresolvableConflict');

// Reordering JSON map members must not change either the displayed slot or the targeted key.
$original = fixture(); $reordered = fixture();
foreach (['emails', 'phones', 'onlineServices', 'addresses'] as $map) $reordered->card[$map] = array_reverse($reordered->card[$map], true);
verify(EwsConverter::itemChangeKey($original->card) === EwsConverter::itemChangeKey($reordered->card), 'hash independent of map order');
foreach ([$original, $reordered] as $c) {
    code(request($c, $updates, EwsConverter::itemChangeKey($c->card)), 'NoError');
    $p = $c->calls[1][1]['update']['c1'];
    verify(isset($p['emails/w~1~0/address'], $p['phones/p2/number'], $p['onlineServices/im2/uri'], $p['addresses/a1/components']), 'sorted update mapping');
    verify(val(contactXml($c->card), '//t:EmailAddresses/t:Entry[@Key="EmailAddress1"]') === 'changed&work@x', 'sorted converter mapping');
}

$c = new JmapClient(); $c->card['emails'] = ['a' => ['address' => 'first@x'], 'b' => ['address' => 'second@x']];
$stale = EwsConverter::itemChangeKey($c->card); unset($c->card['emails']['a']);
code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'wrong@x'), $stale), 'ErrorIrresolvableConflict');
verify(count($c->calls) === 1 && $c->card['emails']['b']['address'] === 'second@x', 'stale key and shifted slot never write');
foreach ([setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'wrong@x'), delIndexed('EmailAddress', 'EmailAddress1'), '<t:SetItemField><t:FieldURI FieldURI="contacts:Email1"/><t:Value>wrong@x</t:Value></t:SetItemField>'] as $field) {
    $c = new JmapClient(); $c->card['emails'] = ['a' => ['address' => 'existing@x']]; $before = $c->card;
    code(request($c, $field, ''), 'ErrorChangeKeyRequired');
    verify(count($c->calls) === 1 && $c->card === $before, 'missing key never writes existing indexed slot');
}
foreach ([setIndexed('PhoneNumber', 'BusinessPhone', 'PhoneNumbers', 'new'), delIndexed('PhoneNumber', 'BusinessPhone'), setIndexed('ImAddress', 'ImAddress1', 'ImAddresses', 'xmpp:new@x'), delIndexed('ImAddress', 'ImAddress1'), setIndexed('PhysicalAddress:City', 'Business', 'PhysicalAddresses', 'new', 'City'), delIndexed('PhysicalAddress:City', 'Business'), '<t:SetItemField><t:FieldURI FieldURI="contacts:BusinessPhone"/><t:Value>new</t:Value></t:SetItemField>'] as $field) {
    $c = fixture(); $before = $c->card;
    code(request($c, $field, ''), 'ErrorChangeKeyRequired');
    verify(count($c->calls) === 1 && $c->card === $before, 'missing key never writes existing phone, IM or address slot');
}
$c = new JmapClient();
code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x'), ''), 'NoError');
verify(count($c->calls) === 3, 'new indexed slot may be created without a ChangeKey');
$c = new JmapClient();
code(request($c, '<t:SetItemField><t:FieldURI FieldURI="contacts:CompanyName"/><t:Value>New</t:Value></t:SetItemField>', ''), 'NoError');
verify(count($c->calls) === 3, 'non-indexed update does not require ChangeKey');

foreach ([
    ['EmailAddress', 'EmailAddress1', 'emails', ['address' => 'first@x'], ['address' => 'second@x']],
    ['PhoneNumber', 'BusinessPhone', 'phones', ['number' => '1', 'contexts' => ['work' => true]], ['number' => '2', 'contexts' => ['work' => true]]],
    ['ImAddress', 'ImAddress1', 'onlineServices', ['uri' => 'xmpp:first@x'], ['uri' => 'xmpp:second@x']],
    ['PhysicalAddress:Street', 'Business', 'addresses', ['contexts' => ['work' => true], 'components' => [['kind' => 'street', 'value' => 'first']]], ['contexts' => ['work' => true], 'components' => [['kind' => 'street', 'value' => 'second']]]],
] as [$uri, $index, $map, $first, $second]) {
    $c = new JmapClient(); $c->card[$map] = ['b' => $second, 'a' => $first]; $before = $c->card;
    $beforeXml = EwsConverter::contactToXml(new DOMDocument(), $before, null);
    code(request($c, delIndexed($uri, $index), EwsConverter::itemChangeKey($before)), 'ErrorInvalidPropertySet');
    verify(count($c->calls) === 1 && $c->card === $before, 'delete cannot compact visible or hidden slots');
    $doc = new DOMDocument();
    verify($doc->loadXML('<m:GetItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '"><m:ItemIds><t:ItemId Id="ab_c1"/></m:ItemIds></m:GetItem>'), 'GetItem XML');
    $response = (new EwsOperations($c))->getItem($doc->documentElement);
    code($response, 'NoError');
    verify(str_contains($response, $beforeXml), 'next GetItem retains the original slots');
}
foreach (['noNewState' => 'ErrorInternalServerError', 'postGet' => 'ErrorIrresolvableConflict', 'postRace' => 'ErrorIrresolvableConflict'] as $failure => $error) {
    $c = fixture(); $c->failure = $failure;
    $response = request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'changed@x'));
    code($response, $error);
    verify(str_contains($response, 'Contact updated'), 'post-write uncertainty reports that the update was applied');
    verify(count($c->calls) === ($failure === 'noNewState' ? 2 : 3), 'post-write uncertainty is an error, not a bogus ChangeKey');
}

$company = '<t:SetItemField><t:FieldURI FieldURI="contacts:CompanyName"/><t:Contact><t:CompanyName>New</t:CompanyName></t:Contact></t:SetItemField>';
$c = fixture(); $c->card['organizations'] = ['a' => ['name' => 'Old', 'label' => 'keep'], 'b' => ['name' => 'Other']]; $before = $c->card;
code(request($c, setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x') . $company), 'ErrorInvalidPropertySet');
verify(count($c->calls) === 1 && $c->card === $before, 'ambiguous organization aborts mixed update without writes');
foreach (['CompanyName' => ['organizations', 'name'], 'JobTitle' => ['jobTitles', 'name'], 'Department' => ['departments', 'name'], 'BusinessHomePage' => ['links', 'uri']] as $field => [$map, $property]) {
    $c = fixture(); $entry = [$property => 'Old', 'label' => 'keep'];
    if ($map === 'links') $entry['contexts'] = ['work' => true];
    $c->card[$map] = ['a' => $entry];
    $legacy = '<t:SetItemField><t:FieldURI FieldURI="contacts:' . $field . '"/><t:Contact><t:' . $field . '>New</t:' . $field . '></t:Contact></t:SetItemField>';
    code(request($c, $legacy . setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x')), 'NoError');
    verify($c->card[$map]['a'] === array_replace($entry, [$property => 'New']) && isset($c->calls[1][1]['update']['c1'][$map . '/a/' . $property]) && !isset($c->calls[1][1]['update']['c1'][$map]), 'legacy map update preserves metadata: ' . $map);
    $c = fixture(); $c->card[$map] = ['a' => $entry, 'b' => $entry];
    code(request($c, $legacy . setIndexed('EmailAddress', 'EmailAddress1', 'EmailAddresses', 'new@x')), 'ErrorInvalidPropertySet');
    verify(count($c->calls) === 1, 'ambiguous legacy map rejects whole write: ' . $map);
}
echo "contact indexed update tests passed\n";
