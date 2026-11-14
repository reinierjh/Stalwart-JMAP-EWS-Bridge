<?php

class EwsConverter {

    const PFX_CONTACTS = 'ab_';
    const PFX_CALENDAR = 'cal_';

    /**
     * Hash the complete JMAP item, independent of JSON object member ordering.
     * Email bodyValues is the sole exception: it is a fetch-dependent, possibly
     * truncated projection. The immutable message blobId/body structure remain
     * in the hash. Callers must otherwise fetch the same complete properties.
     */
    public static function itemChangeKey(array $object): string {
        unset($object['bodyValues']);
        $json = json_encode(self::canonicalJsonObject($object), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private static function canonicalJsonObject(array $object): object {
        ksort($object, SORT_STRING);
        foreach ($object as &$value) $value = self::canonicalJsonValue($value);
        return (object)$object;
    }

    private static function canonicalJsonValue($value) {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                // Associative JSON decoding turns numeric object keys into PHP
                // integers. Contiguous keys must normalize identically whether
                // they arrived in index order or in a different map order.
                $keys = array_keys($value);
                sort($keys, SORT_REGULAR);
                if ($keys !== range(0, count($keys) - 1)) return self::canonicalJsonObject($value);
                ksort($value, SORT_NUMERIC);
            }
            return array_map([self::class, 'canonicalJsonValue'], $value);
        }
        if ($value instanceof stdClass) return self::canonicalJsonObject(get_object_vars($value));
        if (is_object($value) || is_resource($value)) throw new InvalidArgumentException('Expected JSON-compatible item');
        return $value;
    }

    public static function folderToXml(DOMDocument $dom, array $folder, string $folderId, string $parentFolderId = '0', string $changeKey = ''): string {
        $localName = 'Folder';
        if (str_starts_with($folderId, self::PFX_CALENDAR)) {
            $localName = 'CalendarFolder';
        } elseif (str_starts_with($folderId, self::PFX_CONTACTS)) {
            $localName = 'ContactsFolder';
        }

        $xml = '<t:' . $localName . '>';
        $xml .= '<t:FolderId Id="' . EwsSoap::escapeXml($folderId) . '" ChangeKey="' . EwsSoap::escapeXml($changeKey) . '"/>';
        $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($parentFolderId) . '"/>';
        $xml .= '<t:DisplayName>' . EwsSoap::escapeXml($folder['name'] ?? '') . '</t:DisplayName>';
        $xml .= '<t:TotalCount>' . ($folder['totalEmails'] ?? $folder['totalContacts'] ?? $folder['totalEvents'] ?? 0) . '</t:TotalCount>';
        $xml .= '<t:ChildFolderCount>0</t:ChildFolderCount>';
        $xml .= '<t:UnreadCount>' . ($folder['unreadEmails'] ?? 0) . '</t:UnreadCount>';

        if (!empty($folder['role'])) {
            $roleMap = [
                'inbox'   => 'inbox',
                'drafts'  => 'drafts',
                'trash'   => 'deleteditems',
                'sent'    => 'sentitems',
                'junk'    => 'junkemail',
                'spam'    => 'junkemail',
                'archive' => 'archive',
            ];
            $efId = $roleMap[$folder['role']] ?? '';
            if ($efId) {
                $xml .= '<t:DistinguishedFolderId Id="' . $efId . '"/>';
            }
        }

        $xml .= '<t:FolderClass>' . self::folderClass($localName) . '</t:FolderClass>';
        $xml .= '</t:' . $localName . '>';
        return $xml;
    }

    public static function folderClass(string $ewsFolderType): string {
        return match($ewsFolderType) {
            'CalendarFolder' => 'IPF.Appointment',
            'ContactsFolder' => 'IPF.Contact',
            'TasksFolder'    => 'IPF.Task',
            default          => 'IPF.Note',
        };
    }

