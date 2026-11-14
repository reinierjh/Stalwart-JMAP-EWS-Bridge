<?php

class EwsCalendarSafetyException extends DomainException {}

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

        $emails = self::sortedContactEntries($card['emails'] ?? []);
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
        foreach (self::sortedContactEntries($card['addresses'] ?? []) as $addr) {
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
        foreach (self::sortedContactEntries($card['phones'] ?? []) as $p) {
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
        foreach (self::sortedContactEntries($card['onlineServices'] ?? []) as $im) {
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

    /** JMAP maps are unordered; sort original entry IDs before assigning EWS slots. */
    private static function sortedContactEntries(array $entries): array {
        ksort($entries, SORT_STRING);
        return array_values($entries);
    }

    public static function calendarToXml(DOMDocument $dom, array $event, ?string $folderId): string {
        if (array_key_exists('status', $event) && $event['status'] !== null && $event['status'] !== 'confirmed') throw new EwsCalendarSafetyException('Unsupported calendar event status');
        // Calendar/get defaults are not supplied here; never guess an effective reminder.
        $useDefaultAlerts = $event['useDefaultAlerts'] ?? null;
        if ($useDefaultAlerts === true) throw new EwsCalendarSafetyException('Calendar default alerts are not available');
        if ($useDefaultAlerts !== null && $useDefaultAlerts !== false) throw new EwsCalendarSafetyException('Invalid calendar default alerts flag');
        [$startTs, $endTs, $zoneId, $allDay] = self::calendarReadTimes($event);
        $recurrence = self::calendarRecurrenceXml($event, $startTs, $endTs, $allDay);
        $id        = $event['id'] ?? '';
        $changeKey = self::itemChangeKey($event);

        $xml = '<t:CalendarItem>';
        $xml .= EwsSoap::buildId($dom, self::PFX_CALENDAR . $id, $changeKey);
        if ($folderId !== null) {
            $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';
        }

        $xml .= '<t:Subject>' . EwsSoap::escapeXml($event['title'] ?? '(No Subject)') . '</t:Subject>';
        $xml .= '<t:Sensitivity>' . match($event['privacy'] ?? 'public') {
            'private' => 'Private', 'public' => 'Normal',
            default => throw new EwsCalendarSafetyException('Unsupported calendar privacy'),
        } . '</t:Sensitivity>';
        if (!empty($event['description'])) {
            $contentType = $event['descriptionContentType'] ?? 'text/plain';
            if (!in_array($contentType, ['text/plain', 'text/html'], true)) throw new EwsCalendarSafetyException('Unsupported calendar description content type');
            $xml .= '<t:Body BodyType="' . ($contentType === 'text/html' ? 'HTML' : 'Text') . '">' . EwsSoap::escapeXml($event['description']) . '</t:Body>';
        }
        $cats = array_keys($event['categories'] ?? []);
        if ($cats) {
            $xml .= '<t:Categories>';
            foreach ($cats as $cat) $xml .= '<t:String>' . EwsSoap::escapeXml((string)$cat) . '</t:String>';
            $xml .= '</t:Categories>';
        }
        $alerts = $event['alerts'] ?? [];
        if ($alerts instanceof stdClass) $alerts = get_object_vars($alerts);
        if (!is_array($alerts)) throw new EwsCalendarSafetyException('Unsupported calendar alerts');
        if ($alerts) {
            if (count($alerts) !== 1) throw new EwsCalendarSafetyException('Multiple calendar alerts are not supported');
            $alert = reset($alerts);
            if ($alert instanceof stdClass) $alert = get_object_vars($alert);
            if (!is_array($alert) || array_diff(array_keys($alert), ['@type', 'action', 'trigger']) || (isset($alert['@type']) && $alert['@type'] !== 'Alert') || ($alert['action'] ?? null) !== 'display') throw new EwsCalendarSafetyException('Unsupported calendar alert');
            $trigger = $alert['trigger'] ?? null;
            if ($trigger instanceof stdClass) $trigger = get_object_vars($trigger);
            if (!is_array($trigger) || array_diff(array_keys($trigger), ['@type', 'relativeTo', 'offset']) || ($trigger['@type'] ?? null) !== 'OffsetTrigger' || ($trigger['relativeTo'] ?? 'start') !== 'start' || !is_string($trigger['offset'] ?? null) || !preg_match('/^-PT(?:(\d{1,3})H)?(?:(\d{1,3})M)?(?:(\d{1,3})S)?$/D', $trigger['offset'], $offset) || $trigger['offset'] === '-PT') throw new EwsCalendarSafetyException('Unsupported calendar alert trigger');
            $seconds = (int)($offset[1] ?? 0) * 3600 + (int)($offset[2] ?? 0) * 60 + (int)($offset[3] ?? 0);
            if ($seconds % 60 !== 0) throw new EwsCalendarSafetyException('Sub-minute calendar alert is not supported');
            $xml .= '<t:ReminderIsSet>true</t:ReminderIsSet><t:ReminderMinutesBeforeStart>' . intdiv($seconds, 60) . '</t:ReminderMinutesBeforeStart>';
        }
        if (is_string($event['uid'] ?? null) && $event['uid'] !== '') $xml .= '<t:UID>' . EwsSoap::escapeXml($event['uid']) . '</t:UID>';
        $xml .= '<t:Start>' . gmdate('Y-m-d\TH:i:s\Z', $startTs) . '</t:Start>';
        $xml .= '<t:End>' . gmdate('Y-m-d\TH:i:s\Z', $endTs) . '</t:End>';
        $xml .= '<t:IsAllDayEvent>' . ($allDay ? 'true' : 'false') . '</t:IsAllDayEvent>';
        $xml .= '<t:LegacyFreeBusyStatus>' . match($event['freeBusyStatus'] ?? 'busy') {
            'free' => 'Free', 'tentative' => 'Tentative', 'unavailable' => 'OutOfOffice', default => 'Busy',
        } . '</t:LegacyFreeBusyStatus>';

        $locs = $event['locations'] ?? [];
        if ($locs instanceof stdClass) $locs = get_object_vars($locs);
        if (!is_array($locs)) throw new EwsCalendarSafetyException('Invalid calendar locations');
        $mainLocationId = $event['mainLocationId'] ?? null;
        if ($mainLocationId !== null) {
            if (!is_string($mainLocationId) || !isset($locs[$mainLocationId])) throw new EwsCalendarSafetyException('Invalid main calendar location');
            $location = $locs[$mainLocationId];
        } elseif (count($locs) > 1) {
            throw new EwsCalendarSafetyException('Multiple calendar locations without a main location');
        } else {
            $location = $locs ? reset($locs) : null;
        }
        if ($location instanceof stdClass) $location = get_object_vars($location);
        if ($location !== null && (!is_array($location) || (isset($location['name']) && !is_string($location['name'])))) throw new EwsCalendarSafetyException('Invalid calendar location');
        if ($mainLocationId !== null && (!isset($location['name']) || $location['name'] === '')) throw new EwsCalendarSafetyException('Main calendar location has no name');
        if (isset($location['name']) && $location['name'] !== '') $xml .= '<t:Location>' . EwsSoap::escapeXml($location['name']) . '</t:Location>';

        $xml .= '<t:CalendarItemType>' . ($recurrence !== '' ? 'RecurringMaster' : 'Single') . '</t:CalendarItemType>';
        $xml .= self::calendarParticipantsXml($event);
        $xml .= $recurrence;
        $xml .= '<t:StartTimeZone Id="' . $zoneId . '"/><t:EndTimeZone Id="' . $zoneId . '"/>';

        $xml .= '</t:CalendarItem>';
        return $xml;
    }

    /**
     * Convert a server-expanded instance, not an occurrence-index lookup.
     * Caller must authenticate/authorize the account and fetch the complete
     * master and expanded instance from a consistent JMAP state. The ID codec
     * is not an authorization mechanism and must be loaded by the caller.
     */
    public static function calendarOccurrenceToXml(DOMDocument $dom, array $event, array $master, string $accountId, string $occurrenceId, ?string $folderId): string {
        if (!class_exists('EwsCalendarOccurrenceId')) throw new EwsCalendarSafetyException('Occurrence ID codec is not loaded');
        try { $identity = EwsCalendarOccurrenceId::decodeForAccount($occurrenceId, $accountId); }
        catch (InvalidArgumentException $e) { throw new EwsCalendarSafetyException('Invalid occurrence identity', 0, $e); }
        $base = $master['id'] ?? null;
        $recurrenceId = $event['recurrenceId'] ?? null;
        if (!is_string($base) || $base === '' || ($event['baseEventId'] ?? null) !== $base || $identity['baseEventId'] !== $base || !is_string($recurrenceId) || $identity['recurrenceId'] !== $recurrenceId) throw new EwsCalendarSafetyException('Occurrence identity does not match master');
        if (!is_string($event['id'] ?? null) || $event['id'] === '' || $event['id'] === $base) throw new EwsCalendarSafetyException('Missing synthetic occurrence ID');
        if (!is_string($master['uid'] ?? null) || $master['uid'] === '' || ($event['uid'] ?? null) !== $master['uid']) throw new EwsCalendarSafetyException('Occurrence UID does not match master');
        self::utcTime($recurrenceId, false);
        if (isset($event['recurrenceIdTimeZone']) && $event['recurrenceIdTimeZone'] !== ($master['timeZone'] ?? null)) throw new EwsCalendarSafetyException('Occurrence recurrence time zone does not match master');
        if (array_key_exists('recurrenceRule', $event) && $event['recurrenceRule'] !== null) throw new EwsCalendarSafetyException('Expanded instance contains a recurrence rule');
        foreach (['recurrenceRules', 'excludedRecurrenceRules', 'recurrenceOverrides'] as $key) {
            $value = $event[$key] ?? null;
            if ($value !== null && $value !== [] && !($value instanceof stdClass && get_object_vars($value) === [])) throw new EwsCalendarSafetyException('Expanded instance contains recurrence metadata');
        }
        $overrides = $master['recurrenceOverrides'] ?? [];
        if ($overrides instanceof stdClass) $overrides = get_object_vars($overrides);
        if (!is_array($overrides) || ($overrides && array_is_list($overrides))) throw new EwsCalendarSafetyException('Invalid recurrence overrides');
        $masterForRule = $master;
        unset($masterForRule['recurrenceOverrides']);
        [$masterStart, $masterEnd, , $masterAllDay] = self::calendarReadTimes($masterForRule);
        if (self::calendarRecurrenceXml($masterForRule, $masterStart, $masterEnd, $masterAllDay) === '') throw new EwsCalendarSafetyException('Occurrence master has no recurrence rule');
        self::calendarCheckRuleMember($masterForRule, $recurrenceId);
        $expectedStart = $recurrenceId;
        $isException = false;
        foreach ($overrides as $key => $patch) {
            if (!is_string($key)) throw new EwsCalendarSafetyException('Invalid override recurrence ID');
            self::utcTime($key, false);
            if ($patch instanceof stdClass) $patch = get_object_vars($patch);
            if (!is_array($patch) || ($patch && array_is_list($patch))) throw new EwsCalendarSafetyException('Invalid recurrence override');
            if ($patch === ['excluded' => true]) {
                if ($key === $recurrenceId) throw new EwsCalendarSafetyException('Excluded occurrence');
                continue;
            }
            if ($patch === []) continue;
            if (array_keys($patch) !== ['start'] || !is_string($patch['start'])) throw new EwsCalendarSafetyException('Unsupported recurrence override');
            self::utcTime($patch['start'], false);
            if ($key === $recurrenceId) {
                $expectedStart = $patch['start'];
                $isException = true;
            }
        }
        if (($event['start'] ?? null) !== $expectedStart) throw new EwsCalendarSafetyException('Occurrence start does not match recurrence override');
        // Compare all inherited properties rendered by calendarToXml. A partial
        // or stale get must not silently replace master privacy or scheduling.
        foreach (['uid', 'title', 'timeZone', 'endTimeZone', 'duration', 'showWithoutTime', 'status', 'privacy', 'freeBusyStatus', 'description', 'descriptionContentType', 'categories', 'useDefaultAlerts', 'alerts', 'locations', 'mainLocationId', 'participants', 'organizerCalendarAddress', 'calendarIds'] as $key) {
            if (self::itemChangeKey(['v' => $event[$key] ?? null]) !== self::itemChangeKey(['v' => $master[$key] ?? null])) throw new EwsCalendarSafetyException('Occurrence differs from master: ' . $key);
        }
        $copy = $event;
        unset($copy['baseEventId'], $copy['recurrenceId'], $copy['recurrenceIdTimeZone'], $copy['recurrenceRule'], $copy['recurrenceRules'], $copy['excludedRecurrenceRules'], $copy['recurrenceOverrides']);
        [$startTs, $endTs] = self::calendarReadTimes($copy);
        foreach (['utcStart' => $startTs, 'utcEnd' => $endTs] as $key => $expected) {
            if (isset($event[$key]) && (!is_string($event[$key]) || self::utcTime($event[$key], true) !== $expected)) throw new EwsCalendarSafetyException('Occurrence UTC projection mismatch: ' . $key);
        }
        $xml = self::calendarToXml($dom, $copy, $folderId);
        $xml = str_replace(EwsSoap::buildId($dom, self::PFX_CALENDAR . $copy['id'], self::itemChangeKey($copy)), EwsSoap::buildId($dom, $occurrenceId, self::itemChangeKey($master)), $xml, $idCount);
        $xml = str_replace('<t:CalendarItemType>Single</t:CalendarItemType>', '<t:CalendarItemType>' . ($isException ? 'Exception' : 'Occurrence') . '</t:CalendarItemType>', $xml, $typeCount);
        if ($idCount !== 1 || $typeCount !== 1) throw new EwsCalendarSafetyException('Invalid occurrence XML');
        return $xml;
    }

    /** Membership in the restricted daily/weekly rule subset accepted above. */
    private static function calendarCheckRuleMember(array $master, string $recurrenceId): void {
        $start = $master['start'];
        $startTs = self::utcTime($start, false);
        $instanceTs = self::utcTime($recurrenceId, false);
        if ($instanceTs < $startTs || substr($start, 11) !== substr($recurrenceId, 11)) throw new EwsCalendarSafetyException('Recurrence ID is not a rule occurrence');
        $dayDiff = intdiv($instanceTs - $startTs, 86400);
        $rule = $master['recurrenceRule'];
        if ($rule instanceof stdClass) $rule = get_object_vars($rule);
        $interval = $rule['interval'] ?? 1;
        if ($rule['frequency'] === 'daily') {
            if ($dayDiff % $interval !== 0) throw new EwsCalendarSafetyException('Recurrence ID is not a rule occurrence');
            $ordinal = intdiv($dayDiff, $interval) + 1;
        } else {
            $names = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];
            $first = array_search($rule['firstDayOfWeek'] ?? 'mo', $names, true);
            $startDay = (int)gmdate('N', $startTs) - 1;
            $startPos = ($startDay - $first + 7) % 7;
            $byDay = $rule['byDay'] ?? [['day' => $names[$startDay]]];
            $positions = [];
            foreach ($byDay as $day) {
                if ($day instanceof stdClass) $day = get_object_vars($day);
                $positions[] = (array_search($day['day'], $names, true) - $first + 7) % 7;
            }
            $week = intdiv($startPos + $dayDiff, 7);
            $pos = ($startPos + $dayDiff) % 7;
            if ($week % $interval !== 0 || !in_array($pos, $positions, true)) throw new EwsCalendarSafetyException('Recurrence ID is not a rule occurrence');
            if ($week === 0) {
                $ordinal = count(array_filter($positions, fn($p) => $p >= $startPos && $p <= $pos));
            } else {
                $ordinal = count(array_filter($positions, fn($p) => $p >= $startPos)) + (intdiv($week, $interval) - 1) * count($positions) + count(array_filter($positions, fn($p) => $p <= $pos));
            }
        }
        if ((isset($rule['count']) && $ordinal > $rule['count']) || (isset($rule['until']) && $recurrenceId > $rule['until'])) throw new EwsCalendarSafetyException('Recurrence ID is outside rule range');
    }

    /** JSCalendar 2.0 organizer identity is an address, not a participant role. */
    private static function calendarParticipantsXml(array $event): string {
        $participants = $event['participants'] ?? [];
        if ($participants instanceof stdClass) $participants = get_object_vars($participants);
        if (!is_array($participants)) throw new EwsCalendarSafetyException('Invalid calendar participants');
        $organizerAddress = $event['organizerCalendarAddress'] ?? null;
        if ($organizerAddress !== null) self::calendarMailboxAddress($organizerAddress);
        if ($participants && $organizerAddress === null) throw new EwsCalendarSafetyException('Calendar organizer address is missing');
        $organizer = '';
        $attendees = ['RequiredAttendees' => '', 'OptionalAttendees' => ''];
        $addresses = [];
        foreach ($participants as $participant) {
            if ($participant instanceof stdClass) $participant = get_object_vars($participant);
            if (!is_array($participant) || array_diff(array_keys($participant), ['@type', 'name', 'email', 'calendarAddress', 'kind', 'roles', 'participationStatus'])) throw new EwsCalendarSafetyException('Unsupported calendar participant');
            if (array_key_exists('@type', $participant) && $participant['@type'] !== 'Participant') throw new EwsCalendarSafetyException('Invalid calendar participant type');
            $address = $participant['calendarAddress'] ?? null;
            $email = self::calendarMailboxAddress($address);
            if (isset($addresses[$address])) throw new EwsCalendarSafetyException('Duplicate calendar participant address');
            $addresses[$address] = true;
            if (array_key_exists('email', $participant) && $participant['email'] !== $email) throw new EwsCalendarSafetyException('Distinct participant contact email is not supported');
            if (array_key_exists('kind', $participant) && $participant['kind'] !== 'individual') throw new EwsCalendarSafetyException('Unsupported participant kind');
            $name = $participant['name'] ?? '';
            if (!is_string($name)) throw new EwsCalendarSafetyException('Invalid participant name');
            $roles = $participant['roles'] ?? [];
            if ($roles instanceof stdClass) $roles = get_object_vars($roles);
            if (!is_array($roles)) throw new EwsCalendarSafetyException('Invalid participant roles');
            foreach ($roles as $role => $enabled) {
                if (!in_array($role, ['owner', 'required', 'optional'], true) || $enabled !== true) throw new EwsCalendarSafetyException('Unsupported participant role');
            }
            if (isset($roles['required'], $roles['optional'])) throw new EwsCalendarSafetyException('Multiple participant attendance roles are not supported');
            $isOrganizer = $organizerAddress !== null && $address === $organizerAddress;
            if (!$isOrganizer && isset($roles['owner'])) throw new EwsCalendarSafetyException('Non-organizer owner is not supported');
            $mailbox = EwsSoap::buildMailbox($name, $email);
            if ($isOrganizer) {
                if ($organizer !== '') throw new EwsCalendarSafetyException('Multiple calendar organizers are not supported');
                $organizer = '<t:Organizer>' . $mailbox . '</t:Organizer>';
            }
            // Organizer-only entries have no attendee-specific role or status.
            if ($isOrganizer && !isset($roles['required']) && !isset($roles['optional']) && !array_key_exists('participationStatus', $participant)) continue;
            $status = array_key_exists('participationStatus', $participant) ? $participant['participationStatus'] : 'needs-action';
            $response = match($status) {
                'accepted' => 'Accept', 'tentative' => 'Tentative', 'declined' => 'Decline', 'needs-action' => 'NoResponseReceived',
                default => throw new EwsCalendarSafetyException('Unsupported participant status'),
            };
            $tag = isset($roles['optional']) ? 'OptionalAttendees' : 'RequiredAttendees';
            $attendees[$tag] .= '<t:Attendee>' . $mailbox . '<t:ResponseType>' . $response . '</t:ResponseType></t:Attendee>';
        }
        if ($organizerAddress !== null && $organizer === '') throw new EwsCalendarSafetyException('Calendar organizer does not match a participant');
        $xml = $organizer;
        foreach ($attendees as $tag => $items) if ($items !== '') $xml .= '<t:' . $tag . '>' . $items . '</t:' . $tag . '>';
        return $xml;
    }

    /** Restrict CAL-ADDRESS to a single unambiguous mailto mailbox. */
    private static function calendarMailboxAddress($address): string {
        if (!is_string($address) || !preg_match('/^mailto:([^?&#%,]+)$/iD', $address, $match) || filter_var($match[1], FILTER_VALIDATE_EMAIL) === false) throw new EwsCalendarSafetyException('Unsupported participant calendar address');
        return $match[1];
    }

    /** Read-only conversion; safeCalendarTimes remains the stricter write guard. */
    private static function calendarReadTimes(array $event): array {
        foreach (['recurrenceRules', 'recurrenceOverrides', 'excludedRecurrenceRules', 'timeZones'] as $key) {
            $value = $event[$key] ?? null;
            if ($value !== null && $value !== [] && !($value instanceof stdClass && get_object_vars($value) === [])) throw new EwsCalendarSafetyException('Unsupported calendar ' . $key);
        }
        foreach (['recurrenceId', 'recurrenceIdTimeZone', 'baseEventId'] as $key) if (array_key_exists($key, $event) && $event[$key] !== null) throw new EwsCalendarSafetyException('Calendar occurrence is not supported');
        if (!empty($event['relatedTo'])) throw new EwsCalendarSafetyException('Calendar relationship is not supported');
        // CLDR windowsZones: NL/Europe/Amsterdam, ZZ/Etc/UTC.
        $zones = ['UTC' => 'UTC', 'Etc/UTC' => 'UTC', 'Europe/Amsterdam' => 'W. Europe Standard Time'];
        $name = $event['timeZone'] ?? null;
        if (!is_string($name) || !isset($zones[$name])) throw new EwsCalendarSafetyException('Floating or unmapped calendar time zone');
        if (isset($event['endTimeZone']) && $event['endTimeZone'] !== $name) throw new EwsCalendarSafetyException('Different end time zone is not supported');
        $zone = new DateTimeZone($name);
        $start = $event['start'] ?? null;
        if (!is_string($start)) throw new EwsCalendarSafetyException('Invalid calendar start');
        $wall = self::utcTime($start, false);
        if ($name === 'Europe/Amsterdam' && substr($start, 0, 4) < '1970') throw new EwsCalendarSafetyException('Historical Amsterdam time zone mapping is not supported');
        $startTs = self::calendarWallToUtc($wall, $zone);
        $allDay = $event['showWithoutTime'] ?? false;
        if (!is_bool($allDay)) throw new EwsCalendarSafetyException('Invalid all-day flag');
        $duration = $event['duration'] ?? null;
        if (!is_string($duration) || !preg_match('/^P(?:(\d{1,3})W)?(?:(\d{1,3})D)?(?:T(?:(\d{1,3})H)?(?:(\d{1,3})M)?(?:(\d{1,3})S)?)?$/D', $duration, $m) || $duration === 'P' || str_ends_with($duration, 'T')) throw new EwsCalendarSafetyException('Unsupported calendar duration');
        $days = (int)($m[1] ?? 0) * 7 + (int)($m[2] ?? 0);
        $seconds = (int)($m[3] ?? 0) * 3600 + (int)($m[4] ?? 0) * 60 + (int)($m[5] ?? 0);
        if (($days === 0 && $seconds === 0) || $days * 86400 + $seconds > 366 * 86400) throw new EwsCalendarSafetyException('Unsupported calendar duration');
        if ($allDay && (substr($start, 11) !== '00:00:00' || $days === 0 || $seconds !== 0)) throw new EwsCalendarSafetyException('Unsupported all-day interval');
        $endWall = $wall + $days * 86400;
        if (gmdate('Y', $endWall) > '9999') throw new EwsCalendarSafetyException('Calendar end out of range');
        $endTs = self::calendarWallToUtc($endWall, $zone) + $seconds;
        if ($endTs <= $startTs || gmdate('Y', $endTs) > '9999') throw new EwsCalendarSafetyException('Invalid calendar interval');
        return [$startTs, $endTs, $zones[$name], $allDay];
    }

    /** RFC 8984: use the offset before a gap or overlap. */
    private static function calendarWallToUtc(int $wall, DateTimeZone $zone): int {
        $transitions = $zone->getTransitions($wall - 3 * 86400, $wall + 3 * 86400);
        if (!$transitions) throw new EwsCalendarSafetyException('Unsupported time zone rules');
        $previous = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            $next = $transition['offset'];
            $a = $transition['ts'] + $previous;
            $b = $transition['ts'] + $next;
            if ($wall >= min($a, $b) && $wall < max($a, $b)) return $wall - $previous;
            $previous = $next;
        }
        foreach ($transitions as $transition) {
            $candidate = $wall - $transition['offset'];
            if ($zone->getOffset((new DateTimeImmutable('@' . $candidate))) === $transition['offset']) return $candidate;
        }
        throw new EwsCalendarSafetyException('Cannot resolve calendar time zone');
    }

    private static function calendarRecurrenceXml(array $event, int $startTs, int $endTs, bool $allDay): string {
        $rule = $event['recurrenceRule'] ?? null;
        if ($rule === null) return '';
        if ($rule instanceof stdClass) $rule = get_object_vars($rule);
        if (!is_array($rule) || array_is_list($rule)) throw new EwsCalendarSafetyException('Invalid recurrence rule');
        foreach ($rule as $key => $_) if (!in_array($key, ['@type', 'frequency', 'interval', 'byDay', 'firstDayOfWeek', 'count', 'until'], true)) throw new EwsCalendarSafetyException('Unsupported recurrence rule property: ' . $key);
        if (array_key_exists('@type', $rule) && $rule['@type'] !== 'RecurrenceRule') throw new EwsCalendarSafetyException('Invalid recurrence rule type');
        $interval = array_key_exists('interval', $rule) ? $rule['interval'] : 1;
        if (!is_int($interval) || $interval < 1 || $interval > 99) throw new EwsCalendarSafetyException('Invalid recurrence interval');
        $start = $event['start'];
        $date = substr($start, 0, 10);
        $days = ['mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday'];
        $weekStart = array_key_exists('firstDayOfWeek', $rule) ? $rule['firstDayOfWeek'] : 'mo';
        if (!is_string($weekStart) || !isset($days[$weekStart])) throw new EwsCalendarSafetyException('Invalid first day of week');
        if (($event['timeZone'] ?? '') === 'Europe/Amsterdam' && !$allDay) {
            $startClock = substr($start, 11);
            $endLocal = (new DateTimeImmutable('@' . $endTs))->setTimezone(new DateTimeZone('Europe/Amsterdam'));
            // Avoid series whose elapsed duration or local time can change on DST transition days.
            if ($startClock < '03:00:00' || $endLocal->format('Y-m-d') !== $date || $endLocal->format('H:i:s') <= $startClock || preg_match('/^P(?:\d+W|\d+D)/', $event['duration'])) throw new EwsCalendarSafetyException('Recurring interval can cross a time zone transition');
        }
        switch ($rule['frequency'] ?? null) {
            case 'daily':
                if (array_key_exists('byDay', $rule)) throw new EwsCalendarSafetyException('Unsupported daily byDay');
                $pattern = '<t:DailyRecurrence><t:Interval>' . $interval . '</t:Interval></t:DailyRecurrence>';
                break;
            case 'weekly':
                $byDay = array_key_exists('byDay', $rule) ? $rule['byDay'] : [['day' => strtolower(substr(gmdate('D', self::utcTime($start, false)), 0, 2))]];
                if (!is_array($byDay) || !array_is_list($byDay) || !$byDay) throw new EwsCalendarSafetyException('Invalid weekly byDay');
                $selected = [];
                foreach ($byDay as $day) {
                    if ($day instanceof stdClass) $day = get_object_vars($day);
                    if (!is_array($day) || array_diff(array_keys($day), ['@type', 'day']) || (array_key_exists('@type', $day) && $day['@type'] !== 'NDay') || !is_string($day['day'] ?? null) || !isset($days[$day['day']]) || isset($selected[$day['day']])) throw new EwsCalendarSafetyException('Unsupported weekly byDay');
                    $selected[$day['day']] = $days[$day['day']];
                }
                $startDay = strtolower(substr(gmdate('D', self::utcTime($start, false)), 0, 2));
                if (!isset($selected[$startDay])) throw new EwsCalendarSafetyException('Series start is not a weekly occurrence');
                $pattern = '<t:WeeklyRecurrence><t:Interval>' . $interval . '</t:Interval><t:DaysOfWeek>' . implode(' ', $selected) . '</t:DaysOfWeek><t:FirstDayOfWeek>' . $days[$weekStart] . '</t:FirstDayOfWeek></t:WeeklyRecurrence>';
                break;
            default: throw new EwsCalendarSafetyException('Unsupported recurrence frequency');
        }
        if (array_key_exists('count', $rule) && array_key_exists('until', $rule)) throw new EwsCalendarSafetyException('Recurrence count and until cannot coexist');
        if (array_key_exists('count', $rule)) {
            if (!is_int($rule['count']) || $rule['count'] < 1 || $rule['count'] > 999) throw new EwsCalendarSafetyException('Invalid recurrence count');
            $range = '<t:NumberedRecurrence><t:StartDate>' . $date . '</t:StartDate><t:NumberOfOccurrences>' . $rule['count'] . '</t:NumberOfOccurrences></t:NumberedRecurrence>';
        } elseif (array_key_exists('until', $rule)) {
            if (!is_string($rule['until'])) throw new EwsCalendarSafetyException('Invalid recurrence until');
            $until = self::utcTime($rule['until'], false);
            if ($until < self::utcTime($start, false)) throw new EwsCalendarSafetyException('Recurrence ends before start');
            $endDate = substr($rule['until'], 0, 10);
            if (substr($rule['until'], 11) < substr($start, 11)) $endDate = gmdate('Y-m-d', $until - 86400);
            $range = '<t:EndDateRecurrence><t:StartDate>' . $date . '</t:StartDate><t:EndDate>' . $endDate . '</t:EndDate></t:EndDateRecurrence>';
        } else {
            $range = '<t:NoEndRecurrence><t:StartDate>' . $date . '</t:StartDate></t:NoEndRecurrence>';
        }
        return '<t:Recurrence>' . $pattern . $range . '</t:Recurrence>';
    }

    /** Only represent single, second-precision UTC events with exact elapsed durations. */
    public static function safeCalendarTimes(array $event): array {
        // JSCalendar 2.0 uses a single recurrenceRule object. Any non-null
        // value, including a malformed empty object, is unsafe to represent.
        if (array_key_exists('recurrenceRule', $event) && $event['recurrenceRule'] !== null) {
            throw new EwsCalendarSafetyException('Recurring calendar events are not supported');
        }
        foreach (['recurrenceRules', 'recurrenceOverrides', 'excludedRecurrenceRules'] as $key) {
            $value = $event[$key] ?? null;
            if ($value !== null && $value !== [] && !($value instanceof stdClass && get_object_vars($value) === [])) {
                throw new EwsCalendarSafetyException('Recurring calendar events are not supported');
            }
        }
        foreach (['recurrenceId', 'recurrenceIdTimeZone', 'relatedTo'] as $key) {
            if (!empty($event[$key])) throw new EwsCalendarSafetyException('Calendar occurrence or relationship is not supported');
        }
        if (($event['timeZone'] ?? null) !== 'UTC' ||
            (isset($event['endTimeZone']) && $event['endTimeZone'] !== 'UTC') ||
            !in_array($event['showWithoutTime'] ?? false, [false, null], true)) {
            throw new EwsCalendarSafetyException('Only timed UTC calendar events are supported');
        }
        $start = $event['start'] ?? null;
        if (!is_string($start)) throw new EwsCalendarSafetyException('Invalid calendar start');
        $ts = self::utcTime($start, false);
        $duration = $event['duration'] ?? null;
        if (!is_string($duration) || !preg_match('/^P(?:(\d{1,3})D)?(?:T(?:(\d{1,3})H)?(?:(\d{1,3})M)?(?:(\d{1,3})S)?)?$/D', $duration, $m) || $duration === 'P' || str_ends_with($duration, 'T')) {
            throw new EwsCalendarSafetyException('Unsupported calendar duration');
        }
        $seconds = (int)($m[1] ?? 0) * 86400 + (int)($m[2] ?? 0) * 3600 + (int)($m[3] ?? 0) * 60 + (int)($m[4] ?? 0);
        if ($seconds <= 0 || $seconds > 366 * 86400 || gmdate('Y', $ts + $seconds) > '9999') throw new EwsCalendarSafetyException('Unsupported calendar duration');
        return [$ts, $ts + $seconds];
    }

    public static function utcTime(string $value, bool $withZ): int {
        $format = $withZ ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i:s';
        $pattern = $withZ ? '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D';
        $date = preg_match($pattern, $value) ? DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC')) : false;
        if (!$date || $date->format($withZ ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s') !== $value || substr($value, 0, 4) === '0000') {
            throw new EwsCalendarSafetyException('Only valid second-precision UTC times are supported');
        }
        return $date->getTimestamp();
    }

    public static function validateEwsEvent(DOMElement $event): void {
        $seen = [];
        foreach ($event->attributes as $attribute) {
            if ($attribute->namespaceURI !== 'http://www.w3.org/2000/xmlns/') throw new EwsCalendarSafetyException('Unsupported calendar item attribute');
        }
        foreach ($event->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($child->namespaceURI !== EwsSoap::NS_T || !in_array($child->localName, ['Subject', 'Start', 'End', 'IsAllDayEvent', 'Location', 'Body'], true) || isset($seen[$child->localName])) {
                throw new EwsCalendarSafetyException('Unsupported or duplicate calendar create field');
            }
            if ($child->firstElementChild || ($child->localName === 'Body' && $child->getAttribute('BodyType') !== 'Text')) throw new EwsCalendarSafetyException('Unsupported calendar field value');
            foreach ($child->attributes as $attribute) {
                if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
                if ($child->localName !== 'Body' || $attribute->namespaceURI || $attribute->localName !== 'BodyType') throw new EwsCalendarSafetyException('Unsupported calendar field attribute');
            }
            $seen[$child->localName] = trim($child->textContent);
        }
        if (isset($seen['IsAllDayEvent']) && !in_array($seen['IsAllDayEvent'], ['false', '0'], true)) throw new EwsCalendarSafetyException('All-day calendar events are not supported');
        $start = self::utcTime($seen['Start'] ?? '', true);
        $end = self::utcTime($seen['End'] ?? '', true);
        if ($end <= $start || $end - $start > 366 * 86400) throw new EwsCalendarSafetyException('Invalid calendar interval');
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
        self::validateEwsEvent($eventEl);
        $nsT = EwsSoap::NS_T;

        $startStr  = self::getXmlChildValue($eventEl, 't:Start');
        $endStr    = self::getXmlChildValue($eventEl, 't:End');
        $allDay    = strtolower(self::getXmlChildValue($eventEl, 't:IsAllDayEvent')) === 'true';
        $subject   = self::getXmlChildValue($eventEl, 't:Subject');
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
            'timeZone'    => 'UTC',
        ];

        if ($allDay) {
            $event['showWithoutTime'] = true;
            $event['start'] = gmdate('Y-m-d', $startTs);
            $event['duration'] = 'P' . max(1, intdiv($duration, 86400)) . 'D';
        } else {
            $event['start'] = gmdate('Y-m-d\TH:i:s', $startTs);
            $event['duration'] = $duration > 0 ? JmapCalendarConverter::secondsToDuration($duration) : 'PT1H';
        }

        if ($location !== '') {
            $event['locations'] = ['l1' => ['@type' => 'Location', 'name' => $location]];
        }

        if ($body !== '') {
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
