<?php

class EwsServer {

    public function handle(): void {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $rawBody    = file_get_contents('php://input');

        $this->logDebug("EWS request: $method $requestUri");

        header('X-Server: Stalwart-EWS-Bridge/1.0');

        if (stripos($requestUri, 'autodiscover') !== false) {
            $this->handleAutodiscoverRequest($rawBody);
            return;
        }

        if ($method !== 'POST') {
            $this->sendError(405, 'Method Not Allowed');
            return;
        }

        $username = $_SERVER['PHP_AUTH_USER'] ?? '';
        $password = $_SERVER['PHP_AUTH_PW'] ?? '';

        if (!$username || !$password) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded && str_contains($decoded, ':')) {
                    [$username, $password] = explode(':', $decoded, 2);
                }
            }
        }

        if (!$username || !$password) {
            $this->sendAuthError();
            return;
        }

        try {
            $client = new JmapClient(JMAP_SESSION_URL, $username, $password);
            $this->handleSoapRequest($client, $rawBody);
        } catch (AuthenticationRequiredException $e) {
            $this->logDebug("Auth failed for $username");
            $this->sendAuthError();
        } catch (\Throwable $e) {
            $this->logError("Fatal: " . $e->getMessage());
            $this->sendError(500, 'Internal Server Error');
        }
    }

    private function handleAutodiscoverRequest(string $rawBody): void {
        header('Content-Type: application/xml; charset=utf-8');

        $email = '';
        if ($rawBody) {
            $dom = new DOMDocument();
            if ($dom->loadXML($rawBody)) {
                $nodes = $dom->getElementsByTagNameNS(
                    'http://schemas.microsoft.com/exchange/autodiscover/requestschema/2006',
                    'EMailAddress'
                );
                if ($nodes->length > 0) {
                    $email = trim($nodes->item(0)->textContent);
                }
                if (!$email) {
                    $nodes = $dom->getElementsByTagName('EMailAddress');
                    if ($nodes->length > 0) {
                        $email = trim($nodes->item(0)->textContent);
                    }
                }
            }
        }

        if (!$email) {
            $email = $_SERVER['PHP_AUTH_USER'] ?? 'user@localhost';
        }

        $password = $_SERVER['PHP_AUTH_PW'] ?? '';
        $username = $_SERVER['PHP_AUTH_USER'] ?? $email;

        if ($username && $password) {
            try {
                $client = new JmapClient(JMAP_SESSION_URL, $username, $password);
                $ops = new EwsOperations($client);
                $xml = $ops->handleAutodiscover($email, $rawBody);
                echo $xml;
                return;
            } catch (\Throwable $e) {
                $this->logDebug("Autodiscover with auth failed: " . $e->getMessage());
            }
        }

        $parts = explode('@', $email);
        $domain = $parts[1] ?? 'localhost';
        $parsed = parse_url(JMAP_SESSION_URL);
        $host   = $parsed['host'] ?? 'localhost';
        $scheme = $parsed['scheme'] ?? 'https';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $ewsUrl = $scheme . '://' . $host . $port . EWS_SCRIPT_NAME;

        $resp = new DOMDocument('1.0', 'utf-8');
        $resp->formatOutput = true;

        $root = $resp->createElementNS(
            'http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006',
            'Autodiscover'
        );
        $resp->appendChild($root);
        $response = $resp->createElement('Response');
        $root->appendChild($response);

        $user = $resp->createElement('User');
        $response->appendChild($user);
        $user->appendChild($resp->createElement('DisplayName', $email));
        $user->appendChild($resp->createElement('AutoDiscoverSMTPAddress', $email));
        $user->appendChild($resp->createElement('SMTPAddress', $email));

        $protocol = $resp->createElement('Protocol');
        $response->appendChild($protocol);
        $protocol->appendChild($resp->createElement('Type', 'EXCH'));
        $protocol->appendChild($resp->createElement('Server', $host));
        $protocol->appendChild($resp->createElement('AuthPackage', 'Basic'));
        $protocol->appendChild($resp->createElement('EwsUrl', $ewsUrl));
        $protocol->appendChild($resp->createElement('EcpUrl', $ewsUrl));

        $protocol2 = $resp->createElement('Protocol');
        $response->appendChild($protocol2);
        $protocol2->appendChild($resp->createElement('Type', 'EXHTTP'));
        $protocol2->appendChild($resp->createElement('Server', $host));
        $protocol2->appendChild($resp->createElement('AuthPackage', 'Basic'));
        $protocol2->appendChild($resp->createElement('EwsUrl', $ewsUrl));

        echo $resp->saveXML();
    }

    private function handleSoapRequest(JmapClient $client, string $rawBody): void {
        header('Content-Type: application/xml; charset=utf-8');

        if (empty($rawBody)) {
            $this->sendError(400, 'Empty SOAP request');
            return;
        }

        $body = EwsSoap::parseEnvelope($rawBody);
        if (!$body) {
            $this->logError("Failed to parse SOAP envelope");
            $this->sendError(400, 'Invalid SOAP envelope');
            return;
        }

        $operation = EwsSoap::getOperation($body);
        $opElement = EwsSoap::getOperationElement($body);

        $opXml = $opElement ? $opElement->ownerDocument->saveXML($opElement) : 'null';
        $this->logDebug("EWS operation: $operation");
        $this->logDebug("EWS request (full): " . substr($rawBody, 0, 4000));

        $ops = new EwsOperations($client);

        try {
            $response = match($operation) {
                'SyncFolderHierarchy'    => $ops->syncFolderHierarchy($opElement),
                'SyncFolderItems'        => $ops->syncFolderItems($opElement),
                'GetFolder'              => $ops->getFolder($opElement),
                'FindFolder'             => $ops->findFolder($opElement),
                'FindItem'               => $ops->findItem($opElement),
                'GetItem'                => $ops->getItem($opElement),
                'CreateItem'             => $ops->createItem($opElement),
                'UpdateItem'             => $ops->updateItem($opElement),
                'DeleteItem'             => $ops->deleteItem($opElement),
                'SendItem'               => $ops->sendItem($opElement),
                'GetAttachment'          => $ops->getAttachment($opElement),
                'ResolveNames'           => $ops->resolveNames($opElement),
                'GetUserAvailability'    => $ops->getUserAvailability($opElement),
                'ConvertId'              => $ops->convertId($opElement),
                'GetRoomLists'           => $ops->getRoomLists(),
                'GetRooms'               => $ops->getRooms(),
                'GetUserConfiguration',
                'SetUserConfiguration'   => $this->emptySuccessResponse($operation),
                'Subscribe'              => $this->emptySuccessResponse($operation),
                'GetEvents'              => $this->getEventsResponse(),
                'Unsubscribe'            => $this->emptySuccessResponse($operation),
                'GetStreamingEvents'     => $this->getStreamingEventsResponse(),
                default                  => $this->unknownOperationResponse($operation),
            };

            $this->logDebug("EWS response: " . $operation . " (" . strlen($response) . " bytes)");
            $this->logDebug("EWS response body: " . substr($response, 0, 4000));
            echo $response;
        } catch (\Throwable $e) {
            $this->logError("Operation $operation failed: " . $e->getMessage());
            $bodyXml = '<m:' . $operation . 'Response>' .
                '<m:ResponseMessages>' .
                '<m:' . $operation . 'ResponseMessage ResponseClass="Error">' .
                '<m:MessageText></m:MessageText>' .
                '<m:ResponseCode>ErrorInternalServerError</m:ResponseCode>' .
                '</m:' . $operation . 'ResponseMessage>' .
                '</m:ResponseMessages>' .
                '</m:' . $operation . 'Response>';
            echo EwsSoap::buildSoapResponse($bodyXml);
        }
    }

    private function emptySuccessResponse(string $operation): string {
        $extra = '';
        if (strtolower($operation) === 'subscribe') {
            $extra = '<m:SubscriptionId>00000000-0000-0000-0000-000000000000</m:SubscriptionId>' .
                '<m:Watermark>AAAAAAA=</m:Watermark>';
        }
        $bodyXml = '<m:' . $operation . 'Response>' .
            '<m:ResponseMessages>' .
            '<m:' . $operation . 'ResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            $extra .
            '</m:' . $operation . 'ResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:' . $operation . 'Response>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function getEventsResponse(): string {
        $bodyXml = '<m:GetEventsResponse>' .
            '<m:ResponseMessages>' .
            '<m:GetEventsResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            '<m:Notification>' .
            '<t:SubscriptionId>00000000-0000-0000-0000-000000000000</t:SubscriptionId>' .
            '<t:PreviousWatermark>AAAAAAA=</t:PreviousWatermark>' .
            '<t:MoreEvents>false</t:MoreEvents>' .
            '</m:Notification>' .
            '</m:GetEventsResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:GetEventsResponse>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function getStreamingEventsResponse(): string {
        $bodyXml = '<m:GetStreamingEventsResponse>' .
            '<m:ResponseMessages>' .
            '<m:GetStreamingEventsResponseMessage ResponseClass="Success">' .
            '<m:ResponseCode>NoError</m:ResponseCode>' .
            '<m:Notifications>' .
            '<t:Notification>' .
            '<t:SubscriptionId>00000000-0000-0000-0000-000000000000</t:SubscriptionId>' .
            '<t:MoreEvents>false</t:MoreEvents>' .
            '</t:Notification>' .
            '</m:Notifications>' .
            '<m:ConnectionStatus>OK</m:ConnectionStatus>' .
            '</m:GetStreamingEventsResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:GetStreamingEventsResponse>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function unknownOperationResponse(string $operation): string {
        $bodyXml = '<m:' . $operation . 'Response>' .
            '<m:ResponseMessages>' .
            '<m:' . $operation . 'ResponseMessage ResponseClass="Error">' .
            '<m:MessageText>Operation ' . EwsSoap::escapeXml($operation) . ' is not supported</m:MessageText>' .
            '<m:ResponseCode>ErrorInvalidOperation</m:ResponseCode>' .
            '</m:' . $operation . 'ResponseMessage>' .
            '</m:ResponseMessages>' .
            '</m:' . $operation . 'Response>';
        return EwsSoap::buildSoapResponse($bodyXml);
    }

    private function sendAuthError(): void {
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="Stalwart EWS Bridge"');
        header('Content-Type: text/plain');
        echo '401 Unauthorized';
    }

    private function sendError(int $code, string $message): void {
        header("HTTP/1.1 $code $message");
        header('Content-Type: text/plain');
        echo "$code $message";
    }

    private function logDebug(string $msg): void {
        if (defined('LOGLEVEL_DEBUG')) {
            error_log("[EWS-DEBUG] $msg");
        }
    }

    private function logError(string $msg): void {
        error_log("[EWS-ERROR] $msg");
    }
}
