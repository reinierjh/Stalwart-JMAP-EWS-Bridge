<?php

/**
 * Standalone read-only expanded calendar query. No EWS identity or XML mapping.
 *
 * Construct with a JmapClient-compatible object (getSession() and call()). query()
 * returns accountId, queryState, state, and events in server start-sort order.
 * events contains default get properties plus explicitly fetched metadata;
 * defaultEvents contains the exact unmodified default get objects in the same
 * order, for versioning that must not depend on the metadata projection.
 * Missing identity properties remain absent, distinct from explicit null. An
 * occurrence requires both nonempty baseEventId and recurrenceId; neither means
 * a singleton, but a nonconforming server omitting both is indistinguishable
 * from one. Never infer an occurrence from the syntax of its opaque ID.
 * Synthetic IDs are opaque. A result is not a transaction: state checks detect
 * changes visible through JMAP, not changes after the last check, permission
 * changes that do not change state, or a server that violates its state contract.
 *
 * Bounds: at most 365 days, 2000 results, 100 IDs/page and 16 MiB of
 * serialized event data. Server-advertised stricter limits also apply. An
 * unadvertised expansion limit is enforced by the server; errors are propagated
 * as exceptions, never converted to empty results. Caller must retry on mutation.
 */
final class EwsCalendarViewQuery {
    public const MAX_SECONDS = 365 * 86400;
    public const MAX_EVENTS = 2000;
    public const MAX_PAGE = 100;
    public const MAX_EVENT_BYTES = 16777216;
    private const CORE = 'urn:ietf:params:jmap:core';
    private const CAL = 'urn:ietf:params:jmap:calendars';
    private object $client;

    public function __construct(object $client) { $this->client = $client; }

    /**
     * @param string $calendarId Raw JMAP Calendar ID, not an EWS folder ID.
     * @param string $after XML dateTime with explicit Z or numeric UTC offset.
     * @param string $before XML dateTime with explicit Z or numeric UTC offset.
     * @return array{accountId:string,queryState:string,state:string,events:array,defaultEvents:array}
     * @throws InvalidArgumentException|RuntimeException (client errors also propagate)
     */
    public function query(string $calendarId, string $after, string $before): array {
        if ($calendarId === '' || strlen($calendarId) > 1024) throw new InvalidArgumentException('Invalid calendar ID');
        $start = self::parseDateTime($after); $end = self::parseDateTime($before);
        $duration = ((int)$end->format('U') - (int)$start->format('U')) * 1000000 + (int)$end->format('u') - (int)$start->format('u');
        if ($duration <= 0 || $duration > self::MAX_SECONDS * 1000000) throw new InvalidArgumentException('Calendar range outside local limit');

        try { $session = $this->client->getSession(); }
        catch (Throwable $e) { throw new RuntimeException('JMAP session failed'); }
        $account = $session['primaryAccounts'][self::CAL] ?? null;
        if (!is_string($account) || $account === '' || !array_key_exists(self::CAL, $session['capabilities'] ?? []) ||
            !isset($session['accounts'][$account]) || !array_key_exists(self::CAL, $session['accounts'][$account]['accountCapabilities'] ?? [])) {
            throw new RuntimeException('Calendar capability/account unavailable');
        }
        $cal = $session['accounts'][$account]['accountCapabilities'][self::CAL];
        if (!is_array($cal)) throw new RuntimeException('Invalid calendar capability');
        if (array_key_exists('maxExpandedQueryDuration', $cal) && $duration > self::durationMicros($cal['maxExpandedQueryDuration'])) {
            throw new InvalidArgumentException('Calendar range exceeds server expansion limit');
        }
        $core = $session['capabilities'][self::CORE] ?? null;
        if (!is_array($core)) throw new RuntimeException('Core capability unavailable');
        $page = self::MAX_PAGE;
        if (array_key_exists('maxObjectsInGet', $core)) {
            $limit = $core['maxObjectsInGet'];
            if (!is_int($limit) || $limit < 1) throw new RuntimeException('Invalid maxObjectsInGet');
            $page = min($page, $limit);
        }
        $filter = ['inCalendar' => $calendarId, 'after' => self::localUtc($start), 'before' => self::localUtc($end)];
        $state = null;
        $this->get($account, [], ['id'], $state);
        [$ids, $queryState] = $this->scan($account, $filter, $page);
        $events = []; $defaultEvents = []; $bytes = 0;
        foreach (array_chunk($ids, $page) as $chunk) {
            $defaults = $this->get($account, $chunk, null, $state);
            $metadata = $this->get($account, $chunk, ['id', 'baseEventId', 'recurrenceId', 'utcStart'], $state);
            foreach ($chunk as $id) {
                $event = $defaults[$id]; $meta = $metadata[$id];
                foreach (['baseEventId', 'recurrenceId', 'utcStart'] as $key) {
                    if (!array_key_exists($key, $meta)) {
                        if ($key === 'utcStart') throw new RuntimeException('Missing requested event metadata: ' . $key);
                        if (array_key_exists($key, $event) && $event[$key] !== null) throw new RuntimeException('Conflicting event metadata');
                        continue;
                    }
                    if (array_key_exists($key, $event) && $event[$key] !== $meta[$key]) throw new RuntimeException('Conflicting event metadata');
                    $event[$key] = $meta[$key];
                }
                $base = $meta['baseEventId'] ?? null; $recurrence = $meta['recurrenceId'] ?? null;
                if (!(($base === null && $recurrence === null) || (is_string($base) && $base !== '' && is_string($recurrence) && $recurrence !== ''))) {
                    throw new RuntimeException('Incomplete occurrence identity');
                }
                if ($event['utcStart'] !== null && (!is_string($event['utcStart']) || $event['utcStart'] === '')) throw new RuntimeException('Invalid utcStart');
                if ($event['utcStart'] !== null) {
                    try { self::parseDateTime($event['utcStart']); }
                    catch (InvalidArgumentException $e) { throw new RuntimeException('Invalid utcStart', 0, $e); }
                }
                $encoded = json_encode($event, JSON_THROW_ON_ERROR);
                $bytes += strlen($encoded);
                if ($bytes > self::MAX_EVENT_BYTES) throw new RuntimeException('Calendar data exceeds local memory limit');
                $events[] = $event;
                $defaultEvents[] = $defaults[$id];
            }
        }
        // An empty get provides a state even for an empty query and catches writes
        // following the final data batch. Query states and get states are distinct.
        $this->get($account, [], ['id'], $state);
        [$again, $againState] = $this->scan($account, $filter, $page);
        if ($queryState !== $againState || $ids !== $again) throw new RuntimeException('Calendar query changed during read');
        $this->get($account, [], ['id'], $state);
        return ['accountId' => $account, 'queryState' => $queryState, 'state' => $state, 'events' => $events, 'defaultEvents' => $defaultEvents];
    }

