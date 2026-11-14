<?php

/**
 * Standalone, versioned representation of an occurrence identity, not integrated
 * into EWS operations. Values are preserved byte-for-byte (no Unicode, timezone,
 * date or instance-index normalization); recurrenceId is the ORIGINAL JMAP value,
 * not the occurrence's possibly moved start or a Stalwart synthetic ID.
 *
 * The ID is opaque only as an interface: it is reversible, forgeable, not secret,
 * and is neither authentication nor authorization. On EVERY request the caller
 * must reauthenticate, obtain the calendar capability account for that principal,
 * and check account, base event and original recurrence against the JMAP server
 * and the principal's permissions. decodeForAccount is only an extra mismatch
 * check, not a substitute for any of these checks. Account IDs must be scoped to
 * the JMAP server by the caller when multiple servers are used.
 *
 * This does not reconstruct a synthetic hint or implement EWS OccurrenceItemId's
 * 1-based InstanceIndex. It performs no persistence or server communication.
 */
final class EwsCalendarOccurrenceId {
    public const MAX_COMPONENT_BYTES = 1024;
    public const MAX_JSON_BYTES = 6 * 3 * self::MAX_COMPONENT_BYTES + 32;
    // Seven prefix bytes plus padded base64 of the maximum JSON size.
    public const MAX_ID_BYTES = 24627;
    private const PREFIX = 'ewsocc_';

    private static function validateComponent(mixed $value): void {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_COMPONENT_BYTES ||
            preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid occurrence identity component');
        }
    }

    /** @throws InvalidArgumentException */
    public static function encode(mixed $account, mixed $baseEventId, mixed $recurrenceId): string {
        foreach ([$account, $baseEventId, $recurrenceId] as $value) self::validateComponent($value);
        try {
            $json = json_encode([1, $account, $baseEventId, $recurrenceId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid occurrence identity', 0, $e);
        }
        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{account: string, baseEventId: string, recurrenceId: string} */
    public static function decode(mixed $id): array {
        if (!is_string($id) || strlen($id) > self::MAX_ID_BYTES ||
            !preg_match('/\Aewsocc_[A-Za-z0-9_-]+\z/D', $id)) {
            throw new InvalidArgumentException('Malformed occurrence ID');
        }
        $json = base64_decode(strtr(substr($id, strlen(self::PREFIX)), '-_', '+/'), true);
        if ($json === false || strlen($json) > self::MAX_JSON_BYTES) throw new InvalidArgumentException('Malformed occurrence ID');
        try { $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('Malformed occurrence ID', 0, $e); }
        if (!is_array($data) || array_keys($data) !== [0, 1, 2, 3] || $data[0] !== 1) {
            throw new InvalidArgumentException('Invalid occurrence ID version or structure');
        }
        // Re-encoding rejects alternate JSON, duplicate/object keys, padding and
        // non-canonical base64 pad bits as well as invalid component values.
        if (self::encode($data[1], $data[2], $data[3]) !== $id) throw new InvalidArgumentException('Non-canonical occurrence ID');
        return ['account' => $data[1], 'baseEventId' => $data[2], 'recurrenceId' => $data[3]];
    }

    /** The expected account must come from the newly authenticated JMAP session. */
    public static function decodeForAccount(mixed $id, mixed $expectedAccount): array {
        self::validateComponent($expectedAccount);
        $identity = self::decode($id);
        if ($identity['account'] !== $expectedAccount) throw new InvalidArgumentException('Occurrence account mismatch');
        return $identity;
    }
}