    public static function emailToXml(DOMDocument $dom, array $email, string $folderId, array $bodyValues = [], ?string $mime = null): string {
        $id        = $email['id'] ?? '';
        $changeKey = self::itemChangeKey($email);
        $kw        = $email['keywords'] ?? [];

        $xml = '<t:Message>';
        $xml .= EwsSoap::buildId($dom, $id, $changeKey);
        $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';
        $xml .= '<t:ItemClass>IPM.Note</t:ItemClass>';

        if ($mime !== null && $mime !== '') {
            $xml .= '<t:MimeContent CharacterSet="UTF-8">' . base64_encode($mime) . '</t:MimeContent>';
        }

        $xml .= '<t:Subject>' . EwsSoap::escapeXml($email['subject'] ?? '') . '</t:Subject>';

        $from = self::firstAddress($email['from'] ?? []);
        if ($from) {
            $xml .= '<t:From>' . EwsSoap::buildMailbox($from['name'] ?? '', $from['email'] ?? '') . '</t:From>';
        }

        $sender = self::firstAddress($email['sender'] ?? $email['from'] ?? []);
        if ($sender) {
            $xml .= '<t:Sender>' . EwsSoap::buildMailbox($sender['name'] ?? '', $sender['email'] ?? '') . '</t:Sender>';
        }

        $xml .= self::recipientsToXml('ToRecipients',  $email['to']   ?? []);
        $xml .= self::recipientsToXml('CcRecipients',  $email['cc']   ?? []);
        $xml .= self::recipientsToXml('BccRecipients', $email['bcc']  ?? []);
        $xml .= self::recipientsToXml('ReplyTo',       $email['replyTo'] ?? []);

        $xml .= '<t:DateTimeReceived>' . self::formatDateTime($email['receivedAt'] ?? '') . '</t:DateTimeReceived>';
        $xml .= '<t:DateTimeSent>' . self::formatDateTime($email['sentAt'] ?? $email['receivedAt'] ?? '') . '</t:DateTimeSent>';

        $xml .= '<t:IsRead>' . (isset($kw['$seen']) ? 'true' : 'false') . '</t:IsRead>';
        $xml .= '<t:IsDraft>' . (isset($kw['$draft']) ? 'true' : 'false') . '</t:IsDraft>';
        $xml .= '<t:IsFromMe>' . (isset($kw['$submitted']) ? 'true' : 'false') . '</t:IsFromMe>';
        $xml .= '<t:HasAttachments>' . (!empty($email['attachments']) ? 'true' : 'false') . '</t:HasAttachments>';

        $mid = $email['messageId'] ?? null;
        if (is_array($mid)) {
            $mid = $mid[0] ?? null;
        }
        if (!is_string($mid) || $mid === '') {
            $mid = $email['references'] ?? null;
            if (is_array($mid)) {
                $mid = $mid[0] ?? null;
            }
        }
        if (is_string($mid) && $mid !== '') {
            $xml .= '<t:InternetMessageId>' . EwsSoap::escapeXml($mid) . '</t:InternetMessageId>';
        }

        $convIdx = $email['conversationId'] ?? null;
        if (is_string($convIdx) && $convIdx !== '') {
            $xml .= '<t:ConversationIndex>' . base64_encode($convIdx) . '</t:ConversationIndex>';
        } elseif ($id !== '') {
            $xml .= '<t:ConversationIndex>' . base64_encode('01' . $id) . '</t:ConversationIndex>';
        }

        $topic = $email['conversationTopic'] ?? ($email['subject'] ?? '');
        if ($topic !== '') {
            $xml .= '<t:ConversationTopic>' . EwsSoap::escapeXml($topic) . '</t:ConversationTopic>';
        }
        if (!empty($email['inReplyTo'])) {
            $irt = is_array($email['inReplyTo']) ? ($email['inReplyTo'][0] ?? '') : $email['inReplyTo'];
            if ($irt !== '') {
                $xml .= '<t:InReplyTo>' . EwsSoap::escapeXml(is_array($irt) ? ($irt[0] ?? '') : $irt) . '</t:InReplyTo>';
            }
        }

        $refs = $email['references'] ?? null;
        if (is_array($refs)) {
            $refVals = [];
            foreach ($refs as $r) {
                if (is_string($r) && $r !== '') $refVals[] = $r;
            }
            $refs = implode(' ', $refVals);
        }
        if (is_string($refs) && $refs !== '') {
            $xml .= '<t:References>' . EwsSoap::escapeXml($refs) . '</t:References>';
        }

        $xml .= '<t:Size>' . ($email['size'] ?? 0) . '</t:Size>';
        $xml .= '<t:Importance>' . (match($email['importance'] ?? 'normal') { 'high' => 'High', 'low' => 'Low', default => 'Normal' }) . '</t:Importance>';

        $xml .= self::bodyToXml($email, $bodyValues);

        $xml .= '<t:Attachments>';
        if (!empty($email['attachments'])) {
            foreach ($email['attachments'] as $att) {
                $xml .= self::attachmentToXml($dom, $att, $id);
            }
        }
        $xml .= '</t:Attachments>';

        $xml .= '</t:Message>';
        return $xml;
    }

    public static function emailListToXml(DOMDocument $dom, array $emails, string $folderId): string {
        $xml = '';
        foreach ($emails as $email) {
            $xml .= self::emailToXml($dom, $email, $folderId);
        }
        return $xml;
    }

