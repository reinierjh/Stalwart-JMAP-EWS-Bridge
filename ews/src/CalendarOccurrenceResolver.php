<?php

/**
 * Standalone, read-only resolver. Load CalendarOccurrenceId.php and
 * CalendarViewQuery.php first; $client must be freshly authenticated and support
 * invalidateSession(). The caller must use a client bound to the intended JMAP
 * server; its session apiUrl and primary calendar account are pinned per resolve.
 * resolve() returns ['master'=>default master, 'event'=>expanded event,
 * 'calendar'=>authorized raw Calendar object]. It never accepts a synthetic ID.
 *
 * Only second-precision, unambiguous local times in UTC, Etc/UTC or
 * Europe/Amsterdam and daily/weekly rules in the converter's subset are
 * supported. Gaps/overlaps and complex overrides fail closed. At most 16
 * membership calendars, 1000 overrides and two two-second scans per calendar.
 * All calendar memberships must be readable; the lexically first calendar is
 * returned. Each resolve budgets session refresh and all JMAP calls, including
 * helper calls: 512 operations, 16 MiB serialized input/output and 30 seconds.
 * Limits are checked at call boundaries, after client-side response decoding;
 * they cannot interrupt a blocked call or cap its wire/memory use. Configure
 * transport timeouts and response limits separately. The final session refresh
 * replaces the client's cache. A changed endpoint fails even if both are on the
 * same server; the pinned URL is not a substitute for the caller's server binding.
 * State checks are not an atomic snapshot and cannot detect changes
 * after the final check or a server violating its state/permission contract.
 * EwsCalendarOccurrenceNotFound means an authorized, revalidated exclusion or
 * a completed bounded search without a match. An unidentified singleton in an
 * empty search makes absence uncertain. Exceptions contain no server data.
 */
final class EwsCalendarOccurrenceResolver {
    private const CORE = 'urn:ietf:params:jmap:core';
    private const CAL = 'urn:ietf:params:jmap:calendars';
    private object $client;

    public function __construct(object $client) { $this->client = $client; }

    public function resolve(mixed $id): array {
        try {
            // Do not trust a client/helper throwing our public exception type.
            try { $result = $this->resolveChecked($id); }
            catch (Throwable $e) { throw new RuntimeException('Occurrence resolution failed'); }
            if ($result === null) throw new EwsCalendarOccurrenceNotFound();
            return $result;
        } catch (EwsCalendarOccurrenceNotFound $e) {
            throw new EwsCalendarOccurrenceNotFound();
        }
    }

