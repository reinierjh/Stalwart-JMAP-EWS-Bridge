<?php

class EwsSoap {
    const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    const NS_M    = 'http://schemas.microsoft.com/exchange/services/2006/messages';
    const NS_T    = 'http://schemas.microsoft.com/exchange/services/2006/types';
    const NS_XSI  = 'http://www.w3.org/2001/XMLSchema-instance';

    public static function parseEnvelope(string $rawXml): ?DOMElement {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->loadXML($rawXml)) return null;

        $envelope = $dom->documentElement;
        if (!$envelope || $envelope->namespaceURI !== self::NS_SOAP || $envelope->localName !== 'Envelope') return null;

        $body = $envelope->getElementsByTagNameNS(self::NS_SOAP, 'Body')->item(0);
        if (!$body) return null;

        return $body;
    }

    public static function getOperation(DOMElement $soapBody): string {
        foreach ($soapBody->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child->localName;
            }
        }
        return '';
    }

    public static function getOperationElement(DOMElement $soapBody): ?DOMElement {
        foreach ($soapBody->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }
        return null;
    }

    public static function buildSoapResponse(string $bodyXml): string {
        return '<?xml version="1.0" encoding="utf-8"?>' .
            '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '"' .
            ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"' .
            ' xmlns:xsi="' . self::NS_XSI . '"' .
            ' xmlns:m="' . self::NS_M . '"' .
            ' xmlns:t="' . self::NS_T . '">' .
            '<soap:Header>' .
            '<t:ServerVersionInfo MajorVersion="15" MinorVersion="20" MajorBuildNumber="512" MinorBuildNumber="30"/>' .
            '</soap:Header>' .
            '<soap:Body>' . $bodyXml . '</soap:Body>' .
            '</soap:Envelope>';
    }

    public static function responseSuccess(string $responseClass = 'Success'): string {
        return '<m:ResponseCode>NoError</m:ResponseCode>';
    }

    public static function errorResponse(string $code = 'ErrorInternalServerError', string $message = '', string $responseClass = 'Error'): string {
        $xml = $message ? '<m:MessageText>' . self::escapeXml($message) . '</m:MessageText>' : '<m:MessageText></m:MessageText>';
        $xml .= '<m:ResponseCode>' . self::escapeXml($code) . '</m:ResponseCode>';
        return $xml;
    }

    public static function escapeXml(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function getHeader(DOMDocument $dom, string $localName): string {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', self::NS_SOAP);
        $xpath->registerNamespace('m', self::NS_M);
        $xpath->registerNamespace('t', self::NS_T);

        $nodes = $xpath->query("//soap:Header/t:{$localName}");
        return $nodes->length > 0 ? $dom->saveXML($nodes->item(0)) : '';
    }

    public static function getChildValue(DOMElement $parent, string $localName, string $ns = self::NS_T): string {
        if ($ns) {
            $node = $parent->getElementsByTagNameNS($ns, $localName)->item(0);
        } else {
            $node = $parent->getElementsByTagName($localName)->item(0);
        }
        return $node ? trim($node->textContent) : '';
    }

    public static function getChildValueNS(DOMElement $parent, string $ns, string $localName): string {
        $node = $parent->getElementsByTagNameNS($ns, $localName)->item(0);
        return $node ? trim($node->textContent) : '';
    }

    public static function getChildBool(DOMElement $parent, string $localName): bool {
        $val = self::getChildValue($parent, $localName);
        return strtolower($val) === 'true' || $val === '1';
    }

    public static function getChildInt(DOMElement $parent, string $localName, int $default = 0): int {
        $val = self::getChildValue($parent, $localName);
        return $val !== '' ? (int)$val : $default;
    }

    public static function getChildElements(DOMElement $parent, string $localName, string $ns = self::NS_T): DOMNodeList {
        return $parent->getElementsByTagNameNS($ns, $localName);
    }

    public static function getFirstChild(DOMElement $parent, string $localName, ?string $ns = null): ?DOMElement {
        $children = $parent->childNodes;
        for ($i = 0; $i < $children->length; $i++) {
            $child = $children->item($i);
            if ($child instanceof DOMElement && $child->localName === $localName) {
                if ($ns === null || $child->namespaceURI === $ns) {
                    return $child;
                }
            }
        }
        return null;
    }

    public static function buildId(DOMDocument $dom, string $id, ?string $changeKey = null): string {
        $xml = '<t:ItemId Id="' . self::escapeXml($id) . '"';
        if ($changeKey !== null) {
            $xml .= ' ChangeKey="' . self::escapeXml($changeKey) . '"';
        }
        $xml .= '/>';
        return $xml;
    }

    public static function buildFolderId(DOMDocument $dom, string $id, ?string $changeKey = null): string {
        $xml = '<t:FolderId Id="' . self::escapeXml($id) . '"';
        if ($changeKey !== null) {
            $xml .= ' ChangeKey="' . self::escapeXml($changeKey) . '"';
        }
        $xml .= '/>';
        return $xml;
    }

    public static function buildMailbox(string $name, string $email): string {
        $xml = '<t:Mailbox>';
        if ($name) {
            $xml .= '<t:Name>' . self::escapeXml($name) . '</t:Name>';
        }
        $xml .= '<t:EmailAddress>' . self::escapeXml($email) . '</t:EmailAddress>';
        $xml .= '<t:MailboxType>Mailbox</t:MailboxType>';
        $xml .= '</t:Mailbox>';
        return $xml;
    }

    public static function buildEmailAddress(string $name, string $address): string {
        $xml = '<t:EmailAddress>';
        if ($name) {
            $xml .= '<t:Name>' . self::escapeXml($name) . '</t:Name>';
        }
        $xml .= '<t:Address>' . self::escapeXml($address) . '</t:Address>';
        $xml .= '</t:EmailAddress>';
        return $xml;
    }
}
