<?php

class EwsOperations {

    private JmapClient $client;
    private string $accountId;
    private string $contactsAccountId;

    public function __construct(JmapClient $client) {
        $this->client = $client;
        $this->accountId = $client->getAccountId();
        $this->contactsAccountId = $client->getCapabilityAccountId(JmapClient::CAP_CONTACTS);
    }

    public function handleAutodiscover(string $email, string $rawXml): string {
        $dom = new DOMDocument();
        if (!$dom->loadXML($rawXml)) {
            return $this->autodiscoverError('Invalid request XML');
        }

        $resp = new DOMDocument('1.0', 'utf-8');
        $resp->formatOutput = true;

        $root = $resp->createElementNS(
            'http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006',
            'Autodiscover'
        );
        $resp->appendChild($root);

        $response = $resp->createElement('Response');
        $root->appendChild($response);

        $user = $resp->createElement('User');
        $response->appendChild($user);

        $displayName = $email;
        $parts = explode('@', $email);
        $domain = $parts[1] ?? 'localhost';

        try {
            $session = $this->client->getSession();
            $sessionUser = $session['username'] ?? $session['email'] ?? '';
            $displayName = $sessionUser ?: $email;
        } catch (\Throwable) {
        }

        $user->appendChild($resp->createElement('DisplayName', $displayName));
        $user->appendChild($resp->createElement('LegacyDN', '/o=First Organization/ou=Exchange Administrative Group (FYDIBOHF23SPDLT)/cn=Recipients/cn=' . $email));

        $user->appendChild($resp->createElement('AutoDiscoverSMTPAddress', $email));
        $user->appendChild($resp->createElement('SMTPAddress', $email));

        $user->appendChild($resp->createElement('ExchangeVersion', 'Exchange2013'));
        $user->appendChild($resp->createElement('MailboxTarget', 'Mailbox'));

        $apiUrl = $this->client->getApiUrl();
        $parsed = parse_url($apiUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? 'localhost';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $ewsUrl = $scheme . '://' . $host . $port . EWS_SCRIPT_NAME;

        $protocol = $resp->createElement('Protocol');
        $response->appendChild($protocol);

        $protocol->appendChild($resp->createElement('Type', 'EXCH'));
        $protocol->appendChild($resp->createElement('Server', $host));
        $protocol->appendChild($resp->createElement('ServerDN', '/o=First Organization/ou=Exchange Administrative Group (FYDIBOHF23SPDLT)/cn=Configuration/cn=Servers/cn=' . $host));
        $protocol->appendChild($resp->createElement('ServerVersion', 'Exchange2013'));
        $protocol->appendChild($resp->createElement('MDBDN', '/o=First Organization/ou=Exchange Administrative Group (FYDIBOHF23SPDLT)/cn=Databases/cn=Mailbox Database'));

        $authPackage = $resp->createElement('AuthPackage', 'Basic');
        $protocol->appendChild($authPackage);

        $protocol->appendChild($resp->createElement('OOFUrl', $ewsUrl));
        $protocol->appendChild($resp->createElement('UMUrl', $ewsUrl));
        $protocol->appendChild($resp->createElement('EwsUrl', $ewsUrl));
        $protocol->appendChild($resp->createElement('EcpUrl', $ewsUrl));

        $protocol2 = $resp->createElement('Protocol');
        $response->appendChild($protocol2);
        $protocol2->appendChild($resp->createElement('Type', 'EXHTTP'));
        $protocol2->appendChild($resp->createElement('Server', $host));
        $protocol2->appendChild($resp->createElement('AuthPackage', 'Basic'));
        $protocol2->appendChild($resp->createElement('EwsUrl', $ewsUrl));

        return $resp->saveXML();
    }

    private function autodiscoverError(string $msg): string {
        $resp = new DOMDocument('1.0', 'utf-8');
        $root = $resp->createElementNS(
            'http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006',
            'Autodiscover'
        );
        $resp->appendChild($root);
        $response = $resp->createElement('Response');
        $root->appendChild($response);
        $error = $resp->createElement('Error', $msg);
        $response->appendChild($error);
        return $resp->saveXML();
    }

    public function syncFolderHierarchy(DOMElement $request): string {
        try {
            $syncState = EwsSoap::getChildValue($request, 'SyncState');

            $mailboxes   = $this->getAllMailboxes();
            $addressBooks = $this->getAllAddressBooks();
            $calendars    = $this->getAllCalendars();

            $allFolders = array_merge($mailboxes, $addressBooks, $calendars);

            $dom = new DOMDocument();

            $changesXml = '';
            foreach ($allFolders as $folder) {
                $fid  = $folder['id'] ?? '';
                $type = $folder['type'] ?? 'mail';
                $folderId = match($type) {
                    'contacts' => EwsConverter::PFX_CONTACTS . $fid,
                    'calendar' => EwsConverter::PFX_CALENDAR . $fid,
                    default    => $fid,
                };
                $parentId = ($type === 'mail' && !empty($folder['parentId'])) ? $folder['parentId'] : '0';
                $changesXml .= '<t:Create>' .
                    EwsConverter::folderToXml($dom, $folder, $folderId, $parentId, $fid) .
                    '</t:Create>';
            }

            $newSyncState = base64_encode(json_encode([
                't' => time(),
                'mb' => count($mailboxes),
                'ab' => count($addressBooks),
                'cal' => count($calendars),
            ]));

            $bodyXml = '<m:SyncFolderHierarchyResponse>' .
                '<m:ResponseMessages>' .
                '<m:SyncFolderHierarchyResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:SyncState>' . EwsSoap::escapeXml($newSyncState) . '</m:SyncState>' .
                '<m:IncludesLastFolderInRange>true</m:IncludesLastFolderInRange>' .
                '<m:Changes>' . $changesXml . '</m:Changes>' .
                '</m:SyncFolderHierarchyResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:SyncFolderHierarchyResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('SyncFolderHierarchy', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function syncFolderItems(DOMElement $request): string {
        try {
            $syncFolderIdEl = EwsSoap::getFirstChild($request, 'SyncFolderId');
            $syncFolderId = '';
            if ($syncFolderIdEl) {
                $syncFolderId = $this->resolveFolderIdFromEl($syncFolderIdEl);
            }
            if (!$syncFolderId) {
                $syncFolderId = $this->resolveFolderIdFromEl($request);
            }

            $syncState    = EwsSoap::getChildValue($request, 'SyncState');
            $maxChanges   = EwsSoap::getChildInt($request, 'MaxChangesReturned', 512);

            if (!$syncFolderId) {
                return $this->errorResponse('SyncFolderItems', 'ErrorInvalidSyncFolderId', 'Missing SyncFolderId');
            }

            $folderType = $this->folderType($syncFolderId);
            $jmapId     = $this->jmapId($syncFolderId);

            $dom = new DOMDocument();

            $prevState = $syncState ? json_decode(base64_decode($syncState), true) : null;
            $prevIds = $prevState['ids'] ?? [];
            $prevPos = $prevState['pos'] ?? null;

            switch ($folderType) {
                case 'contacts':
                    [$changesXml, $currentIds, $hasMore, $nextPos] = $this->syncContacts($dom, $jmapId, $prevIds, $prevPos, $maxChanges);
                    break;
                case 'calendar':
                    [$changesXml, $currentIds, $hasMore, $nextPos] = $this->syncCalendarEvents($dom, $jmapId, $prevIds, $prevPos, $maxChanges);
                    break;
                default:
                    [$changesXml, $currentIds, $hasMore, $nextPos] = $this->syncEmails($dom, $jmapId, $prevIds, $prevPos, $maxChanges);
                    break;
            }

            $newState = ['t' => time(), 'ids' => $currentIds];
            if ($nextPos !== null) {
                $newState['pos'] = $nextPos;
            }
            $newSyncState = base64_encode(json_encode($newState));

            $includesLast = $hasMore ? 'false' : 'true';

            $bodyXml = '<m:SyncFolderItemsResponse>' .
                '<m:ResponseMessages>' .
                '<m:SyncFolderItemsResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:SyncState>' . EwsSoap::escapeXml($newSyncState) . '</m:SyncState>' .
                '<m:IncludesLastItemInRange>' . $includesLast . '</m:IncludesLastItemInRange>' .
                '<m:Changes>' . $changesXml . '</m:Changes>' .
                '</m:SyncFolderItemsResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:SyncFolderItemsResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('SyncFolderItems', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getFolder(DOMElement $request): string {
        try {
            $folderShape = EwsSoap::getFirstChild($request, 'FolderShape');

            $folderIdsEl = EwsSoap::getFirstChild($request, 'FolderIds');

            $ids = [];
            if ($folderIdsEl) {
                $fidEls = $folderIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'FolderId');
                for ($i = 0; $i < $fidEls->length; $i++) {
                    $ids[] = $fidEls->item($i)->getAttribute('Id');
                }
                $dfidEls = $folderIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'DistinguishedFolderId');
                for ($i = 0; $i < $dfidEls->length; $i++) {
                    $ids[] = $this->resolveFolderId($dfidEls->item($i)->getAttribute('Id'));
                }
            }

            if (empty($ids)) {
                return $this->errorResponse('GetFolder', 'ErrorInvalidIdMalformed', 'No folder IDs provided');
            }

            $mailboxes   = $this->getAllMailboxes();
            $contacts    = $this->getAllAddressBooks();
            $calendars   = $this->getAllCalendars();

            $folderMap = [];
            foreach ($mailboxes as $mb) {
                $folderMap[$mb['id']] = $mb + ['type' => 'mail'];
            }
            foreach ($contacts as $ab) {
                $folderMap[EwsConverter::PFX_CONTACTS . $ab['id']] = $ab + ['type' => 'contacts'];
            }
            foreach ($calendars as $cal) {
                $folderMap[EwsConverter::PFX_CALENDAR . $cal['id']] = $cal + ['type' => 'calendar'];
            }

            $dom = new DOMDocument();
            $responsesXml = '';

            foreach ($ids as $fid) {
                if ($fid === $this->accountId) {
                    $responsesXml .= $this->rootFolderResponse();
                    continue;
                }

                $folder = $folderMap[$fid] ?? null;
                if (!$folder) {
                    $responsesXml .= '<m:GetFolderResponseMessage ResponseClass="Error">' .
                        '<m:MessageText></m:MessageText><m:ResponseCode>ErrorFolderNotFound</m:ResponseCode>' .
                        '</m:GetFolderResponseMessage>';
                    continue;
                }

                $type = $folder['type'] ?? 'mail';
                $parentId = ($type === 'mail' && !empty($folder['parentId'])) ? $folder['parentId'] : '0';
                $folderXml = EwsConverter::folderToXml($dom, $folder, $fid, $parentId, $folder['id']);

                $responsesXml .= '<m:GetFolderResponseMessage ResponseClass="Success">' .
                    '<m:ResponseCode>NoError</m:ResponseCode>' .
                    '<m:Folders>' . $folderXml . '</m:Folders>' .
                    '</m:GetFolderResponseMessage>';
            }

            $bodyXml = '<m:GetFolderResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:GetFolderResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('GetFolder', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    private function rootFolderResponse(): string {
        $totalMail = count($this->getAllMailboxes());
        $xml = '<m:GetFolderResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            '<m:Folders>' .
            '<t:Folder>' .
            '<t:FolderId Id="' . EwsSoap::escapeXml($this->accountId) . '" ChangeKey="' . EwsSoap::escapeXml($this->accountId) . '"/>' .
            '<t:ParentFolderId Id="0"/>' .
            '<t:DisplayName>Root</t:DisplayName>' .
            '<t:TotalCount>0</t:TotalCount>' .
            '<t:ChildFolderCount>' . $totalMail . '</t:ChildFolderCount>' .
            '<t:UnreadCount>0</t:UnreadCount>' .
            '<t:FolderClass>IPF.Note</t:FolderClass>' .
            '</t:Folder>' .
            '</m:Folders>' .
            '</m:GetFolderResponseMessage>';
        return $xml;
    }

    public function findFolder(DOMElement $request): string {
        try {
            $traversal = EwsSoap::getChildValue($request, 'Traversal');
            $paging = EwsSoap::getFirstChild($request, 'Paging');
            $maxRows = 256;
            if ($paging) {
                $maxRows = EwsSoap::getChildInt($paging, 'MaxRows', 256);
            }

            $parentFolderIdsEl = EwsSoap::getFirstChild($request, 'ParentFolderIds');

            $mailboxes   = $this->getAllMailboxes();
            $contacts    = $this->getAllAddressBooks();
            $calendars   = $this->getAllCalendars();

            $dom = new DOMDocument();
            $foldersXml = '';

            foreach ($mailboxes as $mb) {
                $foldersXml .= EwsConverter::folderToXml($dom, $mb, $mb['id'], $mb['parentId'] ?? '0', $mb['id']);
            }
            foreach ($contacts as $ab) {
                $fid = EwsConverter::PFX_CONTACTS . $ab['id'];
                $foldersXml .= EwsConverter::folderToXml($dom, $ab, $fid, '0', $ab['id']);
            }
            foreach ($calendars as $cal) {
                $fid = EwsConverter::PFX_CALENDAR . $cal['id'];
                $foldersXml .= EwsConverter::folderToXml($dom, $cal, $fid, '0', $cal['id']);
            }

            $total = count($mailboxes) + count($contacts) + count($calendars);
            $bodyXml = '<m:FindFolderResponse>' .
                '<m:ResponseMessages>' .
                '<m:FindFolderResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:RootFolder TotalItemsInView="' . $total . '" IncludesLastItemInRange="true">' .
                '<t:Folders>' . $foldersXml . '</t:Folders>' .
                '</m:RootFolder>' .
                '</m:FindFolderResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:FindFolderResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('FindFolder', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function findItem(DOMElement $request): string {
        try {
            $itemShape = EwsSoap::getFirstChild($request, 'ItemShape');
            $baseShape = $itemShape ? EwsSoap::getChildValue($itemShape, 'BaseShape') : 'Default';

            $parentFolderIdsEl = EwsSoap::getFirstChild($request, 'ParentFolderIds');

            $queryString = '';
            $restrictionEl = EwsSoap::getFirstChild($request, 'Restriction');
            if ($restrictionEl) {
                $queryString = $this->extractSearchTerm($restrictionEl);
            }
            if (!$queryString) {
                $queryString = EwsSoap::getChildValue($request, 'QueryString');
            }

            if (!$queryString) {
                $bodyXml = '<m:FindItemResponse>' .
                    '<m:ResponseMessages>' .
                    '<m:FindItemResponseMessage ResponseClass="Success">' .
                    '<m:ResponseCode>NoError</m:ResponseCode>' .
                    '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true">' .
                    '</m:RootFolder>' .
                    '</m:FindItemResponseMessage>' .
                    '</m:ResponseMessages>' .
                    '</m:FindItemResponse>';
                return EwsSoap::buildSoapResponse($bodyXml);
            }

            $r = $this->client->call([
                ['Email/query', [
                    'accountId' => $this->accountId,
                    'filter'    => ['text' => $queryString],
                    'limit'     => 256,
                ], 'q'],
                ['Email/get', [
                    'accountId'         => $this->accountId,
                    '#ids'              => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                    'properties'        => ['id', 'subject', 'from', 'to', 'receivedAt', 'keywords', 'size', 'hasAttachment'],
                    'fetchAllBodyValues' => false,
                ], 'g'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL]);

            $emails = $r[1][1]['list'] ?? [];
            $dom = new DOMDocument();
            $itemsXml = '';

            foreach ($emails as $email) {
                $itemsXml .= EwsConverter::emailToXml($dom, $email, '');
            }

            $bodyXml = '<m:FindItemResponse>' .
                '<m:ResponseMessages>' .
                '<m:FindItemResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:RootFolder TotalItemsInView="' . count($emails) . '" IncludesLastItemInRange="true">' .
                '<m:Items>' . $itemsXml . '</m:Items>' .
                '</m:RootFolder>' .
                '</m:FindItemResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:FindItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('FindItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getItem(DOMElement $request): string {
        try {
            $itemShape = EwsSoap::getFirstChild($request, 'ItemShape');

            $itemIdsEl = EwsSoap::getFirstChild($request, 'ItemIds');
            $itemIdEls = $itemIdsEl ? $itemIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId') : null;

            $ids = [];
            if ($itemIdEls) {
                for ($i = 0; $i < $itemIdEls->length; $i++) {
                    $ids[] = $itemIdEls->item($i)->getAttribute('Id');
                }
            }

            if (empty($ids)) {
                return $this->errorResponse('GetItem', 'ErrorInvalidIdMalformed', 'No item IDs provided');
            }

            $mailIds = [];
            $contactIds = [];
            $calendarIds = [];

            foreach ($ids as $id) {
                if (str_starts_with($id, EwsConverter::PFX_CONTACTS)) {
                    $contactIds[] = substr($id, strlen(EwsConverter::PFX_CONTACTS));
                } elseif (str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    $calendarIds[] = substr($id, strlen(EwsConverter::PFX_CALENDAR));
                } else {
                    $mailIds[] = $id;
                }
            }

            $dom = new DOMDocument();
            $emailsByJmapId = [];
            $contactsByJmapId = [];
            $calendarsByJmapId = [];

            if ($mailIds) {
                $r = $this->client->call([
                    ['Email/get', [
                        'accountId'          => $this->accountId,
                        'ids'                => $mailIds,
                        'properties'         => ['id', 'blobId', 'mailboxIds', 'keywords', 'size',
                            'receivedAt', 'subject', 'from', 'to', 'cc', 'bcc', 'replyTo',
                            'hasAttachment', 'textBody', 'htmlBody', 'attachments', 'bodyValues'],
                        'fetchAllBodyValues' => true,
                    ], '0'],
                ]);
                foreach ($r[0][1]['list'] ?? [] as $email) {
                    $emailsByJmapId[$email['id']] = $email;
                }
            }

            if ($contactIds) {
                $r = $this->client->call([
                    ['ContactCard/get', [
                        'accountId'  => $this->accountId,
                        'ids'        => $contactIds,
                        'properties' => null,
                    ], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                foreach ($r[0][1]['list'] ?? [] as $card) {
                    $contactsByJmapId[$card['id']] = $card;
                }
            }

            if ($calendarIds) {
                $r = $this->client->call([
                    ['CalendarEvent/get', [
                        'accountId'  => $this->accountId,
                        'ids'        => $calendarIds,
                        'properties' => null,
                    ], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
                foreach ($r[0][1]['list'] ?? [] as $event) {
                    $calendarsByJmapId[$event['id']] = $event;
                }
            }

            $responsesXml = '';
            foreach ($ids as $id) {
                if (str_starts_with($id, EwsConverter::PFX_CONTACTS)) {
                    $jmapId = substr($id, strlen(EwsConverter::PFX_CONTACTS));
                    $card = $contactsByJmapId[$jmapId] ?? null;
                    if (!$card) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::contactToXml($dom, $card, '') . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                } elseif (str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    $jmapId = substr($id, strlen(EwsConverter::PFX_CALENDAR));
                    $event = $calendarsByJmapId[$jmapId] ?? null;
                    if (!$event) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::calendarToXml($dom, $event, '') . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                } else {
                    $email = $emailsByJmapId[$id] ?? null;
                    if (!$email) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $mailboxId = array_key_first($email['mailboxIds'] ?? ['0' => true]);
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::emailToXml($dom, $email, $mailboxId ?? '0') . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                }
            }

            $bodyXml = '<m:GetItemResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:GetItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('GetItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function createItem(DOMElement $request): string {
        try {
            $savedFolderIdEl = EwsSoap::getFirstChild($request, 'SavedItemFolderId');
            $parentFolderId = $this->resolveFolderIdFromEl($savedFolderIdEl);

            $itemsEl = EwsSoap::getFirstChild($request, 'Items');
            if (!$itemsEl) {
                return $this->errorResponse('CreateItem', 'ErrorInvalidRequest', 'Missing Items element');
            }

            $dom = new DOMDocument();
            $itemsXml = '';

            foreach ($itemsEl->childNodes as $itemNode) {
                if (!$itemNode instanceof DOMElement) continue;

                switch ($itemNode->localName) {
                    case 'Message':
                        $mime = $this->ewsMessageToMime($itemNode);
                        if ($mime) {
                            try {
                                $blobId = $this->client->uploadBlob($mime, 'message/rfc822');
                                $mailboxId = $parentFolderId ?: $this->getDraftsMailboxId();
                                $r = $this->client->call([
                                    ['Email/import', [
                                        'accountId' => $this->accountId,
                                        'emails'    => ['n1' => [
                                            'blobId'     => $blobId,
                                            'mailboxIds' => [$mailboxId => true],
                                            'keywords'   => ['$draft' => true],
                                            'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                                        ]],
                                    ], '0'],
                                ]);
                                $created = $r[0][1]['created']['n1'] ?? null;
                                if ($created) {
                                    $itemsXml .= EwsSoap::buildId($dom, $created['id']);
                                }
                            } catch (\Throwable $e2) {
                                $itemsXml .= EwsSoap::buildId($dom, 'error-' . uniqid());
                            }
                        }
                        break;

                    case 'Contact':
                        $abId = $parentFolderId
                            ? $this->jmapId($parentFolderId)
                            : $this->contactsAccountId;
                        $card = EwsConverter::ewsContactToCard($itemNode, $abId);
                        try {
                            $r = $this->client->call([
                                ['ContactCard/set', [
                                    'accountId' => $this->accountId,
                                    'create'    => ['c1' => $card],
                                ], '0'],
                            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                            $created = $r[0][1]['created']['c1'] ?? null;
                            if ($created) {
                                $itemsXml .= EwsSoap::buildId($dom, $created['id']);
                            }
                        } catch (\Throwable $e2) {
                            $itemsXml .= EwsSoap::buildId($dom, 'error-' . uniqid());
                        }
                        break;

                    case 'CalendarItem':
                        $calId = $parentFolderId
                            ? $this->jmapId($parentFolderId)
                            : ($this->getDefaultCalendarId() ?: 'default');
                        $event = EwsConverter::ewsEventToJmap($itemNode, $calId);
                        try {
                            $r = $this->client->call([
                                ['CalendarEvent/set', [
                                    'accountId' => $this->accountId,
                                    'create'    => ['ev1' => $event],
                                ], '0'],
                            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
                            $created = $r[0][1]['created']['ev1'] ?? null;
                            if ($created) {
                                $itemsXml .= EwsSoap::buildId($dom, $created['id']);
                            }
                        } catch (\Throwable $e2) {
                            $itemsXml .= EwsSoap::buildId($dom, 'error-' . uniqid());
                        }
                        break;
                }
            }

            if (!$itemsXml) {
                return $this->errorResponse('CreateItem', 'ErrorInvalidItemType', 'No supported items to create');
            }

            $bodyXml = '<m:CreateItemResponse>' .
                '<m:ResponseMessages>' .
                '<m:CreateItemResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:Items>' . $itemsXml . '</m:Items>' .
                '</m:CreateItemResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:CreateItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('CreateItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function updateItem(DOMElement $request): string {
        try {
            $itemChanges = EwsSoap::getFirstChild($request, 'ItemChanges');
            if (!$itemChanges) {
                return $this->errorResponse('UpdateItem', 'ErrorInvalidRequest', 'Missing ItemChanges');
            }

            $dom = new DOMDocument();
            $responsesXml = '';

            $changeEls = $itemChanges->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemChange');
            for ($i = 0; $i < $changeEls->length; $i++) {
                $change = $changeEls->item($i);
                $itemIdEl = $change->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId')->item(0);
                $id = $itemIdEl ? $itemIdEl->getAttribute('Id') : '';
                $changeKey = $itemIdEl ? $itemIdEl->getAttribute('ChangeKey') : '';

                $updates = EwsSoap::getFirstChild($change, 'Updates');

                if (!$id || !$updates) {
                    $responsesXml .= '<m:ItemResponseMessage ResponseClass="Error">' .
                        '<m:MessageText></m:MessageText><m:ResponseCode>ErrorInvalidIdMalformed</m:ResponseCode>' .
                        '</m:ItemResponseMessage>';
                    continue;
                }

                if (str_starts_with($id, EwsConverter::PFX_CONTACTS)) {
                    $response = $this->updateContact($id, $updates);
                } elseif (str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    $response = $this->updateCalendarEvent($id, $updates);
                } else {
                    $response = $this->updateEmail($id, $updates);
                }

                $responsesXml .= $response;
            }

            $bodyXml = '<m:UpdateItemResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:UpdateItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('UpdateItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function deleteItem(DOMElement $request): string {
        try {
            $deleteType = EwsSoap::getChildValue($request, 'DeleteType');
            $isHardDelete = strtolower($deleteType) === 'harddelete';

            $itemIdsEl = EwsSoap::getFirstChild($request, 'ItemIds');
            if (!$itemIdsEl) {
                return $this->errorResponse('DeleteItem', 'ErrorInvalidRequest', 'Missing ItemIds');
            }

            $idEls = $itemIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId');
            $ids = [];
            for ($i = 0; $i < $idEls->length; $i++) {
                $ids[] = $idEls->item($i)->getAttribute('Id');
            }

            $dom = new DOMDocument();
            $responsesXml = '';

            foreach ($ids as $id) {
                try {
                    $folderType = $this->folderType($id);
                    $jmapId = $this->jmapId($id);

                    switch ($folderType) {
                        case 'contacts':
                            $r = $this->client->call([
                                ['ContactCard/set', [
                                    'accountId' => $this->accountId,
                                    'destroy'   => [$jmapId],
                                ], '0'],
                            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                            $success = in_array($jmapId, $r[0][1]['destroyed'] ?? [], true);
                            break;

                        case 'calendar':
                            $r = $this->client->call([
                                ['CalendarEvent/set', [
                                    'accountId' => $this->accountId,
                                    'destroy'   => [$jmapId],
                                ], '0'],
                            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
                            $success = in_array($jmapId, $r[0][1]['destroyed'] ?? [], true);
                            break;

                        default:
                            if ($isHardDelete) {
                                $r = $this->client->call([
                                    ['Email/set', [
                                        'accountId' => $this->accountId,
                                        'destroy'   => [$jmapId],
                                    ], '0'],
                                ]);
                                $success = in_array($jmapId, $r[0][1]['destroyed'] ?? [], true);
                            } else {
                                $trashId = $this->getMailboxIdByRole('trash');
                                if ($trashId) {
                                    $r = $this->client->call([
                                        ['Email/set', [
                                            'accountId' => $this->accountId,
                                            'update'    => [$jmapId => [
                                                "mailboxIds/$id" => null,
                                                "mailboxIds/$trashId" => true,
                                            ]],
                                        ], '0'],
                                    ]);
                                    $success = isset($r[0][1]['updated'][$jmapId]);
                                } else {
                                    $r = $this->client->call([
                                        ['Email/set', [
                                            'accountId' => $this->accountId,
                                            'destroy'   => [$jmapId],
                                        ], '0'],
                                    ]);
                                    $success = in_array($jmapId, $r[0][1]['destroyed'] ?? [], true);
                                }
                            }
                            break;
                    }

                    if ($success) {
                        $responsesXml .= '<m:DeleteItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '</m:DeleteItemResponseMessage>';
                    } else {
                        $responsesXml .= '<m:DeleteItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:DeleteItemResponseMessage>';
                    }

                } catch (\Throwable $e2) {
                    $responsesXml .= '<m:DeleteItemResponseMessage ResponseClass="Error">' .
                        '<m:MessageText>' . EwsSoap::escapeXml($e2->getMessage()) . '</m:MessageText>' .
                        '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                        '</m:DeleteItemResponseMessage>';
                }
            }

            $bodyXml = '<m:DeleteItemResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:DeleteItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('DeleteItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function sendItem(DOMElement $request): string {
        try {
            $savedFolderId = $this->resolveFolderIdFromEl(EwsSoap::getFirstChild($request, 'SavedItemFolderId'));

            $itemIdsEl = EwsSoap::getFirstChild($request, 'ItemIds');
            $idEls = $itemIdsEl ? $itemIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId') : null;

            if (!$idEls || $idEls->length === 0) {
                return $this->errorResponse('SendItem', 'ErrorInvalidRequest', 'Missing ItemIds');
            }

            $success = true;
            for ($i = 0; $i < $idEls->length; $i++) {
                $id = $idEls->item($i)->getAttribute('Id');
                try {
                    $r = $this->client->call([
                        ['Email/get', [
                            'accountId'          => $this->accountId,
                            'ids'                => [$id],
                            'properties'         => ['id', 'blobId', 'subject', 'from', 'to', 'cc', 'bcc',
                                'replyTo', 'textBody', 'htmlBody', 'bodyValues', 'attachments'],
                            'fetchAllBodyValues' => true,
                        ], '0'],
                    ]);
                    $email = $r[0][1]['list'][0] ?? null;
                    if (!$email) {
                        $success = false;
                        continue;
                    }

                    if (!empty($email['blobId'])) {
                        try {
                            $mime = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
                        } catch (\Throwable) {
                            $mime = $this->buildMimeFromEmail($email);
                        }
                    } else {
                        $mime = $this->buildMimeFromEmail($email);
                    }

                    $blobId = $this->client->uploadBlob($mime, 'message/rfc822');
                    $identityId = $this->getIdentityId();

                    $subResponses = $this->client->call([
                        ['Email/import', [
                            'accountId' => $this->accountId,
                            'emails'    => ['s1' => [
                                'blobId'     => $blobId,
                                'mailboxIds' => [$this->getSentMailboxId() ?: $this->accountId => true],
                                'keywords'   => ['$seen' => true, '$submitted' => true],
                                'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                            ]],
                        ], 'im'],
                        ['EmailSubmission/set', [
                            'accountId' => $this->accountId,
                            'create'    => ['sub1' => [
                                'emailId'    => $id,
                                'identityId' => $identityId,
                                'envelope'   => null,
                            ]],
                        ], 'sub'],
                    ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);

                    if (!empty($subResponses[1][1]['notCreated'])) {
                        $success = false;
                    }
                } catch (\Throwable $e2) {
                    $success = false;
                }
            }

            if ($success) {
                $bodyXml = '<m:SendItemResponse>' .
                    '<m:ResponseMessages>' .
                    '<m:SendItemResponseMessage ResponseClass="Success">' .
                    '<m:ResponseCode>NoError</m:ResponseCode>' .
                    '</m:SendItemResponseMessage>' .
                    '</m:ResponseMessages>' .
                    '</m:SendItemResponse>';
            } else {
                $bodyXml = '<m:SendItemResponse>' .
                    '<m:ResponseMessages>' .
                    '<m:SendItemResponseMessage ResponseClass="Error">' .
                    '<m:MessageText></m:MessageText><m:ResponseCode>ErrorSendMessage</m:ResponseCode>' .
                    '</m:SendItemResponseMessage>' .
                    '</m:ResponseMessages>' .
                    '</m:SendItemResponse>';
            }

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('SendItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getAttachment(DOMElement $request): string {
        try {
            $attachmentIdsEl = EwsSoap::getFirstChild($request, 'AttachmentIds');
            $idEls = $attachmentIdsEl ? $attachmentIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'AttachmentId') : null;

            $responsesXml = '';

            if ($idEls) {
                for ($i = 0; $i < $idEls->length; $i++) {
                    $attId = $idEls->item($i)->getAttribute('Id');
                    $parts = explode('||', $attId, 3);
                    $blobId = $parts[1] ?? '';
                    $name   = $parts[2] ?? 'attachment';

                    if (!$blobId) {
                        $responsesXml .= '<m:AttachmentResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorInvalidAttachmentId</m:ResponseCode>' .
                            '</m:AttachmentResponseMessage>';
                        continue;
                    }

                    try {
                        $data = $this->client->downloadBlob($blobId, $name);
                        $contentType = $this->extToMime(strtolower(pathinfo($name, PATHINFO_EXTENSION)));

                        $responsesXml .= '<m:AttachmentResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Attachments>' .
                            '<t:FileAttachment>' .
                            '<t:AttachmentId Id="' . EwsSoap::escapeXml($attId) . '"/>' .
                            '<t:Name>' . EwsSoap::escapeXml($name) . '</t:Name>' .
                            '<t:ContentType>' . EwsSoap::escapeXml($contentType) . '</t:ContentType>' .
                            '<t:Content>' . base64_encode($data) . '</t:Content>' .
                            '</t:FileAttachment>' .
                            '</m:Attachments>' .
                            '</m:AttachmentResponseMessage>';
                    } catch (\Throwable $e2) {
                        $responsesXml .= '<m:AttachmentResponseMessage ResponseClass="Error">' .
                            '<m:MessageText>' . EwsSoap::escapeXml($e2->getMessage()) . '</m:MessageText>' .
                            '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                            '</m:AttachmentResponseMessage>';
                    }
                }
            }

            $bodyXml = '<m:GetAttachmentResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:GetAttachmentResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('GetAttachment', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function resolveNames(DOMElement $request): string {
        try {
            $unresolvedEntry = EwsSoap::getChildValue($request, 'UnresolvedEntry');
            $returnFullContactData = EwsSoap::getChildBool($request, 'ReturnFullContactData');

            if (!$unresolvedEntry) {
                $bodyXml = '<m:ResolveNamesResponse>' .
                    '<m:ResponseMessages>' .
                    '<m:ResolveNamesResponseMessage ResponseClass="Success">' .
                    '<m:ResponseCode>NoError</m:ResponseCode>' .
                    '</m:ResolveNamesResponseMessage>' .
                    '</m:ResponseMessages>' .
                    '</m:ResolveNamesResponse>';
                return EwsSoap::buildSoapResponse($bodyXml);
            }

            $r = $this->client->call([
                ['ContactCard/query', [
                    'accountId' => $this->accountId,
                    'filter'    => ['inAddressBook' => $this->contactsAccountId],
                    'limit'     => 500,
                ], 'q'],
                ['ContactCard/get', [
                    'accountId'  => $this->accountId,
                    '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                    'properties' => $returnFullContactData ? null : ['id', 'name', 'emails'],
                ], 'g'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

            $cards = $r[1][1]['list'] ?? [];
            $dom = new DOMDocument();
            $resolutionsXml = '';
            $queryLower = strtolower($unresolvedEntry);

            foreach ($cards as $card) {
                $name  = $card['name']['full'] ?? $card['name']['given'] ?? '' . ' ' . ($card['name']['surname'] ?? '');
                $emails = array_values($card['emails'] ?? []);
                $matched = false;

                if (stripos($name, $unresolvedEntry) !== false) {
                    $matched = true;
                } else {
                    foreach ($emails as $e) {
                        if (stripos($e['address'] ?? '', $unresolvedEntry) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                }

                if ($matched) {
                    $mailboxXml = '';
                    foreach ($emails as $e) {
                        $mailboxXml .= EwsSoap::buildMailbox($name, $e['address'] ?? '');
                        if ($e) break;
                    }
                    if (!$mailboxXml && $name) {
                        $mailboxXml .= EwsSoap::buildMailbox($name, '');
                    }

                    $contactXml = '';
                    if ($returnFullContactData && $mailboxXml) {
                        $contactXml = EwsConverter::contactToXml($dom, $card, '');
                    }

                    $resolutionsXml .= '<m:Resolution>' .
                        $mailboxXml .
                        $contactXml .
                        '</m:Resolution>';
                }
            }

            $bodyXml = '<m:ResolveNamesResponse>' .
                '<m:ResponseMessages>' .
                '<m:ResolveNamesResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '<m:ResolutionSet TotalItemsInView="' . (int)($resolutionsXml !== '') . '" IncludesLastItemInRange="true">' .
                $resolutionsXml .
                '</m:ResolutionSet>' .
                '</m:ResolveNamesResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:ResolveNamesResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('ResolveNames', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getUserAvailability(DOMElement $request): string {
        try {
            $timeWindow = EwsSoap::getFirstChild($request, 'FreeBusyViewOptions')
                ?->getElementsByTagNameNS(EwsSoap::NS_T, 'TimeWindow')->item(0);

            $startTime = $timeWindow ? EwsSoap::getChildValue($timeWindow, 'StartTime', '', '') : gmdate('Y-m-d\TH:i:s\Z');
            $endTime   = $timeWindow ? EwsSoap::getChildValue($timeWindow, 'EndTime', '', '')   : gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day'));

            $r = $this->client->call([
                ['CalendarEvent/query', [
                    'accountId' => $this->accountId,
                    'filter'    => [
                        'after'  => gmdate('Y-m-d\TH:i:s\Z', strtotime($startTime)),
                        'before' => gmdate('Y-m-d\TH:i:s\Z', strtotime($endTime)),
                    ],
                    'limit'     => 500,
                ], 'q'],
                ['CalendarEvent/get', [
                    'accountId'  => $this->accountId,
                    '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                    'properties' => ['id', 'title', 'start', 'duration', 'freeBusyStatus', 'showWithoutTime'],
                ], 'g'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

            $events = $r[1][1]['list'] ?? [];
            $calEventsXml = '';

            foreach ($events as $event) {
                $status = match($event['freeBusyStatus'] ?? 'busy') {
                    'free'        => 'Free',
                    'tentative'   => 'Tentative',
                    'unavailable' => 'OOF',
                    default       => 'Busy',
                };

                $startTs  = JmapCalendarConverter::jmapTimeToTimestamp($event['start'] ?? '');
                $durSecs  = JmapCalendarConverter::parseDuration($event['duration'] ?? 'PT1H');
                $endTs    = $startTs + $durSecs;

                $calEventsXml .= '<t:CalendarEvent>' .
                    '<t:StartTime>' . gmdate('Y-m-d\TH:i:s\Z', $startTs) . '</t:StartTime>' .
                    '<t:EndTime>' . gmdate('Y-m-d\TH:i:s\Z', $endTs) . '</t:EndTime>' .
                    '<t:BusyType>' . $status . '</t:BusyType>' .
                    '</t:CalendarEvent>';
            }

            $bodyXml = '<m:GetUserAvailabilityResponse>' .
                '<m:FreeBusyResponseArray>' .
                '<m:FreeBusyResponse>' .
                '<m:ResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                '</m:ResponseMessage>' .
                '<m:FreeBusyView>' .
                '<t:FreeBusyViewType>Detailed</t:FreeBusyViewType>' .
                '<t:CalendarEventArray>' . $calEventsXml . '</t:CalendarEventArray>' .
                '<t:WorkingHours>' .
                '<t:TimeZone>UTC</t:TimeZone>' .
                '<t:WorkDays>MondayTuesdayWednesdayThursdayFriday</t:WorkDays>' .
                '<t:StartTimeInMinutes>480</t:StartTimeInMinutes>' .
                '<t:EndTimeInMinutes>1020</t:EndTimeInMinutes>' .
                '</t:WorkingHours>' .
                '</m:FreeBusyView>' .
                '</m:FreeBusyResponse>' .
                '</m:FreeBusyResponseArray>' .
                '</m:GetUserAvailabilityResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('GetUserAvailability', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function convertId(DOMElement $request): string {
        try {
            $sourceIdsEl = EwsSoap::getFirstChild($request, 'SourceIds');
            $idEls = $sourceIdsEl ? $sourceIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'AlternateId') : null;

            $responsesXml = '';
            if ($idEls) {
                for ($i = 0; $i < $idEls->length; $i++) {
                    $el = $idEls->item($i);
                    $originalId = $el->getAttribute('Id');
                    $format     = $el->getAttribute('Format');
                    $mailbox    = $el->getAttribute('Mailbox');

                    $responsesXml .= '<m:ConvertIdResponseMessage ResponseClass="Success">' .
                        '<m:ResponseCode>NoError</m:ResponseCode>' .
                        '<m:AlternateId Id="' . EwsSoap::escapeXml($originalId) . '" Format="EwsId" Mailbox="' . EwsSoap::escapeXml($mailbox) . '"/>' .
                        '</m:ConvertIdResponseMessage>';
                }
            }

            $bodyXml = '<m:ConvertIdResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:ConvertIdResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            return $this->errorResponse('ConvertId', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getRoomLists(): string {
        $bodyXml = '<m:GetRoomListsResponse>' .
            '<m:ResponseMessages>' .
            '<m:GetRoomListsResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            '<m:RoomLists/>' .
            '</m:GetRoomListsResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:GetRoomListsResponse>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    public function getRooms(): string {
        $bodyXml = '<m:GetRoomsResponse>' .
            '<m:ResponseMessages>' .
            '<m:GetRoomsResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            '<m:Rooms/>' .
            '</m:GetRoomsResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:GetRoomsResponse>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function errorResponse(string $operation, string $code, string $message = ''): string {
        $responseName = $operation . 'Response';
        $msgName = $operation . 'ResponseMessage';
        $bodyXml = '<m:' . $responseName . '>' .
            '<m:ResponseMessages>' .
            '<m:' . $msgName . ' ResponseClass="Error">' .
            ($message ? '<m:MessageText>' . EwsSoap::escapeXml($message) . '</m:MessageText>' : '<m:MessageText></m:MessageText>') .
            '<m:ResponseCode>' . $code . '</m:ResponseCode>' .
            '</m:' . $msgName . '>' .
            '</m:ResponseMessages>' .
            '</m:' . $responseName . '>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function syncEmails(DOMDocument $dom, string $mailboxId, array $prevIds, ?int $prevPos, int $maxChanges): array {
        if ($prevPos !== null) {
            return $this->emailDownloadPage($dom, $mailboxId, $prevPos, $maxChanges);
        }

        $r = $this->client->call([
            ['Email/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inMailbox' => $mailboxId],
                'sort'      => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
        ]);

        $qRes = $r[0][1] ?? [];
        $currentIds = $qRes['ids'] ?? [];
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? 0) + count($currentIds)) : null;

        if ($prevIds) {
            $deletedIds = array_diff($prevIds, $currentIds);
            $createdIds = array_diff($currentIds, $prevIds);

            $this->logSync("emails mailbox=$mailboxId prev=" . json_encode($prevIds) .
                " current=" . json_encode($currentIds) .
                " deleted=" . json_encode(array_values($deletedIds)) .
                " created=" . json_encode(array_values($createdIds)));

            $changesXml = '';

            foreach ($deletedIds as $delId) {
                $changesXml .= '<t:Delete>' .
                    '<t:ItemId Id="' . EwsSoap::escapeXml($delId) . '" ChangeKey="' . EwsSoap::escapeXml($delId) . '"/>' .
                    '</t:Delete>';
            }

            if ($createdIds) {
                $r2 = $this->client->call([
                    ['Email/get', [
                        'accountId'          => $this->accountId,
                        'ids'                => array_values($createdIds),
                        'properties'         => ['id', 'blobId', 'mailboxIds', 'keywords', 'size',
                            'receivedAt', 'subject', 'from', 'to', 'hasAttachment'],
                        'fetchAllBodyValues' => false,
                    ], 'g'],
                ]);
                foreach ($r2[0][1]['list'] ?? [] as $email) {
                    $changesXml .= '<t:Create>' .
                        EwsConverter::emailToXml($dom, $email, $mailboxId) .
                        '</t:Create>';
                }
            }
        } else {
            $changesXml = $this->emailCreates($dom, $mailboxId, $currentIds);
        }

        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function emailDownloadPage(DOMDocument $dom, string $mailboxId, int $position, int $maxChanges): array {
        $r = $this->client->call([
            ['Email/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inMailbox' => $mailboxId],
                'sort'      => [['property' => 'receivedAt', 'isAscending' => false]],
                'position'  => $position,
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
        ]);

        $qRes = $r[0][1] ?? [];
        $currentIds = $qRes['ids'] ?? [];
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? $position) + count($currentIds)) : null;

        $changesXml = $this->emailCreates($dom, $mailboxId, $currentIds);
        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function emailCreates(DOMDocument $dom, string $mailboxId, array $ids): string {
        if (!$ids) {
            return '';
        }
        $r2 = $this->client->call([
            ['Email/get', [
                'accountId'          => $this->accountId,
                'ids'                => array_values($ids),
                'properties'         => ['id', 'blobId', 'mailboxIds', 'keywords', 'size',
                    'receivedAt', 'subject', 'from', 'to', 'hasAttachment'],
                'fetchAllBodyValues' => false,
            ], 'g'],
        ]);
        $changesXml = '';
        foreach ($r2[0][1]['list'] ?? [] as $email) {
            $changesXml .= '<t:Create>' .
                EwsConverter::emailToXml($dom, $email, $mailboxId) .
                '</t:Create>';
        }
        return $changesXml;
    }

    private function syncContacts(DOMDocument $dom, string $abId, array $prevIds, ?int $prevPos, int $maxChanges): array {
        if ($prevPos !== null) {
            return $this->contactDownloadPage($dom, $abId, $prevPos, $maxChanges);
        }

        $r = $this->client->call([
            ['ContactCard/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inAddressBook' => $abId],
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $qRes = $r[0][1] ?? [];
        $cards = $r[1][1]['list'] ?? [];
        $currentIds = array_column($cards, 'id');
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? 0) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CONTACTS . $abId;
        $changesXml = '';

        if ($prevIds) {
            $deletedIds = array_diff($prevIds, $currentIds);
            $createdIds = array_diff($currentIds, $prevIds);

            $this->logSync("contacts ab=$abId prev=" . json_encode($prevIds) .
                " current=" . json_encode($currentIds) .
                " deleted=" . json_encode(array_values($deletedIds)) .
                " created=" . json_encode(array_values($createdIds)));

            foreach ($deletedIds as $delId) {
                $changesXml .= '<t:Delete>' .
                    '<t:ItemId Id="' . EwsSoap::escapeXml($delId) . '" ChangeKey="' . EwsSoap::escapeXml($delId) . '"/>' .
                    '</t:Delete>';
            }

            foreach ($cards as $card) {
                if (in_array($card['id'], $createdIds)) {
                    $changesXml .= '<t:Create>' .
                        EwsConverter::contactToXml($dom, $card, $folderId) .
                        '</t:Create>';
                }
            }
        } else {
            foreach ($cards as $card) {
                $changesXml .= '<t:Create>' .
                    EwsConverter::contactToXml($dom, $card, $folderId) .
                    '</t:Create>';
            }
        }

        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function contactDownloadPage(DOMDocument $dom, string $abId, int $position, int $maxChanges): array {
        $r = $this->client->call([
            ['ContactCard/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inAddressBook' => $abId],
                'position'  => $position,
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $qRes = $r[0][1] ?? [];
        $cards = $r[1][1]['list'] ?? [];
        $currentIds = array_column($cards, 'id');
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? $position) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CONTACTS . $abId;

        $changesXml = '';
        foreach ($cards as $card) {
            $changesXml .= '<t:Create>' .
                EwsConverter::contactToXml($dom, $card, $folderId) .
                '</t:Create>';
        }

        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function syncCalendarEvents(DOMDocument $dom, string $calId, array $prevIds, ?int $prevPos, int $maxChanges): array {
        if ($prevPos !== null) {
            return $this->calendarDownloadPage($dom, $calId, $prevPos, $maxChanges);
        }

        $r = $this->client->call([
            ['CalendarEvent/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inCalendar' => $calId],
                'sort'      => [['property' => 'start', 'isAscending' => false]],
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $qRes = $r[0][1] ?? [];
        $events = $r[1][1]['list'] ?? [];
        $currentIds = array_column($events, 'id');
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? 0) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CALENDAR . $calId;
        $changesXml = '';

        if ($prevIds) {
            $deletedIds = array_diff($prevIds, $currentIds);
            $createdIds = array_diff($currentIds, $prevIds);

            $this->logSync("calendar cal=$calId prev=" . json_encode($prevIds) .
                " current=" . json_encode($currentIds) .
                " deleted=" . json_encode(array_values($deletedIds)) .
                " created=" . json_encode(array_values($createdIds)));

            foreach ($deletedIds as $delId) {
                $changesXml .= '<t:Delete>' .
                    '<t:ItemId Id="' . EwsSoap::escapeXml($delId) . '" ChangeKey="' . EwsSoap::escapeXml($delId) . '"/>' .
                    '</t:Delete>';
            }

            foreach ($events as $event) {
                if (in_array($event['id'], $createdIds)) {
                    $changesXml .= '<t:Create>' .
                        EwsConverter::calendarToXml($dom, $event, $folderId) .
                        '</t:Create>';
                }
            }
        } else {
            foreach ($events as $event) {
                $changesXml .= '<t:Create>' .
                    EwsConverter::calendarToXml($dom, $event, $folderId) .
                    '</t:Create>';
            }
        }

        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function calendarDownloadPage(DOMDocument $dom, string $calId, int $position, int $maxChanges): array {
        $r = $this->client->call([
            ['CalendarEvent/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inCalendar' => $calId],
                'sort'      => [['property' => 'start', 'isAscending' => false]],
                'position'  => $position,
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $qRes = $r[0][1] ?? [];
        $events = $r[1][1]['list'] ?? [];
        $currentIds = array_column($events, 'id');
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? $position) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CALENDAR . $calId;

        $changesXml = '';
        foreach ($events as $event) {
            $changesXml .= '<t:Create>' .
                EwsConverter::calendarToXml($dom, $event, $folderId) .
                '</t:Create>';
        }

        return [$changesXml, $currentIds, $hasMore, $nextPos];
    }

    private function logSync(string $msg): void {
        if (defined('LOGLEVEL_DEBUG')) {
            error_log('[EWS-SYNC] ' . $msg);
        }
    }

    private function updateContact(string $id, DOMElement $updates): string {
        $jmapId = $this->jmapId($id);
        $patch = [];

        $setFieldEls = $updates->getElementsByTagNameNS(EwsSoap::NS_T, 'SetItemField');
        for ($i = 0; $i < $setFieldEls->length; $i++) {
            $field = $setFieldEls->item($i);
            $path = EwsSoap::getFirstChild($field, 'FieldURI');
            $fieldUri = $path ? $path->getAttribute('FieldURI') : '';
            $valueEl = EwsSoap::getFirstChild($field, 'Contact') ?: EwsSoap::getFirstChild($field, 'Value');

            if (!$fieldUri || !$valueEl) continue;

            switch ($fieldUri) {
                case 'contacts:GivenName':
                    $patch['name'] = ($patch['name'] ?? []) + ['given' => trim($valueEl->textContent)];
                    break;
                case 'contacts:Surname':
                    $patch['name'] = ($patch['name'] ?? []) + ['surname' => trim($valueEl->textContent)];
                    break;
                case 'contacts:CompanyName':
                    $patch['organizations'] = ['o1' => ['name' => trim($valueEl->textContent)]];
                    break;
                case 'contacts:JobTitle':
                    $patch['jobTitles'] = ['jt1' => ['name' => trim($valueEl->textContent)]];
                    break;
                case 'contacts:Department':
                    $patch['departments'] = ['d1' => ['name' => trim($valueEl->textContent)]];
                    break;
                case 'contacts:Email1Address':
                case 'contacts:Email1':
                    $patch['emails'] = ($patch['emails'] ?? []) + ['e1' => ['address' => trim($valueEl->textContent), 'contexts' => ['work' => true]]];
                    break;
                case 'contacts:Email2Address':
                case 'contacts:Email2':
                    $patch['emails'] = ($patch['emails'] ?? []) + ['e2' => ['address' => trim($valueEl->textContent), 'contexts' => ['home' => true]]];
                    break;
                case 'contacts:BusinessPhone':
                    $patch['phones'] = ($patch['phones'] ?? []) + ['p1' => ['number' => trim($valueEl->textContent), 'contexts' => ['work' => true]]];
                    break;
                case 'contacts:HomePhone':
                    $patch['phones'] = ($patch['phones'] ?? []) + ['p2' => ['number' => trim($valueEl->textContent), 'contexts' => ['home' => true]]];
                    break;
                case 'contacts:MobilePhone':
                    $patch['phones'] = ($patch['phones'] ?? []) + ['p3' => ['number' => trim($valueEl->textContent), 'features' => ['cell' => true]]];
                    break;
                case 'contacts:IMAddress':
                    $patch['onlineServices'] = ['im1' => ['uri' => trim($valueEl->textContent)]];
                    break;
                case 'contacts:BusinessHomePage':
                    $patch['links'] = ['l1' => ['@type' => 'Link', 'uri' => trim($valueEl->textContent)]];
                    break;
                case 'contacts:BusinessStreet':
                case 'contacts:BusinessCity':
                case 'contacts:BusinessState':
                case 'contacts:BusinessPostalCode':
                case 'contacts:BusinessCountry':
                    break;
            }
        }

        if (empty($patch)) {
            return '<m:ItemResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id, $jmapId) .
                '</m:ItemResponseMessage>';
        }

        try {
            $r = $this->client->call([
                ['ContactCard/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$jmapId => $patch],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

            $success = isset($r[0][1]['updated'][$jmapId]);
            return '<m:ItemResponseMessage ResponseClass="' . ($success ? 'Success' : 'Error') . '">' .
                '<m:MessageText></m:MessageText>' .
                '<m:ResponseCode>' . ($success ? 'NoError' : 'ErrorItemNotFound') . '</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id, $jmapId) .
                '</m:ItemResponseMessage>';
        } catch (\Throwable $e) {
            return '<m:ItemResponseMessage ResponseClass="Error">' .
                '<m:MessageText>' . EwsSoap::escapeXml($e->getMessage()) . '</m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:ItemResponseMessage>';
        }
    }

    private function updateCalendarEvent(string $id, DOMElement $updates): string {
        $jmapId = $this->jmapId($id);
        $patch = [];

        $setFieldEls = $updates->getElementsByTagNameNS(EwsSoap::NS_T, 'SetItemField');
        for ($i = 0; $i < $setFieldEls->length; $i++) {
            $field = $setFieldEls->item($i);
            $path = EwsSoap::getFirstChild($field, 'FieldURI');
            $fieldUri = $path ? $path->getAttribute('FieldURI') : '';
            $valueEl = EwsSoap::getFirstChild($field, 'CalendarItem');

            if (!$fieldUri || !$valueEl) continue;

            switch ($fieldUri) {
                case 'calendar:Subject':
                    $patch['title'] = trim($valueEl->textContent);
                    break;
                case 'calendar:Start':
                    $patch['start'] = gmdate('Y-m-d\TH:i:s', strtotime(trim($valueEl->textContent)));
                    break;
                case 'calendar:End':
                    if (isset($patch['start'])) {
                        $startTs = strtotime($patch['start']);
                        $endTs   = strtotime(trim($valueEl->textContent));
                        $patch['duration'] = JmapCalendarConverter::secondsToDuration(max(60, $endTs - $startTs));
                    }
                    break;
                case 'calendar:Location':
                    $patch['locations'] = ['l1' => ['@type' => 'Location', 'name' => trim($valueEl->textContent)]];
                    break;
                case 'calendar:IsAllDayEvent':
                    $patch['showWithoutTime'] = strtolower(trim($valueEl->textContent)) === 'true';
                    break;
                case 'calendar:LegacyFreeBusyStatus':
                    $status = match(strtolower(trim($valueEl->textContent))) {
                        'free'         => 'free',
                        'tentative'    => 'tentative',
                        'oof'          => 'unavailable',
                        'workingelse'  => 'busy',
                        default        => 'busy',
                    };
                    $patch['freeBusyStatus'] = $status;
                    break;
                case 'calendar:ReminderMinutesBeforeStart':
                    $mins = (int)trim($valueEl->textContent);
                    if ($mins > 0) {
                        $patch['alerts'] = ['al1' => [
                            '@type'   => 'Alert',
                            'trigger' => [
                                '@type'      => 'OffsetTrigger',
                                'offset'     => '-PT' . $mins . 'M',
                                'relativeTo' => 'start',
                            ],
                            'action'  => 'display',
                        ]];
                    }
                    break;
                case 'calendar:Sensitivity':
                    $sensitivity = match(strtolower(trim($valueEl->textContent))) {
                        'private'       => 'private',
                        'confidential'  => 'confidential',
                        default         => 'public',
                    };
                    $patch['privacy'] = $sensitivity;
                    break;
                case 'calendar:Categories':
                    break;
            }
        }

        if (empty($patch)) {
            return '<m:ItemResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id, $jmapId) .
                '</m:ItemResponseMessage>';
        }

        try {
            $r = $this->client->call([
                ['CalendarEvent/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$jmapId => $patch],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

            $success = isset($r[0][1]['updated'][$jmapId]);
            return '<m:ItemResponseMessage ResponseClass="' . ($success ? 'Success' : 'Error') . '">' .
                '<m:MessageText></m:MessageText>' .
                '<m:ResponseCode>' . ($success ? 'NoError' : 'ErrorItemNotFound') . '</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id, $jmapId) .
                '</m:ItemResponseMessage>';
        } catch (\Throwable $e) {
            return '<m:ItemResponseMessage ResponseClass="Error">' .
                '<m:MessageText>' . EwsSoap::escapeXml($e->getMessage()) . '</m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:ItemResponseMessage>';
        }
    }

    private function updateEmail(string $id, DOMElement $updates): string {
        $patch = ['keywords' => []];

        $setFieldEls = $updates->getElementsByTagNameNS(EwsSoap::NS_T, 'SetItemField');
        for ($i = 0; $i < $setFieldEls->length; $i++) {
            $field = $setFieldEls->item($i);
            $path = EwsSoap::getFirstChild($field, 'FieldURI');
            $fieldUri = $path ? $path->getAttribute('FieldURI') : '';

            switch ($fieldUri) {
                case 'message:IsRead':
                    $isRead = strtolower(EwsSoap::getChildValue($field, 'Value', EwsSoap::NS_T)) === 'true';
                    $patch['keywords']['$seen'] = $isRead;
                    break;
                case 'message:IsFlagged':
                    $isFlagged = strtolower(EwsSoap::getChildValue($field, 'Value', EwsSoap::NS_T)) === 'true';
                    $patch['keywords']['$flagged'] = $isFlagged;
                    break;
                case 'message:Categories':
                    $catEls = $field->getElementsByTagNameNS(EwsSoap::NS_T, 'String');
                    $cats = [];
                    for ($j = 0; $j < $catEls->length; $j++) {
                        $cats[trim($catEls->item($j)->textContent)] = true;
                    }
                    if ($cats) {
                        $patch['keywords']['$flagged'] = true;
                    }
                    break;
            }
        }

        if (empty($patch['keywords'])) {
            return '<m:ItemResponseMessage ResponseClass="Success">' .
                '<m:ResponseCode>NoError</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id) .
                '</m:ItemResponseMessage>';
        }

        try {
            $r = $this->client->call([
                ['Email/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$id => $patch],
                ], '0'],
            ]);

            $success = isset($r[0][1]['updated'][$id]);
            return '<m:ItemResponseMessage ResponseClass="' . ($success ? 'Success' : 'Error') . '">' .
                '<m:MessageText></m:MessageText>' .
                '<m:ResponseCode>' . ($success ? 'NoError' : 'ErrorItemNotFound') . '</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id) .
                '</m:ItemResponseMessage>';
        } catch (\Throwable $e) {
            return '<m:ItemResponseMessage ResponseClass="Error">' .
                '<m:MessageText>' . EwsSoap::escapeXml($e->getMessage()) . '</m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:ItemResponseMessage>';
        }
    }

    private function folderType(string $folderid): string {
        if (str_starts_with($folderid, EwsConverter::PFX_CONTACTS)) return 'contacts';
        if (str_starts_with($folderid, EwsConverter::PFX_CALENDAR)) return 'calendar';
        return 'mail';
    }

    private function jmapId(string $folderid): string {
        foreach ([EwsConverter::PFX_CONTACTS, EwsConverter::PFX_CALENDAR] as $pfx) {
            if (str_starts_with($folderid, $pfx)) return substr($folderid, strlen($pfx));
        }
        return $folderid;
    }

    private function getAllMailboxes(): array {
        $r = $this->client->call([
            ['Mailbox/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ]);
        $list = $r[0][1]['list'] ?? [];
        return array_map(fn($mb) => $mb + ['type' => 'mail'], $list);
    }

    private function getAllAddressBooks(): array {
        $r = $this->client->call([
            ['AddressBook/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $list = $r[0][1]['list'] ?? [];
        return array_map(fn($ab) => $ab + ['type' => 'contacts'], $list);
    }

    private function getAllCalendars(): array {
        $r = $this->client->call([
            ['Calendar/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $list = $r[0][1]['list'] ?? [];
        return array_map(fn($cal) => $cal + ['type' => 'calendar'], $list);
    }

    private function getCurrentQueryState(string $folderType, string $jmapId): string {
        try {
            switch ($folderType) {
                case 'contacts':
                    $r = $this->client->call([
                        ['ContactCard/query', [
                            'accountId' => $this->accountId,
                            'filter'    => ['inAddressBook' => $jmapId],
                            'limit'     => 1,
                        ], '0'],
                    ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                    return $r[0][1]['queryState'] ?? '';
                case 'calendar':
                    $r = $this->client->call([
                        ['CalendarEvent/query', [
                            'accountId' => $this->accountId,
                            'filter'    => ['inCalendar' => $jmapId],
                            'limit'     => 1,
                        ], '0'],
                    ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
                    return $r[0][1]['queryState'] ?? '';
                default:
                    $r = $this->client->call([
                        ['Email/query', [
                            'accountId' => $this->accountId,
                            'filter'    => ['inMailbox' => $jmapId],
                            'limit'     => 1,
                        ], '0'],
                    ]);
                    return $r[0][1]['queryState'] ?? '';
            }
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveFolderId(?string $folderId): string {
        if (!$folderId) return $this->accountId;

        $distinguishedMap = [
            'inbox'       => 'inbox',
            'drafts'      => 'drafts',
            'sentitems'   => 'sent',
            'deleteditems'=> 'trash',
            'calendar'    => 'calendar',
            'contacts'    => 'contacts',
            'junkemail'   => 'junk',
            'tasks'       => '',
            'notes'       => '',
            'root'        => '',
            'msgfolderroot' => '',
        ];

        $role = $distinguishedMap[strtolower($folderId)] ?? null;
        if ($role !== null) {
            if ($role === 'contacts') {
                $ab = $this->getDefaultAddressBookId();
                return $ab ? EwsConverter::PFX_CONTACTS . $ab : $this->accountId;
            }
            if ($role === 'calendar') {
                $cal = $this->getDefaultCalendarId();
                return $cal ? EwsConverter::PFX_CALENDAR . $cal : $this->accountId;
            }
            if ($role === '') return $this->accountId;
            return $this->getMailboxIdByRole($role) ?: $this->accountId;
        }

        return $folderId;
    }

    private function getDefaultAddressBookId(): ?string {
        $abs = $this->getAllAddressBooks();
        foreach ($abs as $ab) {
            if (!empty($ab['isDefault'])) return $ab['id'];
        }
        return $abs[0]['id'] ?? null;
    }

    private function resolveFolderIdFromEl(?DOMElement $parentEl): string {
        if (!$parentEl) return $this->accountId;

        $fidEl = $parentEl->getElementsByTagNameNS(EwsSoap::NS_T, 'FolderId')->item(0);
        if ($fidEl) {
            return $this->resolveFolderId($fidEl->getAttribute('Id'));
        }

        $dfidEl = $parentEl->getElementsByTagNameNS(EwsSoap::NS_T, 'DistinguishedFolderId')->item(0);
        if ($dfidEl) {
            return $this->resolveFolderId($dfidEl->getAttribute('Id'));
        }

        return $this->accountId;
    }

    private function getMailboxIdByRole(string $role): ?string {
        $mailboxes = $this->getAllMailboxes();
        foreach ($mailboxes as $mb) {
            if (($mb['role'] ?? '') === $role) return $mb['id'];
        }
        return null;
    }

    private function getDraftsMailboxId(): string {
        return $this->getMailboxIdByRole('drafts') ?: $this->accountId;
    }

    private function getSentMailboxId(): ?string {
        return $this->getMailboxIdByRole('sent');
    }

    private function getDefaultCalendarId(): ?string {
        $cals = $this->getAllCalendars();
        foreach ($cals as $cal) {
            if (!empty($cal['isDefault'])) return $cal['id'];
        }
        return $cals[0]['id'] ?? null;
    }

    private function getIdentityId(): string {
        try {
            $r = $this->client->call([
                ['Identity/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);
            return $r[0][1]['list'][0]['id'] ?? $this->accountId;
        } catch (\Throwable) {
            return $this->accountId;
        }
    }

    private function ewsMessageToMime(DOMElement $messageEl): string {
        $subject = EwsSoap::getChildValue($messageEl, 'Subject', EwsSoap::NS_T);
        $body    = EwsSoap::getChildValue($messageEl, 'Body', EwsSoap::NS_T);
        $to      = $this->getMailboxesFromEl($messageEl, 'ToRecipients');
        $cc      = $this->getMailboxesFromEl($messageEl, 'CcRecipients');
        $bcc     = $this->getMailboxesFromEl($messageEl, 'BccRecipients');
        $from    = $this->getMailboxesFromEl($messageEl, 'From');

        $mime  = "From: " . ($from[0] ?? '') . "\r\n";
        $mime .= "To: " . implode(', ', $to) . "\r\n";
        if ($cc)  $mime .= "Cc: " . implode(', ', $cc) . "\r\n";
        if ($bcc) $mime .= "Bcc: " . implode(', ', $bcc) . "\r\n";
        $mime .= "Subject: " . $subject . "\r\n";
        $mime .= "MIME-Version: 1.0\r\n";
        $mime .= "Content-Type: text/plain; charset=utf-8\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "\r\n";
        $mime .= chunk_split(base64_encode($body));
        return $mime;
    }

    private function getMailboxesFromEl(DOMElement $parent, string $localName): array {
        $mailboxes = [];
        $lists = $parent->getElementsByTagNameNS(EwsSoap::NS_T, $localName);
        for ($i = 0; $i < $lists->length; $i++) {
            $list = $lists->item($i);
            $mbEls = $list->getElementsByTagNameNS(EwsSoap::NS_T, 'Mailbox');
            for ($j = 0; $j < $mbEls->length; $j++) {
                $name  = $mbEls->item($j)->getElementsByTagNameNS(EwsSoap::NS_T, 'Name')->item(0)?->textContent ?? '';
                $email = $mbEls->item($j)->getElementsByTagNameNS(EwsSoap::NS_T, 'EmailAddress')->item(0)?->textContent ?? '';
                if ($email) {
                    $mailboxes[] = $name ? "$name <$email>" : $email;
                }
            }
        }
        return $mailboxes;
    }

    private function buildMimeFromEmail(array $email): string {
        $subject = $email['subject'] ?? '';
        $bodyText = '';

        if (!empty($email['textBody'])) {
            $partId = $email['textBody'][0]['partId'] ?? null;
            if ($partId && isset($email['bodyValues'][$partId]['value'])) {
                $bodyText = $email['bodyValues'][$partId]['value'];
            }
        }

        $fromAddr = '';
        $fromArr = array_values($email['from'] ?? []);
        if ($fromArr) {
            $fromAddr = ($fromArr[0]['name'] ?? '') && ($fromArr[0]['email'] ?? '')
                ? ($fromArr[0]['name'] . ' <' . $fromArr[0]['email'] . '>')
                : ($fromArr[0]['email'] ?? '');
        }

        $toList = [];
        foreach (array_values($email['to'] ?? []) as $r) {
            $toList[] = ($r['name'] ?? '') && ($r['email'] ?? '') ? ($r['name'] . ' <' . $r['email'] . '>') : ($r['email'] ?? '');
        }

        $mime  = "From: " . $fromAddr . "\r\n";
        $mime .= "To: " . implode(', ', $toList) . "\r\n";
        $mime .= "Subject: " . $subject . "\r\n";
        $mime .= "MIME-Version: 1.0\r\n";
        $mime .= "Content-Type: text/plain; charset=utf-8\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "\r\n";
        $mime .= chunk_split(base64_encode($bodyText));
        return $mime;
    }

    private function extractSearchTerm(DOMElement $restrictionEl): string {
        $xpath = new DOMXPath($restrictionEl->ownerDocument);
        $xpath->registerNamespace('t', EwsSoap::NS_T);

        $contains = $xpath->query('.//t:Contains/t:Constant', $restrictionEl);
        if ($contains && $contains->length > 0) {
            return $contains->item(0)->getAttribute('Value');
        }

        $isEqualTo = $xpath->query('.//t:IsEqualTo/t:FieldURIOrConstant/t:Constant', $restrictionEl);
        if ($isEqualTo && $isEqualTo->length > 0) {
            return $isEqualTo->item(0)->getAttribute('Value');
        }

        return '';
    }

    private function extToMime(string $ext): string {
        return match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'pdf'         => 'application/pdf',
            'doc'         => 'application/msword',
            'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'         => 'application/vnd.ms-excel',
            'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'         => 'application/zip',
            'txt'         => 'text/plain',
            'html', 'htm' => 'text/html',
            'eml'         => 'message/rfc822',
            default       => 'application/octet-stream',
        };
    }
}
