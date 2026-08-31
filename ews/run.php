<?php
/**
 * Runner for PHP built-in development server.
 *
 * Usage:
 *   php -S 0.0.0.0:8000 -t . ews/run.php
 *
 * Then configure your MacBook Mail.app with:
 *   Server:  http://<your-ip>:8000/EWS/Exchange.asmx
 *   Username: <stalwart-username>
 *   Password: <stalwart-password>
 */
$uri = $_SERVER['REQUEST_URI'] ?? '';

// Route all /EWS/* and /Autodiscover/* requests to the EWS server
if (
    stripos($uri, '/ews/') !== false ||
    stripos($uri, '/autodiscover/') !== false ||
    stripos($uri, 'exchange.asmx') !== false ||
    stripos($uri, 'autodiscover.xml') !== false
) {
    require __DIR__ . '/public/index.php';
    return true;
}

// Serve static files (for any web interface)
return false;