    public static function contactToXml(DOMDocument $dom, array $card, ?string $folderId, array $bodyValues = []): string {
        $id        = $card['id'] ?? '';
        $changeKey = self::itemChangeKey($card);
        $name      = $card['name'] ?? [];

        $xml = '<t:Contact>';
        $xml .= EwsSoap::buildId($dom, self::PFX_CONTACTS . $id, $changeKey);
        if ($folderId !== null) {
            $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';
        }
        $xml .= '<t:ItemClass>IPM.Contact</t:ItemClass>';
        // ItemType properties precede ContactItemType properties in types.xsd.
        $notes = array_values($card['notes'] ?? []);
        if (isset($notes[0]['note']) && is_string($notes[0]['note'])) {
            $xml .= '<t:Body BodyType="Text">' . EwsSoap::escapeXml($notes[0]['note']) . '</t:Body>';
        }
        $cats = array_keys($card['categories'] ?? []);
        if ($cats) {
            $xml .= '<t:Categories>';
            foreach ($cats as $cat) $xml .= '<t:String>' . EwsSoap::escapeXml((string)$cat) . '</t:String>';
            $xml .= '</t:Categories>';
        }

        $parts       = self::contactNameParts($name);
        $given       = $parts['given'];
        $surname     = $parts['surname'];
        $full        = $name['full'] ?? '';
        $displayName = $full !== '' ? $full : ($parts['orderedFull'] !== '' ? $parts['orderedFull'] : trim($given . ' ' . $surname));

        if ($displayName !== '') $xml .= '<t:FileAs>' . EwsSoap::escapeXml($full !== '' ? $full : $displayName) . '</t:FileAs>';
        if ($displayName !== '') $xml .= '<t:DisplayName>' . EwsSoap::escapeXml($displayName) . '</t:DisplayName>';
        if ($given !== '') $xml .= '<t:GivenName>' . EwsSoap::escapeXml($given) . '</t:GivenName>';
        if ($parts['additional'] !== '') {
            $xml .= '<t:MiddleName>' . EwsSoap::escapeXml($parts['additional']) . '</t:MiddleName>';
        }
        if (!empty($card['nicknames'])) {
            $nicks = array_values($card['nicknames']);
            if (!empty($nicks[0]['name'])) $xml .= '<t:Nickname>' . EwsSoap::escapeXml($nicks[0]['name']) . '</t:Nickname>';
        }
        if ($parts['prefix'] !== '' || $parts['suffix'] !== '') {
            $xml .= '<t:CompleteName>';
            if ($parts['prefix'] !== '') $xml .= '<t:Title>' . EwsSoap::escapeXml($parts['prefix']) . '</t:Title>';
            if ($parts['suffix'] !== '') $xml .= '<t:Suffix>' . EwsSoap::escapeXml($parts['suffix']) . '</t:Suffix>';
            $xml .= '</t:CompleteName>';
        }
        $orgs = array_values($card['organizations'] ?? []);
        if ($orgs && !empty($orgs[0]['name'])) {
            $xml .= '<t:CompanyName>' . EwsSoap::escapeXml($orgs[0]['name']) . '</t:CompanyName>';
        }

        $emails = array_values($card['emails'] ?? []);
        usort($emails, fn($a, $b) => (int)(($b['contexts']['work'] ?? false) === true) - (int)(($a['contexts']['work'] ?? false) === true));
        $emailEntries = '';
        $emailIndex = 1;
        foreach ($emails as $email) {
            $address = $email['address'] ?? null;
            if (!is_string($address) || $address === '') continue;
            if ($emailIndex > 3) break;
            $emailEntries .= '<t:Entry Key="EmailAddress' . $emailIndex++ . '">' . EwsSoap::escapeXml($address) . '</t:Entry>';
        }
        if ($emailEntries !== '') $xml .= '<t:EmailAddresses>' . $emailEntries . '</t:EmailAddresses>';

        $addresses = [];
        foreach (array_values($card['addresses'] ?? []) as $addr) {
            $ctx = $addr['contexts'] ?? [];
            $key = ($ctx['work'] ?? false) === true ? 'Business' : ((($ctx['home'] ?? false) === true || ($ctx['private'] ?? false) === true) ? 'Home' : 'Other');
            if (isset($addresses[$key])) continue;
            $parsed = self::parseAddressComponents($addr['components'] ?? []);
            $entry = '';
            foreach (['street' => 'Street', 'city' => 'City', 'state' => 'State', 'country' => 'CountryOrRegion', 'postal' => 'PostalCode'] as $part => $element) {
                if ($parsed[$part] !== '') $entry .= '<t:' . $element . '>' . EwsSoap::escapeXml($parsed[$part]) . '</t:' . $element . '>';
            }
            if ($entry !== '') $addresses[$key] = '<t:Entry Key="' . $key . '">' . $entry . '</t:Entry>';
        }
        if ($addresses) $xml .= '<t:PhysicalAddresses>' . implode('', $addresses) . '</t:PhysicalAddresses>';

        $phones = [];
        foreach (array_values($card['phones'] ?? []) as $p) {
            $num    = $p['number'] ?? null;
            if (!is_string($num) || $num === '') continue;
            $ctx    = $p['contexts'] ?? [];
            $feat   = $p['features'] ?? [];
            $work = ($ctx['work'] ?? false) === true;
            $home = ($ctx['home'] ?? false) === true || ($ctx['private'] ?? false) === true;
            if (($feat['cell'] ?? false) === true || ($feat['mobile'] ?? false) === true) $keys = ['MobilePhone'];
            elseif (($feat['fax'] ?? false) === true) $keys = [$work ? 'BusinessFax' : ($home ? 'HomeFax' : 'OtherFax')];
            elseif (($feat['pager'] ?? false) === true) $keys = ['Pager'];
            elseif ($work) $keys = ['BusinessPhone', 'BusinessPhone2'];
            elseif ($home) $keys = ['HomePhone', 'HomePhone2'];
            else $keys = ['OtherTelephone'];
            foreach ($keys as $key) {
                if (!isset($phones[$key])) { $phones[$key] = $num; break; }
            }
        }
        if ($phones) {
            $xml .= '<t:PhoneNumbers>';
            foreach ($phones as $key => $num) $xml .= '<t:Entry Key="' . $key . '">' . EwsSoap::escapeXml($num) . '</t:Entry>';
            $xml .= '</t:PhoneNumbers>';
        }
        $dates = [];
        foreach ($card['anniversaries'] ?? [] as $ann) {
            $type = $ann['kind'] ?? $ann['type'] ?? '';
            $key = $type === 'birth' ? 'Birthday' : ($type === 'wedding' ? 'WeddingAnniversary' : null);
            $date = $ann['date'] ?? null;
            if ($key === null || isset($dates[$key])) continue;
            if (is_array($date) && is_int($date['year'] ?? null) && is_int($date['month'] ?? null) && is_int($date['day'] ?? null)) {
                $date = sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);
            }
            if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && (int)$m[1] > 0 && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $dates[$key] = $date . 'T00:00:00Z';
        }
        if (isset($dates['Birthday'])) $xml .= '<t:Birthday>' . $dates['Birthday'] . '</t:Birthday>';
        $links = array_values($card['links'] ?? []);
        foreach ($links as $link) {
            if (($link['contexts']['work'] ?? false) === true && !empty($link['uri'])) {
                $xml .= '<t:BusinessHomePage>' . EwsSoap::escapeXml($link['uri']) . '</t:BusinessHomePage>';
                break;
            }
        }
        $depts = array_values($card['departments'] ?? []);
        if ($depts && !empty($depts[0]['name'])) $xml .= '<t:Department>' . EwsSoap::escapeXml($depts[0]['name']) . '</t:Department>';
        $imEntries = '';
        $imIndex = 1;
        foreach (array_values($card['onlineServices'] ?? []) as $im) {
            $uri = $im['uri'] ?? null;
            if (!is_string($uri) || !preg_match('/^(?:im|xmpp|sip|sips|matrix):/i', $uri)) continue;
            if ($imIndex > 3) break;
            $imEntries .= '<t:Entry Key="ImAddress' . $imIndex++ . '">' . EwsSoap::escapeXml($uri) . '</t:Entry>';
        }
        if ($imEntries !== '') $xml .= '<t:ImAddresses>' . $imEntries . '</t:ImAddresses>';
        $titles = array_values($card['jobTitles'] ?? $card['titles'] ?? []);
        if ($titles && !empty($titles[0]['name'])) $xml .= '<t:JobTitle>' . EwsSoap::escapeXml($titles[0]['name']) . '</t:JobTitle>';
        if ($surname !== '') $xml .= '<t:Surname>' . EwsSoap::escapeXml($surname) . '</t:Surname>';
        if (isset($dates['WeddingAnniversary'])) $xml .= '<t:WeddingAnniversary>' . $dates['WeddingAnniversary'] . '</t:WeddingAnniversary>';

