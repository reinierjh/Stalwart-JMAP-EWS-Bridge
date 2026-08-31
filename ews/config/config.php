<?php

require_once __DIR__ . '/../../jmap/config.php';

if (!defined('EWS_SCRIPT_NAME')) {
    define('EWS_SCRIPT_NAME', '/EWS/Exchange.asmx');
}

if (!defined('EWS_AUTODISCOVER_SCRIPT_NAME')) {
    define('EWS_AUTODISCOVER_SCRIPT_NAME', '/Autodiscover/Autodiscover.xml');
}

if (!defined('EWS_SYNC_STATE_DIR')) {
    define('EWS_SYNC_STATE_DIR', sys_get_temp_dir() . '/ews_sync_states');
}
