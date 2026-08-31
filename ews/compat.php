<?php
/**
 * Z-Push compatibility stubs for standalone EWS bridge.
 * These replace classes normally provided by Z-Push.
 */

if (!defined('LOGLEVEL_OFF'))     define('LOGLEVEL_OFF',     0);
if (!defined('LOGLEVEL_FATAL'))   define('LOGLEVEL_FATAL',   1);
if (!defined('LOGLEVEL_ERROR'))   define('LOGLEVEL_ERROR',   2);
if (!defined('LOGLEVEL_WARN'))    define('LOGLEVEL_WARN',    3);
if (!defined('LOGLEVEL_INFO'))    define('LOGLEVEL_INFO',    4);
if (!defined('LOGLEVEL_DEBUG'))   define('LOGLEVEL_DEBUG',   5);
if (!defined('LOGLEVEL_WBXML'))   define('LOGLEVEL_WBXML',   6);
if (!defined('LOGLEVEL_DEVICEID')) define('LOGLEVEL_DEVICEID', 7);

if (!class_exists('ZLog', false)) {
    class ZLog {
        public static function Write(int $level, string $message): void {
            if ($level >= LOGLEVEL_WARN) {
                error_log("[Z-Push] $message");
            }
        }
        public static function IsWarnEnabled(): bool { return true; }
        public static function IsDebugEnabled(): bool { return true; }
    }
}

if (!class_exists('FatalException', false)) {
    class FatalException extends \RuntimeException {
        public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, int $logLevel = LOGLEVEL_FATAL) {
            parent::__construct($message, $code, $previous);
            error_log("[FATAL] $message");
        }
    }
}

if (!class_exists('AuthenticationRequiredException', false)) {
    class AuthenticationRequiredException extends \RuntimeException {
        public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
            parent::__construct($message, $code, $previous);
        }
    }
}

if (!class_exists('StatusException', false)) {
    class StatusException extends \RuntimeException {
        public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
            parent::__construct($message, $code, $previous);
        }
    }
}

// Constants used by JMAP client
if (!defined('SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED')) define('SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED', 106);
if (!defined('SYNC_ITEMOPERATIONSSTATUS_INVALIDATT'))   define('SYNC_ITEMOPERATIONSSTATUS_INVALIDATT', 1005);
if (!defined('SYNC_STATUS_SERVERERROR'))                define('SYNC_STATUS_SERVERERROR', 123);
if (!defined('SYNC_FOLDER_TYPE_INBOX'))                 define('SYNC_FOLDER_TYPE_INBOX', 1);
if (!defined('SYNC_FOLDER_TYPE_DRAFTS'))                define('SYNC_FOLDER_TYPE_DRAFTS', 2);
if (!defined('SYNC_FOLDER_TYPE_WASTEBASKET'))            define('SYNC_FOLDER_TYPE_WASTEBASKET', 3);
if (!defined('SYNC_FOLDER_TYPE_SENTMAIL'))               define('SYNC_FOLDER_TYPE_SENTMAIL', 4);
if (!defined('SYNC_FOLDER_TYPE_USER_MAIL'))              define('SYNC_FOLDER_TYPE_USER_MAIL', 12);
if (!defined('SYNC_FOLDER_TYPE_CONTACT'))                define('SYNC_FOLDER_TYPE_CONTACT', 13);
if (!defined('SYNC_FOLDER_TYPE_USER_CONTACT'))           define('SYNC_FOLDER_TYPE_USER_CONTACT', 14);
if (!defined('SYNC_FOLDER_TYPE_APPOINTMENT'))            define('SYNC_FOLDER_TYPE_APPOINTMENT', 15);
if (!defined('SYNC_FOLDER_TYPE_USER_APPOINTMENT'))       define('SYNC_FOLDER_TYPE_USER_APPOINTMENT', 16);
if (!defined('SYNC_BODYPREFERENCE_MIME'))                define('SYNC_BODYPREFERENCE_MIME', 4);
if (!defined('SYNC_BODYPREFERENCE_HTML'))                define('SYNC_BODYPREFERENCE_HTML', 2);
if (!defined('SYNC_BODYPREFERENCE_PLAIN'))               define('SYNC_BODYPREFERENCE_PLAIN', 1);
