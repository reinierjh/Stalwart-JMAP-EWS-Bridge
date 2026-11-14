<?php

// Can be run with the helper preloaded over SSH stdin, without remote files.
if (!class_exists('EwsCalendarViewQuery')) require_once __DIR__ . '/../ews/src/CalendarViewQuery.php';

const CV_CORE = 'urn:ietf:params:jmap:core';
const CV_CAL = 'urn:ietf:params:jmap:calendars';
function cvCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cvReject(callable $f, string $contains): void {
    try { $f(); } catch (Throwable $e) { cvCheck(str_contains($e->getMessage(), $contains), "expected $contains, got " . $e->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $contains);
}

final class CvFake {
    public array $calls = [];
    public $hook = null;
    public array $session;
    public array $rows;
    public function __construct() {
        $this->session = ['capabilities' => [CV_CORE => ['maxObjectsInGet' => 2], CV_CAL => []],
            'primaryAccounts' => [CV_CORE => 'mail', CV_CAL => 'cal-account'],
            'accounts' => ['cal-account' => ['accountCapabilities' => [CV_CAL => ['maxExpandedQueryDuration' => 'P30D']]]]];
        // The server has expanded a DST transition, moved one instance, and
        // omitted a deleted one. recurrenceId remains the original local time.
        $this->rows = [
            ['id' => 'synthetic-1', 'baseEventId' => 'base', 'recurrenceId' => '2025-03-23T09:00:00', 'utcStart' => '2025-03-23T08:00:00Z', 'start' => '2025-03-23T09:00:00', 'timeZone' => 'Europe/Amsterdam', 'recurrenceOverrides' => null],
            ['id' => 'synthetic-moved', 'baseEventId' => 'base', 'recurrenceId' => '2025-03-30T09:00:00', 'utcStart' => '2025-03-30T10:00:00Z', 'start' => '2025-03-30T12:00:00', 'timeZone' => 'Europe/Amsterdam', 'recurrenceOverrides' => null],
            ['id' => 'single', 'baseEventId' => null, 'utcStart' => '2025-04-02T09:00:00Z', 'start' => '2025-04-02T09:00:00', 'recurrenceOverrides' => null],
        ];
    }
    public function getSession(): array { return $this->session; }
    public function call(array $calls, array $using): array {
        cvCheck(count($calls) === 1 && $using === [CV_CORE, CV_CAL], 'read-only using');
        [$method, $args, $tag] = $calls[0];
        cvCheck(in_array($method, ['CalendarEvent/query', 'CalendarEvent/get'], true), 'no writes');
        cvCheck(($args['accountId'] ?? '') === 'cal-account', 'calendar account');
        $this->calls[] = [$method, $args];
        if ($method === 'CalendarEvent/query') {
            cvCheck($args['filter'] === ['inCalendar' => 'calendar', 'after' => '2025-03-20T00:00:00', 'before' => '2025-04-10T00:00:00'], 'single normalized filter');
            cvCheck(($args['timeZone'] ?? null) === 'Etc/UTC' && $args['expandRecurrences'] === true && $args['sort'] === [['property' => 'start', 'isAscending' => true]], 'top-level timezone and expanded sorted query');
            $data = ['accountId' => 'cal-account', 'queryState' => 'q1', 'position' => $args['position'], 'total' => count($this->rows), 'ids' => array_slice(array_column($this->rows, 'id'), $args['position'], $args['limit'])];
        } else {
            cvCheck(count($args['ids']) <= 2, 'get batching');
            $list = [];
            foreach (array_reverse($this->rows) as $row) if (in_array($row['id'], $args['ids'], true)) {
                if (isset($args['properties'])) {
                    cvCheck(!in_array('recurrenceOverrides', $args['properties'], true), 'utcStart/overrides must be separate');
                    $row = array_intersect_key($row, array_flip($args['properties']));
                } else unset($row['baseEventId'], $row['recurrenceId'], $row['utcStart']);
                $list[] = $row;
            }
            $data = ['accountId' => 'cal-account', 'state' => 's1', 'list' => $list, 'notFound' => []];
        }
        $response = [[$method, $data, $tag]];
        if ($this->hook !== null) $response = ($this->hook)($method, $args, $response, count($this->calls));
        return $response;
    }
}
function cvRun(CvFake $f): array { return (new EwsCalendarViewQuery($f))->query('calendar', '2025-03-20T01:00:00+01:00', '2025-04-09T20:00:00-04:00'); }

$f = new CvFake(); $result = cvRun($f);
cvCheck($result['accountId'] === 'cal-account' && $result['state'] === 's1' && $result['queryState'] === 'q1', 'result states');
cvCheck(array_column($result['events'], 'id') === ['synthetic-1', 'synthetic-moved', 'single'], 'multiple pages and order');
cvCheck($result['events'][1]['recurrenceId'] === '2025-03-30T09:00:00' && $result['events'][1]['start'] === '2025-03-30T12:00:00', 'moved instance original identity');
cvCheck($result['events'][0]['utcStart'] === '2025-03-23T08:00:00Z' && $result['events'][1]['utcStart'] === '2025-03-30T10:00:00Z', 'DST UTC metadata');
cvCheck(array_key_exists('baseEventId', $result['events'][2]) && $result['events'][2]['baseEventId'] === null && !array_key_exists('recurrenceId', $result['events'][2]), 'live singleton preserves null and absent');
$default = $f->rows[2]; unset($default['baseEventId'], $default['recurrenceId'], $default['utcStart']);
cvCheck($result['defaultEvents'][2] === $default, 'exact unmodified singleton default projection');
cvCheck(count(array_filter($f->calls, fn($c) => $c[0] === 'CalendarEvent/query')) === 4, 'pages rechecked');
cvCheck(!in_array('deleted', array_column($result['events'], 'id'), true), 'deleted occurrence omitted by server');
$f = new CvFake(); $f->session['capabilities'][CV_CORE]['maxObjectsInGet'] = 1;
$f->rows = [
    ['id' => 'utc-event', 'start' => '2025-03-30T09:59:00', 'timeZone' => 'Etc/UTC', 'utcStart' => '2025-03-30T09:59:00Z', 'baseEventId' => null],
    ['id' => 'amsterdam-event', 'start' => '2025-03-30T11:00:00', 'timeZone' => 'Europe/Amsterdam', 'utcStart' => '2025-03-30T09:00:00Z', 'baseEventId' => null],
];
$result = cvRun($f);
cvCheck(array_column($result['events'], 'id') === ['utc-event', 'amsterdam-event'], 'local start sorting may reverse UTC ordering');
cvCheck(count(array_filter($f->calls, fn($c) => $c[0] === 'CalendarEvent/query')) === 4, 'mixed timezones span multiple query pages and recheck');
$f = new CvFake(); $f->rows = []; cvCheck(cvRun($f)['events'] === [], 'empty result still checks state');
foreach ([null, 'absent'] as $base) foreach ([null, 'absent'] as $recurrence) {
    $f = new CvFake();
    if ($base === 'absent') unset($f->rows[2]['baseEventId']); else $f->rows[2]['baseEventId'] = null;
    if ($recurrence === 'absent') unset($f->rows[2]['recurrenceId']); else $f->rows[2]['recurrenceId'] = null;
    $single = cvRun($f)['events'][2];
    cvCheck(array_key_exists('baseEventId', $single) === ($base === null) && array_key_exists('recurrenceId', $single) === ($recurrence === null), 'singleton absence preserved');
}
foreach (['P3W', 'P2W7D', 'P2W7DT0S', 'P0W21D', 'P2W6DT24H', 'P52W1D'] as $duration) {
    $f = new CvFake(); $f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration'] = $duration;
    cvCheck(count(cvRun($f)['events']) === 3, 'accepted combined duration');
}
foreach (['P2W6D', 'P0W20DT23H59M59.999999S'] as $duration) {
    $f = new CvFake(); $f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration'] = $duration;
    cvReject(fn() => cvRun($f), 'server expansion');
    cvCheck($f->calls === [], 'duration limit before JMAP');
}
foreach (['P', 'PT', 'P1WT', 'P1Y', 'P1W1D1W', 'P1DT1.1234567S'] as $duration) {
    $f = new CvFake(); $f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration'] = $duration;
    cvReject(fn() => cvRun($f), 'Invalid max');
}

foreach (['missing capability', 'no primary', 'no account capability', 'bad duration', 'short duration', 'bad core limit'] as $case) {
    $f = new CvFake();
    switch ($case) {
        case 'missing capability': unset($f->session['capabilities'][CV_CAL]); break;
        case 'no primary': unset($f->session['primaryAccounts'][CV_CAL]); break;
        case 'no account capability': unset($f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]); break;
        case 'bad duration': $f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration'] = 'banana'; break;
        case 'short duration': $f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration'] = 'P20D'; break;
        case 'bad core limit': $f->session['capabilities'][CV_CORE]['maxObjectsInGet'] = 0; break;
    }
    cvReject(fn() => cvRun($f), match ($case) { 'bad duration' => 'Invalid max', 'short duration' => 'server expansion', 'bad core limit' => 'maxObjectsInGet', default => 'capability/account' });
    cvCheck($f->calls === [], 'invalid session must not call JMAP');
}
foreach (['2025-02-29T00:00:00Z', '2025-03-20T00:00:00', '2025-03-20T00:00:00+14:01', '2025-03-20T24:00:00Z', '2025-03-20T00:00:60Z', '2025-03-20T00:00:00.1234567Z'] as $invalid) {
    cvReject(fn() => (new EwsCalendarViewQuery(new CvFake()))->query('calendar', $invalid, '2025-04-10T00:00:00Z'), 'Invalid');
}
cvReject(fn() => (new EwsCalendarViewQuery(new CvFake()))->query('calendar', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z'), 'local limit');
cvReject(fn() => (new EwsCalendarViewQuery(new CvFake()))->query('calendar', '2025-01-01T00:00:00Z', '2025-01-01T00:00:00Z'), 'local limit');

$faults = [
    'method error' => ['JMAP CalendarEvent/query error: expandDurationTooLarge', function($m,$a,$r) { if ($m === 'CalendarEvent/query') return [['error', ['type' => 'expandDurationTooLarge'], 'cv']]; return $r; }],
    'expansion overflow' => ['cannotCalculateOccurrences', function($m,$a,$r) { if ($m === 'CalendarEvent/query') return [['error', ['type' => 'cannotCalculateOccurrences'], 'cv']]; return $r; }],
    'permission loss' => ['forbidden', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) return [['error', ['type'=>'forbidden'], 'cv']]; return $r; }],
    'tuple tag' => ['tuple', function($m,$a,$r) { $r[0][2] = 'bad'; return $r; }],
    'tuple name' => ['method', function($m,$a,$r) { $r[0][0] = 'Calendar/get'; return $r; }],
    'wrong account' => ['Malformed', function($m,$a,$r) { $r[0][1]['accountId'] = 'mail'; return $r; }],
    'query state' => ['changed during paging', function($m,$a,$r) { if ($m === 'CalendarEvent/query' && $a['position'] > 0) $r[0][1]['queryState'] = 'q2'; return $r; }],
    'get state' => ['changed during read', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) $r[0][1]['state'] = 's2'; return $r; }],
    'late mutation' => ['changed during read', function($m,$a,$r,$n) { if ($m === 'CalendarEvent/get' && $n > 3) $r[0][1]['state'] = 's2'; return $r; }],
    'not found' => ['incomplete get', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) $r[0][1]['notFound'] = [$a['ids'][0]]; return $r; }],
    'missing item' => ['Missing get ID', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) array_pop($r[0][1]['list']); return $r; }],
    'wrong get ID' => ['get ID', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) $r[0][1]['list'][0]['id'] = 'wrong'; return $r; }],
    'duplicate query ID' => ['duplicate query ID', function($m,$a,$r) { if ($m === 'CalendarEvent/query' && $a['position'] === 2) $r[0][1]['ids'] = ['synthetic-1']; return $r; }],
    'missing base' => ['Incomplete occurrence identity', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && ($a['properties'] ?? []) === ['id','baseEventId','recurrenceId','utcStart'] && $a['ids']) $r[0][1]['list'][0]['baseEventId'] = null; return $r; }],
    'missing occurrence recurrence' => ['Incomplete occurrence identity', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && ($a['properties'] ?? []) === ['id','baseEventId','recurrenceId','utcStart'] && $a['ids']) unset($r[0][1]['list'][0]['recurrenceId']); return $r; }],
    'missing occurrence base' => ['Incomplete occurrence identity', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && ($a['properties'] ?? []) === ['id','baseEventId','recurrenceId','utcStart'] && $a['ids']) unset($r[0][1]['list'][0]['baseEventId']); return $r; }],
    'missing utcStart' => ['Missing requested event metadata: utcStart', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && ($a['properties'] ?? []) === ['id','baseEventId','recurrenceId','utcStart'] && $a['ids']) unset($r[0][1]['list'][0]['utcStart']); return $r; }],
    'conflicting metadata' => ['Conflicting', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && !isset($a['properties']) && $a['ids']) $r[0][1]['list'][0]['baseEventId'] = 'wrong'; return $r; }],
    'unconfirmed default metadata' => ['Conflicting', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && $a['ids']) { if (!isset($a['properties'])) $r[0][1]['list'][0]['recurrenceId'] = 'private'; else unset($r[0][1]['list'][0]['recurrenceId']); } return $r; }],
    'malformed occurrence identity' => ['Incomplete occurrence identity', function($m,$a,$r) { if ($m === 'CalendarEvent/get' && ($a['properties'] ?? []) === ['id','baseEventId','recurrenceId','utcStart'] && $a['ids']) $r[0][1]['list'][0]['baseEventId'] = false; return $r; }],
    'too many' => ['result limit', function($m,$a,$r) { if ($m === 'CalendarEvent/query') $r[0][1]['total'] = 2001; return $r; }],
    'short page' => ['Incomplete query', function($m,$a,$r) { if ($m === 'CalendarEvent/query') $r[0][1]['ids'] = []; return $r; }],
    'requery mutation' => ['query changed', function($m,$a,$r,$n) { if ($m === 'CalendarEvent/query' && $n > 6) $r[0][1]['queryState'] = 'q2'; return $r; }],
];
foreach ($faults as $name => [$message, $hook]) { $f = new CvFake(); $f->hook = $hook; cvReject(fn() => cvRun($f), $message); }
$f = new CvFake(); $f->hook = fn() => throw new RuntimeException('private event payload'); cvReject(fn() => cvRun($f), 'JMAP transport failed');
$f = new CvFake(); $f->hook = fn($m,$a,$r) => [['error', ['type'=>'private event payload', 'description'=>'private event payload'], 'cv']]; cvReject(fn() => cvRun($f), 'error: unknown');
$sessionFailure = new class { public function getSession(): array { throw new RuntimeException('private credentials'); } };
cvReject(fn() => (new EwsCalendarViewQuery($sessionFailure))->query('calendar', '2025-03-20T00:00:00Z', '2025-04-10T00:00:00Z'), 'JMAP session failed');
$f = new CvFake(); $f->rows[1]['utcStart'] = 'not-a-date'; cvReject(fn() => cvRun($f), 'Invalid utcStart');
$f = new CvFake(); unset($f->session['accounts']['cal-account']['accountCapabilities'][CV_CAL]['maxExpandedQueryDuration']);
$f->hook = fn($m,$a,$r) => $m === 'CalendarEvent/query' ? [['error', ['type' => 'expandDurationTooLarge'], 'cv']] : $r;
cvReject(fn() => cvRun($f), 'expandDurationTooLarge');
echo "calendar view query tests passed\n";
