#!/usr/bin/env php
<?php
/**
 * Simple CLI WebSocket client for the MCP proxy integration test.
 *
 * The script performs a WebSocket handshake (with TLS) against the proxy and
 * sends the JSON payload provided via ``--payload``. The response body is
 * printed to STDOUT so that the orchestrator can decode it as JSON.
 */

declare(strict_types=1);

$options = getopt('', ['url:', 'token:', 'payload::']);

$url = $options['url'] ?? null;
$token = $options['token'] ?? null;
$payload = $options['payload'] ?? null;

if (!$url || !$token) {
    fwrite(STDERR, "Usage: php mcp_wss_php_client.php --url=wss://host:port/path --token=TOKEN [--payload=JSON]\n");
    exit(1);
}

if ($payload === null) {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 'php-client',
        'method' => 'echo',
        'params' => ['message' => 'default from PHP'],
    ], JSON_THROW_ON_ERROR);
}

$parts = parse_url($url);
if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    fwrite(STDERR, "Invalid URL provided\n");
    exit(1);
}

$scheme = strtolower($parts['scheme']);
if (!in_array($scheme, ['ws', 'wss'], true)) {
    fwrite(STDERR, "Only ws:// or wss:// URLs are supported\n");
    exit(1);
}

$host = $parts['host'];
$port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

$contextOptions = [];
if ($scheme === 'wss') {
    $contextOptions['ssl'] = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ];
}

$context = stream_context_create($contextOptions);
$transport = $scheme === 'wss' ? 'ssl' : $scheme;
$socket = @stream_socket_client(
    sprintf('%s://%s:%d', $transport, $host, $port),
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$socket) {
    fwrite(STDERR, sprintf("Unable to connect to %s: %s (%d)\n", $url, $errstr, $errno));
    exit(1);
}

stream_set_timeout($socket, 30);

$secKey = base64_encode(random_bytes(16));
$headers = [
    sprintf('GET %s HTTP/1.1', $path === '' ? '/' : $path),
    sprintf('Host: %s:%d', $host, $port),
    'Upgrade: websocket',
    'Connection: Upgrade',
    sprintf('Sec-WebSocket-Key: %s', $secKey),
    'Sec-WebSocket-Version: 13',
    sprintf('Authorization: Bearer %s', $token),
    'User-Agent: WP-GPT-MCP-PHP/1.0',
    '',
    '',
];

fwrite($socket, implode("\r\n", $headers));

$response = '';
while (strpos($response, "\r\n\r\n") === false) {
    $chunk = fread($socket, 4096);
    if ($chunk === '' || $chunk === false) {
        fwrite(STDERR, "Incomplete handshake response\n");
        fclose($socket);
        exit(1);
    }
    $response .= $chunk;
}

if (!preg_match('/^HTTP\/1\.[01] 101 /', $response)) {
    fwrite(STDERR, "WebSocket handshake failed:\n" . $response . "\n");
    fclose($socket);
    exit(1);
}

$frame = websocket_encode_frame($payload);
fwrite($socket, $frame);

$reply = websocket_read_frame($socket);
if ($reply === null) {
    fwrite(STDERR, "Failed to read WebSocket frame from server\n");
    fclose($socket);
    exit(1);
}

echo $reply;
fclose($socket);

function websocket_encode_frame(string $payload): string
{
    $length = strlen($payload);
    $head = [0x81];

    if ($length <= 125) {
        $head[] = 0x80 | $length;
    } elseif ($length <= 0xFFFF) {
        $head[] = 0x80 | 126;
        $head = array_merge($head, unpack('C*', pack('n', $length)));
    } else {
        $head[] = 0x80 | 127;
        $head = array_merge($head, unpack('C*', pack('N2', 0, $length)));
    }

    $mask = random_bytes(4);
    $maskedPayload = '';
    for ($i = 0; $i < $length; $i++) {
        $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
    }

    $frame = pack('C*', ...$head) . $mask . $maskedPayload;
    return $frame;
}

function websocket_read_frame($socket): ?string
{
    $header = fread($socket, 2);
    if ($header === false || strlen($header) < 2) {
        return null;
    }

    $bytes = unpack('C2', $header);
    $fin = ($bytes[1] & 0x80) === 0x80;
    $opcode = $bytes[1] & 0x0F;
    $masked = ($bytes[2] & 0x80) === 0x80;
    $length = $bytes[2] & 0x7F;

    if (!$fin || $opcode !== 0x1) {
        return null; // Only support single text frames for the integration test
    }

    if ($length === 126) {
        $ext = fread($socket, 2);
        if ($ext === false || strlen($ext) < 2) {
            return null;
        }
        $length = current(unpack('n', $ext));
    } elseif ($length === 127) {
        $ext = fread($socket, 8);
        if ($ext === false || strlen($ext) < 8) {
            return null;
        }
        $parts = unpack('N2', $ext);
        $length = ($parts[1] << 32) | $parts[2];
    }

    if ($masked) {
        $maskKey = fread($socket, 4);
        if ($maskKey === false || strlen($maskKey) < 4) {
            return null;
        }
    }

    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $data .= $chunk;
    }

    if ($masked && isset($maskKey)) {
        $decoded = '';
        for ($i = 0; $i < $length; $i++) {
            $decoded .= $data[$i] ^ $maskKey[$i % 4];
        }
        return $decoded;
    }

    return $data;
}