        $xml .= '</t:Contact>';
        return $xml;
    }

    public static function calendarToXml(DOMDocument $dom, array $event, ?string $folderId): string {
        $id        = $event['id'] ?? '';
        $changeKey = self::itemChangeKey($event);

        $startRaw  = $event['start'] ?? '';
        $startTs   = JmapCalendarConverter::jmapTimeToTimestamp($startRaw);
        $durSecs   = JmapCalendarConverter::parseDuration($event['duration'] ?? 'PT1H');
        $endTs     = $startTs + $durSecs;
        $allDay    = !empty($event['showWithoutTime']);

        $xml = '<t:CalendarItem>';
        $xml .= EwsSoap::buildId($dom, self::PFX_CALENDAR . $id, $changeKey);
        if ($folderId !== null) {
            $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';
        }

        $xml .= '<t:Subject>' . EwsSoap::escapeXml($event['title'] ?? '(No Subject)') . '</t:Subject>';
        $xml .= '<t:Start>' . gmdate('Y-m-d\TH:i:s\Z', $startTs) . '</t:Start>';
        $xml .= '<t:End>' . gmdate('Y-m-d\TH:i:s\Z', $endTs) . '</t:End>';
        $xml .= '<t:IsAllDayEvent>' . ($allDay ? 'true' : 'false') . '</t:IsAllDayEvent>';
        $xml .= '<t:CalendarItemType>' . ($allDay ? 'Single' : 'Single') . '</t:CalendarItemType>';

        $locs = array_values($event['locations'] ?? []);
        if ($locs) {
            $xml .= '<t:Location>' . EwsSoap::escapeXml($locs[0]['name'] ?? '') . '</t:Location>';
        }

        if (!empty($event['description'])) {
            $xml .= '<t:Body BodyType="Text">' . EwsSoap::escapeXml($event['description']) . '</t:Body>';
        }

        $participants = $event['participants'] ?? [];
        foreach ($participants as $p) {
            $roles = $p['roles'] ?? [];
            if (isset($roles['organizer'])) {
                $xml .= '<t:Organizer>' . EwsSoap::buildMailbox($p['name'] ?? '', $p['email'] ?? $p['sendTo']['imip'] ?? '') . '</t:Organizer>';
            } else {
                $attType = 'RequiredAttendees';
                if (isset($roles['optional']))      $attType = 'OptionalAttendees';
                if (isset($roles['informational'])) $attType = 'Resources';
                $xml .= '<t:' . $attType . '>' . EwsSoap::buildMailbox($p['name'] ?? '', $p['email'] ?? $p['sendTo']['imip'] ?? '') . '</t:' . $attType . '>';
            }
        }

        $xml .= '<t:Sensitivity>' . match($event['privacy'] ?? 'public') {
            'private' => 'Private',
            'confidential', 'secret' => 'Confidential',
            default    => 'Normal',
        } . '</t:Sensitivity>';

        $xml .= '<t:LegacyFreeBusyStatus>' . match($event['freeBusyStatus'] ?? 'busy') {
            'free'        => 'Free',
            'tentative'   => 'Tentative',
            'unavailable' => 'OutOfOffice',
            default       => 'Busy',
        } . '</t:LegacyFreeBusyStatus>';

        foreach ($event['alerts'] ?? [] as $alert) {
            $offset = $alert['trigger']['offset'] ?? null;
            if ($offset && str_starts_with($offset, '-')) {
                $secs = JmapCalendarConverter::parseDuration(ltrim($offset, '-'));
                $xml .= '<t:ReminderMinutesBeforeStart>' . max(0, intdiv($secs, 60)) . '</t:ReminderMinutesBeforeStart>';
                $xml .= '<t:IsReminderSet>true</t:IsReminderSet>';
                break;
            }
        }

        $cats = array_keys($event['categories'] ?? []);
        if ($cats) {
            $xml .= '<t:Categories>';
            foreach ($cats as $cat) {
                $xml .= '<t:String>' . EwsSoap::escapeXml($cat) . '</t:String>';
            }
            $xml .= '</t:Categories>';
        }

        $xml .= '</t:CalendarItem>';
        return $xml;
    }

    private static function contactNameParts(array $name): array {
        // RFC 9553 kinds, plus legacy kinds used by older Stalwart cards.
        $kindMap = [
            'given' => 'given', 'surname' => 'surname', 'surname2' => 'surname',
            'given2' => 'additional', 'additional' => 'additional',
            'title' => 'prefix', 'prefix' => 'prefix',
            'credential' => 'suffix', 'generation' => 'suffix', 'suffix' => 'suffix',
        ];
        $parts = ['given' => '', 'surname' => '', 'additional' => '', 'prefix' => '', 'suffix' => '', 'orderedFull' => ''];
        $lastGroup = null;
        $separator = null;
        $hasFullPart = false;
        $ordered = ($name['isOrdered'] ?? false) === true;
        $defaultSeparator = $ordered && is_string($name['defaultSeparator'] ?? null) ? $name['defaultSeparator'] : ' ';
        foreach (($name['components'] ?? []) as $component) {
            if (!is_array($component) || !is_string($component['value'] ?? null)) continue;
            $kind = $component['kind'] ?? '';
            $value = $component['value'];
            if ($kind === 'separator') {
                if ($ordered) $separator = ($separator ?? '') . $value;
                continue;
            }
            if ($value === '') continue;
            if ($ordered) {
                if ($hasFullPart) $parts['orderedFull'] .= $separator ?? $defaultSeparator;
                $parts['orderedFull'] .= $value;
                $hasFullPart = true;
            }
            $group = is_string($kind) ? ($kindMap[$kind] ?? null) : null;
            if ($group !== null) {
                if ($parts[$group] !== '') {
                    $parts[$group] .= $group === $lastGroup ? ($separator ?? $defaultSeparator) : ' ';
                }
                $parts[$group] .= $value;
            }
            $lastGroup = $group;
            $separator = null;
        }
        foreach (['given', 'surname', 'additional', 'prefix', 'suffix'] as $key) {
            if ($parts[$key] === '' && is_string($name[$key] ?? null)) $parts[$key] = $name[$key];
        }
        return $parts;
    }

    public static function ewsContactToCard(DOMElement $contactEl, string $addressBookId): array {
        $card = [
            '@type'          => 'Card',
            'version'        => '1.0',
            'addressBookIds' => [$addressBookId => true],
        ];

        $nsT = EwsSoap::NS_T;

        $given   = self::getXmlChildValue($contactEl, 't:GivenName');
        $surname = self::getXmlChildValue($contactEl, 't:Surname');
        $company = self::getXmlChildValue($contactEl, 't:CompanyName');
        $jobTitle = self::getXmlChildValue($contactEl, 't:JobTitle');

        $middle = self::getXmlChildValue($contactEl, 't:MiddleName');
        $prefix = self::getXmlChildValue($contactEl, 't:CompleteName/t:Title') ?: self::getXmlChildValue($contactEl, 't:Title');
        $suffix = self::getXmlChildValue($contactEl, 't:CompleteName/t:Suffix') ?: self::getXmlChildValue($contactEl, 't:Suffix');
        $displayName = self::getXmlChildValue($contactEl, 't:DisplayName');
        $components = [];
        foreach (['title' => $prefix, 'given' => $given, 'given2' => $middle, 'surname' => $surname, 'credential' => $suffix] as $kind => $value) {
            if ($value !== '') $components[] = ['kind' => $kind, 'value' => $value];
        }
        if ($components || $displayName !== '') {
            $card['name'] = [];
            if ($components) {
                $card['name']['components'] = $components;
                $card['name']['isOrdered'] = true;
            }
            if ($displayName !== '') $card['name']['full'] = $displayName;
        }

        if ($company)  $card['organizations'] = ['o1' => ['name' => $company]];
        if ($jobTitle) $card['jobTitles']     = ['jt1' => ['name' => $jobTitle]];

        $dept = self::getXmlChildValue($contactEl, 't:Department');
        if ($dept)     $card['departments']   = ['d1' => ['name' => $dept]];

        $emails = [];
        $emailNodes = self::contactDictionaryEntries($contactEl, 'EmailAddresses');
        foreach (['Email1', 'Email2', 'Email3'] as $i => $key) {
            $node = $emailNodes['EmailAddress' . ($i + 1)] ?? null;
            $val = $node ? trim($node->textContent) : (self::getXmlChildValue($contactEl, "t:{$key}Address") ?: self::getXmlChildValue($contactEl, "t:{$key}"));
            if ($val !== '') {
                $emails['e' . ($i+1)] = ['address' => $val];
            }
        }
        if ($emails) $card['emails'] = $emails;

        $phones = [];
        $pi = 1;
        $phoneFields = [
            'BusinessPhone' => [['work' => true], []], 'BusinessPhone2' => [['work' => true], []],
            'HomePhone' => [['home' => true], []], 'HomePhone2' => [['home' => true], []],
            'MobilePhone' => [[], ['cell' => true]], 'BusinessFax' => [['work' => true], ['fax' => true]],
            'HomeFax' => [['home' => true], ['fax' => true]], 'OtherFax' => [[], ['fax' => true]],
            'OtherTelephone' => [[], []], 'Pager' => [[], ['pager' => true]],
        ];
        $phoneNodes = self::contactDictionaryEntries($contactEl, 'PhoneNumbers');
        foreach ($phoneFields as $xmlField => [$ctx, $feat]) {
            $node = $phoneNodes[$xmlField] ?? null;
            $val = $node ? trim($node->textContent) : self::getXmlChildValue($contactEl, 't:' . $xmlField);
            if ($val !== '') {
                $entry = ['number' => $val];
                if ($ctx)  $entry['contexts'] = $ctx;
                if ($feat) $entry['features'] = $feat;
                $phones['p' . $pi++] = $entry;
            }
        }
        if ($phones) $card['phones'] = $phones;

        $addrs = [];
        $addressNodes = self::contactDictionaryEntries($contactEl, 'PhysicalAddresses');
        foreach (['Business' => 'work', 'Home' => 'home', 'Other' => 'other'] as $prefix => $context) {
            $node = $addressNodes[$prefix] ?? null;
            $street = $node ? self::getXmlChildValue($node, 't:Street') : self::getXmlChildValue($contactEl, "t:{$prefix}Street");
            $city   = $node ? self::getXmlChildValue($node, 't:City') : self::getXmlChildValue($contactEl, "t:{$prefix}City");
            $state  = $node ? self::getXmlChildValue($node, 't:State') : self::getXmlChildValue($contactEl, "t:{$prefix}State");
            $postal = $node ? self::getXmlChildValue($node, 't:PostalCode') : self::getXmlChildValue($contactEl, "t:{$prefix}PostalCode");
            $country= $node ? self::getXmlChildValue($node, 't:CountryOrRegion') : self::getXmlChildValue($contactEl, "t:{$prefix}Country");
            if ($street !== '' || $city !== '' || $state !== '' || $postal !== '' || $country !== '') {
                $components = [];
                if ($street !== '') $components[] = ['kind' => 'street', 'value' => $street];
                if ($city !== '')   $components[] = ['kind' => 'locality', 'value' => $city];
                if ($state !== '')  $components[] = ['kind' => 'region', 'value' => $state];
                if ($postal !== '') $components[] = ['kind' => 'postcode', 'value' => $postal];
                if ($country !== '')$components[] = ['kind' => 'country', 'value' => $country];
                $addr = ['@type' => 'Address', 'components' => $components];
                if ($context !== 'other') $addr['contexts'] = [$context => true];
                $addrs['a' . $pi++] = $addr;
            }
        }
        if ($addrs) $card['addresses'] = $addrs;

        $imNodes = self::contactDictionaryEntries($contactEl, 'ImAddresses');
        $ims = [];
        foreach (range(1, 3) as $i) {
            $node = $imNodes['ImAddress' . $i] ?? null;
            $im = $node ? trim($node->textContent) : ($i === 1 ? self::getXmlChildValue($contactEl, 't:IMAddress') : '');
            if ($im !== '') $ims['im' . $i] = preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $im) ? ['uri' => $im] : ['user' => $im];
        }
        if ($ims) $card['onlineServices'] = $ims;

        $url = self::getXmlChildValue($contactEl, 't:BusinessHomePage');
        if ($url) $card['links'] = ['l1' => ['@type' => 'Link', 'uri' => $url]];

        $body = self::getXmlChildValue($contactEl, 't:Body');
        if ($body) $card['notes'] = ['n1' => ['note' => $body]];

        $cats = self::getXmlChildrenValues($contactEl, 't:Categories', 't:String');
        if ($cats) {
            $categories = [];
            foreach ($cats as $cat) $categories[$cat] = true;
            $card['categories'] = $categories;
        }

        return $card;
    }

    private static function contactDictionaryEntries(DOMElement $contact, string $dictionary): array {
        $entries = [];
        $container = EwsSoap::getFirstChild($contact, $dictionary, EwsSoap::NS_T);
        if ($container) foreach ($container->childNodes as $entry) {
            if ($entry instanceof DOMElement && $entry->namespaceURI === EwsSoap::NS_T && $entry->localName === 'Entry') {
                $key = $entry->getAttribute('Key');
                if ($key !== '' && !isset($entries[$key])) $entries[$key] = $entry;
            }
        }
        return $entries;
    }

    public static function ewsEventToJmap(DOMElement $eventEl, string $calendarId): array {
        $nsT = EwsSoap::NS_T;

        $startStr  = self::getXmlChildValue($eventEl, 't:Start');
        $endStr    = self::getXmlChildValue($eventEl, 't:End');
        $allDay    = strtolower(self::getXmlChildValue($eventEl, 't:IsAllDayEvent')) === 'true';
        $subject   = self::getXmlChildValue($eventEl, 't:Subject') ?: '(No Subject)';
        $location  = self::getXmlChildValue($eventEl, 't:Location');
        $body      = self::getXmlChildValue($eventEl, 't:Body');

        $startTs   = $startStr ? strtotime($startStr) : time();
        $endTs     = $endStr   ? strtotime($endStr)   : ($startTs + 3600);
        $duration  = $endTs - $startTs;

        $event = [
            '@type'       => 'Event',
            'calendarIds' => [$calendarId => true],
            'title'       => $subject,
            'uid'         => bin2hex(random_bytes(16)) . '@ews-bridge',
        ];

        if ($allDay) {
            $event['showWithoutTime'] = true;
            $event['start'] = gmdate('Y-m-d', $startTs);
            $event['duration'] = 'P' . max(1, intdiv($duration, 86400)) . 'D';
        } else {
            $event['start'] = gmdate('Y-m-d\TH:i:s', $startTs);
            $event['duration'] = $duration > 0 ? JmapCalendarConverter::secondsToDuration($duration) : 'PT1H';
        }

        if ($location) {
            $event['locations'] = ['l1' => ['@type' => 'Location', 'name' => $location]];
        }

        if ($body) {
            $event['description'] = $body;
        }

        $orgName  = self::getXmlChildValue($eventEl, 't:Organizer', 't:Name');
        $orgEmail = self::getXmlChildValue($eventEl, 't:Organizer', 't:EmailAddress');
        if ($orgEmail) {
            $event['participants']['org'] = [
                '@type' => 'Participant',
                'name'  => $orgName ?: $orgEmail,
                'email' => $orgEmail,
                'roles' => ['organizer' => true, 'attendee' => true],
            ];
        }

        $attTypes = ['t:RequiredAttendees', 't:OptionalAttendees', 't:Resources'];
        $rolesMap = ['t:RequiredAttendees' => ['attendee' => true], 't:OptionalAttendees' => ['optional' => true], 't:Resources' => ['informational' => true]];
        $idx = 0;
        foreach ($attTypes as $attType) {
            $role = $rolesMap[$attType];
            $atts = $eventEl->getElementsByTagNameNS($nsT, substr($attType, 2));
            for ($i = 0; $i < $atts->length; $i++) {
                $attEl = $atts->item($i);
                $aName  = self::getXmlChildValue($attEl, 't:Name');
                $aEmail = self::getXmlChildValue($attEl, 't:EmailAddress');
                if ($aEmail) {
                    $event['participants']['att' . $idx++] = [
                        '@type' => 'Participant',
                        'name'  => $aName ?: $aEmail,
                        'email' => $aEmail,
                        'roles' => $role,
                    ];
                }
            }
        }

        return $event;
    }

    private static function bodyValue(array $bodyValues, ?string $partId): ?string {
        if ($partId === null) return null;
        if (is_string($bodyValues[$partId]['value'] ?? null)) {
            return $bodyValues[$partId]['value'];
        }
        foreach ($bodyValues as $bv) {
            if (is_array($bv) && ($bv['partId'] ?? null) === $partId && is_string($bv['value'] ?? null)) {
                return $bv['value'];
            }
        }
        return null;
    }

    public static function bodyToXml(array $email, array $bodyValues = []): string {
        if (is_array($email['bodyValues'] ?? null)) $bodyValues += $email['bodyValues'];
        foreach (['htmlBody' => 'HTML', 'textBody' => 'Text'] as $property => $type) {
            $part = $email[$property][0] ?? null;
            if (!is_array($part)) continue;
            $partId = is_string($part['partId'] ?? null) ? $part['partId'] : null;
            $value = self::bodyValue($bodyValues, $partId);
            if ($value === null && is_string($part['value'] ?? null)) $value = $part['value'];
            if ($value !== null) {
                return '<t:Body BodyType="' . $type . '">' . EwsSoap::escapeXml($value) . '</t:Body>';
            }
        }
        return '';
    }

    public static function attachmentToXml(DOMDocument $dom, array $att, string $emailId): string {
        $blobId   = $att['blobId'] ?? '';
        $filename = $att['name']   ?? ('attachment-' . substr($blobId, 0, 8));
        $contentType = $att['type'] ?? 'application/octet-stream';
        $size     = $att['size']   ?? 0;
        $inline   = strtolower($att['disposition'] ?? '') === 'inline';

        $xml = '<t:FileAttachment>';
        $xml .= '<t:AttachmentId Id="' . EwsSoap::escapeXml($emailId . '||' . $blobId . '||' . $filename) . '" />';
        $xml .= '<t:Name>' . EwsSoap::escapeXml($filename) . '</t:Name>';
        $xml .= '<t:ContentType>' . EwsSoap::escapeXml($contentType) . '</t:ContentType>';
        $xml .= '<t:Size>' . $size . '</t:Size>';
        $xml .= '<t:IsInline>' . ($inline ? 'true' : 'false') . '</t:IsInline>';
        $xml .= '</t:FileAttachment>';
        return $xml;
    }

    public static function modHash(array $item): string {
        return self::itemChangeKey($item);
    }

    private static function firstAddress(array $addrs): ?array {
        $addrs = array_values($addrs);
        return $addrs[0] ?? null;
    }

    private static function recipientsToXml(string $tag, array $recipients): string {
        if (empty($recipients)) return '';
        $xml = '';
        foreach (array_values($recipients) as $rcpt) {
            $xml .= '<t:' . $tag . '>' .
                    EwsSoap::buildMailbox($rcpt['name'] ?? '', $rcpt['email'] ?? '') .
                    '</t:' . $tag . '>';
        }
        return $xml;
    }

    private static function formatDateTime(?string $jmapDate): string {
        if (!$jmapDate) return gmdate('Y-m-d\TH:i:s\Z');
        $ts = strtotime($jmapDate);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function getXmlChildValue(DOMElement $parent, string $xpathExpr, ?string $subExpr = null): string {
        $parts = explode('/', $xpathExpr);
        $current = $parent;
        foreach ($parts as $part) {
            if (str_starts_with($part, 't:')) {
                $localName = substr($part, 2);
                $found = null;
                foreach ($current->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === $localName && $child->namespaceURI === EwsSoap::NS_T) {
                        $found = $child;
                        break;
                    }
                }
                if (!$found) return '';
                $current = $found;
            }
        }
        return trim($current->textContent);
    }

    private static function getXmlChildrenValues(DOMElement $parent, string $listExpr, string $itemExpr): array {
        $listParts = explode('/', $listExpr);
        $listLocal = substr(end($listParts), 2);
        $itemLocal = substr($itemExpr, 2);

        $values = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $listLocal) {
                foreach ($child->childNodes as $item) {
                    if ($item instanceof DOMElement && $item->localName === $itemLocal) {
                        $values[] = trim($item->textContent);
                    }
                }
            }
        }
        return $values;
    }

    private static function parseAddressComponents(array $components): array {
        $r = ['street' => '', 'city' => '', 'state' => '', 'postal' => '', 'country' => ''];
        foreach ($components as $comp) {
            $v = $comp['value'] ?? '';
            match ($comp['kind'] ?? '') {
                'name', 'number', 'street' => $r['street']  .= ($r['street'] ? ', ' : '') . $v,
                'locality'                 => $r['city']     = $v,
                'region'                   => $r['state']    = $v,
                'postcode', 'postalCode'   => $r['postal']   = $v,
                'country', 'countryName'   => $r['country']  = $v,
                default                    => null,
            };
        }
        return $r;
    }
}
