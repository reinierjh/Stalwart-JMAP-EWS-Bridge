<?php

// Structure and key definitions: Microsoft EWS Contact, EmailAddresses,
// PhoneNumbers, PhysicalAddresses, ImAddresses and CompleteName references:
// https://learn.microsoft.com/en-us/exchange/client-developer/web-service-reference/contact
// https://learn.microsoft.com/en-us/exchange/client-developer/web-service-reference/entry-emailaddress
// https://learn.microsoft.com/en-us/exchange/client-developer/web-service-reference/entry-phonenumber
// https://learn.microsoft.com/en-us/exchange/client-developer/web-service-reference/entry-physicaladdress
// These checks cover the supported subset; they are not a complete types.xsd validator.
if (!defined('CONTACT_SCHEMA_EMBEDDED')) {
    require __DIR__ . '/../ews/src/Soap.php';
    require __DIR__ . '/../ews/src/Converter.php';
}
function schemaCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function schemaXml(array $card): DOMXPath {
    $xml = EwsConverter::contactToXml(new DOMDocument(), $card, 'ab_folder');
    $doc = new DOMDocument();
    schemaCheck($doc->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>'), 'valid XML');
    $xp = new DOMXPath($doc); $xp->registerNamespace('t', EwsSoap::NS_T);
    return $xp;
}
function xval(DOMXPath $xp, string $query): string { return $xp->evaluate('string(' . $query . ')'); }

$card = [
    'id' => 'contact',
    'name' => ['isOrdered' => true, 'components' => [['kind' => 'title', 'value' => 'Dr.'], ['kind' => 'given', 'value' => 'A & B'], ['kind' => 'given2', 'value' => 'M'], ['kind' => 'surname', 'value' => 'Surname'], ['kind' => 'credential', 'value' => 'Jr.']]],
    'emails' => [
        'a' => ['address' => 'home&one@example.invalid', 'contexts' => ['private' => true]],
        'b' => ['address' => 'work@example.invalid', 'contexts' => ['work' => true]],
        'c' => ['address' => 'third@example.invalid'], 'd' => ['address' => 'fourth@example.invalid'],
        'empty' => ['address' => ''],
    ],
    'phones' => [
        ['number' => 'work&1', 'contexts' => ['work' => true]], ['number' => 'work2', 'contexts' => ['work' => true]],
        ['number' => 'work3', 'contexts' => ['work' => true]], ['number' => 'home1', 'contexts' => ['private' => true]],
        ['number' => 'home2', 'contexts' => ['home' => true]], ['number' => 'mobile', 'features' => ['cell' => true]],
        ['number' => 'fax', 'contexts' => ['work' => true], 'features' => ['fax' => true]],
        ['number' => 'otherfax', 'features' => ['fax' => true]], ['number' => 'pager', 'features' => ['pager' => true]],
        ['number' => 'other'], ['number' => ''],
    ],
    'addresses' => [
        ['contexts' => ['work' => true], 'components' => [['kind' => 'number', 'value' => '12'], ['kind' => 'name', 'value' => 'Main & Co'], ['kind' => 'locality', 'value' => '<City>'], ['kind' => 'region', 'value' => 'CA'], ['kind' => 'country', 'value' => 'US'], ['kind' => 'postcode', 'value' => '123']]],
        ['contexts' => ['work' => true], 'components' => [['kind' => 'street', 'value' => 'ignored']]],
        ['contexts' => ['private' => true], 'components' => [['kind' => 'street', 'value' => 'Home']]],
        ['components' => [['kind' => 'region', 'value' => 'Other region']]],
    ],
    'notes' => [['note' => 'note & text']], 'categories' => ['a&b' => true],
    'nicknames' => [['name' => 'Nick']], 'organizations' => [['name' => 'Company']],
    'departments' => [['name' => 'Dept']], 'jobTitles' => [['name' => 'Engineer']],
    'links' => [['uri' => 'https://example.invalid/?a=1&b=2']],
    'onlineServices' => [['uri' => 'xmpp:user@example.invalid'], ['uri' => 'https://example.invalid/social'], ['service' => 'not-an-address']],
    'anniversaries' => [['type' => 'birth', 'date' => ['year' => 2000, 'month' => 2, 'day' => 29]], ['type' => 'death', 'date' => ['year' => 2020, 'month' => 1, 'day' => 1]]],
    'media' => [['uri' => 'data:text/plain;base64,ZmFrZQ==']],
];
$xp = schemaXml($card);
schemaCheck(xval($xp, '//t:Contact/t:ItemId/@ChangeKey') === EwsConverter::itemChangeKey($card), 'item version');
$order = ['ItemId', 'ParentFolderId', 'ItemClass', 'Body', 'Categories', 'FileAs', 'DisplayName', 'GivenName', 'MiddleName', 'Nickname', 'CompleteName', 'CompanyName', 'EmailAddresses', 'PhysicalAddresses', 'PhoneNumbers', 'Birthday', 'BusinessHomePage', 'Department', 'ImAddresses', 'JobTitle', 'Surname', 'WeddingAnniversary'];
$last = -1;
foreach ($xp->query('//t:Contact/*') as $child) {
    $pos = array_search($child->localName, $order, true);
    schemaCheck($pos !== false && $pos > $last, 'valid contact element order: ' . $child->localName);
    $last = $pos;
}
schemaCheck(xval($xp, '//t:Contact/t:GivenName') === 'A & B', 'given name');
schemaCheck(xval($xp, '//t:CompleteName/t:Title') === 'Dr.' && xval($xp, '//t:CompleteName/t:Suffix') === 'Jr.', 'name parts within CompleteName');
schemaCheck($xp->query('//t:EmailAddresses/t:Entry')->length === 3, 'three emails only');
schemaCheck(xval($xp, '//t:EmailAddresses/t:Entry[@Key="EmailAddress1"]') === 'work@example.invalid', 'work email preferred');
schemaCheck(xval($xp, '//t:EmailAddresses/t:Entry[@Key="EmailAddress2"]') === 'home&one@example.invalid', 'second escaped email');
foreach (['BusinessPhone' => 'work&1', 'BusinessPhone2' => 'work2', 'HomePhone' => 'home1', 'HomePhone2' => 'home2', 'MobilePhone' => 'mobile', 'BusinessFax' => 'fax', 'OtherFax' => 'otherfax', 'Pager' => 'pager', 'OtherTelephone' => 'other'] as $key => $number) {
    schemaCheck(xval($xp, '//t:PhoneNumbers/t:Entry[@Key="' . $key . '"]') === $number, 'phone ' . $key);
}
schemaCheck($xp->query('//t:PhoneNumbers/t:Entry')->length === 9, 'phone keys unique and extras omitted');
schemaCheck($xp->query('//t:PhysicalAddresses/t:Entry')->length === 3, 'unique address keys');
schemaCheck(xval($xp, '//t:PhysicalAddresses/t:Entry[@Key="Business"]/t:Street') === '12, Main & Co', 'street formatting');
schemaCheck(xval($xp, '//t:PhysicalAddresses/t:Entry[@Key="Business"]/t:City') === '<City>', 'city escaping');
schemaCheck(xval($xp, '//t:PhysicalAddresses/t:Entry[@Key="Business"]/t:CountryOrRegion') === 'US', 'country schema name');
schemaCheck($xp->query('//t:PhysicalAddresses/t:Entry[@Key="Other"]/t:City')->length === 0, 'unknown address parts omitted');
schemaCheck($xp->query('//t:Contact/t:IMAddress | //t:Contact/t:ContactPhoto | //t:Contact/t:Title | //t:Contact/t:Suffix | //t:Contact/t:Email1 | //t:Contact/t:BusinessPhone')->length === 0, 'unsupported direct elements omitted');
schemaCheck(xval($xp, '//t:ImAddresses/t:Entry[@Key="ImAddress1"]') === 'xmpp:user@example.invalid' && $xp->query('//t:ImAddresses/t:Entry')->length === 1, 'only identified IM values');
schemaCheck($xp->query('//t:WeddingAnniversary')->length === 0, 'death is not wedding');

$single = schemaXml(['id' => 's', 'emails' => [['address' => 'one@example.invalid']], 'phones' => [['number' => '1']], 'addresses' => [['components' => [['kind' => 'postcode', 'value' => '0']]]]]);
schemaCheck($single->query('//t:EmailAddresses/t:Entry')->length === 1 && $single->query('//t:PhoneNumbers/t:Entry')->length === 1 && $single->query('//t:PhysicalAddresses/t:Entry')->length === 1, 'single dictionary entries');
schemaCheck(xval($single, '//t:PhysicalAddresses/t:Entry/t:PostalCode') === '0', 'zero is not empty');
$empty = schemaXml(['id' => 'empty', 'emails' => [['address' => '']], 'phones' => [['number' => '']], 'addresses' => [['components' => [['kind' => 'unknown', 'value' => 'x']]]], 'anniversaries' => [['type' => 'birth', 'date' => ['month' => 1, 'day' => 1]]]]);
schemaCheck($empty->query('//t:EmailAddresses | //t:PhoneNumbers | //t:PhysicalAddresses | //t:Birthday | //t:GivenName | //t:Surname')->length === 0, 'no fabricated values or empty dictionaries');

$contact = $xp->query('//t:Contact')->item(0);
$roundTrip = EwsConverter::ewsContactToCard($contact, 'book');
schemaCheck($roundTrip['emails']['e2']['address'] === 'home&one@example.invalid', 'read email dictionary');
schemaCheck(count($roundTrip['phones']) === 9, 'read phone dictionary');
schemaCheck(count($roundTrip['addresses']) === 3, 'read address dictionary including region-only');
schemaCheck($roundTrip['name']['components'][0]['value'] === 'Dr.' && $roundTrip['name']['components'][4]['value'] === 'Jr.', 'read CompleteName');
schemaCheck($roundTrip['onlineServices']['im1']['uri'] === 'xmpp:user@example.invalid', 'read IM dictionary');
echo "contact schema tests passed\n";