    private function resolveChecked(mixed $id): ?array {
        $client = new EwsCalendarOccurrenceResolverBudgetClient($this->client);
        $session = $client->getSession();
        $account = $session['primaryAccounts'][self::CAL] ?? null;
        if (!is_string($account) || $account === '' || !is_array($session['capabilities'][self::CORE] ?? null) ||
            !is_array($session['capabilities'][self::CAL] ?? null) || !is_array($session['accounts'][$account]['accountCapabilities'][self::CAL] ?? null)) throw new RuntimeException();
        $sessionState = $session['state'] ?? null;
        if (!is_string($sessionState) || $sessionState === '') throw new RuntimeException();
        $core = $session['capabilities'][self::CORE];
        if (array_key_exists('maxObjectsInGet', $core) && (!is_int($core['maxObjectsInGet']) || $core['maxObjectsInGet'] < 1)) throw new RuntimeException();
        $identity = EwsCalendarOccurrenceId::decodeForAccount($id, $account);
        [$master, $state] = $this->master($client, $account, $identity['baseEventId']);
        $folders = self::members($master);
        [$expected, $times, $excluded] = self::occurrenceTimes($master, $identity['recurrenceId']);
        $calendars = []; $calendarState = null;
        foreach ($folders as $folder) {
            [$cal, $cs] = $this->calendar($client, $account, $folder);
            if ($calendarState !== null && $calendarState !== $cs) throw new RuntimeException();
            $calendarState = $cs; $calendars[$folder] = $cal;
        }
        $query = new EwsCalendarViewQuery($client); $found = null; $foundFolder = null; $folderIds = []; $uncertain = false;
        foreach ($folders as $folder) foreach ($excluded ? [] : $times as $time) {
            $after = $time->modify('-1 second'); $before = $time->modify('+1 second');
            if ((int)$after->format('Y') < 1 || (int)$before->format('Y') > 9999) throw new RuntimeException();
            $result = $query->query($folder, $after->format('Y-m-d\TH:i:s\Z'), $before->format('Y-m-d\TH:i:s\Z'));
            if ($result['accountId'] !== $account || $result['state'] !== $state) throw new RuntimeException();
            $matches = 0;
            foreach ($result['events'] as $event) {
                if (($event['baseEventId'] ?? null) === null && ($event['recurrenceId'] ?? null) === null) $uncertain = true;
                if (($event['baseEventId'] ?? null) !== $identity['baseEventId'] || ($event['recurrenceId'] ?? null) !== $identity['recurrenceId']) continue;
                if (++$matches > 1) throw new RuntimeException();
                self::checkEvent($event, $master, $folders, $expected, $identity['baseEventId'], $after, $before);
                if (isset($folderIds[$folder]) && $folderIds[$folder] !== $event['id']) throw new RuntimeException();
                $folderIds[$folder] = $event['id'];
                if ($found !== null) {
                    $a = $found; $b = $event; unset($a['id'], $b['id']);
                    if (!self::equal($a, $b)) throw new RuntimeException();
                } else { $found = $event; $foundFolder = $folder; }
            }
        }
        [$again, $endState] = $this->master($client, $account, $identity['baseEventId']);
        if ($endState !== $state || !self::equal($master, $again) || self::members($again) !== $folders) throw new RuntimeException();
        foreach ($folders as $folder) {
            [$cal, $cs] = $this->calendar($client, $account, $folder);
            if ($cs !== $calendarState || !self::equal($cal, $calendars[$folder])) throw new RuntimeException();
        }
        $session = $client->refreshSession();
        if (($session['state'] ?? null) !== $sessionState || ($session['primaryAccounts'][self::CAL] ?? null) !== $account || !is_array($session['capabilities'][self::CORE] ?? null) || !is_array($session['capabilities'][self::CAL] ?? null) || !is_array($session['accounts'][$account]['accountCapabilities'][self::CAL] ?? null)) throw new RuntimeException();
        $client->checkBudget();
        if ($found === null) {
            if ($uncertain) throw new RuntimeException();
            return null;
        }
        return ['master'=>$master, 'event'=>$found, 'calendar'=>$calendars[$foundFolder]];
    }

    private function invoke(object $client, string $method, array $args): array {
        $r = $client->call([[$method, $args, 'cor']], [self::CORE, self::CAL]);
        if (!is_array($r) || !array_is_list($r) || count($r) !== 1 || !is_array($r[0]) || !array_is_list($r[0]) || count($r[0]) !== 3 || $r[0][0] !== $method || $r[0][2] !== 'cor' || !is_array($r[0][1])) throw new RuntimeException();
        return $r[0][1];
    }

