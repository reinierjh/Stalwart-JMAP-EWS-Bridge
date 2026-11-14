<?php

// The module can also be preloaded when this suite is run over stdin.
if (!class_exists('EwsCalendarOccurrenceId')) require_once __DIR__ . '/../ews/src/CalendarOccurrenceId.php';

function occurrenceCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function occurrenceReject(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function occurrenceRaw(string $json): string {
    return 'ewsocc_' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

$account = 'calendar-account'; $base = 'base-1'; $original = '2032-01-02T10:00:00';
$id = EwsCalendarOccurrenceId::encode($account, $base, $original);
$expected = ['account' => $account, 'baseEventId' => $base, 'recurrenceId' => $original];
occurrenceCheck($id === EwsCalendarOccurrenceId::encode($account, $base, $original), 'deterministic');
occurrenceCheck(EwsCalendarOccurrenceId::decode($id) === $expected, 'roundtrip');
occurrenceCheck(EwsCalendarOccurrenceId::decodeForAccount($id, $account) === $expected, 'caller account match');
occurrenceReject(fn() => EwsCalendarOccurrenceId::decodeForAccount($id, 'other-account'), 'caller account mismatch');
occurrenceCheck(EwsCalendarOccurrenceId::encode('other-account', $base, $original) !== $id, 'account isolation');
occurrenceCheck(EwsCalendarOccurrenceId::encode($account, 'other-base', $original) !== $id, 'base isolation');
occurrenceCheck(EwsCalendarOccurrenceId::encode($account, $base, '2032-01-09T10:00:00') !== $id, 'recurrence isolation');

// An adversary can forge an ID for the caller's account: the helper does not
// authenticate it or prove the event/recurrence exists or is authorized.
$forged = EwsCalendarOccurrenceId::encode($account, 'forged-base', '2032-01-09T10:00:00');
occurrenceCheck(EwsCalendarOccurrenceId::decodeForAccount($forged, $account)['baseEventId'] === 'forged-base', 'no secret or authorization in token');
$before = ['baseEventId' => $base, 'recurrenceId' => $original, 'start' => $original, 'id' => 'old-synthetic'];
$after = ['baseEventId' => $base, 'recurrenceId' => $original, 'start' => '2032-01-03T12:00:00', 'id' => 'new-synthetic'];
occurrenceCheck(EwsCalendarOccurrenceId::encode($account, $before['baseEventId'], $before['recurrenceId']) ===
    EwsCalendarOccurrenceId::encode($account, $after['baseEventId'], $after['recurrenceId']), 'move and synthetic ID change');

foreach ([['é/世界', '"\\+&<>', '2032-01-02T10:00:00.123'], ['a', '0', '0'],
    [str_repeat('a', 1024), str_repeat('\\', 1024), str_repeat('"', 1024)],
    [str_repeat("\xe2\x80\xa8", 341), 'b', 'r']] as [$a, $b, $r]) {
    $token = EwsCalendarOccurrenceId::encode($a, $b, $r);
    occurrenceCheck(strlen($token) <= EwsCalendarOccurrenceId::MAX_ID_BYTES, 'encoded bound');
    occurrenceCheck((bool)preg_match('/\A[A-Za-z0-9_-]+\z/D', $token), 'XML and URL safe alphabet');
    occurrenceCheck(rawurlencode($token) === $token && htmlspecialchars($token, ENT_QUOTES | ENT_XML1, 'UTF-8') === $token, 'safe in XML attribute and URL');
    occurrenceCheck(EwsCalendarOccurrenceId::decode($token) === ['account' => $a, 'baseEventId' => $b, 'recurrenceId' => $r], 'edge roundtrip');
}
occurrenceCheck(EwsCalendarOccurrenceId::encode('é', 'b', 'r') !== EwsCalendarOccurrenceId::encode("e\u{0301}", 'b', 'r'), 'Unicode is not normalized');

foreach (['', str_repeat('x', 1025), "bad\xff", "\xed\xa0\x80", "bad\0", "bad\n", "bad\x7f", null, false, 42, [], new stdClass()] as $invalid) {
    foreach ([0, 1, 2] as $position) {
        $values = [$account, $base, $original]; $values[$position] = $invalid;
        occurrenceReject(fn() => EwsCalendarOccurrenceId::encode(...$values), 'invalid property ' . $position);
    }
    occurrenceReject(fn() => EwsCalendarOccurrenceId::decodeForAccount($id, $invalid), 'invalid expected account');
}

$badJson = [
    '', 'null', '{}', '[1,"a","b"]', '[1,"a","b","r",0]', '[2,"a","b","r"]',
    '["1","a","b","r"]', '[1.0,"a","b","r"]', '[1,"a","b",null]',
    '[1,"a",123,"r"]', '[1,"","b","r"]', '[1,"a","b","\\u0000"]',
    '[1,"a","b","\\ud800"]', "[1,\"a\",\"b\",\"\xff\"]", '[1, "a","b","r"]',
    '[1,"\\u0061","b","r"]', '{"0":1,"1":"a","2":"b","3":"r"}',
    '{"0":1,"1":"a","1":"a","2":"b","3":"r"}', '[1,"a","b","' . str_repeat('x', 1025) . '"]',
];
foreach ($badJson as $json) occurrenceReject(fn() => EwsCalendarOccurrenceId::decode(occurrenceRaw($json)), 'bad JSON/structure/version');
foreach (['', 'ewsocc_', 'ewsocc_A', 'ewsocc_=', $id . '=', $id . '+', $id . '/', $id . "\n", 'x' . $id,
    'ewsocc_' . str_repeat('A', EwsCalendarOccurrenceId::MAX_ID_BYTES), null, 3, []] as $bad) {
    occurrenceReject(fn() => EwsCalendarOccurrenceId::decode($bad), 'malformed token');
}
// Alter only the unused low base64 bits, retaining the same decoded JSON.
$one = EwsCalendarOccurrenceId::encode('aa', 'b', 'r');
$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
$last = strpos($alphabet, substr($one, -1));
if ((strlen($one) - 7) % 4 !== 0) {
    $noncanonical = substr($one, 0, -1) . $alphabet[$last + 1];
    occurrenceReject(fn() => EwsCalendarOccurrenceId::decode($noncanonical), 'noncanonical base64 pad bits');
} else {
    throw new RuntimeException('pad-bits test fixture must have padding');
}
echo "calendar occurrence ID tests passed\n";
