<?php

require_once __DIR__ . '/SyncState.php';
require_once __DIR__ . '/Converter.php';

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
            $folder = $this->syncResolveFolder($request);
            [$folderType, $jmapId, $syncFolderId, $account, $using] = $folder;
            $seenFields = [];
            foreach ($request->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, ['ItemShape', 'SyncFolderId', 'SyncState', 'MaxChangesReturned', 'SyncScope', 'Ignore'], true)) {
                    if ($child->namespaceURI !== EwsSoap::NS_M || isset($seenFields[$child->localName])) {
                        throw new EwsSyncException('ErrorInvalidParameter', 'Invalid or duplicate sync request field');
                    }
                    $seenFields[$child->localName] = true;
                    if ($child->localName === 'Ignore' || ($child->localName === 'SyncScope' && trim($child->textContent) !== 'NormalItems')) {
                        throw new EwsSyncException('ErrorInvalidParameter', 'Unsupported sync option');
                    }
                }
            }
            $stateEl = EwsSoap::getFirstChild($request, 'SyncState', EwsSoap::NS_M);
            $token = $stateEl ? trim($stateEl->textContent) : '';
            if ($stateEl && $token === '') throw new EwsSyncException('ErrorInvalidSyncStateData', 'Empty sync state');
            $maxEl = EwsSoap::getFirstChild($request, 'MaxChangesReturned', EwsSoap::NS_M);
            $maxText = $maxEl ? trim($maxEl->textContent) : '';
            if (!preg_match('/^[1-9][0-9]{0,2}$/D', $maxText) || (int)$maxText > 512) {
                throw new EwsSyncException('ErrorInvalidParameter', 'Invalid MaxChangesReturned');
            }
            $maxChanges = (int)$maxText;
            $shape = $this->syncItemShape($request);
            $session = $this->client->getSession();
            $principal = $session['username'] ?? null;
            if (!is_string($principal) || trim($principal) === '') throw new RuntimeException('JMAP session username unavailable');
            $scope = ['server' => $this->client->getApiUrl(), 'principal' => $principal, 'account' => $account, 'folder' => $syncFolderId];
            $store = new SqliteSyncStore(EWS_SYNC_STATE_DIR);
            $options = ['operation' => 'SyncFolderItems'];
            $source = $token === '' ? null : $token;
            $isPage = $source !== null && !$store->tokenIsComplete($scope, $source);
            if (!$isPage) {
                $round = $store->existingRun($scope, $source, $options, $maxChanges);
                if ($round === null) {
                    $base = $source === null ? [] : $store->baseline($scope, $source);
                    [$hashes, $objects] = $this->syncSnapshot($folderType, $jmapId, $account, $using);
                    if ($this->syncResolveFolder($request) !== $folder) throw new RuntimeException('Sync folder changed during scan');
                    $old = []; $records = []; $changes = [];
                    foreach ($base as $record) $old['i' . base64_encode($record['id'])] = $record;
                    foreach ($old as $key => $record) if (!isset($hashes[$key])) $changes[] = ['kind' => 'Delete', 'id' => $record['id']];
                    foreach ($hashes as $key => $hash) {
                        $object = $objects[$key]; $id = $object['id'];
                        if (!isset($old[$key])) $changes[] = ['kind' => 'Create', 'id' => $id];
                        elseif ($old[$key]['changeKey'] !== $hash) $changes[] = ['kind' => 'Update', 'id' => $id];
                        else $object = $old[$key]['object']; // Keep the same semantic version and avoid transient ordering differences.
                        $records[] = ['id' => $id, 'changeKey' => $hash, 'object' => $object];
                    }
                    $round = $store->advance($scope, $source, $options, $maxChanges, $records, $changes);
                }
                $token = $round;
            }
            $page = $store->page($scope, $token, $options, $maxChanges);
            $dom = new DOMDocument();
            $changesXml = '';
            foreach ($page['changes'] as $change) {
                $kind = $change['kind']; $rawId = $change['id'];
                $prefix = $folderType === 'contacts' ? EwsConverter::PFX_CONTACTS : ($folderType === 'calendar' ? EwsConverter::PFX_CALENDAR : '');
                if ($kind === 'Delete') {
                    $xml = EwsSoap::buildId($dom, $prefix . $rawId);
                } else {
                    $xml = $this->syncItemXml($dom, $folderType, $syncFolderId, $prefix . $rawId, $change['record'], $shape);
                }
                $changesXml .= '<t:' . $kind . '>' . $xml . '</t:' . $kind . '>';
            }
            $newSyncState = $page['nextToken'];
            $includesLast = $page['done'] ? 'true' : 'false';

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

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('SyncFolderItems', 'ErrorInvalidOperation', $e->getMessage());
        } catch (EwsSyncException $e) {
            return $this->errorResponse('SyncFolderItems', $e->ewsCode, $e->getMessage());
        } catch (SqliteSyncInvalidState $e) {
            return $this->errorResponse('SyncFolderItems', 'ErrorInvalidSyncStateData', 'Invalid or expired sync state');
        } catch (\Throwable $e) {
            error_log('SyncFolderItems failed (' . get_class($e) . ')');
            return $this->errorResponse('SyncFolderItems', 'ErrorInternalServerError', 'Sync could not be completed');
        }
    }

    private function syncItemShape(DOMElement $request): string {
        $shape = EwsSoap::getFirstChild($request, 'ItemShape', EwsSoap::NS_M);
        if (!$shape) throw new EwsSyncException('ErrorInvalidParameter', 'Missing ItemShape');
        $base = null;
        foreach ($shape->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($child->namespaceURI === EwsSoap::NS_T && $child->localName === 'BaseShape' && $base === null) {
                $base = trim($child->textContent);
            } else {
                throw new EwsSyncException('ErrorInvalidParameter', 'Unsupported ItemShape property');
            }
        }
        if (!in_array($base, ['IdOnly', 'Default', 'AllProperties'], true)) throw new EwsSyncException('ErrorInvalidParameter', 'Unsupported BaseShape');
        return $base;
    }

    private function syncItemXml(DOMDocument $dom, string $type, string $folder, string $id, mixed $record, string $shape): string {
        if (!is_array($record) || !is_array($record['object'] ?? null) || !is_string($record['changeKey'] ?? null) ||
            !hash_equals($record['changeKey'], EwsConverter::itemChangeKey($record['object']))) throw new RuntimeException('Stored item version mismatch');
        $tag = match ($type) { 'contacts' => 'Contact', 'calendar' => 'CalendarItem', default => 'Message' };
        // Calendar IdOnly must still pass the complete read conversion, including
        // XML validation, rather than the unrelated and stricter write guard.
        if ($shape === 'IdOnly' && $type !== 'calendar') return '<t:' . $tag . '>' . EwsSoap::buildId($dom, $id, $record['changeKey']) . '</t:' . $tag . '>';
        $xml = match ($type) {
            'contacts' => EwsConverter::contactToXml($dom, $record['object'], $folder),
            'calendar' => EwsConverter::calendarToXml($dom, $record['object'], $folder),
            default => EwsConverter::emailToXml($dom, $record['object'], $folder),
        };
        $wrapper = new DOMDocument();
        if (!$wrapper->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>', LIBXML_NONET)) throw new RuntimeException('Invalid converted item');
        $item = $wrapper->documentElement->firstElementChild;
        if (!$item || $item->namespaceURI !== EwsSoap::NS_T || $item->localName !== $tag) throw new RuntimeException('Invalid converted item type');
        $itemId = null; $remove = [];
        foreach ($item->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== EwsSoap::NS_T) continue;
            if ($child->localName === 'ItemId') {
                if ($itemId !== null) throw new RuntimeException('Duplicate converted item ID');
                $itemId = $child;
            }
            if (in_array($child->localName, ['Body', 'Attachments'], true)) $remove[] = $child;
        }
        if (!$itemId) throw new RuntimeException('Missing converted item ID');
        if ($shape === 'IdOnly') return '<t:' . $tag . '>' . EwsSoap::buildId($dom, $id, $record['changeKey']) . '</t:' . $tag . '>';
        $itemId->setAttribute('Id', $id); $itemId->setAttribute('ChangeKey', $record['changeKey']);
        foreach ($remove as $child) $item->removeChild($child);
        return $wrapper->saveXML($item);
    }

    /** Resolve and verify a single sync folder without the generic root/account fallback. */
    private function syncResolveFolder(DOMElement $request): array {
        $container = EwsSoap::getFirstChild($request, 'SyncFolderId', EwsSoap::NS_M);
        if (!$container) throw new EwsSyncException('ErrorInvalidSyncFolderId', 'Missing SyncFolderId');
        $elements = [];
        foreach ($container->childNodes as $child) if ($child instanceof DOMElement) $elements[] = $child;
        if (count($elements) !== 1 || $elements[0]->namespaceURI !== EwsSoap::NS_T ||
            !in_array($elements[0]->localName, ['FolderId', 'DistinguishedFolderId'], true) ||
            $elements[0]->getAttribute('Id') === '' || $elements[0]->getElementsByTagNameNS(EwsSoap::NS_T, 'Mailbox')->length) {
            throw new EwsSyncException('ErrorInvalidSyncFolderId', 'Invalid SyncFolderId');
        }
        $el = $elements[0];
        $id = $el->getAttribute('Id');
        $dist = $el->localName === 'DistinguishedFolderId';
        $roleMap = ['inbox' => 'inbox', 'drafts' => 'drafts', 'sentitems' => 'sent', 'deleteditems' => 'trash', 'junkemail' => 'junk'];
        $distId = strtolower($id);
        if ($dist && !isset($roleMap[$distId]) && !in_array($distId, ['contacts', 'calendar'], true)) {
            throw new EwsSyncException('ErrorInvalidSyncFolderId', 'Unsupported distinguished folder');
        }
        $type = $dist ? (in_array($distId, ['contacts', 'calendar'], true) ? $distId : 'mail') : $this->folderType($id);
        $cap = match ($type) { 'contacts' => JmapClient::CAP_CONTACTS, 'calendar' => JmapClient::CAP_CALENDARS, default => JmapClient::CAP_MAIL };
        $account = $this->client->getCapabilityAccountId($cap);
        $using = [JmapClient::CAP_CORE, $cap];
        $method = match ($type) { 'contacts' => 'AddressBook/get', 'calendar' => 'Calendar/get', default => 'Mailbox/get' };
        $raw = $dist ? null : $this->jmapId($id);
        if ($raw === '') throw new EwsSyncException('ErrorInvalidSyncFolderId', 'Empty folder ID');
        $r = $this->syncCall($method, ['accountId' => $account, 'ids' => $raw === null ? null : [$raw]], $using, $account);
        if (!isset($r['list']) || !is_array($r['list']) || !array_is_list($r['list']) || !isset($r['notFound']) || !is_array($r['notFound']) ||
            !array_is_list($r['notFound']) || ($dist && $r['notFound'] !== [])) {
            throw new RuntimeException('Invalid folder get response');
        }
        $selected = null;
        foreach ($r['list'] as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id']) || $item['id'] === '') throw new RuntimeException('Invalid folder object');
            if (!$dist && $item['id'] === $raw) $selected = $item['id'];
            elseif ($dist && $type === 'mail' && ($item['role'] ?? null) === $roleMap[$distId]) $selected = $item['id'];
            elseif ($dist && $type !== 'mail' && ($selected === null || !empty($item['isDefault']))) $selected = $item['id'];
        }
        if ($selected === null || (!$dist && (count($r['list']) !== 1 || count($r['notFound']) !== 0))) {
            throw new EwsSyncException('ErrorInvalidSyncFolderId', 'Folder not found');
        }
        $prefix = $type === 'contacts' ? EwsConverter::PFX_CONTACTS : ($type === 'calendar' ? EwsConverter::PFX_CALENDAR : '');
        return [$type, $selected, $prefix . $selected, $account, $using];
    }

    private function syncCall(string $method, array $args, array $using, string $account): array {
        $responses = $this->client->call([[$method, $args, 'sync']], $using);
        if (count($responses) !== 1 || !isset($responses[0][0], $responses[0][1], $responses[0][2]) ||
            $responses[0][0] !== $method || $responses[0][2] !== 'sync' || !is_array($responses[0][1]) ||
            ($responses[0][1]['accountId'] ?? null) !== $account) {
            throw new RuntimeException('JMAP ' . $method . ' failed or returned an invalid response');
        }
        return $responses[0][1];
    }

    private function syncQuery(string $type, string $folder, string $account, array $using): array {
        $method = match ($type) { 'contacts' => 'ContactCard/query', 'calendar' => 'CalendarEvent/query', default => 'Email/query' };
        $filter = match ($type) { 'contacts' => 'inAddressBook', 'calendar' => 'inCalendar', default => 'inMailbox' };
        $maxItems = defined('EWS_SYNC_MAX_ITEMS') ? EWS_SYNC_MAX_ITEMS : 100000;
        if (!is_int($maxItems) || $maxItems < 1 || $maxItems > 100000) throw new RuntimeException('Invalid sync item limit');
        $ids = []; $seen = []; $position = 0; $state = null; $total = null;
        do {
            $r = $this->syncCall($method, ['accountId' => $account, 'filter' => [$filter => $folder], 'position' => $position, 'limit' => 100, 'calculateTotal' => true], $using, $account);
            if (!isset($r['queryState'], $r['position'], $r['total'], $r['ids']) || !is_string($r['queryState']) || $r['queryState'] === '' ||
                !is_int($r['position']) || $r['position'] !== $position || !is_int($r['total']) || $r['total'] < 0 || $r['total'] > $maxItems ||
                !is_array($r['ids']) || !array_is_list($r['ids']) || count($r['ids']) > 100 ||
                ($state !== null && $state !== $r['queryState']) || ($total !== null && $total !== $r['total'])) {
                throw new RuntimeException('Invalid or changed JMAP query result');
            }
            $state = $r['queryState']; $total = $r['total'];
            foreach ($r['ids'] as $id) {
                if (!is_string($id) || $id === '' || strlen($id) > 1024) throw new RuntimeException('Invalid JMAP item ID');
                $key = 'i' . base64_encode($id);
                if (isset($seen[$key])) throw new RuntimeException('Duplicate JMAP item ID');
                $seen[$key] = true; $ids[] = $id;
            }
            $count = count($r['ids']); $position += $count;
            if ($position > $total || ($count === 0 && $position < $total)) throw new RuntimeException('Incomplete JMAP query');
        } while ($position < $total);
        return [$ids, $state];
    }

    private static function syncCanonical(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::syncCanonical($item);
        return $value;
    }

    private function syncSnapshot(string $type, string $folder, string $account, array $using): array {
        [$ids, $queryState] = $this->syncQuery($type, $folder, $account, $using);
        $method = match ($type) { 'contacts' => 'ContactCard/get', 'calendar' => 'CalendarEvent/get', default => 'Email/get' };
        $objects = []; $hashes = []; $bytes = 0;
        // Even an empty result must provide a collection state.
        $chunks = $ids ? array_chunk($ids, 100) : [[]];
        foreach ($chunks as $chunk) {
            $r = $this->syncCall($method, ['accountId' => $account, 'ids' => $chunk], $using, $account);
            if (!isset($r['state'], $r['list'], $r['notFound']) || !is_string($r['state']) || $r['state'] === '' ||
                !is_array($r['list']) || !array_is_list($r['list']) || !is_array($r['notFound']) || $r['notFound'] !== [] ||
                count($r['list']) !== count($chunk)) throw new RuntimeException('Incomplete Jmap get result');
            $expected = array_fill_keys(array_map(fn($id) => 'i' . base64_encode($id), $chunk), true);
            foreach ($r['list'] as $object) {
                if (!is_array($object) || !isset($object['id']) || !is_string($object['id'])) throw new RuntimeException('Invalid JMAP object');
                unset($object['bodyValues']);
                $key = 'i' . base64_encode($object['id']);
                if (!isset($expected[$key]) || isset($objects[$key])) throw new RuntimeException('Unexpected JMAP object');
                $encoded = json_encode(self::syncCanonical($object), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                $bytes += strlen($encoded);
                if (strlen($encoded) > 1048576 || $bytes > 33554432) throw new RuntimeException('Sync object size limit exceeded');
                $hash = EwsConverter::itemChangeKey($object);
                // Validate every calendar item before publishing any snapshot,
                // regardless of the requested shape or which page contains it.
                if ($type === 'calendar') $this->syncItemXml(new DOMDocument(), $type, EwsConverter::PFX_CALENDAR . $folder, EwsConverter::PFX_CALENDAR . $object['id'], ['object' => $object, 'changeKey' => $hash], 'Default');
                $hashes[$key] = $hash; $objects[$key] = $object;
            }
        }
        [$verifyIds, $verifyState] = $this->syncQuery($type, $folder, $account, $using);
        if ($ids !== $verifyIds || $queryState !== $verifyState) throw new RuntimeException('JMAP query changed during sync');
        ksort($hashes, SORT_STRING);
        return [$hashes, $objects];
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
            if (EwsSoap::getFirstChild($request, 'CalendarView', EwsSoap::NS_M)) {
                return (new EwsCalendarViewOperation($this->client))->findItem($request);
            }
            if ($request->getElementsByTagNameNS(EwsSoap::NS_M, 'CalendarView')->length || $request->getElementsByTagNameNS(EwsSoap::NS_T, 'CalendarView')->length) {
                return $this->errorResponse('FindItem', 'ErrorInvalidOperation', 'CalendarView is not supported');
            }
            $parents = EwsSoap::getFirstChild($request, 'ParentFolderIds');
            if ($parents) foreach ($parents->childNodes as $folder) {
                if ($folder instanceof DOMElement && (($folder->localName === 'FolderId' && str_starts_with($folder->getAttribute('Id'), EwsConverter::PFX_CALENDAR)) ||
                    ($folder->localName === 'DistinguishedFolderId' && strtolower($folder->getAttribute('Id')) === 'calendar'))) {
                    return $this->errorResponse('FindItem', 'ErrorInvalidOperation', 'Calendar search is not supported');
                }
            }
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
            $this->rejectOccurrenceIds($request);
            $itemShape = EwsSoap::getFirstChild($request, 'ItemShape');
            $wantMime = false;
            $baseShape = $itemShape ? EwsSoap::getChildValue($itemShape, 'BaseShape') : 'Default';
            if ($itemShape) {
                $furi = $itemShape->getElementsByTagNameNS(EwsSoap::NS_T, 'FieldURI');
                for ($i = 0; $i < $furi->length; $i++) {
                    if ($furi->item($i)->getAttribute('FieldURI') === 'item:MimeContent') {
                        $wantMime = true;
                        break;
                    }
                }
            }

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
                if (str_starts_with($id, 'ewsocc_')) {
                    // Occurrence IDs are untrusted and are never JMAP mail IDs.
                    continue;
                } elseif (str_starts_with($id, EwsConverter::PFX_CONTACTS)) {
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
            $mailFailed = $contactsFailed = $calendarFailed = false;

            if ($mailIds) {
                try {
                $r = $this->client->call([
                    ['Email/get', [
                        'accountId'          => $this->accountId,
                        'ids'                => $mailIds,
                        // Match the default Email/get property set used by sync.
                        'properties'         => null,
                        'fetchAllBodyValues' => true,
                    ], '0'],
                ]);
                if (($r[0][0] ?? null) !== 'Email/get' || !is_array($r[0][1]['list'] ?? null)) throw new RuntimeException('Email get failed');
                foreach ($r[0][1]['list'] ?? [] as $email) {
                    $emailsByJmapId[$email['id']] = $email;
                }
                } catch (\Throwable $e) { $mailFailed = true; }
            }

            if ($contactIds) {
                try {
                $r = $this->client->call([
                    ['ContactCard/get', [
                        'accountId'  => $this->contactsAccountId,
                        'ids'        => $contactIds,
                        'properties' => null,
                    ], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                if (($r[0][0] ?? null) !== 'ContactCard/get' || !is_array($r[0][1]['list'] ?? null)) throw new RuntimeException('Contact get failed');
                foreach ($r[0][1]['list'] ?? [] as $card) {
                    $contactsByJmapId[$card['id']] = $card;
                }
                } catch (\Throwable $e) { $contactsFailed = true; }
            }

            if ($calendarIds) {
                try {
                $r = $this->client->call([
                    ['CalendarEvent/get', [
                        'accountId'  => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
                        'ids'        => $calendarIds,
                        'properties' => null,
                    ], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
                if (($r[0][0] ?? null) !== 'CalendarEvent/get' || !is_array($r[0][1]['list'] ?? null)) throw new RuntimeException('Calendar get failed');
                foreach ($r[0][1]['list'] ?? [] as $event) {
                    $calendarsByJmapId[$event['id']] = $event;
                }
                } catch (\Throwable $e) { $calendarFailed = true; }
            }

            $responsesXml = '';
            foreach ($ids as $id) {
                if (str_starts_with($id, 'ewsocc_')) {
                    try {
                        try {
                            $resolved = (new EwsCalendarOccurrenceResolver($this->client))->resolve($id);
                        } catch (EwsCalendarOccurrenceNotFound $e) {
                            $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error"><m:ResponseCode>ErrorItemNotFound</m:ResponseCode></m:GetItemResponseMessage>';
                            continue;
                        }
                        // The resolver pins and refreshes the caller's session and
                        // returns the authorized calendar, not an account ID.
                        $account = $this->client->getSession()['primaryAccounts'][JmapClient::CAP_CALENDARS] ?? null;
                        if (!is_string($account) || $account === '') throw new RuntimeException('Calendar account unavailable');
                        $xml = EwsConverter::calendarOccurrenceToXml($dom, $resolved['event'], $resolved['master'], $account, $id, EwsConverter::PFX_CALENDAR . $resolved['calendar']['id']);
                        if ($baseShape === 'IdOnly') {
                            $xml = '<t:CalendarItem>' . EwsSoap::buildId($dom, $id, EwsConverter::itemChangeKey($resolved['master'])) . '</t:CalendarItem>';
                        }
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>' . $xml . '</m:Items></m:GetItemResponseMessage>';
                    } catch (\Throwable $e) {
                        // Only the resolver's typed, revalidated absence is
                        // distinguishable; other failures must remain concealed.
                        $code = 'ErrorInternalServerError';
                        try { EwsCalendarOccurrenceId::decode($id); }
                        catch (InvalidArgumentException $ignored) { $code = 'ErrorInvalidIdMalformed'; }
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error"><m:ResponseCode>' . $code . '</m:ResponseCode></m:GetItemResponseMessage>';
                    }
                } elseif (str_starts_with($id, EwsConverter::PFX_CONTACTS)) {
                    $jmapId = substr($id, strlen(EwsConverter::PFX_CONTACTS));
                    $card = $contactsByJmapId[$jmapId] ?? null;
                    if ($contactsFailed) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error"><m:ResponseCode>ErrorInternalServerError</m:ResponseCode></m:GetItemResponseMessage>';
                    } elseif (!$card) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::contactToXml($dom, $card, $this->itemParentFolderId($card, 'addressBookIds', EwsConverter::PFX_CONTACTS)) . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                } elseif (str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    $jmapId = substr($id, strlen(EwsConverter::PFX_CALENDAR));
                    $event = $calendarsByJmapId[$jmapId] ?? null;
                    if ($calendarFailed) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error"><m:ResponseCode>ErrorInternalServerError</m:ResponseCode></m:GetItemResponseMessage>';
                    } elseif (!$event) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::calendarToXml($dom, $event, $this->itemParentFolderId($event, 'calendarIds', EwsConverter::PFX_CALENDAR)) . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                } else {
                    $email = $emailsByJmapId[$id] ?? null;
                    if ($mailFailed) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error"><m:ResponseCode>ErrorInternalServerError</m:ResponseCode></m:GetItemResponseMessage>';
                    } elseif (!$email) {
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Error">' .
                            '<m:MessageText></m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>' .
                            '</m:GetItemResponseMessage>';
                    } else {
                        $mailboxId = array_key_first($email['mailboxIds'] ?? ['0' => true]);
                        // bodyValues is a fetch-dependent projection, not part of
                        // the canonical item version used for SyncFolderItems.
                        $bodyValues = is_array($email['bodyValues'] ?? null) ? $email['bodyValues'] : [];
                        unset($email['bodyValues']);
                        $mime = null;
                        if ($wantMime && !empty($email['blobId'])) {
                            try {
                                $raw = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
                                if (is_string($raw) && $raw !== '') {
                                    $mime = $raw;
                                }
                            } catch (\Throwable $e) {
                                $this->logDebug("MimeContent download failed for $id: " . $e->getMessage());
                            }
                        }
                        $responsesXml .= '<m:GetItemResponseMessage ResponseClass="Success">' .
                            '<m:ResponseCode>NoError</m:ResponseCode>' .
                            '<m:Items>' . EwsConverter::emailToXml($dom, $email, $mailboxId ?? '0', $bodyValues, $mime) . '</m:Items>' .
                            '</m:GetItemResponseMessage>';
                    }
                }
            }

            $bodyXml = '<m:GetItemResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:GetItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('GetItem', 'ErrorInvalidOperation', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('GetItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function createItem(DOMElement $request): string {
        try {
            $itemsEl = EwsSoap::getFirstChild($request, 'Items');
            if (!$itemsEl) {
                return $this->errorResponse('CreateItem', 'ErrorInvalidRequest', 'Missing Items element');
            }
            $items = [];
            foreach ($itemsEl->childNodes as $node) if ($node instanceof DOMElement) $items[] = $node;
            $calendarItems = $request->getElementsByTagNameNS(EwsSoap::NS_T, 'CalendarItem');
            if ($calendarItems->length || array_filter($items, fn($node) => $node->localName === 'CalendarItem')) {
                if (count($items) !== 1 || $items[0]->namespaceURI !== EwsSoap::NS_T || $items[0]->localName !== 'CalendarItem' || $calendarItems->length !== 1) throw new EwsCalendarSafetyException('Mixed or multiple calendar creates are not supported');
                $this->rejectCalendarContext($request);
                foreach ($request->childNodes as $child) {
                    if ($child instanceof DOMElement && ($child->namespaceURI !== EwsSoap::NS_M || !in_array($child->localName, ['Items', 'SavedItemFolderId'], true))) throw new EwsCalendarSafetyException('Unsupported calendar create option');
                }
                foreach ($request->attributes as $attribute) {
                    if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
                    if ($attribute->namespaceURI || $attribute->localName !== 'SendMeetingInvitations' || $attribute->value !== 'SendToNone') throw new EwsCalendarSafetyException('Unsupported calendar create option');
                }
                $containers = 0; $folders = 0;
                foreach ($request->childNodes as $child) if ($child instanceof DOMElement) {
                    if ($child->localName === 'Items') $containers++;
                    if ($child->localName === 'SavedItemFolderId') {
                        $folders++;
                        $ids = [];
                        foreach ($child->childNodes as $folder) if ($folder instanceof DOMElement) $ids[] = $folder;
                        if (count($ids) !== 1 || $ids[0]->namespaceURI !== EwsSoap::NS_T ||
                            !(($ids[0]->localName === 'FolderId' && str_starts_with($ids[0]->getAttribute('Id'), EwsConverter::PFX_CALENDAR) && strlen($ids[0]->getAttribute('Id')) > strlen(EwsConverter::PFX_CALENDAR)) ||
                              ($ids[0]->localName === 'DistinguishedFolderId' && $ids[0]->getAttribute('Id') === 'calendar')) || $ids[0]->firstElementChild) throw new EwsCalendarSafetyException('Invalid calendar folder');
                    }
                }
                if ($containers !== 1 || $folders > 1) throw new EwsCalendarSafetyException('Invalid calendar create request');
                EwsConverter::validateEwsEvent($items[0]);
            }
            $savedFolderIdEl = EwsSoap::getFirstChild($request, 'SavedItemFolderId');
            $parentFolderId = $this->resolveFolderIdFromEl($savedFolderIdEl);

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
                        $abId = $parentFolderId !== $this->accountId
                            ? $this->jmapId($parentFolderId)
                            : $this->getDefaultAddressBookId();
                        if ($abId === null || $abId === '') throw new RuntimeException('No address book available');
                        $card = EwsConverter::ewsContactToCard($itemNode, $abId);
                        try {
                            $r = $this->client->call([
                                ['ContactCard/set', [
                                    'accountId' => $this->contactsAccountId,
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
                        $explicitFolder = $savedFolderIdEl?->getElementsByTagNameNS(EwsSoap::NS_T, 'FolderId')->item(0);
                        if ($explicitFolder && $explicitFolder->getAttribute('Id') === '') throw new RuntimeException('Empty calendar ID');
                        $calId = $explicitFolder || $parentFolderId !== $this->accountId
                            ? $this->jmapId($parentFolderId)
                            : $this->getDefaultCalendarId(true);
                        if ($calId === null || $calId === '') throw new RuntimeException('No calendar available');
                        $event = EwsConverter::ewsEventToJmap($itemNode, $calId);
                        return $this->createCalendarEvent($event);
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

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('CreateItem', 'ErrorInvalidOperation', $e->getMessage());
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

            $this->rejectOccurrenceIds($request, true);
            $allChanges = $itemChanges->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemChange');
            $hasCalendar = false;
            foreach ($allChanges as $change) {
                $itemId = EwsSoap::getFirstChild($change, 'ItemId', EwsSoap::NS_T);
                if ($itemId && str_starts_with($itemId->getAttribute('Id'), EwsConverter::PFX_CALENDAR)) $hasCalendar = true;
            }
            if ($hasCalendar) {
                if ($allChanges->length !== 1) throw new EwsCalendarSafetyException('Mixed or multiple calendar updates are not supported');
                $this->rejectCalendarContext($request);
                $requestChildren = [];
                foreach ($request->childNodes as $child) if ($child instanceof DOMElement) $requestChildren[] = $child;
                if (count($requestChildren) !== 1 || $requestChildren[0] !== $itemChanges || $itemChanges->namespaceURI !== EwsSoap::NS_M) throw new EwsCalendarSafetyException('Unsupported calendar update option');
                foreach ($request->attributes as $attribute) {
                    if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
                    if ($attribute->namespaceURI || !(($attribute->localName === 'SendMeetingInvitationsOrCancellations' && $attribute->value === 'SendToNone') ||
                        ($attribute->localName === 'ConflictResolution' && in_array($attribute->value, ['NeverOverwrite', 'AutoResolve'], true)))) throw new EwsCalendarSafetyException('Unsupported calendar update option');
                }
                $changeChildren = [];
                foreach ($allChanges->item(0)->childNodes as $child) if ($child instanceof DOMElement) $changeChildren[] = $child;
                $directChanges = [];
                foreach ($itemChanges->childNodes as $child) if ($child instanceof DOMElement) $directChanges[] = $child;
                if (count($directChanges) !== 1 || $directChanges[0] !== $allChanges->item(0) || count($changeChildren) !== 2 ||
                    $changeChildren[0]->namespaceURI !== EwsSoap::NS_T || $changeChildren[0]->localName !== 'ItemId' ||
                    $changeChildren[1]->namespaceURI !== EwsSoap::NS_T || $changeChildren[1]->localName !== 'Updates') throw new EwsCalendarSafetyException('Invalid calendar update request');
                $updates = EwsSoap::getFirstChild($allChanges->item(0), 'Updates', EwsSoap::NS_T);
                if (!$updates) throw new EwsCalendarSafetyException('Missing calendar updates');
                $this->calendarUpdatePatch($updates);
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
                    $response = $this->updateContact($id, $updates, $changeKey);
                } elseif (str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    $response = $this->updateCalendarEvent($id, $updates, $changeKey);
                } else {
                    $response = $this->updateEmail($id, $updates);
                }

                $responsesXml .= $response;
            }

            $bodyXml = '<m:UpdateItemResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:UpdateItemResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('UpdateItem', 'ErrorInvalidPropertySet', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('UpdateItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function deleteItem(DOMElement $request): string {
        try {
            $this->rejectOccurrenceIds($request, true);
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
            foreach ($ids as $id) if (str_starts_with($id, EwsConverter::PFX_CALENDAR)) throw new EwsCalendarSafetyException('Calendar deletion is not supported');

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
                                    'accountId' => $this->contactsAccountId,
                                    'destroy'   => [$jmapId],
                                ], '0'],
                            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                            $success = in_array($jmapId, $r[0][1]['destroyed'] ?? [], true);
                            break;

                        case 'calendar':
                            $r = $this->client->call([
                                ['CalendarEvent/set', [
                                    'accountId' => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
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

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('DeleteItem', 'ErrorInvalidOperation', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('DeleteItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function sendItem(DOMElement $request): string {
        try {
            $this->rejectOccurrenceIds($request, true);
            $savedFolderId = $this->resolveFolderIdFromEl(EwsSoap::getFirstChild($request, 'SavedItemFolderId'));

            $itemIdsEl = EwsSoap::getFirstChild($request, 'ItemIds');
            $idEls = $itemIdsEl ? $itemIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId') : null;

            if (!$idEls || $idEls->length === 0) {
                return $this->errorResponse('SendItem', 'ErrorInvalidRequest', 'Missing ItemIds');
            }

            // Validate the entire batch before sending any message.
            foreach ($idEls as $itemId) {
                $id = $itemId->getAttribute('Id');
                if ($id === '' || str_starts_with($id, EwsConverter::PFX_CONTACTS) ||
                    str_starts_with($id, EwsConverter::PFX_CALENDAR)) {
                    return $this->errorResponse('SendItem', 'ErrorInvalidOperation', 'Only mail items can be sent');
                }
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

        } catch (EwsCalendarSafetyException $e) {
            return $this->errorResponse('SendItem', 'ErrorInvalidOperation', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('SendItem', 'ErrorInternalServerError', $e->getMessage());
        }
    }

    public function getAttachment(DOMElement $request): string {
        try {
            $attachmentIdsEl = EwsSoap::getFirstChild($request, 'AttachmentIds');
            $idEls = $attachmentIdsEl ? $attachmentIdsEl->getElementsByTagNameNS(EwsSoap::NS_T, 'AttachmentId') : null;

            // Garantieer altijd een geldig antwoordskelet voor eM Client
            $fallbackResponse = '<m:AttachmentResponseMessage ResponseClass="Error">' .
                '<m:MessageText>No attachment IDs provided</m:MessageText>' .
                '<m:ResponseCode>ErrorInvalidAttachmentId</m:ResponseCode>' .
                '</m:AttachmentResponseMessage>';

            $responsesXml = '';

            if ($idEls && $idEls->length > 0) {
                for ($i = 0; $i < $idEls->length; $i++) {
                    try {
                        $attId = $idEls->item($i)->getAttribute('Id');
                        $parts = explode('||', $attId, 3);
                        $blobId = $parts[1] ?? '';
                        $name   = $parts[2] ?? 'attachment';

                        if (!$blobId) {
                            $responsesXml .= $fallbackResponse;
                            continue;
                        }

                        $data = @$this->client->downloadBlob($blobId, $name);
                        $contentType = $this->extToMime(strtolower(pathinfo($name, PATHINFO_EXTENSION)));

                        if ($data === false || $data === '') {
                            $responsesXml .= $fallbackResponse;
                        } else {
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
                        }
                    } catch (\Throwable $e2) {
                        $responsesXml .= $fallbackResponse;
                    }
                }
            }

            // Altijd een validatie-safeguard: als responsesXml leeg of ongeldig is, fallback gebruiken
            if (!$responsesXml || strpos($responsesXml, 'm:AttachmentResponseMessage') === false) {
                $responsesXml = $fallbackResponse;
            }

            $bodyXml = '<m:GetAttachmentResponse>' .
                '<m:ResponseMessages>' . $responsesXml . '</m:ResponseMessages>' .
                '</m:GetAttachmentResponse>';

            return EwsSoap::buildSoapResponse($bodyXml);

        } catch (\Throwable $e) {
            // Nog een laatste fallback via errorResponse, maar vorm it op zodat de structuur klopt
            $msgName = 'AttachmentResponseMessage';
            $bodyXml = '<m:GetAttachmentResponse>' .
                '<m:ResponseMessages>' .
                '<m:' . $msgName . ' ResponseClass="Error">' .
                '<m:MessageText>Internal error retrieving attachments</m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:' . $msgName . '>' .
                '</m:ResponseMessages>' .
                '</m:GetAttachmentResponse>';
            return EwsSoap::buildSoapResponse($bodyXml);
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

            $addressBookId = $this->getDefaultAddressBookId();
            $r = $addressBookId !== null && $addressBookId !== '' ? $this->client->call([
                ['ContactCard/query', [
                    'accountId' => $this->contactsAccountId,
                    'filter'    => ['inAddressBook' => $addressBookId],
                    'limit'     => 500,
                ], 'q'],
                ['ContactCard/get', [
                    'accountId'  => $this->contactsAccountId,
                    '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                    'properties' => $returnFullContactData ? null : ['id', 'name', 'emails'],
                ], 'g'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]) : [];

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
        // Availability cannot be inferred safely without recurrence expansion,
        // mailbox identity, complete results and time-zone interpretation.
        $count = max(1, $request->getElementsByTagNameNS(EwsSoap::NS_T, 'MailboxData')->length);
        $response = '<m:FreeBusyResponse><m:ResponseMessage ResponseClass="Error">' .
            '<m:MessageText>Availability is not supported</m:MessageText>' .
            '<m:ResponseCode>ErrorInvalidOperation</m:ResponseCode></m:ResponseMessage></m:FreeBusyResponse>';
        return EwsSoap::buildSoapResponse('<m:GetUserAvailabilityResponse><m:FreeBusyResponseArray>' .
            str_repeat($response, $count) . '</m:FreeBusyResponseArray></m:GetUserAvailabilityResponse>');
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
                'accountId' => $this->contactsAccountId,
                'filter'    => ['inAddressBook' => $abId],
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->contactsAccountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $qRes = $r[0][1] ?? [];
        $cards = $r[1][1]['list'] ?? [];
        $currentIds = array_map(fn($c) => EwsConverter::PFX_CONTACTS . $c['id'], $cards);
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? 0) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CONTACTS . $abId;
        $changesXml = '';

        if ($prevIds) {
            $deletedIds = array_diff($prevIds, $currentIds);
            $createdJmapIds = array_map(fn($ci) => substr($ci, strlen(EwsConverter::PFX_CONTACTS)), array_diff($currentIds, $prevIds));

            $this->logSync("contacts ab=$abId prev=" . json_encode($prevIds) .
                " current=" . json_encode($currentIds) .
                " deleted=" . json_encode(array_values($deletedIds)) .
                " created=" . json_encode(array_values($createdJmapIds)));

            foreach ($deletedIds as $delId) {
                $changesXml .= '<t:Delete>' .
                    '<t:ItemId Id="' . EwsSoap::escapeXml($delId) . '" ChangeKey="' . EwsSoap::escapeXml($delId) . '"/>' .
                    '</t:Delete>';
            }

            foreach ($cards as $card) {
                if (in_array(EwsConverter::PFX_CONTACTS . $card['id'], $createdJmapIds)) {
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
                'accountId' => $this->contactsAccountId,
                'filter'    => ['inAddressBook' => $abId],
                'position'  => $position,
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->contactsAccountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $qRes = $r[0][1] ?? [];
        $cards = $r[1][1]['list'] ?? [];
        $currentIds = array_map(fn($c) => EwsConverter::PFX_CONTACTS . $c['id'], $cards);
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
                'accountId' => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
                'filter'    => ['inCalendar' => $calId],
                'sort'      => [['property' => 'start', 'isAscending' => false]],
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['CalendarEvent/get', [
                'accountId'  => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
                '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $qRes = $r[0][1] ?? [];
        $events = $r[1][1]['list'] ?? [];
        $currentIds = array_map(fn($e) => EwsConverter::PFX_CALENDAR . $e['id'], $events);
        $hasMore = !empty($qRes['more']);
        $nextPos = $hasMore ? ((int)($qRes['position'] ?? 0) + count($currentIds)) : null;
        $folderId = EwsConverter::PFX_CALENDAR . $calId;
        $changesXml = '';

        if ($prevIds) {
            $deletedIds = array_diff($prevIds, $currentIds);
            $createdJmapIds = array_map(fn($ci) => substr($ci, strlen(EwsConverter::PFX_CALENDAR)), array_diff($currentIds, $prevIds));

            $this->logSync("calendar cal=$calId prev=" . json_encode($prevIds) .
                " current=" . json_encode($currentIds) .
                " deleted=" . json_encode(array_values($deletedIds)) .
                " created=" . json_encode(array_values($createdJmapIds)));

            foreach ($deletedIds as $delId) {
                $changesXml .= '<t:Delete>' .
                    '<t:ItemId Id="' . EwsSoap::escapeXml($delId) . '" ChangeKey="' . EwsSoap::escapeXml($delId) . '"/>' .
                    '</t:Delete>';
            }

            foreach ($events as $event) {
                if (in_array(EwsConverter::PFX_CALENDAR . $event['id'], $createdJmapIds)) {
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
                'accountId' => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
                'filter'    => ['inCalendar' => $calId],
                'sort'      => [['property' => 'start', 'isAscending' => false]],
                'position'  => $position,
                'limit'     => max(1, min($maxChanges, JMAP_MAX_OBJECTS_PER_REQUEST)),
            ], 'q'],
            ['CalendarEvent/get', [
                'accountId'  => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
                '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                'properties' => null,
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $qRes = $r[0][1] ?? [];
        $events = $r[1][1]['list'] ?? [];
        $currentIds = array_map(fn($e) => EwsConverter::PFX_CALENDAR . $e['id'], $events);
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

    private function itemParentFolderId(array $item, string $property, string $prefix): ?string {
        $memberships = $item[$property] ?? null;
        if (is_array($memberships)) {
            foreach ($memberships as $id => $member) {
                if ((is_string($id) || is_int($id)) && (string)$id !== '' && $member === true) {
                    return $prefix . (string)$id;
                }
            }
        }
        return null;
    }

    private function logDebug(string $msg): void {
        if (defined('LOGLEVEL_DEBUG')) {
            error_log('[EWS-DEBUG] ' . $msg);
        }
    }

    private function logSync(string $msg): void {
        if (defined('LOGLEVEL_DEBUG')) {
            error_log('[EWS-SYNC] ' . $msg);
        }
    }

    private function updateContact(string $id, DOMElement $updates, string $changeKey): string {
        $jmapId = $this->jmapId($id);
        $patch = [];
        $nameChanges = [];
        $indexedChanges = [];
        $legacyChanges = [];
        $nameFields = [
            'contacts:GivenName' => 'given', 'contacts:Surname' => 'surname',
            'contacts:MiddleName' => 'additional', 'contacts:Title' => 'prefix',
            'contacts:Suffix' => 'suffix', 'contacts:DisplayName' => 'full',
        ];

        foreach ($updates->childNodes as $field) {
            if (!$field instanceof DOMElement) continue;
            if ($field->namespaceURI !== EwsSoap::NS_T || !in_array($field->localName, ['SetItemField', 'DeleteItemField'], true)) {
                return $this->contactUpdateError('ErrorInvalidPropertySet', 'Unsupported contact update operation');
            }
            $elements = [];
            foreach ($field->childNodes as $child) if ($child instanceof DOMElement) $elements[] = $child;
            if (isset($elements[0]) && $elements[0]->namespaceURI === EwsSoap::NS_T && $elements[0]->localName === 'IndexedFieldURI') {
                try {
                    $indexedChanges[] = $this->parseContactIndexedUpdate($field, $elements);
                } catch (DomainException $e) {
                    return $this->contactUpdateError('ErrorInvalidPropertySet', $e->getMessage());
                }
                continue;
            }
            if ($field->localName !== 'SetItemField') return $this->contactUpdateError('ErrorInvalidPropertySet', 'Unsupported contact update operation');
            $path = null; $valueEl = null;
            foreach ($field->childNodes as $child) {
                if (!$child instanceof DOMElement) continue;
                if ($child->namespaceURI === EwsSoap::NS_T && $child->localName === 'FieldURI' && $path === null) {
                    $path = $child;
                } elseif ($child->namespaceURI === EwsSoap::NS_T && in_array($child->localName, ['Contact', 'Value'], true) && $valueEl === null) {
                    $valueEl = $child;
                } else {
                    return $this->contactUpdateError('ErrorInvalidPropertySet', 'Unsupported contact property path or update');
                }
            }
            $fieldUri = $path ? $path->getAttribute('FieldURI') : '';
            if ($fieldUri === '' || !$valueEl) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Missing contact property path or value');

            if (isset($nameFields[$fieldUri])) {
                if (!$valueEl) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Missing contact name value');
                $nameValue = $valueEl->localName === 'Contact'
                    ? EwsSoap::getFirstChild($valueEl, substr($fieldUri, strpos($fieldUri, ':') + 1), EwsSoap::NS_T)
                    : $valueEl;
                if (!$nameValue) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Missing contact name value');
                $nameChanges[$nameFields[$fieldUri]] = trim($nameValue->textContent);
                continue;
            }
            switch ($fieldUri) {
                case 'contacts:CompanyName':
                case 'contacts:JobTitle':
                case 'contacts:Department':
                case 'contacts:BusinessHomePage':
                    $map = match ($fieldUri) { 'contacts:CompanyName' => 'organizations', 'contacts:JobTitle' => 'jobTitles', 'contacts:Department' => 'departments', default => 'links' };
                    if (isset($legacyChanges[$map])) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Duplicate contact update');
                    $valueNode = $valueEl;
                    if ($valueEl->childElementCount) {
                        if ($valueEl->localName !== 'Contact' || $valueEl->childElementCount !== 1) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                        $valueNode = $valueEl->firstElementChild;
                        if ($valueNode->namespaceURI !== EwsSoap::NS_T || $valueNode->localName !== substr($fieldUri, strlen('contacts:')) || $valueNode->childElementCount) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                        foreach ($valueEl->childNodes as $node) if (($node instanceof DOMText || $node instanceof DOMCdataSection) && trim($node->textContent) !== '') return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                    }
                    $value = trim($valueNode->textContent);
                    if ($value === '') return $this->contactUpdateError('ErrorInvalidPropertySet', 'Empty contact value');
                    $legacyChanges[$map] = $value;
                    break;
                case 'contacts:Email1Address':
                case 'contacts:Email1':
                case 'contacts:Email2Address':
                case 'contacts:Email2':
                case 'contacts:BusinessPhone':
                case 'contacts:HomePhone':
                case 'contacts:MobilePhone':
                case 'contacts:IMAddress':
                    $valueNode = $valueEl;
                    if ($valueEl->childElementCount) {
                        if ($valueEl->localName !== 'Contact' || $valueEl->childElementCount !== 1) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                        $valueNode = $valueEl->firstElementChild;
                        if ($valueNode->namespaceURI !== EwsSoap::NS_T || $valueNode->localName !== substr($fieldUri, strlen('contacts:')) || $valueNode->childElementCount) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                        foreach ($valueEl->childNodes as $node) if (($node instanceof DOMText || $node instanceof DOMCdataSection) && trim($node->textContent) !== '') return $this->contactUpdateError('ErrorInvalidPropertySet', 'Invalid contact value');
                    }
                    $value = trim($valueNode->textContent);
                    if ($value === '') return $this->contactUpdateError('ErrorInvalidPropertySet', 'Empty contact value');
                    if (str_starts_with($fieldUri, 'contacts:Email')) {
                        $map = 'emails'; $index = str_contains($fieldUri, 'Email1') ? 'EmailAddress1' : 'EmailAddress2';
                    } elseif ($fieldUri === 'contacts:IMAddress') {
                        if (!preg_match('/^(?:im|xmpp|sip|sips|matrix):/i', $value)) return $this->contactUpdateError('ErrorInvalidPropertySet', 'IM address must have a supported URI scheme');
                        $map = 'onlineServices'; $index = 'ImAddress1';
                    } else {
                        $map = 'phones'; $index = substr($fieldUri, strlen('contacts:'));
                    }
                    $indexedChanges[] = ['map' => $map, 'index' => $index, 'part' => null, 'delete' => false, 'value' => $value];
                    break;
                case 'contacts:BusinessStreet':
                case 'contacts:BusinessCity':
                case 'contacts:BusinessState':
                case 'contacts:BusinessPostalCode':
                case 'contacts:BusinessCountry':
                default:
                    return $this->contactUpdateError('ErrorInvalidPropertySet', 'Unsupported contact property: ' . $fieldUri);
            }
        }

        if (empty($patch) && empty($nameChanges) && empty($indexedChanges) && empty($legacyChanges)) {
            return $this->contactUpdateError('ErrorInvalidPropertySet', 'No supported contact updates');
        }

        $seenIndexed = [];
        foreach ($indexedChanges as $change) {
            if (array_key_exists($change['map'], $patch)) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Conflicting contact updates');
            $identity = $change['map'] . '/' . $change['index'] . '/' . ($change['part'] ?? '');
            if (isset($seenIndexed[$identity])) return $this->contactUpdateError('ErrorInvalidPropertySet', 'Duplicate indexed update');
            $seenIndexed[$identity] = true;
        }

        try {
            $state = null;
            {
                $get = $this->client->call([
                    ['ContactCard/get', ['accountId' => $this->contactsAccountId, 'ids' => [$jmapId], 'properties' => null], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                if (($get[0][0] ?? '') !== 'ContactCard/get' || !is_array($get[0][1] ?? null)) {
                    return $this->contactUpdateError('ErrorInternalServerError', 'Contact read failed');
                }
                $result = $get[0][1];
                if (!is_string($result['state'] ?? null) || $result['state'] === '' || !is_array($result['list'] ?? null)) {
                    return $this->contactUpdateError('ErrorInternalServerError', 'Contact read did not include state and list');
                }
                if (count($result['list']) === 0 && in_array($jmapId, $result['notFound'] ?? [], true)) {
                    return $this->contactUpdateError('ErrorItemNotFound', 'Contact not found');
                }
                if (count($result['list']) !== 1 || ($result['list'][0]['id'] ?? null) !== $jmapId) {
                    return $this->contactUpdateError('ErrorInternalServerError', 'Unexpected contact read result');
                }
                $state = $result['state'];
                if ($changeKey !== '' && !hash_equals(EwsConverter::itemChangeKey($result['list'][0]), $changeKey)) return $this->contactUpdateError('ErrorIrresolvableConflict', 'Contact item changed');
                if ($nameChanges) $patch['name'] = $this->mergeContactName($result['list'][0], $nameChanges);
                if ($indexedChanges) $patch += $this->mergeContactIndexed($result['list'][0], $indexedChanges, $changeKey !== '');
                if ($legacyChanges) $patch += $this->mergeContactLegacyMaps($result['list'][0], $legacyChanges);
            }
            $arguments = ['accountId' => $this->contactsAccountId, 'update' => [$jmapId => $patch]];
            if ($state !== null) $arguments['ifInState'] = $state;
            $r = $this->client->call([
                ['ContactCard/set', $arguments, '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

            $type = $r[0][1]['type'] ?? '';
            if (($r[0][0] ?? '') === 'error') {
                return $this->contactUpdateError($type === 'stateMismatch' ? 'ErrorIrresolvableConflict' : 'ErrorInternalServerError', 'Contact update failed: ' . $type);
            }
            if (($r[0][0] ?? '') !== 'ContactCard/set' || !is_array($r[0][1] ?? null)) {
                return $this->contactUpdateError('ErrorInternalServerError', 'Unexpected contact update result');
            }
            $notUpdated = $r[0][1]['notUpdated'] ?? null;
            $success = is_array($r[0][1]['updated'] ?? null) && array_key_exists($jmapId, $r[0][1]['updated']) && !(is_array($notUpdated) && array_key_exists($jmapId, $notUpdated));
            if (!$success) {
                $type = $r[0][1]['notUpdated'][$jmapId]['type'] ?? '';
                return $this->contactUpdateError($type === 'notFound' ? 'ErrorItemNotFound' : ($type === 'stateMismatch' ? 'ErrorIrresolvableConflict' : 'ErrorInternalServerError'), 'Contact update failed: ' . $type);
            }
            $newState = $r[0][1]['newState'] ?? null;
            if (!is_string($newState) || $newState === '') return $this->contactUpdateError('ErrorInternalServerError', 'Contact updated, but new state is unavailable');
            try {
                $after = $this->client->call([
                    ['ContactCard/get', ['accountId' => $this->contactsAccountId, 'ids' => [$jmapId], 'properties' => null], '0'],
                ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
            } catch (\Throwable $e) {
                return $this->contactUpdateError('ErrorInternalServerError', 'Contact updated, but its resulting version cannot be read');
            }
            $afterData = $after[0][1] ?? null;
            if (($after[0][0] ?? '') !== 'ContactCard/get' || !is_array($afterData) || ($afterData['state'] ?? null) !== $newState || !is_array($afterData['list'] ?? null) || count($afterData['list']) !== 1 || ($afterData['list'][0]['id'] ?? null) !== $jmapId) {
                return $this->contactUpdateError('ErrorIrresolvableConflict', 'Contact updated, but its resulting version cannot be determined');
            }
            $updatedKey = EwsConverter::itemChangeKey($afterData['list'][0]);
            return '<m:ItemResponseMessage ResponseClass="' . ($success ? 'Success' : 'Error') . '">' .
                '<m:MessageText></m:MessageText>' .
                '<m:ResponseCode>' . ($success ? 'NoError' : 'ErrorItemNotFound') . '</m:ResponseCode>' .
                EwsSoap::buildId(new DOMDocument(), $id, $updatedKey) .
                '</m:ItemResponseMessage>';
        } catch (DomainException $e) {
            return $this->contactUpdateError($e->getCode() === 1 ? 'ErrorChangeKeyRequired' : 'ErrorInvalidPropertySet', $e->getMessage());
        } catch (\Throwable $e) {
            return '<m:ItemResponseMessage ResponseClass="Error">' .
                '<m:MessageText>' . EwsSoap::escapeXml($e->getMessage()) . '</m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:ItemResponseMessage>';
        }
    }

    private function contactUpdateError(string $code, string $message): string {
        return '<m:ItemResponseMessage ResponseClass="Error"><m:MessageText>' . EwsSoap::escapeXml($message) .
            '</m:MessageText><m:ResponseCode>' . EwsSoap::escapeXml($code) . '</m:ResponseCode></m:ItemResponseMessage>';
    }

    private function mergeContactLegacyMaps(array $card, array $changes): array {
        $patch = [];
        if (isset($card['localizations']) && !is_array($card['localizations'])) throw new DomainException('Unsupported contact localizations');
        foreach ($changes as $map => $value) {
            foreach (($card['localizations'] ?? []) as $localization) {
                if (!is_array($localization)) throw new DomainException('Unsupported contact localizations');
                foreach ($localization as $path => $_) if ($path === $map || str_starts_with((string)$path, $map . '/')) throw new DomainException('Localized contact data cannot be safely updated');
            }
            $entries = $card[$map] ?? [];
            if (!is_array($entries) || count($entries) > 1) throw new DomainException('Ambiguous contact ' . $map);
            if ($map === 'jobTitles' && !$entries && !empty($card['titles'])) throw new DomainException('Legacy titles cannot be safely updated');
            $property = $map === 'links' ? 'uri' : 'name';
            if (!$entries) {
                $entry = [$property => $value];
                if ($map === 'links') $entry += ['@type' => 'Link', 'contexts' => ['work' => true]];
                if (!isset($card[$map])) $patch[$map] = (object)['ews1' => $entry];
                else $patch[$map . '/ews1'] = $entry;
            } else {
                $key = array_key_first($entries); $entry = $entries[$key];
                if (!is_array($entry) || ($map === 'links' && ($entry['contexts']['work'] ?? false) !== true)) throw new DomainException('Ambiguous contact ' . $map);
                $path = $map . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key) . '/' . $property;
                $patch[$path] = $value;
            }
        }
        return $patch;
    }

    private function parseContactIndexedUpdate(DOMElement $field, array $children): array {
        $path = $children[0];
        $this->validateContactIndexedXml($field, []);
        $this->validateContactIndexedXml($path, ['FieldURI', 'FieldIndex']);
        $uri = $path->getAttribute('FieldURI');
        $index = $path->getAttribute('FieldIndex');
        $addressParts = ['Street' => 'street', 'City' => 'locality', 'State' => 'region', 'Country' => 'country', 'PostalCode' => 'postcode'];
        $phones = ['BusinessPhone', 'BusinessPhone2', 'HomePhone', 'HomePhone2', 'MobilePhone', 'BusinessFax', 'HomeFax', 'OtherFax', 'OtherTelephone', 'Pager'];
        $part = null;
        if ($uri === 'contacts:EmailAddress' && preg_match('/^EmailAddress[1-3]$/D', $index)) {
            $map = 'emails'; $container = 'EmailAddresses';
        } elseif ($uri === 'contacts:PhoneNumber' && in_array($index, $phones, true)) {
            $map = 'phones'; $container = 'PhoneNumbers';
        } elseif ($uri === 'contacts:ImAddress' && preg_match('/^ImAddress[1-3]$/D', $index)) {
            $map = 'onlineServices'; $container = 'ImAddresses';
        } elseif (preg_match('/^contacts:PhysicalAddress:(Street|City|State|Country|PostalCode)$/D', $uri, $m) && in_array($index, ['Business', 'Home', 'Other'], true)) {
            $map = 'addresses'; $container = 'PhysicalAddresses'; $part = $addressParts[$m[1]];
        } else {
            throw new DomainException('Unsupported indexed contact property or index');
        }
        $delete = $field->localName === 'DeleteItemField';
        if (count($children) !== ($delete ? 1 : 2) || $path->childElementCount !== 0) throw new DomainException('Invalid indexed update structure');
        $value = null;
        if (!$delete) {
            $contact = $children[1];
            if ($contact->namespaceURI !== EwsSoap::NS_T || $contact->localName !== 'Contact') throw new DomainException('Expected Contact value');
            $this->validateContactIndexedXml($contact, []);
            $containers = [];
            foreach ($contact->childNodes as $node) if ($node instanceof DOMElement) $containers[] = $node;
            if (count($containers) !== 1 || $containers[0]->namespaceURI !== EwsSoap::NS_T || $containers[0]->localName !== $container) throw new DomainException('Invalid contact dictionary');
            $this->validateContactIndexedXml($containers[0], []);
            $entries = [];
            foreach ($containers[0]->childNodes as $node) if ($node instanceof DOMElement) $entries[] = $node;
            if (count($entries) !== 1 || $entries[0]->namespaceURI !== EwsSoap::NS_T || $entries[0]->localName !== 'Entry' || $entries[0]->getAttribute('Key') !== $index) throw new DomainException('Invalid contact dictionary entry');
            $entry = $entries[0];
            $this->validateContactIndexedXml($entry, ['Key'], $part === null);
            if ($part !== null) {
                $parts = [];
                foreach ($entry->childNodes as $node) if ($node instanceof DOMElement) $parts[] = $node;
                $element = $m[1] === 'Country' ? 'CountryOrRegion' : $m[1];
                if (count($parts) !== 1 || $parts[0]->namespaceURI !== EwsSoap::NS_T || $parts[0]->localName !== $element || $parts[0]->childElementCount) throw new DomainException('Invalid physical address value');
                $entry = $parts[0];
                $this->validateContactIndexedXml($entry, [], true);
            } elseif ($entry->childElementCount) throw new DomainException('Invalid dictionary value');
            $value = trim($entry->textContent);
            if ($value === '') throw new DomainException('Empty indexed value; use DeleteItemField');
            if ($map === 'onlineServices' && !preg_match('/^(?:im|xmpp|sip|sips|matrix):/i', $value)) throw new DomainException('IM address must have a supported URI scheme');
        }
        return compact('map', 'index', 'part', 'delete', 'value');
    }

    private function validateContactIndexedXml(DOMElement $element, array $attributes, bool $allowText = false): void {
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
            if ($attribute->namespaceURI || !in_array($attribute->localName, $attributes, true)) throw new DomainException('Unsupported indexed update attribute');
        }
        if (!$allowText) foreach ($element->childNodes as $node) {
            if (($node instanceof DOMText || $node instanceof DOMCdataSection) && trim($node->textContent) !== '') throw new DomainException('Unexpected indexed update text');
        }
    }

    // Mirror contactToXml's selection exactly, retaining the original map keys.
    private function contactIndexedSlots(string $map, array $entries): array {
        $slots = [];
        ksort($entries, SORT_STRING);
        if ($map === 'emails') {
            $keys = array_keys($entries);
            usort($keys, fn($a, $b) => (int)(($entries[$b]['contexts']['work'] ?? false) === true) - (int)(($entries[$a]['contexts']['work'] ?? false) === true));
            foreach ($keys as $key) if (is_string($entries[$key]['address'] ?? null) && $entries[$key]['address'] !== '' && count($slots) < 3) $slots['EmailAddress' . (count($slots) + 1)] = $key;
        } elseif ($map === 'onlineServices') {
            foreach ($entries as $key => $entry) if (is_string($entry['uri'] ?? null) && preg_match('/^(?:im|xmpp|sip|sips|matrix):/i', $entry['uri']) && count($slots) < 3) $slots['ImAddress' . (count($slots) + 1)] = $key;
        } elseif ($map === 'phones') {
            foreach ($entries as $key => $entry) {
                if (!is_string($entry['number'] ?? null) || $entry['number'] === '') continue;
                $ctx = $entry['contexts'] ?? []; $feat = $entry['features'] ?? [];
                $work = ($ctx['work'] ?? false) === true; $home = ($ctx['home'] ?? false) === true || ($ctx['private'] ?? false) === true;
                if (($feat['cell'] ?? false) === true || ($feat['mobile'] ?? false) === true) $candidates = ['MobilePhone'];
                elseif (($feat['fax'] ?? false) === true) $candidates = [$work ? 'BusinessFax' : ($home ? 'HomeFax' : 'OtherFax')];
                elseif (($feat['pager'] ?? false) === true) $candidates = ['Pager'];
                elseif ($work) $candidates = ['BusinessPhone', 'BusinessPhone2'];
                elseif ($home) $candidates = ['HomePhone', 'HomePhone2'];
                else $candidates = ['OtherTelephone'];
                foreach ($candidates as $candidate) if (!array_key_exists($candidate, $slots)) { $slots[$candidate] = $key; break; }
            }
        } else {
            foreach ($entries as $key => $entry) {
                $ctx = $entry['contexts'] ?? [];
                $index = ($ctx['work'] ?? false) === true ? 'Business' : ((($ctx['home'] ?? false) === true || ($ctx['private'] ?? false) === true) ? 'Home' : 'Other');
                if (array_key_exists($index, $slots)) continue;
                $parsed = ['street' => '', 'locality' => '', 'region' => '', 'postcode' => '', 'country' => ''];
                foreach ($entry['components'] ?? [] as $component) {
                    $v = $component['value'] ?? ''; $kind = $component['kind'] ?? '';
                    if (in_array($kind, ['street', 'number', 'name'], true)) $parsed['street'] .= ($parsed['street'] ? ', ' : '') . $v;
                    elseif ($kind === 'locality') $parsed['locality'] = $v;
                    elseif ($kind === 'region') $parsed['region'] = $v;
                    elseif (in_array($kind, ['postcode', 'postalCode'], true)) $parsed['postcode'] = $v;
                    elseif (in_array($kind, ['country', 'countryName'], true)) $parsed['country'] = $v;
                }
                foreach ($parsed as $v) if ($v !== '') { $slots[$index] = $key; break; }
            }
        }
        return $slots;
    }

    private function mergeContactIndexed(array $card, array $changes, bool $hasChangeKey): array {
        $maps = []; $slots = []; $seen = []; $created = []; $touched = [];
        foreach ($changes as $change) {
            ['map' => $map, 'index' => $index, 'part' => $part, 'delete' => $delete, 'value' => $value] = $change;
            $identity = $map . '/' . $index . '/' . ($part ?? '');
            if (isset($seen[$identity])) throw new DomainException('Duplicate indexed update');
            $seen[$identity] = true;
            if (!array_key_exists($map, $maps)) {
                if (isset($card['localizations']) && !is_array($card['localizations'])) throw new DomainException('Unsupported contact localizations');
                foreach (($card['localizations'] ?? []) as $localization) {
                    if (!is_array($localization)) throw new DomainException('Unsupported contact localizations');
                    foreach ($localization as $path => $_) if ($path === $map || str_starts_with((string)$path, $map . '/')) throw new DomainException('Localized contact data cannot be safely updated');
                }
                $maps[$map] = $card[$map] ?? [];
                if (!is_array($maps[$map])) throw new DomainException('Unsupported contact dictionary');
                foreach ($maps[$map] as $entry) {
                    if (!is_array($entry) || (isset($entry['contexts']) && !is_array($entry['contexts'])) || (isset($entry['features']) && !is_array($entry['features'])) || (isset($entry['components']) && !is_array($entry['components']))) throw new DomainException('Unsupported contact dictionary entry');
                    foreach ($entry['components'] ?? [] as $component) if (!is_array($component) || !is_string($component['value'] ?? null)) throw new DomainException('Unsupported address component');
                }
                $slots[$map] = $this->contactIndexedSlots($map, $maps[$map]);
            }
            if (!$hasChangeKey && array_key_exists($index, $slots[$map])) throw new DomainException('ChangeKey required to update an existing indexed contact slot', 1);
            $key = $slots[$map][$index] ?? $created[$map][$index] ?? null;
            if ($key === null) {
                if ($delete) throw new DomainException('Indexed contact field does not exist');
                $keys = array_map('strval', array_keys($maps[$map]));
                sort($keys, SORT_STRING); $last = $keys ? end($keys) : '';
                $key = 'z';
                while (strcmp($key, $last) <= 0) {
                    $key .= 'z';
                    if (strlen($key) > 255) throw new DomainException('Cannot safely allocate indexed contact key');
                }
                $entry = [];
                if ($map === 'addresses') {
                    $entry = ['@type' => 'Address', 'components' => []];
                    if ($index !== 'Other') $entry['contexts'] = [$index === 'Business' ? 'work' : 'home' => true];
                } elseif ($map === 'phones') {
                    if (str_starts_with($index, 'Business')) $entry['contexts'] = ['work' => true];
                    elseif (str_starts_with($index, 'Home')) $entry['contexts'] = ['home' => true];
                    if ($index === 'MobilePhone') $entry['features'] = ['cell' => true];
                    elseif (str_ends_with($index, 'Fax')) $entry['features'] = ['fax' => true];
                    elseif ($index === 'Pager') $entry['features'] = ['pager' => true];
                }
                $maps[$map][$key] = $entry; $created[$map][$index] = $key;
            }
            $touched[$map][$key] = true;
            if ($part !== null) {
                $entry =& $maps[$map][$key];
                if (isset($entry['full'])) throw new DomainException('Formatted address cannot be safely updated');
                $components = $entry['components'] ?? [];
                if (!array_is_list($components)) throw new DomainException('Unsupported address components');
                $aliases = match ($part) { 'street' => ['street', 'number', 'name'], 'postcode' => ['postcode', 'postalCode'], 'country' => ['country', 'countryName'], default => [$part] };
                $matches = [];
                foreach ($components as $i => $component) if (in_array($component['kind'] ?? '', $aliases, true)) $matches[] = $i;
                if (count($matches) > 1 || ($matches && $part === 'street' && $components[$matches[0]]['kind'] !== 'street')) throw new DomainException('Ambiguous address component');
                if ($delete && !$matches) throw new DomainException('Address component does not exist');
                if ($matches) {
                    $i = $matches[0];
                    if ($delete) {
                        if (array_diff(array_keys($components[$i]), ['kind', 'value', '@type'])) throw new DomainException('Address component metadata cannot be removed');
                        array_splice($components, $i, 1);
                    } else {
                        if (isset($components[$i]['phonetic'])) throw new DomainException('Phonetic address component cannot be updated');
                        $components[$i]['value'] = $value;
                    }
                } else $components[] = ['kind' => $part, 'value' => $value];
                $entry['components'] = $components;
                unset($entry);
            } else {
                $property = $map === 'emails' ? 'address' : ($map === 'phones' ? 'number' : 'uri');
                if ($delete) {
                    $allowed = [$property, '@type'];
                    if ($map === 'phones') $allowed = array_merge($allowed, ['contexts', 'features']);
                    if ($map === 'emails') $allowed[] = 'contexts';
                    if (array_diff(array_keys($maps[$map][$key]), $allowed)) throw new DomainException('Contact entry metadata cannot be removed');
                    foreach (['contexts' => ['work', 'home', 'private'], 'features' => ['cell', 'mobile', 'fax', 'pager']] as $propertyName => $allowedKeys) {
                        foreach ($maps[$map][$key][$propertyName] ?? [] as $k => $v) if (!in_array($k, $allowedKeys, true) || $v !== true) throw new DomainException('Contact entry metadata cannot be removed');
                    }
                    unset($maps[$map][$key]);
                } else $maps[$map][$key][$property] = $value;
            }
        }
        foreach ($created as $map => $indexes) {
            $finalSlots = $this->contactIndexedSlots($map, $maps[$map]);
            foreach ($indexes as $index => $key) if (($finalSlots[$index] ?? null) !== $key) throw new DomainException('Cannot create an unrepresentable indexed slot');
        }
        // Do not let deletion or insertion silently retarget another EWS slot,
        // including promotion of entries previously hidden by the converter.
        foreach ($maps as $map => $entries) {
            $finalSlots = $this->contactIndexedSlots($map, $entries);
            foreach ($slots[$map] as $index => $oldKey) {
                if (array_key_exists($oldKey, $entries) && ($finalSlots[$index] ?? null) !== $oldKey) {
                    // An address can remain in the map after its last visible
                    // component is deleted; it must not promote a replacement.
                    if ($map !== 'addresses' || in_array($oldKey, $finalSlots, true) || isset($finalSlots[$index])) throw new DomainException('Contact update would shift indexed slots');
                }
                if (isset($finalSlots[$index]) && $finalSlots[$index] !== $oldKey) throw new DomainException('Contact update would shift indexed slots');
            }
            foreach ($finalSlots as $index => $key) {
                if (!isset($slots[$map][$index]) && array_key_exists($key, $card[$map] ?? []) && !in_array($key, $slots[$map], true)) throw new DomainException('Contact update would expose a hidden indexed entry');
            }
        }
        $patch = [];
        foreach ($maps as $map => $entries) {
            if (!isset($card[$map])) {
                $patch[$map] = (object)$entries;
                continue;
            }
            foreach ($touched[$map] as $key => $_) {
                $path = $map . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
                if (!array_key_exists($key, $entries)) $patch[$path] = null;
                elseif (!array_key_exists($key, $card[$map])) $patch[$path] = $entries[$key];
                else {
                    $property = match ($map) { 'emails' => 'address', 'phones' => 'number', 'onlineServices' => 'uri', default => 'components' };
                    $patch[$path . '/' . $property] = $entries[$key][$property];
                }
            }
        }
        return $patch;
    }

    private function mergeContactName(array $card, array $changes): ?array {
        $name = $card['name'] ?? [];
        if (!is_array($name)) throw new DomainException('Unsupported contact name');
        foreach (($card['localizations'] ?? []) as $localization) {
            if (!is_array($localization)) throw new DomainException('Unsupported contact localizations');
            foreach ($localization as $path => $_) {
                if ($path === 'name' || str_starts_with((string)$path, 'name/')) {
                    throw new DomainException('Localized contact names cannot be safely updated');
                }
            }
        }
        $kinds = [
            'given' => ['given'], 'surname' => ['surname', 'surname2'],
            'additional' => ['given2', 'additional'], 'prefix' => ['title', 'prefix'],
            'suffix' => ['credential', 'generation', 'suffix'],
        ];
        $canonical = ['given' => 'given', 'surname' => 'surname', 'additional' => 'given2', 'prefix' => 'title', 'suffix' => 'credential'];
        $rank = ['prefix' => 0, 'given' => 1, 'additional' => 2, 'surname' => 3, 'suffix' => 4];
        $components = $name['components'] ?? [];
        if (!is_array($components) || !array_is_list($components)) throw new DomainException('Unsupported name components');
        $componentChanges = array_diff_key($changes, ['full' => true]);
        foreach ($componentChanges as $value) {
            if ($value === '') throw new DomainException('Clearing contact name components is not supported');
        }

        // A legacy-only name can be migrated without guessing how to order existing components.
        if ($componentChanges && !$components) {
            foreach (['prefix', 'given', 'additional', 'surname', 'suffix'] as $group) {
                if (isset($name[$group]) && (!is_string($name[$group]))) throw new DomainException('Unsupported legacy name');
                if (($name[$group] ?? '') !== '') $components[] = ['kind' => $canonical[$group], 'value' => $name[$group]];
            }
            if ($components) $name['isOrdered'] = true;
        }
        $groupOf = static function ($component) use ($kinds): ?string {
            if (!is_array($component) || !is_string($component['kind'] ?? null) || !is_string($component['value'] ?? null)) {
                throw new DomainException('Unsupported name component');
            }
            foreach ($kinds as $group => $aliases) if (in_array($component['kind'], $aliases, true)) return $group;
            return null;
        };
        foreach ($components as $component) $groupOf($component);

        if ($componentChanges && !array_key_exists('full', $changes) && isset($name['full'])) {
            if (!is_string($name['full']) || $name['full'] !== $this->renderContactName($name, $components)) {
                throw new DomainException('Custom display name requires an explicit DisplayName update');
            }
        }
        foreach ($componentChanges as $group => $value) {
            $matches = [];
            foreach ($components as $i => $component) if ($groupOf($component) === $group) $matches[] = $i;
            if (count($matches) > 1) throw new DomainException('Multiple name components cannot be safely replaced');
            foreach ($kinds[$group] as $kind) {
                if (array_key_exists($kind, $name['sortAs'] ?? [])) throw new DomainException('Sorted name components cannot be safely updated');
            }
            if ($matches) {
                $i = $matches[0];
                if (isset($components[$i]['phonetic'])) throw new DomainException('Phonetic name components cannot be safely updated');
                $components[$i]['value'] = $value;
            } else {
                // Insertion around explicit separators or in a non-standard order is ambiguous.
                $insert = count($components);
                $lastRank = -1;
                foreach ($components as $i => $component) {
                    $existing = $groupOf($component);
                    if ($existing === null || $rank[$existing] < $lastRank) throw new DomainException('Cannot safely insert into this name');
                    $lastRank = $rank[$existing];
                    if ($rank[$existing] > $rank[$group] && $insert === count($components)) $insert = $i;
                }
                array_splice($components, $insert, 0, [['kind' => $canonical[$group], 'value' => $value]]);
                if (count($components) === 1) $name['isOrdered'] = true;
            }
            if (array_key_exists($group, $name)) $name[$group] = $value;
        }
        if ($componentChanges) {
            foreach ($kinds as $group => $_) {
                if (!isset($name[$group]) || $name[$group] === '') continue;
                $found = false;
                foreach ($components as $component) if ($groupOf($component) === $group) $found = true;
                if (!$found) throw new DomainException('Mixed legacy name cannot be safely updated');
            }
        }
        if ($componentChanges) $name['components'] = $components;
        if (array_key_exists('full', $changes)) {
            if ($changes['full'] === '') throw new DomainException('Clearing DisplayName is not supported');
            $name['full'] = $changes['full'];
        } elseif ($componentChanges && isset($name['full'])) {
            $name['full'] = $this->renderContactName($name, $components);
        }
        return $name ?: null;
    }

    private function renderContactName(array $name, array $components): string {
        if (!$components) return '';
        if (($name['isOrdered'] ?? false) !== true) throw new DomainException('Unordered name requires an explicit DisplayName update');
        $default = $name['defaultSeparator'] ?? ' ';
        if (!is_string($default)) throw new DomainException('Unsupported name separator');
        $result = '';
        $separator = null;
        $hasPart = false;
        foreach ($components as $component) {
            if ($component['kind'] === 'separator') {
                $separator = ($separator ?? '') . $component['value'];
            } elseif ($component['value'] !== '') {
                if ($hasPart) $result .= $separator ?? $default;
                $result .= $component['value'];
                $separator = null;
                $hasPart = true;
            }
        }
        return $result;
    }

    private function rejectOccurrenceIds(DOMElement $request, bool $rejectEncoded = false): void {
        foreach (['OccurrenceItemId', 'RecurringMasterItemId', 'RecurringMasterItemIdRanges'] as $name) {
            if ($request->getElementsByTagNameNS(EwsSoap::NS_T, $name)->length) {
                throw new EwsCalendarSafetyException('Calendar occurrence and master IDs are not supported');
            }
        }
        if ($rejectEncoded) foreach ($request->getElementsByTagNameNS(EwsSoap::NS_T, 'ItemId') as $itemId) {
            if (str_starts_with($itemId->getAttribute('Id'), 'ewsocc_')) throw new EwsCalendarSafetyException('Calendar occurrence writes are not supported');
        }
    }

    private function rejectCalendarContext(DOMElement $request): void {
        if ($request->ownerDocument->getElementsByTagNameNS(EwsSoap::NS_T, 'TimeZoneContext')->length) {
            throw new EwsCalendarSafetyException('Calendar time-zone context is not supported');
        }
    }

    private function calendarUpdatePatch(DOMElement $updates): array {
        $patch = [];
        foreach ($updates->childNodes as $field) {
            if (!$field instanceof DOMElement) continue;
            if ($field->namespaceURI !== EwsSoap::NS_T || $field->localName !== 'SetItemField') throw new EwsCalendarSafetyException('Unsupported calendar update operation');
            $children = [];
            foreach ($field->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child;
            if (count($children) !== 2 || $children[0]->namespaceURI !== EwsSoap::NS_T || $children[0]->localName !== 'FieldURI' ||
                $children[1]->namespaceURI !== EwsSoap::NS_T || $children[1]->localName !== 'CalendarItem') throw new EwsCalendarSafetyException('Invalid calendar update');
            $uri = $children[0]->getAttribute('FieldURI');
            $names = ['item:Subject' => ['Subject', 'title'], 'calendar:Subject' => ['Subject', 'title']];
            if (!isset($names[$uri])) throw new EwsCalendarSafetyException('Unsupported calendar property: ' . $uri);
            [$name, $property] = $names[$uri];
            $values = [];
            foreach ($children[1]->childNodes as $value) if ($value instanceof DOMElement) $values[] = $value;
            if (count($values) !== 1 || $values[0]->namespaceURI !== EwsSoap::NS_T || $values[0]->localName !== $name || $values[0]->firstElementChild || array_key_exists($property, $patch)) {
                throw new EwsCalendarSafetyException('Invalid calendar property value');
            }
            $patch[$property] = trim($values[0]->textContent);
        }
        if (!$patch) throw new EwsCalendarSafetyException('No supported calendar updates');
        return $patch;
    }

    /** Read the default object projection used by GetItem and SyncFolderItems. */
    private function calendarWriteRead(string $id, string $account, array $using): array {
        $r = $this->client->call([['CalendarEvent/get', ['accountId' => $account, 'ids' => [$id], 'properties' => null], '0']], $using);
        $data = $r[0][1] ?? null;
        if (count($r) !== 1 || ($r[0][0] ?? null) !== 'CalendarEvent/get' || ($r[0][2] ?? null) !== '0' || !is_array($data) ||
            ($data['accountId'] ?? null) !== $account || !is_string($data['state'] ?? null) || $data['state'] === '' ||
            !is_array($data['list'] ?? null) || !array_is_list($data['list']) || count($data['list']) !== 1 ||
            !is_array($data['list'][0]) || ($data['list'][0]['id'] ?? null) !== $id || ($data['notFound'] ?? null) !== []) {
            throw new RuntimeException('Invalid calendar read');
        }
        return $data;
    }

    private function calendarWriteError(string $operation, string $code, string $message): string {
        return '<m:' . $operation . 'ResponseMessage ResponseClass="Error"><m:MessageText>' . EwsSoap::escapeXml($message) .
            '</m:MessageText><m:ResponseCode>' . $code . '</m:ResponseCode></m:' . $operation . 'ResponseMessage>';
    }

    private function calendarWriteSuccess(string $operation, string $id, array $event): string {
        return '<m:' . $operation . 'ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:CalendarItem>' .
            EwsSoap::buildId(new DOMDocument(), EwsConverter::PFX_CALENDAR . $id, EwsConverter::itemChangeKey($event)) .
            '</t:CalendarItem></m:Items></m:' . $operation . 'ResponseMessage>';
    }

    private function createCalendarEvent(array $event): string {
        $account = $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS);
        $using = [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS];
        try {
            $r = $this->client->call([['CalendarEvent/set', ['accountId' => $account, 'create' => ['ev1' => $event]], '0']], $using);
            $result = $r[0][1] ?? null;
            if (count($r) !== 1 || ($r[0][0] ?? null) !== 'CalendarEvent/set' || ($r[0][2] ?? null) !== '0' || !is_array($result) ||
                ($result['accountId'] ?? null) !== $account || !is_string($result['newState'] ?? null) || $result['newState'] === '' ||
                !is_array($result['created'] ?? null) || count($result['created']) !== 1 || !is_array($result['created']['ev1'] ?? null) ||
                !is_string($result['created']['ev1']['id'] ?? null) || strlen($result['created']['ev1']['id']) > 1024 ||
                preg_match('/^[\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+$/uD', $result['created']['ev1']['id']) !== 1 ||
                !in_array($result['notCreated'] ?? null, [null, []], true)) throw new RuntimeException('Invalid calendar set');
            $id = $result['created']['ev1']['id'];
            $after = $this->calendarWriteRead($id, $account, $using);
            if ($after['state'] !== $result['newState'] || ($after['list'][0]['uid'] ?? null) !== $event['uid'] ||
                ($after['list'][0]['calendarIds'] ?? null) !== $event['calendarIds']) throw new RuntimeException('Calendar result changed');
            EwsConverter::safeCalendarTimes($after['list'][0]);
            $message = $this->calendarWriteSuccess('CreateItem', $id, $after['list'][0]);
        } catch (\Throwable $e) {
            // A failed or malformed response can follow a successful write: never retry or invent an ID.
            $message = $this->calendarWriteError('CreateItem', 'ErrorInternalServerError', 'Calendar create outcome could not be determined');
        }
        return EwsSoap::buildSoapResponse('<m:CreateItemResponse><m:ResponseMessages>' . $message . '</m:ResponseMessages></m:CreateItemResponse>');
    }

    private function updateCalendarEvent(string $id, DOMElement $updates, string $changeKey): string {
        $jmapId = $this->jmapId($id);
        $patch = $this->calendarUpdatePatch($updates);
        if ($jmapId === '') return $this->calendarWriteError('UpdateItem', 'ErrorInvalidIdMalformed', 'Invalid calendar item ID');
        // AutoResolve is deliberately as conservative as NeverOverwrite: without
        // a matching version there is no safe basis for merging this update.
        if ($changeKey === '') return $this->calendarWriteError('UpdateItem', 'ErrorChangeKeyRequired', 'Calendar change key is required');
        $account = $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS);
        $using = [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS];
        try { $data = $this->calendarWriteRead($jmapId, $account, $using); }
        catch (\Throwable $e) { return $this->calendarWriteError('UpdateItem', 'ErrorInternalServerError', 'Calendar read failed'); }
        EwsConverter::safeCalendarTimes($data['list'][0]);
        foreach (['participants', 'organizerCalendarAddress', 'replyTo', 'schedulingLinks'] as $field) {
            $value = $data['list'][0][$field] ?? null;
            if ($value !== null && $value !== [] && !($value instanceof stdClass && get_object_vars($value) === [])) {
                throw new EwsCalendarSafetyException('Calendar scheduling updates are not supported');
            }
        }
        if (!hash_equals(EwsConverter::itemChangeKey($data['list'][0]), $changeKey)) return $this->calendarWriteError('UpdateItem', 'ErrorIrresolvableConflict', 'Calendar item changed');
        try {
            $r = $this->client->call([['CalendarEvent/set', ['accountId' => $account, 'ifInState' => $data['state'], 'update' => [$jmapId => $patch]], '0']], $using);
            $result = $r[0][1] ?? null;
            if (count($r) === 1 && ($r[0][0] ?? null) === 'error' && ($r[0][2] ?? null) === '0' && is_array($result) && ($result['type'] ?? null) === 'stateMismatch') {
                return $this->calendarWriteError('UpdateItem', 'ErrorIrresolvableConflict', 'Calendar changed during update');
            }
            if (count($r) === 1 && ($r[0][0] ?? null) === 'CalendarEvent/set' && ($r[0][2] ?? null) === '0' && is_array($result) &&
                ($result['accountId'] ?? null) === $account && ($result['oldState'] ?? null) === $data['state'] &&
                in_array($result['updated'] ?? null, [null, []], true) && is_array($result['notUpdated'] ?? null) &&
                count($result['notUpdated']) === 1 && in_array($result['notUpdated'][$jmapId]['type'] ?? null, ['stateMismatch', 'conflict'], true)) {
                return $this->calendarWriteError('UpdateItem', 'ErrorIrresolvableConflict', 'Calendar changed during update');
            }
            if (count($r) !== 1 || ($r[0][0] ?? null) !== 'CalendarEvent/set' || ($r[0][2] ?? null) !== '0' || !is_array($result) ||
                ($result['accountId'] ?? null) !== $account || ($result['oldState'] ?? null) !== $data['state'] ||
                !is_string($result['newState'] ?? null) || $result['newState'] === '' || !is_array($result['updated'] ?? null) ||
                count($result['updated']) !== 1 || !array_key_exists($jmapId, $result['updated']) ||
                !in_array($result['notUpdated'] ?? null, [null, []], true)) throw new RuntimeException('Invalid calendar set');
            $updated = $result['updated'][$jmapId];
            if ($updated !== null && !is_array($updated) && !($updated instanceof stdClass)) throw new RuntimeException('Invalid updated object');
            $after = $this->calendarWriteRead($jmapId, $account, $using);
            if ($after['state'] !== $result['newState'] || ($after['list'][0]['title'] ?? null) !== $patch['title']) {
                return $this->calendarWriteError('UpdateItem', 'ErrorIrresolvableConflict', 'Calendar update outcome could not be determined');
            }
            EwsConverter::safeCalendarTimes($after['list'][0]);
            return $this->calendarWriteSuccess('UpdateItem', $jmapId, $after['list'][0]);
        } catch (\Throwable $e) {
            return $this->calendarWriteError('UpdateItem', 'ErrorInternalServerError', 'Calendar update outcome could not be determined');
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
            ['AddressBook/get', ['accountId' => $this->contactsAccountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $list = $r[0][1]['list'] ?? [];
        return array_map(fn($ab) => $ab + ['type' => 'contacts'], $list);
    }

    private function getAllCalendars(): array {
        $r = $this->client->call([
            ['Calendar/get', ['accountId' => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS), 'ids' => null], '0'],
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
                            'accountId' => $this->contactsAccountId,
                            'filter'    => ['inAddressBook' => $jmapId],
                            'limit'     => 1,
                        ], '0'],
                    ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
                    return $r[0][1]['queryState'] ?? '';
                case 'calendar':
                    $r = $this->client->call([
                        ['CalendarEvent/query', [
                            'accountId' => $this->client->getCapabilityAccountId(JmapClient::CAP_CALENDARS),
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

    private function getDefaultCalendarId(bool $requireDefault = false): ?string {
        $cals = $this->getAllCalendars();
        foreach ($cals as $cal) {
            if (!empty($cal['isDefault'])) return $cal['id'];
        }
        return $requireDefault ? null : ($cals[0]['id'] ?? null);
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
