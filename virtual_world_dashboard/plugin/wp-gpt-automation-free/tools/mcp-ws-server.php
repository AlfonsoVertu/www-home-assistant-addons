#!/usr/bin/env php
<?php
declare(strict_types=1);

use WP_GPT_Automation_Pro\Token_Validator;
use WP_GPT_Automation_Pro\WP_GPT_MCP_Server;

require __DIR__ . '/../vendor/autoload.php';

function wp_gpt_find_wordpress_root(string $startDirectory): ?string
{
    $directory = realpath($startDirectory);
    while ($directory && $directory !== DIRECTORY_SEPARATOR) {
        if (is_readable($directory . '/wp-load.php')) {
            return $directory;
        }
        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }

    return null;
}

function wp_gpt_flag($value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));

    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
}

function wp_gpt_normalize_path(string $path): string
{
    $normalized = '/' . ltrim($path, '/');
    $normalized = preg_replace('#/+#', '/', $normalized) ?: '/';

    if ($normalized !== '/' && substr($normalized, -1) === '/') {
        $normalized = rtrim($normalized, '/');
    }

    return $normalized;
}

$cliOptions = getopt('', [
    'host::',
    'port::',
    'path::',
    'cert:',
    'key:',
    'ca::',
    'passphrase::',
    'wp::',
    'allow-self-signed::',
    'verbose::',
]);

$host       = $cliOptions['host'] ?? getenv('WP_GPT_WS_HOST') ?? '0.0.0.0';
$port       = (int) ($cliOptions['port'] ?? getenv('WP_GPT_WS_PORT') ?? 8901);
$path       = $cliOptions['path'] ?? getenv('WP_GPT_WS_PATH') ?? '/mcp';
$certPath   = $cliOptions['cert'] ?? getenv('WP_GPT_WS_CERT');
$keyPath    = $cliOptions['key'] ?? getenv('WP_GPT_WS_KEY');
$caPath     = $cliOptions['ca'] ?? getenv('WP_GPT_WS_CA') ?? null;
$passphrase = $cliOptions['passphrase'] ?? getenv('WP_GPT_WS_PASSPHRASE') ?? null;
$wpRoot     = $cliOptions['wp'] ?? getenv('WP_GPT_WP_ROOT') ?? null;
$allowSelf  = wp_gpt_flag($cliOptions['allow-self-signed'] ?? getenv('WP_GPT_WS_ALLOW_SELF_SIGNED'), false);
$verbose    = wp_gpt_flag($cliOptions['verbose'] ?? getenv('WP_GPT_WS_VERBOSE'), false);

$path = wp_gpt_normalize_path($path);

if ($wpRoot === null) {
    $wpRoot = wp_gpt_find_wordpress_root(__DIR__);
}

if (!$wpRoot || !is_readable($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "Unable to locate wp-load.php. Use --wp=/percorso/wordpress o l'ambiente WP_GPT_WP_ROOT.\n");
    exit(1);
}

if (!$certPath || !is_readable($certPath)) {
    fwrite(STDERR, "TLS certificate (--cert) is required and must be readable.\n");
    exit(1);
}

if (!$keyPath || !is_readable($keyPath)) {
    fwrite(STDERR, "TLS private key (--key) is required and must be readable.\n");
    exit(1);
}

require_once $wpRoot . '/wp-load.php';
require_once __DIR__ . '/../wp-gpt-automation-pro.php';

if (!defined('REST_REQUEST')) {
    define('REST_REQUEST', true);
}

if (!class_exists(WP_GPT_MCP_Server::class) || !class_exists(Token_Validator::class)) {
    fwrite(STDERR, "WP GPT Automation Pro classes are not loaded. Check that the plugin is active.\n");
    exit(1);
}

WP_GPT_MCP_Server::init();

$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
}
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
}

$tlsOptions = [
    'local_cert'       => $certPath,
    'local_pk'         => $keyPath,
    'crypto_method'    => $cryptoMethod,
    'verify_peer'      => !$allowSelf,
    'verify_peer_name' => !$allowSelf,
    'allow_self_signed'=> $allowSelf,
    'SNI_enabled'      => true,
];

