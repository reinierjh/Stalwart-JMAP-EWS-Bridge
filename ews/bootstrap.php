<?php

// Prevent XXE (XML External Entity) attacks
libxml_disable_entity_loader(true);

spl_autoload_register(function ($class) {
    $map = [
        'EwsServer'     => __DIR__ . '/src/Server.php',
        'EwsSoap'       => __DIR__ . '/src/Soap.php',
        'EwsConverter'  => __DIR__ . '/src/Converter.php',
        'EwsOperations' => __DIR__ . '/src/Operations.php',
    ];
    if (isset($map[$class])) {
        require $map[$class];
    }
});

require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/../jmap/jmap_client.php';
require_once __DIR__ . '/../jmap/jmap_contacts.php';
require_once __DIR__ . '/../jmap/jmap_calendar.php';