    private function one(object $client, string $method, string $account, string $id, ?array $properties): array {
        $args = ['accountId'=>$account, 'ids'=>[$id]];
        if ($properties !== null) $args['properties'] = $properties;
        $r = $this->invoke($client, $method, $args);
        if (($r['accountId'] ?? null) !== $account || !is_string($r['state'] ?? null) || $r['state'] === '' || ($r['notFound'] ?? null) !== [] || !is_array($r['list'] ?? null) || !array_is_list($r['list']) || count($r['list']) !== 1 || !is_array($r['list'][0]) || ($r['list'][0]['id'] ?? null) !== $id) throw new RuntimeException();
        if (strlen(json_encode($r['list'][0], JSON_THROW_ON_ERROR)) > 16777216) throw new RuntimeException();
        return [$r['list'][0], $r['state']];
    }
    private function master(object $client, string $account, string $id): array { return $this->one($client, 'CalendarEvent/get', $account, $id, null); }
    private function calendar(object $client, string $account, string $id): array {
        [$cal, $state] = $this->one($client, 'Calendar/get', $account, $id, ['id','myRights']);
        if (($cal['myRights']['mayReadItems'] ?? null) !== true) throw new RuntimeException();
        return [$cal, $state];
    }
    private static function map(mixed $v): array {
        if ($v instanceof stdClass) $v = get_object_vars($v);
        if (!is_array($v) || ($v !== [] && array_is_list($v))) throw new RuntimeException();
        return $v;
    }
    private static function members(array $event): array {
        // JmapClient decodes JSON objects associatively, so {"0":true}
        // becomes a list-shaped PHP array. CalendarIds is a map by schema;
        // still require every key and value to satisfy the map contract.
        $map = $event['calendarIds'] ?? null;
        if ($map instanceof stdClass) $map = get_object_vars($map);
        if (!is_array($map)) throw new RuntimeException();
        $ids = [];
        foreach ($map as $id => $value) {
            $id = (string)$id;
            if ($id === '' || strlen($id) > 1024 || $value !== true) throw new RuntimeException();
            $ids[] = $id;
        }
        if (!$ids || count($ids) > 16) throw new RuntimeException();
        sort($ids, SORT_STRING); return $ids;
    }
    private static function equal(mixed $a, mixed $b): bool {
        // Canonicalize maps without changing list order or losing value types.
        $canonical = function ($v) use (&$canonical) {
            if ($v instanceof stdClass) $v = get_object_vars($v);
            if (is_array($v)) { if (!array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as $k=>$x) $v[$k] = $canonical($x); }
            return $v;
        };
        return $canonical($a) === $canonical($b);
    }
    private static function wall(string $s, string $zone): DateTimeImmutable {
        if (!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\z/D', $s)) throw new RuntimeException();
        $wall = EwsCalendarViewQuery::parseDateTime($s . 'Z');
        if (!in_array($zone, ['UTC','Etc/UTC','Europe/Amsterdam'], true) || ($zone === 'Europe/Amsterdam' && substr($s, 0, 4) < '1970')) throw new RuntimeException();
        $z = new DateTimeZone($zone); $ts = (int)$wall->format('U'); $trans = $z->getTransitions($ts - 259200, $ts + 259200);
        if (!$trans) throw new RuntimeException();
        $candidates = [];
        foreach ($trans as $t) {
            $n = $ts - $t['offset']; $d = (new DateTimeImmutable('@'.$n))->setTimezone($z);
            if ($d->format('Y-m-d\TH:i:s') === $s) $candidates[$n] = $n;
        }
        if (count($candidates) !== 1) throw new RuntimeException();
        return (new DateTimeImmutable('@'.reset($candidates)))->setTimezone(new DateTimeZone('UTC'));
    }
    private static function occurrenceTimes(array $m, string $rid): array {
        $zone = $m['timeZone'] ?? null;
        if (!is_string($zone) || !is_string($m['start'] ?? null) || !is_string($m['uid'] ?? null) || $m['uid'] === '') throw new RuntimeException();
        self::wall($m['start'], $zone); $original = self::wall($rid, $zone);
        $duration = $m['duration'] ?? null; $allDay = $m['showWithoutTime'] ?? false;
        if (!is_bool($allDay) || !is_string($duration) || !preg_match('/\AP(?:(\d{1,3})W)?(?:(\d{1,3})D)?(?:T(?:(\d{1,3})H)?(?:(\d{1,3})M)?(?:(\d{1,3})S)?)?\z/D', $duration, $parts) || $duration === 'P' || str_ends_with($duration, 'T')) throw new RuntimeException();
        $days = (int)($parts[1] ?? 0) * 7 + (int)($parts[2] ?? 0);
        $seconds = (int)($parts[3] ?? 0) * 3600 + (int)($parts[4] ?? 0) * 60 + (int)($parts[5] ?? 0);
        if ($days * 86400 + $seconds < 1 || $days * 86400 + $seconds > 366 * 86400 || ($allDay && (substr($m['start'],11) !== '00:00:00' || !$days || $seconds))) throw new RuntimeException();
        if (isset($m['endTimeZone']) && $m['endTimeZone'] !== $zone) throw new RuntimeException();
        $clock = (int)substr($m['start'],11,2)*3600 + (int)substr($m['start'],14,2)*60 + (int)substr($m['start'],17,2);
        if ($zone === 'Europe/Amsterdam' && !$allDay && ($clock < 10800 || $days || $clock + $seconds >= 86400)) throw new RuntimeException();
        foreach (['recurrenceRules','excludedRecurrenceRules','timeZones'] as $k) if (($m[$k] ?? null) !== null && self::map($m[$k]) !== []) throw new RuntimeException();
        if (($m['baseEventId'] ?? null) !== null || ($m['recurrenceId'] ?? null) !== null || ($m['recurrenceIdTimeZone'] ?? null) !== null || !empty($m['relatedTo'])) throw new RuntimeException();
        self::ruleMember($m, $rid);
        $overrides = self::map($m['recurrenceOverrides'] ?? []);
        if (count($overrides) > 1000) throw new RuntimeException();
        $expected = $rid; $excluded = false;
        foreach ($overrides as $key=>$patch) {
            if (!is_string($key)) throw new RuntimeException();
            self::wall($key, $zone); $patch = self::map($patch);
            if ($patch === ['excluded'=>true]) { if ($key === $rid) $excluded = true; continue; }
            if ($patch === []) continue;
            if (array_keys($patch) !== ['start'] || !is_string($patch['start'])) throw new RuntimeException();
            self::wall($patch['start'], $zone);
            if ($key === $rid) $expected = $patch['start'];
        }
        if ($allDay && substr($expected, 11) !== '00:00:00') throw new RuntimeException();
        $moved = self::wall($expected, $zone);
        return [$expected, $moved == $original ? [$original] : [$original, $moved], $excluded];
    }
    private static function ruleMember(array $m, string $rid): void {
        $r = self::map($m['recurrenceRule'] ?? null);
        if (array_diff(array_keys($r), ['@type','frequency','interval','byDay','firstDayOfWeek','count','until']) || (array_key_exists('@type',$r) && $r['@type'] !== 'RecurrenceRule')) throw new RuntimeException();
        $interval = array_key_exists('interval',$r) ? $r['interval'] : 1; $first = array_key_exists('firstDayOfWeek',$r) ? $r['firstDayOfWeek'] : 'mo'; $days = ['mo','tu','we','th','fr','sa','su'];
        if (!is_int($interval) || $interval < 1 || $interval > 99 || !in_array($first, $days, true) || (array_key_exists('count',$r) && array_key_exists('until',$r))) throw new RuntimeException();
        $start = EwsCalendarViewQuery::parseDateTime($m['start'].'Z'); $instance = EwsCalendarViewQuery::parseDateTime($rid.'Z');
        if ($instance < $start || substr($m['start'],11) !== substr($rid,11)) throw new RuntimeException();
        $diff = intdiv((int)$instance->format('U') - (int)$start->format('U'), 86400);
        if (($r['frequency'] ?? null) === 'daily') {
            if (array_key_exists('byDay',$r) || $diff % $interval !== 0) throw new RuntimeException();
            $ordinal = intdiv($diff,$interval)+1;
        } elseif (($r['frequency'] ?? null) === 'weekly') {
            $startDay = (int)$start->format('N')-1; $firstPos = array_search($first,$days,true); $startPos = ($startDay-$firstPos+7)%7;
            $by = array_key_exists('byDay',$r) ? $r['byDay'] : [['day'=>$days[$startDay]]];
            if (!is_array($by) || !array_is_list($by) || !$by) throw new RuntimeException();
            $positions = [];
            foreach ($by as $d) {
                $d = self::map($d);
                if (array_diff(array_keys($d), ['@type','day']) || (array_key_exists('@type',$d) && $d['@type'] !== 'NDay') || !in_array($d['day'] ?? null,$days,true)) throw new RuntimeException();
                $p = (array_search($d['day'],$days,true)-$firstPos+7)%7;
                if (in_array($p,$positions,true)) throw new RuntimeException(); $positions[]=$p;
            }
            if (!in_array($startPos,$positions,true)) throw new RuntimeException();
            $week = intdiv($startPos+$diff,7); $pos = ($startPos+$diff)%7;
            if ($week % $interval !== 0 || !in_array($pos,$positions,true)) throw new RuntimeException();
            $ordinal = $week === 0 ? count(array_filter($positions,fn($p)=>$p >= $startPos && $p <= $pos)) : count(array_filter($positions,fn($p)=>$p >= $startPos)) + (intdiv($week,$interval)-1)*count($positions) + count(array_filter($positions,fn($p)=>$p <= $pos));
        } else throw new RuntimeException();
        if (array_key_exists('count',$r) && (!is_int($r['count']) || $r['count'] < 1 || $r['count'] > 999 || $ordinal > $r['count'])) throw new RuntimeException();
        if (array_key_exists('until',$r)) {
            if (!is_string($r['until'])) throw new RuntimeException();
            $until = EwsCalendarViewQuery::parseDateTime($r['until'].'Z');
            if ($until < $start || $instance > $until) throw new RuntimeException();
        }
    }
    private static function checkEvent(array $event, array $master, array $folders, string $expected, string $base, DateTimeImmutable $after, DateTimeImmutable $before): void {
        if (!is_string($event['id'] ?? null) || $event['id'] === '' || $event['id'] === $base || ($event['start'] ?? null) !== $expected || self::members($event) !== $folders || ($event['uid'] ?? null) !== $master['uid']) throw new RuntimeException();
        if (isset($event['recurrenceIdTimeZone']) && $event['recurrenceIdTimeZone'] !== $master['timeZone']) throw new RuntimeException();
        if (($event['recurrenceRule'] ?? null) !== null) throw new RuntimeException();
        foreach (['recurrenceRules','excludedRecurrenceRules','recurrenceOverrides'] as $k) if (($event[$k] ?? null) !== null && self::map($event[$k]) !== []) throw new RuntimeException();
        foreach (['uid','title','timeZone','endTimeZone','duration','showWithoutTime','status','privacy','freeBusyStatus','description','descriptionContentType','categories','useDefaultAlerts','alerts','locations','mainLocationId','participants','organizerCalendarAddress','calendarIds'] as $k) if (!self::equal($event[$k] ?? null,$master[$k] ?? null)) throw new RuntimeException();
        $start = self::wall($expected, $master['timeZone']);
        // The occurrence can overlap the original window when moved. A matching
        // result must nevertheless have the reconstructed UTC start.
        if (!is_string($event['utcStart'] ?? null) || EwsCalendarViewQuery::parseDateTime($event['utcStart']) != $start) throw new RuntimeException();
        preg_match('/\AP(?:(\d{1,3})W)?(?:(\d{1,3})D)?(?:T(?:(\d{1,3})H)?(?:(\d{1,3})M)?(?:(\d{1,3})S)?)?\z/D', $master['duration'], $parts);
        $days = (int)($parts[1] ?? 0)*7 + (int)($parts[2] ?? 0);
        $seconds = (int)($parts[3] ?? 0)*3600 + (int)($parts[4] ?? 0)*60 + (int)($parts[5] ?? 0);
        $endWall = EwsCalendarViewQuery::parseDateTime($expected.'Z')->modify('+'.$days.' days');
        if ((int)$endWall->format('Y') > 9999) throw new RuntimeException();
        $end = self::wall($endWall->format('Y-m-d\TH:i:s'), $master['timeZone'])->modify('+'.$seconds.' seconds');
        if ((int)$end->format('Y') > 9999 || $end <= $start || $end <= $after || $start >= $before) throw new RuntimeException();
        if (isset($event['utcEnd']) && (!is_string($event['utcEnd']) || EwsCalendarViewQuery::parseDateTime($event['utcEnd']) != $end)) throw new RuntimeException();
    }
}

/** Confirmed absence only; intentionally does not expose identity or contents. */
final class EwsCalendarOccurrenceNotFound extends RuntimeException {
    public function __construct() { parent::__construct('Occurrence not found'); }
}

/** Internal per-resolution client adapter; no changes to the query helper. */
final class EwsCalendarOccurrenceResolverBudgetClient {
    public const MAX_OPERATIONS = 512;
    public const MAX_BYTES = 16777216;
    public const MAX_SECONDS = 30;
    private const CAL = 'urn:ietf:params:jmap:calendars';
    private object $client;
    private int $started;
    private int $operations = 0;
    private int $bytes = 0;
    private ?string $account = null;
    private ?string $apiUrl = null;

