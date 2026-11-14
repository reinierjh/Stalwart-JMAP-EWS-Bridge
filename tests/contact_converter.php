<?php

if (!defined('CONTACT_TEST_EMBEDDED')) {
    require __DIR__ . '/../ews/src/Soap.php';
    require __DIR__ . '/../jmap/jmap_contacts.php';
    require __DIR__ . '/../ews/src/Converter.php';
}

function checkSame($expected, $actual, string $description): void {
    if ($expected !== $actual) {
        throw new RuntimeException($description . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contactXml(array $name): DOMElement {
    $xml = EwsConverter::contactToXml(new DOMDocument(), ['id' => '123', 'name' => $name], null);
    $dom = new DOMDocument();
    if (!$dom->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>')) {
        throw new RuntimeException('invalid XML');
    }
    return $dom->documentElement->firstChild;
}

function value(DOMElement $contact, string $field): string {
    $node = $contact->getElementsByTagNameNS(EwsSoap::NS_T, $field)->item(0);
    return $node ? $node->textContent : '';
}

$name = [
    'isOrdered' => true,
    'defaultSeparator' => ' ',
    'given' => 'stale',
    'components' => [
        ['kind' => 'title', 'value' => 'Dr.'],
        ['kind' => 'given', 'value' => 'Anne &'],
        ['kind' => 'separator', 'value' => '-'],
        ['kind' => 'given', 'value' => 'Marie'],
        ['kind' => 'given2', 'value' => 'Q.'],
        ['kind' => 'surname', 'value' => 'van <Gogh>'],
        ['kind' => 'surname2', 'value' => 'Smith'],
        ['kind' => 'generation', 'value' => 'III'],
        ['kind' => 'credential', 'value' => 'M.D.'],
    ],
];
$xml = contactXml($name);
checkSame('Anne &-Marie', value($xml, 'GivenName'), 'component given precedence and separator');
checkSame('Q.', value($xml, 'MiddleName'), 'given2');
checkSame('van <Gogh> Smith', value($xml, 'Surname'), 'surname2 and XML escaping');
checkSame('Dr.', value($xml, 'Title'), 'title');
checkSame('III M.D.', value($xml, 'Suffix'), 'generation and credential');
checkSame('Dr. Anne &-Marie Q. van <Gogh> Smith III M.D.', value($xml, 'DisplayName'), 'ordered full name');
checkSame(value($xml, 'DisplayName'), value($xml, 'FileAs'), 'file as');

$xml = contactXml(['full' => 'Explicit & full', 'given' => 'Legacy', 'surname' => 'Name', 'additional' => 'Middle', 'prefix' => 'Mr.', 'suffix' => 'Jr.']);
foreach (['DisplayName' => 'Explicit & full', 'FileAs' => 'Explicit & full', 'GivenName' => 'Legacy', 'Surname' => 'Name', 'MiddleName' => 'Middle', 'Title' => 'Mr.', 'Suffix' => 'Jr.'] as $field => $expected) {
    checkSame($expected, value($xml, $field), 'legacy ' . $field);
}
$xml = contactXml(['components' => [['kind' => 'given', 'value' => 'A'], ['kind' => 'additional', 'value' => 'B'], ['kind' => 'prefix', 'value' => 'C'], ['kind' => 'suffix', 'value' => 'D']], 'surname' => 'Fallback']);
foreach (['GivenName' => 'A', 'MiddleName' => 'B', 'Title' => 'C', 'Suffix' => 'D', 'Surname' => 'Fallback'] as $field => $expected) {
    checkSame($expected, value($xml, $field), 'legacy component or fallback ' . $field);
}
$xml = contactXml(['isOrdered' => true, 'defaultSeparator' => '', 'components' => [['kind' => 'given', 'value' => 'A'], ['kind' => 'given', 'value' => 'B']]]);
checkSame('AB', value($xml, 'GivenName'), 'empty default separator');
$xml = contactXml(['isOrdered' => true, 'defaultSeparator' => '/', 'components' => [['kind' => 'given', 'value' => 'A'], ['kind' => 'given', 'value' => 'B'], ['kind' => 'surname', 'value' => 'C'], ['kind' => 'given', 'value' => 'D']]]);
checkSame('A/B D', value($xml, 'GivenName'), 'multiple separated given components');
checkSame('A/B/C/D', value($xml, 'DisplayName'), 'default separator for full name');
$xml = contactXml(['full' => 'Override', 'components' => [['kind' => 'given', 'value' => 'From component']]]);
checkSame('Override', value($xml, 'DisplayName'), 'explicit full name precedence');
checkSame('From component', value($xml, 'GivenName'), 'full name is not split');

$dom = new DOMDocument();
$dom->loadXML('<t:Contact xmlns:t="' . EwsSoap::NS_T . '"><t:GivenName>A &amp; B</t:GivenName><t:Surname>Smith</t:Surname><t:MiddleName>Q</t:MiddleName><t:Title>Dr.</t:Title><t:Suffix>Jr.</t:Suffix><t:DisplayName>Dr. A &amp; B Q Smith Jr.</t:DisplayName></t:Contact>');
$card = EwsConverter::ewsContactToCard($dom->documentElement, 'book');
checkSame(['title', 'given', 'given2', 'surname', 'credential'], array_column($card['name']['components'], 'kind'), 'create standard kinds and order');
checkSame('Dr. A & B Q Smith Jr.', $card['name']['full'], 'create display name');
checkSame(false, isset($card['name']['given']), 'create omits legacy properties');
$xml = contactXml($card['name']);
checkSame('A & B', value($xml, 'GivenName'), 'create round trip');
checkSame('Q', value($xml, 'MiddleName'), 'create middle round trip');
$dom->loadXML('<t:Contact xmlns:t="' . EwsSoap::NS_T . '"><t:DisplayName>Only name</t:DisplayName></t:Contact>');
checkSame(['full' => 'Only name'], EwsConverter::ewsContactToCard($dom->documentElement, 'book')['name'], 'display-only create');
echo "contact converter tests passed\n";
