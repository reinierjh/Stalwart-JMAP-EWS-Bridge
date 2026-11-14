<?php
// Standalone converter tests. May also be evaluated after loading the classes via stdin.
if (!class_exists('EwsConverter')) {
    require __DIR__ . '/../ews/src/Soap.php';
    require __DIR__ . '/../ews/src/Converter.php';
}
function verifyCalendarConversion(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function convertCalendar(array $event): DOMXPath {
    $xml = EwsConverter::calendarToXml(new DOMDocument(), $event, 'cal_folder');
    $doc = new DOMDocument();
    verifyCalendarConversion($doc->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>'), 'invalid XML');
    $xpath = new DOMXPath($doc); $xpath->registerNamespace('t', EwsSoap::NS_T);
    return $xpath;
}
function val(DOMXPath $x, string $path): string { return $x->evaluate('string(/root/t:CalendarItem/t:' . $path . ')'); }
function rejected(array $event, string $label): void {
    try { convertCalendar($event); } catch (EwsCalendarSafetyException $e) { return; }
    throw new RuntimeException('Expected refusal: ' . $label);
}
$base = ['id' => '1', 'uid' => 'uid&1', 'title' => 'Test', 'start' => '2025-03-29T10:00:00', 'timeZone' => 'Europe/Amsterdam', 'duration' => 'PT1H'];
foreach ([
    ['2025-03-29T10:00:00', '2025-03-29T09:00:00Z', '2025-03-29T10:00:00Z'],
    ['2025-03-30T10:00:00', '2025-03-30T08:00:00Z', '2025-03-30T09:00:00Z'],
    ['2025-10-25T10:00:00', '2025-10-25T08:00:00Z', '2025-10-25T09:00:00Z'],
    ['2025-10-26T10:00:00', '2025-10-26T09:00:00Z', '2025-10-26T10:00:00Z'],
    ['2025-03-30T02:30:00', '2025-03-30T01:30:00Z', '2025-03-30T02:30:00Z'],
    ['2025-10-26T02:30:00', '2025-10-26T00:30:00Z', '2025-10-26T01:30:00Z'],
] as [$start, $expectedStart, $expectedEnd]) {
    $x = convertCalendar(array_replace($base, ['start' => $start]));
    verifyCalendarConversion(val($x, 'Start') === $expectedStart && val($x, 'End') === $expectedEnd, 'DST conversion ' . $start);
    verifyCalendarConversion(val($x, 'StartTimeZone/@Id') === 'W. Europe Standard Time' && val($x, 'EndTimeZone/@Id') === 'W. Europe Standard Time', 'Amsterdam zone');
}
$x = convertCalendar(array_replace($base, ['timeZone' => 'UTC']));
verifyCalendarConversion(val($x, 'Start') === '2025-03-29T10:00:00Z' && val($x, 'End') === '2025-03-29T11:00:00Z' && val($x, 'StartTimeZone/@Id') === 'UTC', 'UTC');
$x = convertCalendar(array_replace($base, ['timeZone' => 'Etc/UTC', 'recurrenceRule' => ['frequency' => 'daily', 'count' => 4]]));
verifyCalendarConversion(val($x, 'Start') === '2025-03-29T10:00:00Z' && val($x, 'StartTimeZone/@Id') === 'UTC' && val($x, 'CalendarItemType') === 'RecurringMaster' && val($x, 'Recurrence/t:NumberedRecurrence/t:NumberOfOccurrences') === '4', 'Stalwart normalized Etc/UTC');
foreach ([['2025-03-30', '2025-03-29T23:00:00Z', '2025-03-30T22:00:00Z'], ['2025-10-26', '2025-10-25T22:00:00Z', '2025-10-26T23:00:00Z']] as [$date, $start, $end]) {
    $x = convertCalendar(array_replace($base, ['start' => $date . 'T00:00:00', 'duration' => 'P1D', 'showWithoutTime' => true]));
    verifyCalendarConversion(val($x, 'Start') === $start && val($x, 'End') === $end && val($x, 'IsAllDayEvent') === 'true', 'all-day DST ' . $date);
}
$x = convertCalendar(array_replace($base, ['start' => '2025-03-29T10:00:00', 'duration' => 'P1DT2H']));
verifyCalendarConversion(val($x, 'End') === '2025-03-30T10:00:00Z', 'nominal day plus elapsed hours');
$x = convertCalendar(array_replace($base, ['recurrenceRule' => ['frequency' => 'daily', 'interval' => 2]]));
verifyCalendarConversion(val($x, 'CalendarItemType') === 'RecurringMaster' && val($x, 'Recurrence/t:DailyRecurrence/t:Interval') === '2' && val($x, 'Recurrence/t:NoEndRecurrence/t:StartDate') === '2025-03-29', 'daily no end');
$x = convertCalendar(array_replace($base, ['start' => '2025-06-02T10:00:00', 'recurrenceRule' => ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'interval' => 2, 'byDay' => [['day' => 'mo'], ['@type' => 'NDay', 'day' => 'we']], 'firstDayOfWeek' => 'su', 'count' => 5]]));
verifyCalendarConversion(val($x, 'Recurrence/t:WeeklyRecurrence/t:DaysOfWeek') === 'Monday Wednesday' && val($x, 'Recurrence/t:WeeklyRecurrence/t:FirstDayOfWeek') === 'Sunday' && val($x, 'Recurrence/t:NumberedRecurrence/t:NumberOfOccurrences') === '5', 'weekly count');
foreach ([['2025-04-02T09:59:59', '2025-04-01'], ['2025-04-02T10:00:00', '2025-04-02'], ['2025-04-02T23:59:59', '2025-04-02']] as [$until, $date]) {
    $x = convertCalendar(array_replace($base, ['recurrenceRule' => ['frequency' => 'daily', 'until' => $until]]));
    verifyCalendarConversion(val($x, 'Recurrence/t:EndDateRecurrence/t:EndDate') === $date, 'inclusive until ' . $until);
}
$x = convertCalendar(array_replace($base, ['description' => 'body', 'categories' => ['a' => true], 'locations' => ['a' => ['name' => 'Room']], 'organizerCalendarAddress' => 'mailto:o@example.org', 'participants' => [
    'org' => ['@type' => 'Participant', 'name' => 'Org', 'calendarAddress' => 'mailto:o@example.org', 'roles' => ['owner' => true]],
    'one' => ['name' => 'One', 'calendarAddress' => 'mailto:one@example.org', 'roles' => ['required' => true], 'participationStatus' => 'accepted'],
    'two' => ['name' => 'Two', 'calendarAddress' => 'mailto:two@example.org', 'roles' => ['required' => true], 'participationStatus' => 'tentative'],
    'three' => ['name' => 'Three', 'calendarAddress' => 'mailto:three@example.org', 'roles' => ['optional' => true], 'participationStatus' => 'declined'],
    'four' => ['name' => 'Four', 'calendarAddress' => 'mailto:four@example.org', 'roles' => ['optional' => true]],
], 'recurrenceRule' => ['frequency' => 'daily']]));
$names = []; foreach ($x->query('/root/t:CalendarItem/*') as $node) $names[] = $node->localName;
verifyCalendarConversion($names === ['ItemId', 'ParentFolderId', 'Subject', 'Sensitivity', 'Body', 'Categories', 'UID', 'Start', 'End', 'IsAllDayEvent', 'LegacyFreeBusyStatus', 'Location', 'CalendarItemType', 'Organizer', 'RequiredAttendees', 'OptionalAttendees', 'Recurrence', 'StartTimeZone', 'EndTimeZone'], 'element order');
verifyCalendarConversion($x->query('/root/t:CalendarItem/t:RequiredAttendees/t:Attendee/t:Mailbox')->length === 2 && $x->query('/root/t:CalendarItem/t:OptionalAttendees/t:Attendee/t:Mailbox')->length === 2 && val($x, 'UID') === 'uid&1' && $x->query('/root/t:CalendarItem/t:IsMeeting')->length === 0, 'participants and flags');
verifyCalendarConversion(val($x, 'Organizer/t:Mailbox/t:EmailAddress') === 'o@example.org', 'organizer address');
foreach (['one@example.org' => 'Accept', 'two@example.org' => 'Tentative', 'three@example.org' => 'Decline', 'four@example.org' => 'NoResponseReceived'] as $email => $response) {
    verifyCalendarConversion($x->evaluate('string(//t:Attendee[t:Mailbox/t:EmailAddress="' . $email . '"]/t:ResponseType)') === $response, 'participant response ' . $email);
}
$x = convertCalendar(array_replace($base, ['organizerCalendarAddress' => 'mailto:o@example.org', 'participants' => ['org' => ['calendarAddress' => 'mailto:o@example.org', 'roles' => ['owner' => true, 'required' => true], 'participationStatus' => 'accepted']]]));
verifyCalendarConversion($x->query('//t:Organizer')->length === 1 && val($x, 'RequiredAttendees/t:Attendee/t:ResponseType') === 'Accept', 'organizer also attendee');
$x = convertCalendar(array_replace($base, ['locations' => ['other' => ['name' => 'Wrong'], 'main' => ['name' => 'Right']], 'mainLocationId' => 'main']));
verifyCalendarConversion(val($x, 'Location') === 'Right', 'main location');
$x = convertCalendar(array_replace($base, ['alerts' => ['a' => ['@type' => 'Alert', 'action' => 'display', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M']]]]));
verifyCalendarConversion(val($x, 'ReminderIsSet') === 'true' && val($x, 'ReminderMinutesBeforeStart') === '15', 'reminder');
foreach ([false, null] as $defaultFlag) {
    $x = convertCalendar(array_replace($base, ['useDefaultAlerts' => $defaultFlag, 'alerts' => ['a' => ['action' => 'display', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M']]]]));
    verifyCalendarConversion(val($x, 'ReminderIsSet') === 'true' && val($x, 'ReminderMinutesBeforeStart') === '15', 'explicit reminder with defaults disabled');
    $x = convertCalendar(array_replace($base, ['useDefaultAlerts' => $defaultFlag]));
    verifyCalendarConversion($x->query('/root/t:CalendarItem/t:ReminderIsSet')->length === 0, 'no invented reminder with defaults disabled');
}
foreach ([
    ['timeZone' => null], ['timeZone' => ''], ['timeZone' => 'floating'], ['timeZone' => 'Europe/Paris'], ['timeZone' => 'not-a-zone'],
    ['endTimeZone' => 'UTC'], ['start' => '2025-02-30T10:00:00'], ['start' => '2025-03-29'], ['start' => '2025-03-29T10:00:00Z'],
    ['duration' => 'PT0S'], ['duration' => 'P1M'], ['duration' => 'PT1.5H'], ['showWithoutTime' => true], ['showWithoutTime' => 'true'],
    ['recurrenceOverrides' => ['2025-03-30T10:00:00' => ['excluded' => true]]], ['recurrenceOverrides' => ['2025-03-30T10:00:00' => []]],
    ['recurrenceRules' => [['frequency' => 'daily']]], ['excludedRecurrenceRules' => [['frequency' => 'daily']]], ['recurrenceId' => '2025-03-30T10:00:00'], ['baseEventId' => 'master'],
    ['recurrenceRule' => []], ['recurrenceRule' => (object)[]], ['recurrenceRule' => 'daily'], ['recurrenceRule' => ['frequency' => 'monthly']],
    ['recurrenceRule' => ['frequency' => 'daily', 'interval' => null]], ['recurrenceRule' => ['frequency' => 'daily', 'interval' => 0]],
    ['recurrenceRule' => ['frequency' => 'daily', 'count' => 0]], ['recurrenceRule' => ['frequency' => 'daily', 'count' => 1000]],
    ['recurrenceRule' => ['frequency' => 'daily', 'count' => 1, 'until' => '2025-04-01T10:00:00']],
    ['recurrenceRule' => ['frequency' => 'daily', 'until' => '2025-02-30T10:00:00']], ['recurrenceRule' => ['frequency' => 'daily', 'until' => '2025-03-28T10:00:00']],
    ['recurrenceRule' => ['frequency' => 'daily', 'byHour' => [10]]], ['recurrenceRule' => ['frequency' => 'weekly', 'byDay' => null]],
    ['recurrenceRule' => ['frequency' => 'weekly', 'byDay' => [['day' => 'mo']]]], ['recurrenceRule' => ['frequency' => 'weekly', 'byDay' => [['day' => 'sa', 'nthOfPeriod' => 1]]]],
    ['start' => '2025-03-29T01:30:00', 'recurrenceRule' => ['frequency' => 'daily']],
    ['duration' => 'P1D', 'recurrenceRule' => ['frequency' => 'daily']],
    ['alerts' => ['a' => ['action' => 'display', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT30S']]]],
    ['alerts' => ['a' => ['action' => 'email', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M']]]],
    ['useDefaultAlerts' => true], ['useDefaultAlerts' => true, 'alerts' => ['a' => ['action' => 'display', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M']]]],
    ['useDefaultAlerts' => 'true'], ['useDefaultAlerts' => 0], ['useDefaultAlerts' => []],
    ['status' => 'cancelled'], ['status' => 'tentative'], ['privacy' => 'x-unknown'], ['privacy' => 'secret'],
    ['locations' => ['a' => ['name' => 'A'], 'b' => ['name' => 'B']]], ['locations' => ['a' => ['name' => 'A']], 'mainLocationId' => 'missing'],
    ['participants' => ['p' => ['name' => 'No address']]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['email' => 'p@example.org']]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['calendarAddress' => 'mailto:p@example.org']]],
    ['participants' => ['p' => ['calendarAddress' => 'mailto:p@example.org']]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['calendarAddress' => 'https://example.org/p']]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['calendarAddress' => 'mailto:org@example.org', 'roles' => ['informational' => true]]]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['calendarAddress' => 'mailto:org@example.org', 'participationStatus' => 'delegated']]],
    ['organizerCalendarAddress' => 'mailto:org@example.org', 'participants' => ['p' => ['calendarAddress' => 'mailto:org@example.org', 'expectReply' => true]]],
] as $change) rejected(array_replace($base, $change), json_encode($change));
// Existing write guard must remain stricter than the new read converter.
try { EwsConverter::safeCalendarTimes($base); throw new RuntimeException('write guard widened'); } catch (EwsCalendarSafetyException $expected) {}
$card = ['id' => 'contact', 'emails' => [
    'z' => ['address' => 'z@example.org'], 'a' => ['address' => 'a@example.org'], 'w' => ['address' => 'work@example.org', 'contexts' => ['work' => true]],
], 'phones' => [
    'z' => ['number' => '222', 'contexts' => ['work' => true]], 'a' => ['number' => '111', 'contexts' => ['work' => true]],
], 'addresses' => [
    'z' => ['contexts' => ['home' => true], 'components' => [['kind' => 'locality', 'value' => 'Home']]],
    'a' => ['contexts' => ['work' => true], 'components' => [['kind' => 'locality', 'value' => 'Work']]],
    'm' => ['components' => [['kind' => 'locality', 'value' => 'Other']]],
], 'onlineServices' => [
    'z' => ['uri' => 'sip:z@example.org'], 'a' => ['uri' => 'sip:a@example.org'], 'm' => ['uri' => 'sip:m@example.org'],
]];
$reversed = $card;
foreach (['emails', 'phones', 'addresses', 'onlineServices'] as $property) $reversed[$property] = array_reverse($card[$property], true);
$contact1 = EwsConverter::contactToXml(new DOMDocument(), $card, null);
$contact2 = EwsConverter::contactToXml(new DOMDocument(), $reversed, null);
verifyCalendarConversion($contact1 === $contact2, 'contact map insertion order changed indexed slots');
foreach (['EmailAddress1">work@example.org', 'EmailAddress2">a@example.org', 'EmailAddress3">z@example.org',
          'BusinessPhone">111', 'BusinessPhone2">222', '<t:City>Work</t:City>', '<t:City>Home</t:City>', '<t:City>Other</t:City>',
          'ImAddress1">sip:a@example.org', 'ImAddress2">sip:m@example.org', 'ImAddress3">sip:z@example.org'] as $expected) {
    verifyCalendarConversion(str_contains($contact1, $expected), 'missing or wrong contact slot: ' . $expected);
}
echo "calendar conversion tests passed\n";