if ($passphrase !== null && $passphrase !== '') {
    $tlsOptions['passphrase'] = $passphrase;
}

if ($caPath !== null && $caPath !== '') {
    if (!is_readable($caPath)) {
        fwrite(STDERR, "CA bundle specified in --ca is not readable.\n");
        exit(1);
    }
    $tlsOptions['cafile'] = $caPath;
}

final class WpGptMcpWebsocketServer
{
    private string $host;
    private int $port;
    private string $path;
    /** @var array<string, mixed> */
    private array $tlsOptions;
    private bool $verbose;
    /** @var resource|null */
    private $server = null;
    /** @var array<int, array<string, mixed>> */
    private array $clients = [];
    private bool $running = true;

    public function __construct(string $host, int $port, string $path, array $tlsOptions, bool $verbose)
    {
        $this->host       = $host;
        $this->port       = $port;
        $this->path       = $path;
        $this->tlsOptions = $tlsOptions;
        $this->verbose    = $verbose;
    }

    public function run(): void
    {
        $context = stream_context_create(['ssl' => $this->tlsOptions]);
        $endpoint = sprintf('tls://%s:%d', $this->host, $this->port);

        $this->server = @stream_socket_server(
            $endpoint,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!is_resource($this->server)) {
            fwrite(STDERR, sprintf("Unable to start TLS server (%d): %s\n", $errno, $errstr));
            exit(1);
        }

        stream_set_blocking($this->server, false);
        $this->registerSignalHandlers();

        $displayHost = $this->host === '0.0.0.0' ? '[all interfaces]' : $this->host;
        $siteUrl     = function_exists('home_url') ? home_url('/') : 'WordPress';
        printf(
            "WP GPT MCP WebSocket proxy ready at wss://%s:%d%s (site: %s)\n",
            $displayHost,
            $this->port,
            $this->path,
            $siteUrl
        );

        set_time_limit(0);

        while ($this->running) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                if (is_resource($client['stream'])) {
                    $read[] = $client['stream'];
                }
            }

            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1, 0);

            if ($changed === false) {
                continue;
            }

            if ($changed > 0 && in_array($this->server, $read, true)) {
                $this->acceptConnection();
                $serverIndex = array_search($this->server, $read, true);
                if ($serverIndex !== false) {
                    unset($read[$serverIndex]);
                }
            }

            foreach ($read as $stream) {
                $id = (int) $stream;
                if (!isset($this->clients[$id])) {
                    continue;
                }
                $this->handleClient($id);
            }
        }

        $this->shutdown();
    }

    private function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                $this->log('Received SIGINT, shutting down...');
                $this->running = false;
            });
            pcntl_signal(SIGTERM, function (): void {
                $this->log('Received SIGTERM, shutting down...');
                $this->running = false;
            });
        }
    }

    private function acceptConnection(): void
    {
        if (!is_resource($this->server)) {
            return;
        }

        $clientStream = @stream_socket_accept($this->server, 0);
        if ($clientStream === false) {
            return;
        }

        $cryptoMethod = $this->tlsOptions['crypto_method'] ?? STREAM_CRYPTO_METHOD_TLS_SERVER;

        stream_set_blocking($clientStream, true);
        $cryptoResult = @stream_socket_enable_crypto($clientStream, true, $cryptoMethod);
        stream_set_blocking($clientStream, false);

        if ($cryptoResult !== true) {
            fclose($clientStream);
            return;
        }

        $id = (int) $clientStream;
        $remote = @stream_socket_get_name($clientStream, true) ?: 'unknown';

        $this->clients[$id] = [
            'stream'         => $clientStream,
            'buffer'         => '',
            'handshake'      => false,
            'token'          => null,
            'user_agent'     => null,
            'message_buffer' => '',
            'remote'         => $remote,
        ];

        $this->log(sprintf('Incoming connection from %s', $remote));
    }

    private function handleClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $stream = $this->clients[$id]['stream'];
        if (!is_resource($stream)) {
            $this->closeClient($id, 1006, 'Socket closed', false);
            return;
        }

        $data = @fread($stream, 8192);
        if ($data === '' || $data === false) {
            if (feof($stream)) {
                $this->closeClient($id, 1000, 'Client closed', false);
            }
            return;
        }

        $this->clients[$id]['buffer'] .= $data;

        if (!$this->clients[$id]['handshake']) {
            if (!$this->performHandshake($id)) {
                return;
            }
        }

        while (isset($this->clients[$id])) {
            $frame = $this->decodeFrame($this->clients[$id]['buffer']);
            if ($frame === null) {
                break;
            }
            $this->handleFrame($id, $frame);
        }
    }

    private function performHandshake(int $id): bool
    {
        if (!isset($this->clients[$id])) {
            return false;
        }

        $buffer = &$this->clients[$id]['buffer'];
        $pos    = strpos($buffer, "\r\n\r\n");
        if ($pos === false) {
            if (strlen($buffer) > 16384) {
                $this->closeClient($id, 1002, 'Handshake too large', false);
            }
            return false;
        }

        $requestRaw = substr($buffer, 0, $pos + 4);
        $buffer     = substr($buffer, $pos + 4);

        $parsed = $this->parseHttpRequest($requestRaw);
        if ($parsed === null) {
            $this->sendHttpError($this->clients[$id]['stream'], 400, 'Bad Request');
            $this->closeClient($id, 1002, 'Invalid handshake', false);
            return false;
        }

        if ($this->normalizePath($parsed['path']) !== $this->path) {
            $this->sendHttpError($this->clients[$id]['stream'], 404, 'Not Found');
            $this->closeClient($id, 1008, 'Invalid path', false);
            return false;
        }

        $headers = $parsed['headers'];

        if (!isset($headers['upgrade']) || stripos($headers['upgrade'], 'websocket') === false) {
            $this->sendHttpError($this->clients[$id]['stream'], 400, 'Upgrade header missing');
            $this->closeClient($id, 1002, 'Missing upgrade header', false);
            return false;
        }

        if (!isset($headers['connection']) || stripos($headers['connection'], 'upgrade') === false) {
            $this->sendHttpError($this->clients[$id]['stream'], 400, 'Connection header missing');
            $this->closeClient($id, 1002, 'Missing connection header', false);
            return false;
        }

        if (!isset($headers['sec-websocket-key'])) {
            $this->sendHttpError($this->clients[$id]['stream'], 400, 'Missing Sec-WebSocket-Key');
            $this->closeClient($id, 1002, 'Missing websocket key', false);
            return false;
        }

        if (($headers['sec-websocket-version'] ?? '') !== '13') {
            $this->sendHttpError($this->clients[$id]['stream'], 426, 'Upgrade Required');
            $this->closeClient($id, 1002, 'Unsupported websocket version', false);
            return false;
        }

        $token = $this->extractToken($headers, $parsed['query']);
        if ($token === null || $token === '') {
            $this->sendHttpError($this->clients[$id]['stream'], 401, 'Missing token');
            $this->closeClient($id, 1008, 'Missing token', false);
            return false;
        }

        $validation = Token_Validator::validate_bearer_token($token);
        if (is_wp_error($validation)) {
            $this->sendHttpError($this->clients[$id]['stream'], 401, 'Unauthorized');
            $this->closeClient($id, 1008, 'Invalid token', false);
            return false;
        }

        $acceptKey = base64_encode(sha1(trim($headers['sec-websocket-key']) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $responseHeaders = [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: ' . $acceptKey,
        ];

        if (isset($headers['sec-websocket-protocol'])) {
            $responseHeaders[] = 'Sec-WebSocket-Protocol: ' . $headers['sec-websocket-protocol'];
        }

        $response = implode("\r\n", $responseHeaders) . "\r\n\r\n";
        $this->writeAll($this->clients[$id]['stream'], $response);

        $this->clients[$id]['handshake']  = true;
        $this->clients[$id]['token']      = $token;
        $this->clients[$id]['user_agent'] = $headers['user-agent'] ?? null;

        $this->log(sprintf('Handshake completed with %s', $this->clients[$id]['remote']));

        return true;
    }

    /**
     * @return array{path:string,query:string,headers:array<string,string>}|null
     */
    private function parseHttpRequest(string $request): ?array
    {
        $parts = preg_split("/\r\n/", trim($request));
        if (empty($parts)) {
            return null;
        }

        $requestLine = array_shift($parts);
        if (!is_string($requestLine) || !preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $matches)) {
            return null;
        }

        $target = $matches[1];
        $path   = parse_url($target, PHP_URL_PATH) ?? '/';
        $query  = parse_url($target, PHP_URL_QUERY) ?? '';

        $headers = [];
        foreach ($parts as $line) {
            if ($line === '') {
                break;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name  = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return [
            'path'    => $path,
            'query'   => $query,
            'headers' => $headers,
        ];
    }

    private function extractToken(array $headers, string $queryString): ?string
    {
        if (isset($headers['authorization']) && stripos($headers['authorization'], 'Bearer ') === 0) {
            return trim(substr($headers['authorization'], 7));
        }

        parse_str($queryString, $query);
        $token = $query['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function handleFrame(int $id, array $frame): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        switch ($frame['opcode']) {
            case 0x1:
                $this->handleTextMessage($id, $frame['payload']);
                break;
            case 0x8:
                $this->closeClient($id, 1000, 'Client closed');
                break;
            case 0x9:
                $this->sendFrame($id, $frame['payload'], 0xA);
                break;
            case 0xA:
                // ignore pong
                break;
            case 0x0:
            case 0x2:
            default:
                $this->closeClient($id, 1003, 'Unsupported frame');
                break;
        }
    }

    private function handleTextMessage(int $id, string $payload): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $client = &$this->clients[$id];
        $token  = $client['token'];

        $validation = Token_Validator::validate_bearer_token($token);
        if (is_wp_error($validation)) {
            $error = $this->buildError(null, false, -32001, $validation->get_error_message());
            $this->sendFrame($id, $this->encodeJson($error));
            $this->closeClient($id, 1008, 'Token revoked');
            return;
        }

        $data = json_decode($payload, true);
        $hasId = is_array($data) && array_key_exists('id', $data);
        $rpcId = $hasId ? $data['id'] : null;

        if (!is_array($data)) {
            $error = $this->buildError(null, false, -32700, 'Invalid JSON payload.');
            $this->sendFrame($id, $this->encodeJson($error));
            return;
        }

        $request = new WP_REST_Request('POST', '/wp-gpt/v1/mcp');
        $request->set_body($payload);
        $request->set_header('content-type', 'application/json');
        $request->set_header('authorization', 'Bearer ' . $token);
        if (!empty($client['user_agent'])) {
            $request->set_header('user-agent', $client['user_agent']);
        }

        $previousAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $previousUa = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = $client['user_agent'] ?? 'wp-gpt-mcp-websocket/1.0';

        try {
            $response = WP_GPT_MCP_Server::handle_request($request);
        } catch (Throwable $exception) {
            $this->restoreServerGlobals($previousAuth, $previousUa);
            $this->logError('Exception: ' . $exception->getMessage());
            $error = $this->buildError($rpcId, $hasId, -32603, 'Internal error.', [
                'exception' => $exception->getMessage(),
            ]);
            $this->sendFrame($id, $this->encodeJson($error));
            return;
        }

        $this->restoreServerGlobals($previousAuth, $previousUa);

        if ($response instanceof WP_Error) {
            $error = $this->buildError($rpcId, $hasId, -32002, $response->get_error_message(), [
                'code' => $response->get_error_code(),
                'data' => $response->get_error_data(),
            ]);
            $this->sendFrame($id, $this->encodeJson($error));
            return;
        }

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            if (!is_array($data)) {
                $data = [
                    'jsonrpc' => '2.0',
                    'result'  => $data,
                ];
            }
            if ($hasId && !array_key_exists('id', $data)) {
                $data['id'] = $rpcId;
            }
            $this->sendFrame($id, $this->encodeJson($data));
            return;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'result'  => $response,
        ];
        if ($hasId) {
            $payload['id'] = $rpcId;
        }

        $this->sendFrame($id, $this->encodeJson($payload));
    }

    private function decodeFrame(string &$buffer): ?array
    {
        $length = strlen($buffer);
        if ($length < 2) {
            return null;
        }

        $byte1  = ord($buffer[0]);
        $byte2  = ord($buffer[1]);
        $fin    = ($byte1 & 0x80) !== 0;
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $payloadLength = $byte2 & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if ($length < 4) {
                return null;
            }
            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if ($length < 10) {
                return null;
            }
            $parts = unpack('N2', substr($buffer, 2, 8));
            $payloadLength = ($parts[1] << 32) | $parts[2];
            $offset = 10;
        }

        if (!$masked) {
            return ['opcode' => 0x8, 'payload' => '', 'fin' => $fin];
        }

        if ($length < $offset + 4) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $offset += 4;

        if ($length < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);
        $buffer  = substr($buffer, $offset + $payloadLength);

        $decoded = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $decoded .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        return [
            'fin'     => $fin,
            'opcode'  => $opcode,
            'payload' => $decoded,
        ];
    }

    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $fin    = 0x80;
        $byte1  = chr($fin | ($opcode & 0x0F));
        $length = strlen($payload);

        if ($length <= 125) {
            $header = $byte1 . chr($length);
        } elseif ($length <= 65535) {
            $header = $byte1 . chr(126) . pack('n', $length);
        } else {
            $header = $byte1 . chr(127) . pack('NN', ($length >> 32) & 0xFFFFFFFF, $length & 0xFFFFFFFF);
        }

        return $header . $payload;
    }

    private function sendFrame(int $id, string $payload, int $opcode = 0x1): bool
    {
        if (!isset($this->clients[$id])) {
            return false;
        }

        $stream = $this->clients[$id]['stream'];
        if (!is_resource($stream)) {
            return false;
        }

        $frame = $this->encodeFrame($payload, $opcode);
        return $this->writeAll($stream, $frame);
    }

    private function writeAll($stream, string $data): bool
    {
        $total = strlen($data);
        $written = 0;

        while ($written < $total) {
            $result = @fwrite($stream, substr($data, $written));
            if ($result === false) {
                return false;
            }
            if ($result === 0) {
                usleep(1000);
                continue;
            }
            $written += $result;
        }

        return true;
    }

    private function closeClient(int $id, int $code = 1000, string $reason = '', bool $sendClose = true): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $stream = $this->clients[$id]['stream'];
        if ($sendClose && is_resource($stream)) {
            $payload = pack('n', $code) . $reason;
            $frame   = $this->encodeFrame($payload, 0x8);
            $this->writeAll($stream, $frame);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        $remote = $this->clients[$id]['remote'];
        unset($this->clients[$id]);
        $this->log(sprintf('Connection closed (%s)', $remote));
    }

    private function sendHttpError($stream, int $status, string $message): void
    {
        $reason  = $message;
        $body    = $message . "\n";
        $headers = sprintf("HTTP/1.1 %d %s\r\nConnection: close\r\nContent-Length: %d\r\n\r\n%s", $status, $reason, strlen($body), $body);
        $this->writeAll($stream, $headers);
    }

    private function buildError($idValue, bool $hasId, int $code, string $message, array $data = []): array
    {
        $error = [
            'jsonrpc' => '2.0',
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        if (!empty($data)) {
            $error['error']['data'] = $data;
        }

        if ($hasId) {
            $error['id'] = $idValue;
        }

        return $error;
    }

    private function encodeJson(array $payload): string
    {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (!is_string($encoded)) {
            $encoded = json_encode($payload);
        }

        return (string) $encoded;
    }

    private function normalizePath(string $path): string
    {
        return wp_gpt_normalize_path($path);
    }

    private function restoreServerGlobals($auth, $ua): void
    {
        if ($auth === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $auth;
        }

        if ($ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $ua;
        }
    }

    private function shutdown(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->closeClient($id, 1001, 'Server shutdown');
        }

        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            fprintf(STDOUT, "[%s] %s\n", date('c'), $message);
        }
    }

    private function logError(string $message): void
    {
        fprintf(STDERR, "[%s] %s\n", date('c'), $message);
    }
}

$server = new WpGptMcpWebsocketServer($host, $port, $path, $tlsOptions, $verbose);
$server->run();
