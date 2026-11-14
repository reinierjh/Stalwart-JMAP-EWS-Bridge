<?php

/**
 * Unintegrated read-only FindItem CalendarView handler.
 * Load EwsSoap, EwsConverter, EwsCalendarOccurrenceId and EwsCalendarViewQuery
 * before use. The client must be a freshly authenticated JmapClient-compatible
 * object. findItem() returns a complete SOAP envelope, including on error.
 *
 * Only Shallow, one explicit cal_ FolderId, one CalendarView, and an ItemShape
 * containing only IdOnly, Default or AllProperties are accepted. The default
 * MaxEntriesReturned is 100, maximum 1000. No offset or continuation is
 * fabricated. Separate whole-second boundary scans plus the main scan preserve
 * server duration limits; each scan has the EwsCalendarViewQuery bounds, and their
 * combined distinct result is capped at MAX_EVENTS. AdditionalProperties and
 * other shape options are not implemented. Default/AllProperties expose only
 * the converter's supported subset, without FindItem body or attachments.
 * The caller must reauthorize on later requests: occurrence IDs are forgeable;
 * GetItem occurrences and OccurrenceItemId/InstanceIndex are not implemented.
 * State checks detect observed changes, not an atomic snapshot or later changes.
 */
final class EwsCalendarViewOperation {
    private const CORE = 'urn:ietf:params:jmap:core';
    private const CAL = 'urn:ietf:params:jmap:calendars';
    private const DEFAULT_ENTRIES = 100;
    private const MAX_ENTRIES = 1000;
    private const MAX_MASTER_BYTES = 16777216;
    private const MAX_XML_BYTES = 16777216;
    private object $client;

    public function __construct(object $client) { $this->client = $client; }