    private function scan(string $account, array $filter, int $page): array {
        $ids = []; $seen = []; $queryState = null; $total = null;
        do {
            $position = count($ids);
            $r = $this->invoke('CalendarEvent/query', ['accountId' => $account, 'filter' => $filter, 'timeZone' => 'Etc/UTC', 'expandRecurrences' => true,
                'sort' => [['property' => 'start', 'isAscending' => true]], 'position' => $position, 'limit' => $page, 'calculateTotal' => true]);
            if (($r['accountId'] ?? null) !== $account || !is_string($r['queryState'] ?? null) || $r['queryState'] === '' ||
                ($r['position'] ?? null) !== $position || !is_int($r['total'] ?? null) || $r['total'] < 0 ||
                !is_array($r['ids'] ?? null) || !array_is_list($r['ids']) || count($r['ids']) > $page) throw new RuntimeException('Malformed query response');
            if ($r['total'] > self::MAX_EVENTS) throw new RuntimeException('Calendar query exceeds local result limit');
            if ($queryState !== null && ($queryState !== $r['queryState'] || $total !== $r['total'])) throw new RuntimeException('Calendar query changed during paging');
            $queryState = $r['queryState']; $total = $r['total'];
            foreach ($r['ids'] as $id) {
                if (!is_string($id) || $id === '' || strlen($id) > 1024 || isset($seen[$id])) throw new RuntimeException('Invalid or duplicate query ID');
                $seen[$id] = true; $ids[] = $id;
            }
            if (count($ids) > $total || (!$r['ids'] && count($ids) < $total)) throw new RuntimeException('Incomplete query page');
        } while (count($ids) < $total);
        return [$ids, $queryState];
    }

