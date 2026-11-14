<?php
// Standalone: no JMAP requests or server writes.
if (!class_exists('EwsConverter')) {
    require __DIR__ . '/../ews/src/Soap.php';
    require __DIR__ . '/../ews/src/Converter.php';
}
if (!class_exists('EwsCalendarOccurrenceId')) require __DIR__ . '/../ews/src/CalendarOccurrenceId.php';
function occurrenceConversionCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function occurrenceConversionXml(array $instance, array $master, string $account, string $id): DOMXPath {
    $xml = EwsConverter::calendarOccurrenceToXml(new DOMDocument(), $instance, $master, $account, $id, 'cal_folder');
    $doc = new DOMDocument();
    occurrenceConversionCheck($doc->loadXML('<root xmlns:t="' . EwsSoap::NS_T . '">' . $xml . '</root>'), 'invalid XML');
    $x = new DOMXPath($doc); $x->registerNamespace('t', EwsSoap::NS_T);
    return $x;
}
function occurrenceConversionValue(DOMXPath $x, string $path): string { return $x->evaluate('string(/root/t:CalendarItem/t:' . $path . ')'); }
function occurrenceConversionReject(array $event, array $master, string $account, string $id, string $label): void {
    try { occurrenceConversionXml($event, $master, $account, $id); } catch (EwsCalendarSafetyException $e) { return; }
    throw new RuntimeException('Expected refusal: ' . $label);
}
$account = 'account-1';
$rid = '2025-03-31T10:00:00';
$master = ['id' => 'base1', 'uid' => 'uid1', 'version' => '2.0', 'title' => 'Series', 'start' => '2025-03-24T10:00:00', 'duration' => 'PT1H', 'timeZone' => 'Europe/Amsterdam', 'privacy' => 'private', 'calendarIds' => ['c1' => true], 'organizerCalendarAddress' => 'mailto:organizer@example.org', 'participants' => [
    'org' => ['calendarAddress' => 'mailto:organizer@example.org', 'name' => 'Organizer', 'roles' => ['owner' => true]],
    'attendee' => ['calendarAddress' => 'mailto:attendee@example.org', 'roles' => ['optional' => true], 'participationStatus' => 'accepted'],
], 'recurrenceRule' => ['frequency' => 'weekly', 'count' => 5, 'byDay' => [['day' => 'mo']]]];
$event = $master;
unset($event['recurrenceRule']);
$event['id'] = 'synthetic-1'; $event['baseEventId'] = 'base1'; $event['recurrenceId'] = $rid;
$event['recurrenceIdTimeZone'] = 'Europe/Amsterdam'; $event['start'] = $rid;
$event['utcStart'] = '2025-03-31T08:00:00Z'; $event['utcEnd'] = '2025-03-31T09:00:00Z';
$id = EwsCalendarOccurrenceId::encode($account, 'base1', $rid);
$originalEvent = $event; $originalMaster = $master;
$x = occurrenceConversionXml($event, $master, $account, $id);
occurrenceConversionCheck(occurrenceConversionValue($x, 'ItemId/@Id') === $id && occurrenceConversionValue($x, 'ItemId/@ChangeKey') === EwsConverter::itemChangeKey($master), 'stable item ID and master change key');
occurrenceConversionCheck(occurrenceConversionValue($x, 'Start') === '2025-03-31T08:00:00Z' && occurrenceConversionValue($x, 'End') === '2025-03-31T09:00:00Z' && occurrenceConversionValue($x, 'CalendarItemType') === 'Occurrence' && occurrenceConversionValue($x, 'Sensitivity') === 'Private', 'occurrence fields and DST');
occurrenceConversionCheck(occurrenceConversionValue($x, 'Organizer/t:Mailbox/t:EmailAddress') === 'organizer@example.org' && occurrenceConversionValue($x, 'OptionalAttendees/t:Attendee/t:ResponseType') === 'Accept', 'preserve organizer and attendee');
occurrenceConversionCheck($x->query('//t:Recurrence')->length === 0 && $event === $originalEvent && $master === $originalMaster, 'no mutation or recurrence element');
$movedMaster = $master; $movedMaster['recurrenceOverrides'] = [$rid => ['start' => '2025-04-01T12:00:00']];
$moved = $event; $moved['id'] = 'synthetic-after-move'; $moved['start'] = '2025-04-01T12:00:00'; $moved['utcStart'] = '2025-04-01T10:00:00Z'; $moved['utcEnd'] = '2025-04-01T11:00:00Z';
$x = occurrenceConversionXml($moved, $movedMaster, $account, $id);
occurrenceConversionCheck(occurrenceConversionValue($x, 'ItemId/@Id') === $id && occurrenceConversionValue($x, 'CalendarItemType') === 'Exception' && occurrenceConversionValue($x, 'Start') === '2025-04-01T10:00:00Z', 'moved exception retains original ID');
occurrenceConversionCheck(occurrenceConversionValue($x, 'ItemId/@ChangeKey') === EwsConverter::itemChangeKey($movedMaster) && EwsConverter::itemChangeKey($master) !== EwsConverter::itemChangeKey($movedMaster), 'master version changes');
$allMaster = ['id' => 'all', 'uid' => 'all-uid', 'start' => '2025-03-29T00:00:00', 'duration' => 'P1D', 'showWithoutTime' => true, 'timeZone' => 'Europe/Amsterdam', 'recurrenceRule' => ['frequency' => 'daily', 'count' => 3]];
$all = $allMaster; unset($all['recurrenceRule']); $all['id'] = 'synthetic-all'; $all['baseEventId'] = 'all'; $all['recurrenceId'] = '2025-03-30T00:00:00'; $all['start'] = $all['recurrenceId'];
$allId = EwsCalendarOccurrenceId::encode($account, 'all', $all['recurrenceId']);
$x = occurrenceConversionXml($all, $allMaster, $account, $allId);
occurrenceConversionCheck(occurrenceConversionValue($x, 'Start') === '2025-03-29T23:00:00Z' && occurrenceConversionValue($x, 'End') === '2025-03-30T22:00:00Z' && occurrenceConversionValue($x, 'IsAllDayEvent') === 'true', 'all-day 23-hour DST occurrence');
$utcMaster = ['id' => 'utc', 'uid' => 'utc-uid', 'start' => '2025-01-01T12:00:00', 'duration' => 'PT1H', 'timeZone' => 'Etc/UTC', 'recurrenceRule' => ['frequency' => 'daily', 'interval' => 2, 'until' => '2025-01-05T12:00:00']];
$utc = $utcMaster; unset($utc['recurrenceRule']); $utc['id'] = 'synthetic-utc'; $utc['baseEventId'] = 'utc'; $utc['recurrenceId'] = '2025-01-05T12:00:00'; $utc['start'] = $utc['recurrenceId'];
$utcId = EwsCalendarOccurrenceId::encode($account, 'utc', $utc['recurrenceId']);
$x = occurrenceConversionXml($utc, $utcMaster, $account, $utcId);
occurrenceConversionCheck(occurrenceConversionValue($x, 'StartTimeZone/@Id') === 'UTC' && occurrenceConversionValue($x, 'Start') === '2025-01-05T12:00:00Z', 'UTC occurrence');
$bad = $master; $bad['recurrenceOverrides'] = [$rid => ['excluded' => true]]; occurrenceConversionReject($event, $bad, $account, $id, 'excluded');
$bad = $master; $bad['recurrenceOverrides'] = [$rid => ['title' => 'other']]; occurrenceConversionReject($event, $bad, $account, $id, 'unsupported override');
$bad = $master; $bad['recurrenceOverrides'] = [$rid => ['excluded' => true, 'start' => $rid]]; occurrenceConversionReject($event, $bad, $account, $id, 'malformed excluded override');
$bad = $master; $bad['recurrenceOverrides'] = [$rid => ['start' => '2025-04-01T12:00:00']]; occurrenceConversionReject($event, $bad, $account, $id, 'stale moved start');
occurrenceConversionReject($event, $master, 'different', $id, 'account mismatch');
occurrenceConversionReject($event, $master, $account, EwsCalendarOccurrenceId::encode($account, 'wrong', $rid), 'ID base mismatch');
occurrenceConversionReject($event, $master, $account, EwsCalendarOccurrenceId::encode($account, 'base1', '2025-04-07T10:00:00'), 'ID recurrence mismatch');
$defaultMaster = $master; $defaultMaster['useDefaultAlerts'] = true;
$defaultEvent = $event; $defaultEvent['useDefaultAlerts'] = true;
occurrenceConversionReject($defaultEvent, $defaultMaster, $account, $id, 'occurrence calendar default alerts');
$defaultEvent['useDefaultAlerts'] = false;
occurrenceConversionReject($defaultEvent, $defaultMaster, $account, $id, 'occurrence default alert state mismatch');
foreach ([['baseEventId' => 'other'], ['baseEventId' => null], ['recurrenceId' => null], ['uid' => 'other'], ['id' => 'base1'], ['start' => '2025-04-01T10:00:00'], ['recurrenceIdTimeZone' => 'UTC'], ['utcStart' => '2025-03-31T09:00:00Z'], ['privacy' => 'public'], ['recurrenceRule' => ['frequency' => 'daily']], ['recurrenceRule' => []]] as $change) {
    occurrenceConversionReject(array_replace($event, $change), $master, $account, $id, json_encode($change));
}
$bad = $master; $bad['timeZone'] = 'Europe/Paris'; $badEvent = $event; $badEvent['timeZone'] = 'Europe/Paris'; $badEvent['recurrenceIdTimeZone'] = 'Europe/Paris'; occurrenceConversionReject($badEvent, $bad, $account, $id, 'unsupported zone');
$badEvent = $event; $badEvent['recurrenceId'] = '2025-04-01T10:00:00'; $badEvent['start'] = $badEvent['recurrenceId']; unset($badEvent['utcStart'], $badEvent['utcEnd']);
occurrenceConversionReject($badEvent, $master, $account, EwsCalendarOccurrenceId::encode($account, 'base1', $badEvent['recurrenceId']), 'wrong weekday');
$badEvent = $event; $badEvent['recurrenceId'] = '2025-04-28T10:00:00'; $badEvent['start'] = $badEvent['recurrenceId']; unset($badEvent['utcStart'], $badEvent['utcEnd']);
occurrenceConversionReject($badEvent, $master, $account, EwsCalendarOccurrenceId::encode($account, 'base1', $badEvent['recurrenceId']), 'count exceeded');
$badEvent = $utc; $badEvent['recurrenceId'] = '2025-01-07T12:00:00'; $badEvent['start'] = $badEvent['recurrenceId'];
occurrenceConversionReject($badEvent, $utcMaster, $account, EwsCalendarOccurrenceId::encode($account, 'utc', $badEvent['recurrenceId']), 'until exceeded');
$weekly = $master; $weekly['recurrenceRule'] = ['frequency' => 'weekly', 'interval' => 2, 'firstDayOfWeek' => 'su', 'byDay' => [['day' => 'mo'], ['day' => 'we']], 'count' => 4];
$instance = $event; unset($instance['utcStart'], $instance['utcEnd']); $instance['recurrenceId'] = '2025-04-09T10:00:00'; $instance['start'] = $instance['recurrenceId'];
$instanceId = EwsCalendarOccurrenceId::encode($account, 'base1', $instance['recurrenceId']);
occurrenceConversionCheck(occurrenceConversionValue(occurrenceConversionXml($instance, $weekly, $account, $instanceId), 'CalendarItemType') === 'Occurrence', 'weekly interval/count last occurrence');
$instance['recurrenceId'] = '2025-04-21T10:00:00'; $instance['start'] = $instance['recurrenceId'];
occurrenceConversionReject($instance, $weekly, $account, EwsCalendarOccurrenceId::encode($account, 'base1', $instance['recurrenceId']), 'weekly interval/count overflow');
echo "calendar occurrence conversion tests passed\n";