    public function __construct(object $client) {
        if (!is_callable([$client, 'getSession']) || !is_callable([$client, 'call']) || !is_callable([$client, 'invalidateSession'])) throw new RuntimeException();
        $this->client = $client; $this->started = hrtime(true);
    }
    public function checkBudget(): void {
        if (hrtime(true) - $this->started >= self::MAX_SECONDS * 1000000000 || $this->bytes > self::MAX_BYTES) throw new RuntimeException();
    }
    private function operation(): void {
        $this->checkBudget();
        if ($this->operations >= self::MAX_OPERATIONS) throw new RuntimeException();
        ++$this->operations;
    }
    private function charge(mixed $value): void {
        $this->bytes += strlen(json_encode($value, JSON_THROW_ON_ERROR));
        $this->checkBudget();
    }
    private function validateSession(mixed $session): array {
        if (!is_array($session)) throw new RuntimeException();
        $account = $session['primaryAccounts'][self::CAL] ?? null;
        $api = $session['apiUrl'] ?? null;
        if (!is_string($account) || $account === '' || !is_string($api) || $api === '' || strlen($api) > 8192) throw new RuntimeException();
        $parts = parse_url($api);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new RuntimeException();
        if ($this->account === null) { $this->account = $account; $this->apiUrl = $api; }
        elseif ($this->account !== $account || $this->apiUrl !== $api) throw new RuntimeException();
        return $session;
    }
    public function getSession(): array {
        // Count even potentially cached reads: the compatible client may fetch.
        $this->operation();
        $session = $this->client->getSession();
        $this->charge($session);
        return $this->validateSession($session);
    }
    public function call(array $calls, array $using): array {
        // Recheck the pinned endpoint/account before every actual request.
        $this->getSession();
        $this->operation(); $this->charge([$calls, $using]);
        $result = $this->client->call($calls, $using);
        $this->charge($result);
        if (!is_array($result)) throw new RuntimeException();
        return $result;
    }
    public function refreshSession(): array {
        $this->checkBudget();
        // Reserve the potentially networked getSession before invalidating the
        // caller's cache; a budget failure must not leave it invalidated.
        if ($this->operations >= self::MAX_OPERATIONS) throw new RuntimeException();
        $this->client->invalidateSession();
        return $this->getSession();
    }
}
