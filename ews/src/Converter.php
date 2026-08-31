<?php

class EwsConverter {

    const PFX_CONTACTS = 'ab_';
    const PFX_CALENDAR = 'cal_';

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

    public static function emailToXml(DOMDocument $dom, array $email, string $folderId, array $bodyValues = []): string {
        $id        = $email['id'] ?? '';
        $changeKey = self::modHash($email);
        $kw        = $email['keywords'] ?? [];

        $xml = '<t:Message>';
        $xml .= EwsSoap::buildId($dom, $id, $changeKey);
        $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';

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

        if (!empty($email['conversationId'])) {
            $xml .= '<t:ConversationIndex>' . base64_encode($email['conversationId']) . '</t:ConversationIndex>';
        }
        if (!empty($email['references'])) {
            $xml .= '<t:InternetMessageId>' . EwsSoap::escapeXml($email['references']) . '</t:InternetMessageId>';
        }

        $xml .= '<t:Size>' . ($email['size'] ?? 0) . '</t:Size>';
        $xml .= '<t:Importance>' . (match($email['importance'] ?? 'normal') { 'high' => 'High', 'low' => 'Low', default => 'Normal' }) . '</t:Importance>';

        $xml .= self::bodyToXml($email, $bodyValues);

        if (!empty($email['attachments'])) {
            foreach ($email['attachments'] as $att) {
                $xml .= self::attachmentToXml($dom, $att, $id);
            }
        }

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

    public static function contactToXml(DOMDocument $dom, array $card, string $folderId, array $bodyValues = []): string {
        $id        = $card['id'] ?? '';
        $changeKey = JmapContactConverter::cardModHash($card);
        $name      = $card['name'] ?? [];

        $xml = '<t:Contact>';
        $xml .= EwsSoap::buildId($dom, $id, $changeKey);
        $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';

        $given       = $name['given']      ?? '';
        $surname     = $name['surname']    ?? '';
        $full        = $name['full']       ?? '';
        $displayName = $full ?: trim($given . ' ' . $surname) ?: '';

        $xml .= '<t:DisplayName>' . EwsSoap::escapeXml($displayName) . '</t:DisplayName>';
        $xml .= '<t:GivenName>' . EwsSoap::escapeXml($given) . '</t:GivenName>';
        $xml .= '<t:Surname>' . EwsSoap::escapeXml($surname) . '</t:Surname>';
        if (!empty($name['additional'])) {
            $xml .= '<t:MiddleName>' . EwsSoap::escapeXml($name['additional']) . '</t:MiddleName>';
        }
        if (!empty($name['prefix'])) {
            $xml .= '<t:Title>' . EwsSoap::escapeXml($name['prefix']) . '</t:Title>';
        }
        if (!empty($name['suffix'])) {
            $xml .= '<t:Suffix>' . EwsSoap::escapeXml($name['suffix']) . '</t:Suffix>';
        }
        $xml .= '<t:FileAs>' . EwsSoap::escapeXml($full ?: $displayName) . '</t:FileAs>';

        if (!empty($card['nicknames'])) {
            $nicks = array_values($card['nicknames']);
            $xml .= '<t:Nickname>' . EwsSoap::escapeXml($nicks[0]['name'] ?? '') . '</t:Nickname>';
        }

        $emails = array_values($card['emails'] ?? []);
        foreach ($emails as $i => $e) {
            $key = match($i) { 0 => 'Email1', 1 => 'Email2', 2 => 'Email3', default => null };
            if ($key) {
                $xml .= '<t:' . $key . '><t:Entry Key="'. $key . '">' . EwsSoap::escapeXml($e['address'] ?? '') . '</t:Entry></t:' . $key . '>';
                $xml .= '<t:' . $key . 'Address>' . EwsSoap::escapeXml($e['address'] ?? '') . '</t:' . $key . 'Address>';
            }
        }

        foreach (array_values($card['phones'] ?? []) as $p) {
            $num    = $p['number'] ?? '';
            $ctx    = $p['contexts'] ?? [];
            $feat   = $p['features'] ?? [];
            $phoneKey = 'PhoneNumber';
            if (isset($feat['cell']) || isset($feat['mobile'])) {
                $phoneKey = 'MobilePhone';
            } elseif (isset($feat['fax'])) {
                $phoneKey = isset($ctx['work']) ? 'BusinessFax' : 'HomeFax';
            } elseif (isset($ctx['work'])) {
                $phoneKey = 'BusinessPhone';
            } elseif (isset($ctx['home'])) {
                $phoneKey = 'HomePhone';
            }
            $xml .= '<t:' . $phoneKey . '>' . EwsSoap::escapeXml($num) . '</t:' . $phoneKey . '>';
        }

        foreach (array_values($card['addresses'] ?? []) as $addr) {
            $ctx     = $addr['contexts'] ?? [];
            $parsed  = self::parseAddressComponents($addr['components'] ?? []);
            $addrKey = isset($ctx['work']) ? 'Business' : (isset($ctx['home']) ? 'Home' : 'Other');
            $xml .= '<t:' . $addrKey . 'Street>' . EwsSoap::escapeXml($parsed['street']) . '</t:' . $addrKey . 'Street>';
            $xml .= '<t:' . $addrKey . 'City>' . EwsSoap::escapeXml($parsed['city']) . '</t:' . $addrKey . 'City>';
            $xml .= '<t:' . $addrKey . 'State>' . EwsSoap::escapeXml($parsed['state']) . '</t:' . $addrKey . 'State>';
            $xml .= '<t:' . $addrKey . 'PostalCode>' . EwsSoap::escapeXml($parsed['postal']) . '</t:' . $addrKey . 'PostalCode>';
            $xml .= '<t:' . $addrKey . 'Country>' . EwsSoap::escapeXml($parsed['country']) . '</t:' . $addrKey . 'Country>';
        }

        $orgs = array_values($card['organizations'] ?? []);
        if ($orgs) {
            $xml .= '<t:CompanyName>' . EwsSoap::escapeXml($orgs[0]['name'] ?? '') . '</t:CompanyName>';
        }
        $titles = array_values($card['jobTitles'] ?? []);
        if ($titles) {
            $xml .= '<t:JobTitle>' . EwsSoap::escapeXml($titles[0]['name'] ?? '') . '</t:JobTitle>';
        }
        $depts = array_values($card['departments'] ?? []);
        if ($depts) {
            $xml .= '<t:Department>' . EwsSoap::escapeXml($depts[0]['name'] ?? '') . '</t:Department>';
        }

        $ims = array_values($card['onlineServices'] ?? []);
        if ($ims) {
            $xml .= '<t:IMAddress>' . EwsSoap::escapeXml($ims[0]['uri'] ?? $ims[0]['service'] ?? '') . '</t:IMAddress>';
        }

        $links = array_values($card['links'] ?? []);
        if ($links) {
            $xml .= '<t:BusinessHomePage>' . EwsSoap::escapeXml($links[0]['uri'] ?? '') . '</t:BusinessHomePage>';
        }

        foreach ($card['anniversaries'] ?? [] as $ann) {
            $date = $ann['date'] ?? [];
            $dateStr = '';
            if (is_array($date)) {
                $y = $date['year'] ?? 0;
                $m = $date['month'] ?? 1;
                $d = $date['day'] ?? 1;
                $dateStr = sprintf('%04d-%02d-%02dT00:00:00Z', $y, $m, $d);
            } elseif (is_string($date)) {
                $dateStr = $date;
            }
            if ($dateStr) {
                if (str_contains(strtolower($ann['type'] ?? 'birth'), 'birth')) {
                    $xml .= '<t:Birthday>' . $dateStr . '</t:Birthday>';
                } else {
                    $xml .= '<t:WeddingAnniversary>' . $dateStr . '</t:WeddingAnniversary>';
                }
            }
        }

        $notes = array_values($card['notes'] ?? []);
        if ($notes && !empty($notes[0]['note'])) {
            $xml .= '<t:Body BodyType="Text">' . EwsSoap::escapeXml($notes[0]['note']) . '</t:Body>';
        }

        $cats = array_keys($card['categories'] ?? []);
        if ($cats) {
            $xml .= '<t:Categories>';
            foreach ($cats as $cat) {
                $xml .= '<t:String>' . EwsSoap::escapeXml($cat) . '</t:String>';
            }
            $xml .= '</t:Categories>';
        }

        $photoUri = '';
        foreach (['media', 'photos'] as $key) {
            if (!empty($card[$key])) {
                $entry = reset($card[$key]);
                if (!empty($entry['uri'])) {
                    $photoUri = $entry['uri'];
                    break;
                }
            }
        }
        if ($photoUri) {
            $commaPos = strpos($photoUri, ',');
            if ($commaPos !== false) {
                $rawBase64 = substr($photoUri, $commaPos + 1);
                $xml .= '<t:ContactPhoto>' . EwsSoap::escapeXml($rawBase64) . '</t:ContactPhoto>';
            }
        }

        $xml .= '</t:Contact>';
        return $xml;
    }

    public static function calendarToXml(DOMDocument $dom, array $event, string $folderId): string {
        $id        = $event['id'] ?? '';
        $changeKey = JmapCalendarConverter::eventModHash($event);

        $startRaw  = $event['start'] ?? '';
        $startTs   = JmapCalendarConverter::jmapTimeToTimestamp($startRaw);
        $durSecs   = JmapCalendarConverter::parseDuration($event['duration'] ?? 'PT1H');
        $endTs     = $startTs + $durSecs;
        $allDay    = !empty($event['showWithoutTime']);

        $xml = '<t:CalendarItem>';
        $xml .= EwsSoap::buildId($dom, $id, $changeKey);
        $xml .= '<t:ParentFolderId Id="' . EwsSoap::escapeXml($folderId) . '"/>';

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

        $components = [];
        if ($given)   $components[] = ['kind' => 'given',    'value' => $given];
        if ($surname) $components[] = ['kind' => 'surname',  'value' => $surname];

        $middle = self::getXmlChildValue($contactEl, 't:MiddleName');
        if ($middle)  $components[] = ['kind' => 'additional', 'value' => $middle];

        $prefix = self::getXmlChildValue($contactEl, 't:Title');
        if ($prefix)  $components[] = ['kind' => 'prefix', 'value' => $prefix];

        $suffix = self::getXmlChildValue($contactEl, 't:Suffix');
        if ($suffix)  $components[] = ['kind' => 'suffix', 'value' => $suffix];

        if ($components) {
            $card['name'] = ['components' => $components, 'isOrdered' => true];
            if ($given)   $card['name']['given']    = $given;
            if ($surname) $card['name']['surname']  = $surname;
            $full = $given || $surname ? trim($given . ' ' . $surname) : null;
            if ($full)    $card['name']['full']     = $full;
        }

        if ($company)  $card['organizations'] = ['o1' => ['name' => $company]];
        if ($jobTitle) $card['jobTitles']     = ['jt1' => ['name' => $jobTitle]];

        $dept = self::getXmlChildValue($contactEl, 't:Department');
        if ($dept)     $card['departments']   = ['d1' => ['name' => $dept]];

        $emails = [];
        foreach (['Email1', 'Email2', 'Email3'] as $i => $key) {
            $val = self::getXmlChildValue($contactEl, "t:{$key}Address") ?: self::getXmlChildValue($contactEl, "t:{$key}");
            if ($val) {
                $ctx = match($i) {
                    0 => ['work' => true],
                    1 => ['home' => true],
                    default => [],
                };
                $emails['e' . ($i+1)] = ['address' => $val, 'contexts' => $ctx];
            }
        }
        if ($emails) $card['emails'] = $emails;

        $phones = [];
        $pi = 1;
        $phoneFields = [
            't:BusinessPhone'   => [['work' => true], []],
            't:HomePhone'       => [['home' => true], []],
            't:MobilePhone'     => [[], ['cell' => true]],
            't:BusinessFax'     => [['work' => true], ['fax' => true]],
            't:HomeFax'         => [['home' => true], ['fax' => true]],
            't:Pager'           => [[], ['pager' => true]],
        ];
        foreach ($phoneFields as $xmlField => [$ctx, $feat]) {
            $val = self::getXmlChildValue($contactEl, $xmlField);
            if ($val) {
                $entry = ['number' => $val];
                if ($ctx)  $entry['contexts'] = $ctx;
                if ($feat) $entry['features'] = $feat;
                $phones['p' . $pi++] = $entry;
            }
        }
        if ($phones) $card['phones'] = $phones;

        $addrs = [];
        foreach (['Business' => 'work', 'Home' => 'home', 'Other' => 'other'] as $prefix => $context) {
            $street = self::getXmlChildValue($contactEl, "t:{$prefix}Street");
            $city   = self::getXmlChildValue($contactEl, "t:{$prefix}City");
            $state  = self::getXmlChildValue($contactEl, "t:{$prefix}State");
            $postal = self::getXmlChildValue($contactEl, "t:{$prefix}PostalCode");
            $country= self::getXmlChildValue($contactEl, "t:{$prefix}Country");
            if ($street || $city) {
                $components = [];
                if ($street) $components[] = ['kind' => 'street', 'value' => $street];
                if ($city)   $components[] = ['kind' => 'locality', 'value' => $city];
                if ($state)  $components[] = ['kind' => 'region', 'value' => $state];
                if ($postal) $components[] = ['kind' => 'postcode', 'value' => $postal];
                if ($country)$components[] = ['kind' => 'country', 'value' => $country];
                $addr = ['@type' => 'Address', 'components' => $components, 'contexts' => [$context => true]];
                $addrs['a' . $pi++] = $addr;
            }
        }
        if ($addrs) $card['addresses'] = $addrs;

        $im = self::getXmlChildValue($contactEl, 't:IMAddress');
        if ($im) $card['onlineServices'] = ['im1' => ['uri' => $im]];

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

    public static function bodyToXml(array $email, array $bodyValues = []): string {
        $xml = '';

        if (!empty($email['htmlBody'])) {
            $partId = $email['htmlBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                $xml .= '<t:Body BodyType="HTML">' . EwsSoap::escapeXml($bodyValues[$partId]['value']) . '</t:Body>';
                return $xml;
            }
        }

        if (!empty($email['textBody'])) {
            $partId = $email['textBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                $xml .= '<t:Body BodyType="Text">' . EwsSoap::escapeXml($bodyValues[$partId]['value']) . '</t:Body>';
                return $xml;
            }
        }

        if (!empty($email['textBody'])) {
            $partId = $email['textBody'][0]['partId'] ?? null;
            if ($partId) {
                $xml .= '<t:Body BodyType="Text">' . EwsSoap::escapeXml($email['textBody'][0]['value'] ?? '') . '</t:Body>';
            }
        }

        return $xml;
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
        $key = ($item['subject'] ?? '') .
               ($item['receivedAt'] ?? '') .
               implode('', array_keys($item['keywords'] ?? []));
        return sprintf('%08x', crc32($key));
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