    public function findItem(DOMElement $request): string {
        try {
            [$folder, $after, $before, $max, $shape] = $this->parse($request);
            try { $session = $this->client->getSession(); }
            catch (Throwable $e) { throw new RuntimeException('JMAP session failed'); }
            $account = $session['primaryAccounts'][self::CAL] ?? null;
            if (!is_string($account) || $account === '' || !array_key_exists(self::CAL, $session['capabilities'] ?? []) ||
                !isset($session['accounts'][$account]) || !array_key_exists(self::CAL, $session['accounts'][$account]['accountCapabilities'] ?? [])) throw new RuntimeException('Calendar capability unavailable');
            $core = $session['capabilities'][self::CORE] ?? null;
            if (!is_array($core)) throw new RuntimeException('Core capability unavailable');
            $batch = EwsCalendarViewQuery::MAX_PAGE;
            if (array_key_exists('maxObjectsInGet', $core)) {
                if (!is_int($core['maxObjectsInGet']) || $core['maxObjectsInGet'] < 1) throw new RuntimeException('Invalid get limit');
                $batch = min($batch, $core['maxObjectsInGet']);
            }
            $rawFolder = substr($folder, 4);
            $calendarState = $this->authorize($account, $rawFolder);
            try {
                $start = EwsCalendarViewQuery::parseDateTime($after);
                $end = EwsCalendarViewQuery::parseDateTime($before);
                if ($start > $end) throw new InvalidArgumentException('Invalid range');
                $span = ((int)$end->format('U') - (int)$start->format('U')) * 1000000 + (int)$end->format('u') - (int)$start->format('u');
                if ($span > EwsCalendarViewQuery::MAX_SECONDS * 1000000) throw new InvalidArgumentException('Range outside local limit');
                $startFloor = $start->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'), 0);
                $endFloor = $end->setTime((int)$end->format('H'), (int)$end->format('i'), (int)$end->format('s'), 0);
                $boundary = $startFloor->modify('-1 second');
                if ((int)$boundary->format('Y') < 1) throw new InvalidArgumentException('Boundary outside supported range');
                $query = new EwsCalendarViewQuery($this->client);
                $format = fn(DateTimeImmutable $d) => $d->format('Y-m-d\TH:i:s\Z');
                // JMAP after is exclusive for event end, whereas EWS includes an
                // end exactly at StartDate. Use whole seconds for all JMAP bounds
                // so a server discarding fractional query precision cannot lose
                // events. The converter rejects sub-second event projections.
                $boundaryEnd = $start->format('u') === '000000' ? $startFloor : $startFloor->modify('+1 second');
                $results = [$query->query($rawFolder, $format($boundary), $format($boundaryEnd))];
                if ($boundaryEnd < $endFloor) $results[] = $query->query($rawFolder, $format($boundaryEnd), $format($endFloor));
                if ($end->format('u') !== '000000') {
                    $upper = $endFloor->modify('+1 second');
                    if ((int)$upper->format('Y') > 9999) throw new InvalidArgumentException('Boundary outside supported range');
                    $results[] = $query->query($rawFolder, $format($endFloor), $format($upper));
                }
            }
            catch (InvalidArgumentException $e) { throw new EwsCalendarViewOperationException('ErrorInvalidRequest'); }
            $state = null; $records = []; $masterIds = [];
            foreach ($results as $result) {
                $scanSeen = [];
                if (($result['accountId'] ?? null) !== $account || !is_string($result['state'] ?? null) || $result['state'] === '' ||
                    !is_array($result['events'] ?? null) || !array_is_list($result['events']) || !is_array($result['defaultEvents'] ?? null) || !array_is_list($result['defaultEvents']) || count($result['events']) !== count($result['defaultEvents'])) throw new RuntimeException('Invalid query result');
                if ($state !== null && $state !== $result['state']) throw new RuntimeException('Events changed between scans');
                $state = $result['state'];
                foreach ($result['events'] as $i => $event) {
                    $default = $result['defaultEvents'][$i];
                    if (!is_array($event) || !is_string($event['id'] ?? null) || $event['id'] === '' || !$this->member($event, $rawFolder)) throw new RuntimeException('Invalid event or calendar membership');
                    if (!is_array($default) || ($default['id'] ?? null) !== $event['id']) throw new RuntimeException('Invalid default event');
                    $base = $event['baseEventId'] ?? null; $recurrence = $event['recurrenceId'] ?? null;
                    if ($base === null && $recurrence === null) $key = 's:' . $event['id'];
                    else {
                        if (!is_string($base) || $base === '' || !is_string($recurrence) || $recurrence === '') throw new RuntimeException('Incomplete occurrence identity');
                        $key = 'o:' . EwsCalendarOccurrenceId::encode($account, $base, $recurrence);
                        // PHP coerces numeric string keys, so retain the ID as a value.
                        $masterIds[$base] = $base;
                    }
                    if (isset($scanSeen[$key])) throw new RuntimeException('Duplicate occurrence identity');
                    $scanSeen[$key] = true;
                    if (isset($records[$key])) {
                        $previous = $records[$key];
                        // Synthetic IDs may differ between otherwise identical scans.
                        $oldEvent = $previous['event']; $oldDefault = $previous['default'];
                        if ($base !== null) { $oldEvent['id'] = ''; $oldDefault['id'] = ''; $event['id'] = ''; $default['id'] = ''; }
                        if (EwsConverter::itemChangeKey($oldEvent) !== EwsConverter::itemChangeKey($event) || EwsConverter::itemChangeKey($oldDefault) !== EwsConverter::itemChangeKey($default)) throw new RuntimeException('Inconsistent repeated event');
                    } else {
                        $records[$key] = ['event' => $event, 'default' => $default];
                        if (count($records) > EwsCalendarViewQuery::MAX_EVENTS) throw new RuntimeException('Combined query exceeds local result limit');
                    }
                }
            }
            $masters = []; $bytes = 0;
            foreach (array_chunk(array_values($masterIds), $batch) as $ids) {
                foreach ($this->getMasters($account, $ids, $state) as $id => $master) {
                    if (!$this->member($master, $rawFolder)) throw new RuntimeException('Invalid master calendar membership');
                    $bytes += strlen(json_encode($master, JSON_THROW_ON_ERROR));
                    if ($bytes > self::MAX_MASTER_BYTES) throw new RuntimeException('Master data limit exceeded');
                    $masters[$id] = $master;
                }
            }
            // Verify all results, even those beyond the requested maximum, so
            // TotalItemsInView never silently counts unusable occurrences.
            $items = ''; $dom = new DOMDocument(); $matched = []; $matchedBytes = 0;
            foreach ($records as $record) {
                $event = $record['event'];
                $base = $event['baseEventId'] ?? null; $recurrence = $event['recurrenceId'] ?? null;
                if ($base !== null) {
                    $occurrenceId = EwsCalendarOccurrenceId::encode($account, $base, $recurrence);
                    $xml = EwsConverter::calendarOccurrenceToXml($dom, $event, $masters[$base], $account, $occurrenceId, $folder);
                } else {
                    $xml = EwsConverter::calendarToXml($dom, $record['default'], $folder);
                }
                [$xml, $eventStart, $eventEnd, $itemId] = $this->findItemProjection($xml, $shape);
                if (($event['utcStart'] ?? null) !== null && EwsCalendarViewQuery::parseDateTime($event['utcStart']) != $eventStart) throw new RuntimeException('Inconsistent event UTC start');
                if ($eventEnd >= $start && $eventStart < $end) {
                    $matchedBytes += strlen($xml);
                    if ($matchedBytes > self::MAX_XML_BYTES) throw new RuntimeException('Calendar XML limit exceeded');
                    $matched[] = ['xml' => $xml, 'start' => $eventStart, 'id' => $itemId];
                }
            }
            usort($matched, fn($a, $b) => ($a['start'] <=> $b['start']) ?: strcmp($a['id'], $b['id']));
            foreach (array_slice($matched, 0, $max) as $item) {
                if (strlen($items) + strlen($item['xml']) > self::MAX_XML_BYTES) throw new RuntimeException('Calendar XML limit exceeded');
                $items .= $item['xml'];
            }
            // Check event state again after conversion, including when no masters
            // were needed, then reauthorize the folder and compare Calendar state.
            $this->getMasters($account, [], $state);
            if ($this->authorize($account, $rawFolder) !== $calendarState) throw new RuntimeException('Calendar changed during read');
            $total = count($matched); $last = $total <= $max ? 'true' : 'false';
            return EwsSoap::buildSoapResponse('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . $total . '" IncludesLastItemInRange="' . $last . '"><t:Items>' . $items . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
        } catch (EwsCalendarViewOperationException $e) {
            return $this->error($e->responseCode);
        } catch (Throwable $e) {
            // Never return exception messages, JMAP IDs, descriptions or bodies.
            return $this->error('ErrorInternalServerError');
        }
    }

    private function error(string $code): string {
        return EwsSoap::buildSoapResponse('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Error">' . EwsSoap::errorResponse($code) . '</m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    /** @return array{string,string,string,int,string} */
    private function parse(DOMElement $request): array {
        if ($request->namespaceURI !== EwsSoap::NS_M || $request->localName !== 'FindItem') throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        $this->attributes($request, ['Traversal']);
        if (!$request->hasAttribute('Traversal') || $request->getAttribute('Traversal') !== 'Shallow') throw new EwsCalendarViewOperationException('ErrorInvalidOperation');
        $children = $this->children($request); $parts = [];
        foreach ($children as $child) {
            if ($child->namespaceURI !== EwsSoap::NS_M || !in_array($child->localName, ['ItemShape', 'CalendarView', 'ParentFolderIds'], true) || isset($parts[$child->localName])) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
            $parts[$child->localName] = $child;
        }
        if (count($parts) !== 3) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        $shape = $parts['ItemShape']; $this->attributes($shape, []); $shapeChildren = $this->children($shape);
        if (count($shapeChildren) !== 1 || $shapeChildren[0]->namespaceURI !== EwsSoap::NS_T || $shapeChildren[0]->localName !== 'BaseShape') throw new EwsCalendarViewOperationException('ErrorInvalidPropertyRequest');
        $base = $shapeChildren[0]; $this->attributes($base, []);
        if ($this->children($base) !== []) throw new EwsCalendarViewOperationException('ErrorInvalidPropertyRequest');
        $shapeValue = trim($base->textContent);
        if (!in_array($shapeValue, ['IdOnly', 'Default', 'AllProperties'], true)) throw new EwsCalendarViewOperationException('ErrorInvalidPropertyRequest');
        $view = $parts['CalendarView']; $this->attributes($view, ['StartDate', 'EndDate', 'MaxEntriesReturned']);
        if ($this->children($view) !== [] || !$view->hasAttribute('StartDate') || !$view->hasAttribute('EndDate')) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        $max = self::DEFAULT_ENTRIES;
        if ($view->hasAttribute('MaxEntriesReturned')) {
            $v = trim($view->getAttribute('MaxEntriesReturned'));
            if (strlen($v) > 20 || !preg_match('/\A\+?0*[1-9][0-9]{0,3}\z/D', $v) || (int)$v > self::MAX_ENTRIES) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
            $max = (int)$v;
        }
        $parents = $parts['ParentFolderIds']; $this->attributes($parents, []); $folders = $this->children($parents);
        if (count($folders) !== 1 || $folders[0]->namespaceURI !== EwsSoap::NS_T || $folders[0]->localName !== 'FolderId') throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        $f = $folders[0]; $this->attributes($f, ['Id']);
        $id = $f->getAttribute('Id');
        if ($this->children($f) !== [] || !str_starts_with($id, 'cal_') || strlen($id) <= 4 || strlen($id) > 1028) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        return [$id, $view->getAttribute('StartDate'), $view->getAttribute('EndDate'), $max, $shapeValue];
    }

    private function attributes(DOMElement $element, array $allowed): void {
        foreach ($element->attributes as $a) {
            if ($a->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
            if (($a->namespaceURI !== null && $a->namespaceURI !== '') || !in_array($a->localName, $allowed, true)) throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        }
    }

    private function children(DOMElement $parent): array {
        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) $children[] = $child;
            elseif (($child instanceof DOMText || $child instanceof DOMCdataSection) && trim($child->nodeValue) !== '' && $parent->localName !== 'BaseShape') throw new EwsCalendarViewOperationException('ErrorInvalidRequest');
        }
        return $children;
    }

    private function invoke(string $method, array $args): array {
        try { $r = $this->client->call([[$method, $args, 'cvo']], [self::CORE, self::CAL]); }
        catch (Throwable $e) { throw new RuntimeException('JMAP call failed'); }
        if (!is_array($r) || !array_is_list($r) || count($r) !== 1 || !is_array($r[0]) || !array_is_list($r[0]) || count($r[0]) !== 3 || $r[0][0] !== $method || $r[0][2] !== 'cvo' || !is_array($r[0][1])) throw new RuntimeException('Invalid JMAP response');
        return $r[0][1];
    }

    private function authorize(string $account, string $folder): string {
        $r = $this->invoke('Calendar/get', ['accountId' => $account, 'ids' => [$folder], 'properties' => ['id', 'myRights']]);
        if (($r['accountId'] ?? null) !== $account || !is_string($r['state'] ?? null) || $r['state'] === '' || !is_array($r['list'] ?? null) || !array_is_list($r['list']) || !is_array($r['notFound'] ?? null) || !array_is_list($r['notFound'])) throw new RuntimeException('Invalid calendar response');
        if ($r['list'] === [] && $r['notFound'] === [$folder]) throw new EwsCalendarViewOperationException('ErrorFolderNotFound');
        if (count($r['list']) !== 1 || $r['notFound'] !== [] || !is_array($r['list'][0]) || ($r['list'][0]['id'] ?? null) !== $folder) throw new RuntimeException('Invalid calendar response');
        if (($r['list'][0]['myRights']['mayReadItems'] ?? null) !== true) throw new EwsCalendarViewOperationException('ErrorAccessDenied');
        return $r['state'];
    }

    private function getMasters(string $account, array $ids, string $expectedState): array {
        $r = $this->invoke('CalendarEvent/get', ['accountId' => $account, 'ids' => $ids]);
        if (($r['accountId'] ?? null) !== $account || ($r['state'] ?? null) !== $expectedState || !is_array($r['list'] ?? null) || !array_is_list($r['list']) || ($r['notFound'] ?? null) !== []) throw new RuntimeException('Invalid or stale master response');
        $wanted = array_fill_keys($ids, true); $result = [];
        foreach ($r['list'] as $event) {
            if (!is_array($event) || !is_string($event['id'] ?? null) || !isset($wanted[$event['id']]) || isset($result[$event['id']])) throw new RuntimeException('Invalid master ID');
            $result[$event['id']] = $event;
        }
        if (count($result) !== count($ids)) throw new RuntimeException('Missing master');
        return $result;
    }

    private function member(array $event, string $folder): bool {
        $ids = $event['calendarIds'] ?? null;
        if ($ids instanceof stdClass) $ids = get_object_vars($ids);
        return is_array($ids) && ($ids[$folder] ?? null) === true;
    }

    private function findItemProjection(string $xml, string $shape): array {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) throw new RuntimeException('Invalid converted XML');
        $item = $doc->documentElement->firstChild;
        if (!$item instanceof DOMElement || $item->namespaceURI !== EwsSoap::NS_T || $item->localName !== 'CalendarItem' || $item->nextSibling !== null) throw new RuntimeException('Invalid converted calendar item');
        $id = null; $start = null; $end = null;
        foreach (iterator_to_array($item->childNodes) as $child) if ($child instanceof DOMElement) {
            if ($child->namespaceURI === EwsSoap::NS_T && $child->localName === 'Start') {
                if ($start !== null) throw new RuntimeException('Duplicate converted start');
                $start = EwsCalendarViewQuery::parseDateTime($child->textContent);
            }
            if ($child->namespaceURI === EwsSoap::NS_T && $child->localName === 'End') {
                if ($end !== null) throw new RuntimeException('Duplicate converted end');
                $end = EwsCalendarViewQuery::parseDateTime($child->textContent);
            }
            if ($child->namespaceURI === EwsSoap::NS_T && $child->localName === 'ItemId') {
                if ($id !== null || $child->getAttribute('Id') === '' || $child->getAttribute('ChangeKey') === '') throw new RuntimeException('Invalid converted item ID');
                $id = $child;
            } elseif ($shape === 'IdOnly' || ($child->namespaceURI === EwsSoap::NS_T && in_array($child->localName, ['Body', 'Attachments'], true))) $item->removeChild($child);
        }
        if ($id === null || $start === null || $end === null || $end <= $start) throw new RuntimeException('Missing converted item ID or times');
        return [$doc->saveXML($item), $start, $end, $id->getAttribute('Id')];
    }
}

final class EwsCalendarViewOperationException extends RuntimeException {
    public function __construct(public string $responseCode) { parent::__construct($responseCode); }
}