    private function get(string $account, array $ids, ?array $properties, ?string &$state): array {
        $args = ['accountId' => $account, 'ids' => $ids];
        if ($properties !== null) $args['properties'] = $properties;
        $r = $this->invoke('CalendarEvent/get', $args);
        if (($r['accountId'] ?? null) !== $account || !is_string($r['state'] ?? null) || $r['state'] === '' ||
            !is_array($r['list'] ?? null) || !array_is_list($r['list']) || !is_array($r['notFound'] ?? null) || !array_is_list($r['notFound']) || $r['notFound'] !== []) throw new RuntimeException('Malformed or incomplete get response');
        if ($state !== null && $state !== $r['state']) throw new RuntimeException('Calendar events changed during read');
        $state = $r['state']; $wanted = array_fill_keys($ids, true); $result = [];
        foreach ($r['list'] as $event) {
            if (!is_array($event) || !is_string($event['id'] ?? null) || !isset($wanted[$event['id']]) || isset($result[$event['id']])) throw new RuntimeException('Unexpected or duplicate get ID');
            $result[$event['id']] = $event;
        }
        if (count($result) !== count($ids)) throw new RuntimeException('Missing get ID');
        return $result;
    }

    private function invoke(string $method, array $args): array {
        try { $responses = $this->client->call([[$method, $args, 'cv']], [self::CORE, self::CAL]); }
        catch (Throwable $e) { throw new RuntimeException('JMAP transport failed'); }
        if (!is_array($responses) || !array_is_list($responses) || count($responses) !== 1 || !is_array($responses[0]) ||
            !array_is_list($responses[0]) || count($responses[0]) !== 3 || $responses[0][2] !== 'cv' || !is_array($responses[0][1])) throw new RuntimeException('Malformed JMAP response tuple');
        [$name, $data] = $responses[0];
        if ($name === 'error') {
            // Do not expose arbitrary server-supplied text or client exception
            // bodies, which can contain calendar contents or identifiers.
            $types = ['invalidArguments', 'forbidden', 'accountNotFound', 'accountNotSupportedByMethod', 'serverFail', 'serverUnavailable', 'serverPartialFail', 'rateLimit', 'requestTooLarge', 'unknownMethod', 'unknownCapability', 'anchorNotFound', 'unsupportedFilter', 'unsupportedSort', 'expandDurationTooLarge', 'cannotCalculateOccurrences'];
            $type = $data['type'] ?? null;
            throw new RuntimeException('JMAP ' . $method . ' error: ' . (is_string($type) && in_array($type, $types, true) ? $type : 'unknown'));
        }
        if ($name !== $method) throw new RuntimeException('Unexpected JMAP response method');
        return $data;
    }

    /** Strict XML dateTime with a mandatory offset, normalized to UTC. */
    public static function parseDateTime(string $s): DateTimeImmutable {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})\z/D', $s, $m) ||
            (int)$m[1] < 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) throw new InvalidArgumentException('Invalid XML dateTime');
        $zone = $m[8];
        if ($zone !== 'Z' && ((int)substr($zone, 1, 2) > 14 || (int)substr($zone, 4, 2) > 59 || ((int)substr($zone, 1, 2) === 14 && substr($zone, 4, 2) !== '00'))) throw new InvalidArgumentException('Invalid UTC offset');
        $date = new DateTimeImmutable($s);
        $date = $date->setTimezone(new DateTimeZone('UTC'));
        if ((int)$date->format('Y') < 1 || (int)$date->format('Y') > 9999) throw new InvalidArgumentException('UTC date out of range');
        return $date;
    }

    private static function localUtc(DateTimeImmutable $date): string {
        $s = $date->format('Y-m-d\TH:i:s'); $fraction = rtrim($date->format('u'), '0');
        return $s . ($fraction === '' ? '' : '.' . $fraction);
    }

    /** JSCalendar Duration; reject unknown formats instead of guessing units. */
    private static function durationMicros(mixed $value): int {
        if (!is_string($value) || strlen($value) > 64 || !preg_match('/\AP(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.(\d{1,6}))?S)?)?\z/D', $value, $m, PREG_UNMATCHED_AS_NULL) || $value === 'P' || str_ends_with($value, 'T')) throw new RuntimeException('Invalid maxExpandedQueryDuration');
        $seconds = (float)($m[1] ?? 0) * 604800 + (float)($m[2] ?? 0) * 86400 + (float)($m[3] ?? 0) * 3600 + (float)($m[4] ?? 0) * 60 + (float)($m[5] ?? 0);
        if ($seconds > self::MAX_SECONDS) return self::MAX_SECONDS * 1000000;
        return (int)$seconds * 1000000 + (int)str_pad($m[6] ?? '', 6, '0');
    }
}
