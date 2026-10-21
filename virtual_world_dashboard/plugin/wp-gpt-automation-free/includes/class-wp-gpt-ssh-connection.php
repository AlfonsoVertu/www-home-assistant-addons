<?php

namespace WP_GPT_Automation_Pro;

use Exception;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;


class WP_GPT_SSH {
    private const SSH_ASYNC_PLACEHOLDER_TEXT = 'Riprova fra qualche secondo per visualizzare la risposta.';
    private const SSH_ASYNC_META_KEY         = '__wp_gpt_async_meta';

    /**
     * Singleton instance used so helper methods can tap into the internal
     * connection routines without re-registering REST routes.
     *
     * @var self|null
     */
    private static $instance = null;

    public function __construct() {
        self::$instance = $this;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Retrieve the stored passive SSH credentials.
     *
     * @return array|WP_Error
     */
    public static function get_passive_credentials() {
        if (!get_option('wp_gpt_ssh_passive_enabled')) {
            return new WP_Error(
                'wp_gpt_passive_mode_disabled',
                __('Passive SSH mode is disabled.', 'wp-gpt-automation-pro'),
                ['status' => 403]
            );
        }

        $host                   = get_option('wp_gpt_ssh_host');
        $port                   = get_option('wp_gpt_ssh_port', 22);
        $username               = get_option('wp_gpt_ssh_username');
        $password               = get_option('wp_gpt_ssh_password');
        $private_key            = get_option('wp_gpt_ssh_private_key');
        $private_key_passphrase = get_option('wp_gpt_ssh_private_key_passphrase');

        if (empty($host) || empty($username)) {
            return new WP_Error(
                'wp_gpt_missing_ssh_credentials',
                __('SSH passive credentials are not configured.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $host     = sanitize_text_field(trim((string) $host));
        $port     = sanitize_text_field(trim((string) $port));
        $username = sanitize_text_field(trim((string) $username));
        $password = $password !== null ? wp_unslash($password) : null;
        if (is_string($private_key)) {
            $private_key = wp_unslash($private_key);
        }
        if (is_string($private_key_passphrase)) {
            $private_key_passphrase = wp_unslash($private_key_passphrase);
        }

        if (class_exists('WP_GPT_Email_Placeholder') && method_exists('WP_GPT_Email_Placeholder', 'replace_placeholders')) {
            $host     = \WP_GPT_Email_Placeholder::replace_placeholders($host, 'ssh');
            $port     = \WP_GPT_Email_Placeholder::replace_placeholders($port, 'ssh');
            $username = \WP_GPT_Email_Placeholder::replace_placeholders($username, 'ssh');
            if (null !== $password) {
                $password = \WP_GPT_Email_Placeholder::replace_placeholders($password, 'ssh');
            }
        }

        $credentials = [
            'host'                   => $host,
            'port'                   => $port ? (int) $port : 22,
            'username'               => $username,
            'password'               => $password,
            'private_key'            => $private_key,
            'private_key_passphrase' => $private_key_passphrase,
        ];

        /**
         * Allow filtering of the passive SSH credentials before usage.
         *
         * @param array $credentials
         */
        $credentials = apply_filters('wp_gpt_ssh_passive_credentials', $credentials);

        if (empty($credentials['host']) || empty($credentials['username'])) {
            return new WP_Error(
                'wp_gpt_invalid_ssh_credentials',
                __('SSH passive credentials are incomplete.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        if (empty($credentials['port'])) {
            $credentials['port'] = 22;
        }

        return $credentials;
    }

    /**
     * Execute a command over the passive SSH connection.
     *
     * @param string $command The command to execute remotely.
     *
     * @return string|WP_Error The captured stdout/stderr or a WP_Error on failure.
     */
    public static function execute_passive_command($command) {
        $credentials = self::get_passive_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $instance = self::$instance;
        if (!$instance instanceof self) {
            $instance = new self();
        }

        $output = $instance->connect_ssh_like_admin(
            $credentials['host'],
            (int) $credentials['port'],
            $credentials['username'],
            $credentials['password'],
            $command,
            isset($credentials['private_key']) ? $credentials['private_key'] : '',
            isset($credentials['private_key_passphrase']) ? $credentials['private_key_passphrase'] : null
        );

        if ($output === null) {
            return new WP_Error(
                'wp_gpt_ssh_execution_failed',
                __('Unable to execute the remote SSH command.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        return $output;
    }

    /**
     * Execute a command asynchronously over the passive SSH connection.
     *
     * The command is wrapped in a detached process via `nohup`/`setsid` and the
     * stdout/stderr streams are redirected to a remote log file. The helper
     * returns immediately with metadata that can be used to poll the job later.
     *
     * @param string $command The command to execute remotely.
     * @param array  $options Optional overrides. Supports `job_id`, `log_file`,
     *                        and `log_dir`.
     *
     * @return array|WP_Error Array containing job metadata or a WP_Error on
     *                        failure.
     */
    public static function execute_passive_command_async($command, array $options = []) {
        $command = is_string($command) ? trim($command) : '';
        if ($command === '') {
            return new WP_Error(
                'wp_gpt_async_empty_command',
                __('No command was provided for asynchronous execution.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $credentials = self::get_passive_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $job_id = isset($options['job_id']) ? self::normalize_job_id($options['job_id']) : '';
        if ($job_id === '') {
            $job_id = self::generate_job_id();
        }

        $log_file = isset($options['log_file']) ? self::sanitize_remote_path($options['log_file']) : '';
        $log_dir  = isset($options['log_dir']) ? self::sanitize_remote_path($options['log_dir']) : '';

        if ($log_file === '') {
            if ($log_dir === '') {
                $log_dir = '/tmp/wp-gpt-jobs';
            }

            $log_dir = rtrim($log_dir, '/');
            if ($log_dir === '') {
                $log_dir = '/tmp';
            }

            $log_file = $log_dir . '/' . $job_id . '.log';
        }

        if ($log_file[0] !== '/') {
            $log_file = '/' . ltrim($log_file, '/');
        }

        $command_b64 = base64_encode($command);

        $remote_command = self::build_async_wrapper($job_id, $log_file, $command_b64);

        $instance = self::$instance;
        if (!$instance instanceof self) {
            $instance = new self();
        }

        $output = $instance->execute_ssh_with_fallbacks(
            $credentials['host'],
            (int) $credentials['port'],
            $credentials['username'],
            $credentials['password'],
            isset($credentials['private_key']) ? $credentials['private_key'] : '',
            isset($credentials['private_key_passphrase']) ? $credentials['private_key_passphrase'] : null,
            $remote_command
        );

        if (is_wp_error($output)) {
            return $output;
        }

        $parsed = self::parse_key_value_output($output);
        $status = isset($parsed['status']) ? strtolower(trim((string) $parsed['status'])) : '';

        if ($status !== 'started') {
            return new WP_Error(
                'wp_gpt_async_unexpected_status',
                __('The remote host did not acknowledge the asynchronous job.', 'wp-gpt-automation-pro'),
                [
                    'status'        => 500,
                    'raw_output'    => trim((string) $output),
                    'parsed_output' => $parsed,
                ]
            );
        }

        $result = [
            'job_id'        => isset($parsed['job_id']) ? self::normalize_job_id($parsed['job_id']) : $job_id,
            'log_file'      => isset($parsed['log_file']) ? self::sanitize_remote_path($parsed['log_file']) : $log_file,
            'status'        => $status,
            'raw_output'    => trim((string) $output),
            'remote_output' => $parsed,
        ];

        if (isset($parsed['pid']) && $parsed['pid'] !== '') {
            $result['pid'] = (int) $parsed['pid'];
        }

        return $result;
    }

    private function execute_command_async_with_credentials(array $credentials, $command, array $options = []) {
        $command = is_string($command) ? trim($command) : '';
        if ($command === '') {
            return new WP_Error(
                'wp_gpt_async_empty_command',
                __('No command was provided for asynchronous execution.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $host                   = isset($credentials['host']) ? (string) $credentials['host'] : '';
        $port                   = isset($credentials['port']) ? (int) $credentials['port'] : 22;
        $username               = isset($credentials['username']) ? (string) $credentials['username'] : '';
        $password               = array_key_exists('password', $credentials) ? $credentials['password'] : null;
        $private_key            = isset($credentials['private_key']) ? $credentials['private_key'] : '';
        $private_key_passphrase = isset($credentials['private_key_passphrase']) ? $credentials['private_key_passphrase'] : null;

        if ($host === '' || $username === '') {
            return new WP_Error(
                'wp_gpt_async_missing_credentials',
                __('SSH credentials are incomplete for asynchronous execution.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $job_id = isset($options['job_id']) ? self::normalize_job_id($options['job_id']) : '';
        if ($job_id === '') {
            $job_id = self::generate_job_id();
        }

        $log_file = isset($options['log_file']) ? self::sanitize_remote_path($options['log_file']) : '';
        $log_dir  = isset($options['log_dir']) ? self::sanitize_remote_path($options['log_dir']) : '';

        if ($log_file === '') {
            if ($log_dir === '') {
                $log_dir = '/tmp/wp-gpt-jobs';
            }

            $log_dir = rtrim($log_dir, '/');
            if ($log_dir === '') {
                $log_dir = '/tmp';
            }

            $log_file = $log_dir . '/' . $job_id . '.log';
        }

        if ($log_file[0] !== '/') {
            $log_file = '/' . ltrim($log_file, '/');
        }

        $command_b64    = base64_encode($command);
        $remote_command = self::build_async_wrapper($job_id, $log_file, $command_b64);

        $output = $this->execute_ssh_with_fallbacks(
            $host,
            $port,
            $username,
            $password,
            $private_key,
            $private_key_passphrase,
            $remote_command
        );

        if (is_wp_error($output)) {
            return $output;
        }

        $parsed = self::parse_key_value_output($output);
        $status = isset($parsed['status']) ? strtolower(trim((string) $parsed['status'])) : '';
        if ($status !== 'started') {
            return new WP_Error(
                'wp_gpt_async_unexpected_status',
                __('The remote host did not acknowledge the asynchronous job.', 'wp-gpt-automation-pro'),
                [
                    'status'        => 500,
                    'raw_output'    => trim((string) $output),
                    'parsed_output' => $parsed,
                ]
            );
        }

        $result = [
            'job_id'        => isset($parsed['job_id']) ? self::normalize_job_id($parsed['job_id']) : $job_id,
            'log_file'      => isset($parsed['log_file']) ? self::sanitize_remote_path($parsed['log_file']) : $log_file,
            'status'        => $status,
            'raw_output'    => trim((string) $output),
            'remote_output' => $parsed,
        ];

        if (isset($parsed['pid']) && $parsed['pid'] !== '') {
            $result['pid'] = (int) $parsed['pid'];
        }

        return $result;
    }

    private function get_async_credentials_storage_key($job_id) {
        $job_id = self::normalize_job_id($job_id);
        return 'wp_gpt_async_ssh_creds_' . $job_id;
    }

    private function get_async_credentials_crypto_key() {
        $key = get_option('wp_gpt_encryption_key');
        if (!is_string($key) || trim($key) === '') {
            $key = wp_salt('auth');
        }

        return hash('sha256', (string) $key, true);
    }

    private function encrypt_async_credentials(array $credentials) {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
            return new WP_Error(
                'wp_gpt_async_crypto_unavailable',
                __('OpenSSL is required to encrypt SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $json = wp_json_encode($credentials);
        if (!is_string($json) || $json === '') {
            return new WP_Error(
                'wp_gpt_async_crypto_encode_failed',
                __('Unable to encode SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $iv         = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt(
            $json,
            'AES-256-CBC',
            $this->get_async_credentials_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($ciphertext) || $ciphertext === '') {
            return new WP_Error(
                'wp_gpt_async_crypto_encrypt_failed',
                __('Unable to encrypt SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        return base64_encode($iv . $ciphertext);
    }

    private function decrypt_async_credentials($payload) {
        if (!is_string($payload) || trim($payload) === '') {
            return new WP_Error(
                'wp_gpt_async_crypto_missing_payload',
                __('Missing encrypted SSH credentials payload.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        if (!function_exists('openssl_decrypt')) {
            return new WP_Error(
                'wp_gpt_async_crypto_unavailable',
                __('OpenSSL is required to decrypt SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) <= 16) {
            return new WP_Error(
                'wp_gpt_async_crypto_invalid_payload',
                __('Invalid encrypted SSH credentials payload.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $json       = openssl_decrypt(
            $ciphertext,
            'AES-256-CBC',
            $this->get_async_credentials_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($json) || $json === '') {
            return new WP_Error(
                'wp_gpt_async_crypto_decrypt_failed',
                __('Unable to decrypt SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new WP_Error(
                'wp_gpt_async_crypto_decode_failed',
                __('Unable to decode SSH credentials for async polling.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        return $data;
    }

    private function store_async_credentials_for_job($job_id, array $credentials) {
        $payload = $this->encrypt_async_credentials($credentials);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $storage_key = $this->get_async_credentials_storage_key($job_id);
        set_transient($storage_key, $payload, MONTH_IN_SECONDS);

        return $storage_key;
    }

    private function load_async_credentials_for_job($job_id) {
        $storage_key = $this->get_async_credentials_storage_key($job_id);
        return $this->load_async_credentials_from_storage_key($storage_key);
    }

    private function load_async_credentials_from_storage_key($storage_key) {
        $payload     = get_transient($storage_key);

        if (!is_string($payload) || $payload === '') {
            return new WP_Error(
                'wp_gpt_async_credentials_not_found',
                __('Async SSH credentials were not found for the requested job.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        return $this->decrypt_async_credentials($payload);
    }

    private function delete_async_credentials_for_job($job_id) {
        $storage_key = $this->get_async_credentials_storage_key($job_id);
        delete_transient($storage_key);
    }

    public function register_routes() {
        register_rest_route('wp-gpt/v1', '/ssh-connection', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_request'],
            'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code']
        ]);
                register_rest_route('wp-gpt/v1', '/ssh-connection-advanced-command', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_request_advanced_command'],
            'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code']
        ]);
    }

    public function handle_request(WP_REST_Request $request) {
        $action = $request->get_param('action');

        switch ($action) {
            case 'ssh-connection':
                return $this->connect_ssh($request);
            case 'get_ssh_connection_response_list_in_chunk':
                return $this->get_transient_list($request);
            case 'get_ssh_connection_single_response_in_chunk':
                return $this->get_transient_chunk($request);
            case 'delete_ssh_connection_transients':
                return $this->delete_transients($request);
            default:
                return new WP_REST_Response(['error' => 'Invalid action'], 400);
        }
    }

    private function resolve_credentials(WP_REST_Request $request) {
        $host                   = $request->get_param('host');
        $port                   = $request->get_param('port');
        $username               = $request->get_param('username');
        $password               = $request->get_param('password');
        $private_key            = $request->get_param('private_key');
        $private_key_passphrase = $request->get_param('private_key_passphrase');

        // When passive mode is enabled, fall back to saved credentials if
        // any parameter is missing from the request.
        if (get_option('wp_gpt_ssh_passive_enabled')) {
            if (empty($host)) {
                $host = get_option('wp_gpt_ssh_host');
            }
            if (empty($port)) {
                $port = get_option('wp_gpt_ssh_port', 22);
            }
            if (empty($username)) {
                $username = get_option('wp_gpt_ssh_username');
            }
            // Explicitly check for null or empty string so that a saved
            // password is used even when the request omits it completely.
            if ($password === null || $password === '') {
                $password = get_option('wp_gpt_ssh_password');
            }
            if ($private_key === null || $private_key === '') {
                $private_key = get_option('wp_gpt_ssh_private_key');
            }
            if ($private_key_passphrase === null || $private_key_passphrase === '') {
                $private_key_passphrase = get_option('wp_gpt_ssh_private_key_passphrase');
            }
        }

        $host     = $host ? sanitize_text_field( trim( $host ) ) : '';
        $port     = $port ? sanitize_text_field( trim( $port ) ) : '';
        $username = $username ? sanitize_text_field( trim( $username ) ) : '';
        $password = $password !== null ? wp_unslash( $password ) : null;
        if ( is_string( $private_key ) ) {
            $private_key = wp_unslash( $private_key );
        }
        if ( is_string( $private_key_passphrase ) ) {
            $private_key_passphrase = wp_unslash( $private_key_passphrase );
        }

        if ( class_exists( 'WP_GPT_Email_Placeholder' ) && method_exists( 'WP_GPT_Email_Placeholder', 'replace_placeholders' ) ) {
            $host     = WP_GPT_Email_Placeholder::replace_placeholders( $host, 'ssh' );
            $port     = WP_GPT_Email_Placeholder::replace_placeholders( $port, 'ssh' );
            $username = WP_GPT_Email_Placeholder::replace_placeholders( $username, 'ssh' );
            if ( null !== $password ) {
                $password = WP_GPT_Email_Placeholder::replace_placeholders( $password, 'ssh' );
            }
        }

        return [
            'host'     => $host,
            'port'     => $port ? intval( $port ) : 0,
            'username' => $username,
            // Do not sanitize the password to avoid stripping special characters.
            'password' => $password,
            'private_key' => $private_key,
            'private_key_passphrase' => $private_key_passphrase,
        ];
    }

    private function build_ssh_command($host, $port, $username, $command, $password = null) {
        $port             = (int) $port;
        $escaped_command  = $this->escape_command_for_double_quotes($command);
        $escaped_username = escapeshellarg((string) $username);
        $escaped_host     = escapeshellarg((string) $host);

        $ssh_command = sprintf(
            'ssh -o StrictHostKeyChecking=no -p %d %s@%s "%s"',
            $port,
            $escaped_username,
            $escaped_host,
            $escaped_command
        );

        if ($password === null || $password === '') {
            return $ssh_command;
        }

        return sprintf(
            'sshpass -p %s %s',
            escapeshellarg((string) $password),
            $ssh_command
        );
    }

    private function escape_command_for_double_quotes($command) {
        $command = (string) $command;

        return addcslashes($command, "\\\"");
    }

    private function connect_ssh_like_admin($host, $port, $username, $password, $command, $private_key = '', $private_key_passphrase = null) {
        $identityFile   = null;
        $privateKeyData = is_string($private_key) ? trim($private_key) : '';

        try {
            if ($privateKeyData !== '') {
                if (function_exists('wp_tempnam')) {
                    $identityFile = wp_tempnam('wp-gpt-ssh-key');
                }

                if (!$identityFile) {
                    $identityFile = tempnam(sys_get_temp_dir(), 'wp-gpt-ssh-key');
                }

                if (!$identityFile) {
                    throw new Exception('Unable to create a temporary file for the SSH private key.');
                }

                if (file_put_contents($identityFile, $privateKeyData) === false) {
                    throw new Exception('Unable to write the SSH private key to the temporary file.');
                }

                if (!chmod($identityFile, 0600)) {
                    throw new Exception('Unable to set secure permissions on the SSH private key temporary file.');
                }

                $escapedCommand  = $this->escape_command_for_double_quotes($command);
                $escapedUsername = escapeshellarg((string) $username);
                $escapedHost     = escapeshellarg((string) $host);
                $escapedIdentity = escapeshellarg($identityFile);

                $sshCommand = sprintf(
                    'ssh -o StrictHostKeyChecking=no -i %s -p %d %s@%s "%s"',
                    $escapedIdentity,
                    (int) $port,
                    $escapedUsername,
                    $escapedHost,
                    $escapedCommand
                );
            } else {
                $sshCommand = $this->build_ssh_command($host, $port, $username, $command, $password);
            }

            return shell_exec($sshCommand . ' 2>&1');
        } catch (Exception $e) {
            error_log('connect_ssh_like_admin failed: ' . $e->getMessage());
            return null;
        } finally {
            if ($identityFile && file_exists($identityFile)) {
                @unlink($identityFile);
            }
        }
    }

    private function execute_ssh_with_fallbacks($host, $port, $username, $password, $private_key, $private_key_passphrase, $command) {
        $errors            = [];
        $response          = null;
        $password_is_empty = ($password === null || $password === '');
        $private_key_value = is_string($private_key) ? $private_key : '';
        $has_private_key   = trim($private_key_value) !== '';
        $ssh2_available    = function_exists('ssh2_connect');

        if ($has_private_key) {
            if ($ssh2_available && function_exists('ssh2_auth_pubkey_file')) {
                try {
                    $response = $this->attempt_ssh2_pubkey_file($host, $port, $username, $private_key_value, $private_key_passphrase, $command);
                    if ($response !== null) {
                        return $response;
                    }
                    $errors[] = __('SSH2 extension (public key) failed to execute the command.', 'wp-gpt-automation-pro');
                } catch (Throwable $e) {
                    $errors[] = sprintf(__('SSH2 extension (public key) error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
                }
            } else {
                $errors[] = __('SSH2 extension (public key) not available.', 'wp-gpt-automation-pro');
            }

            try {
                $response = $this->attempt_phpseclib($host, $port, $username, null, $command, $private_key_value, $private_key_passphrase, false);
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('phpseclib public key authentication failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('phpseclib public key authentication error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }
        }

        if ($ssh2_available) {
            if ($password_is_empty) {
                $errors[] = __('SSH2 extension skipped because no password was provided for authentication.', 'wp-gpt-automation-pro');
            } else {
                try {
                    $response = $this->attempt_ssh2_connect($host, $port, $username, $password, $command);
                    if ($response !== null) {
                        return $response;
                    }
                    $errors[] = __('SSH2 extension failed to execute the command.', 'wp-gpt-automation-pro');
                } catch (Throwable $e) {
                    $errors[] = sprintf(__('SSH2 extension error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
                }
            }
        } else {
            $errors[] = __('SSH2 extension not available.', 'wp-gpt-automation-pro');
        }

        if ($password_is_empty) {
            $errors[] = __('sshpass strategies skipped because no password was provided.', 'wp-gpt-automation-pro');
        } else {
            try {
                $response = $this->attempt_sshpass_proc_open($host, $port, $username, $password, $command);
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('sshpass (proc_open) failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('sshpass (proc_open) error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }

            try {
                $response = $this->attempt_sshpass_shell_exec($host, $port, $username, $password, $command);
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('sshpass (shell_exec) failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('sshpass (shell_exec) error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }

            try {
                $response = $this->attempt_expect_script($host, $port, $username, $password, $command);
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('expect script failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('expect script error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }

            try {
                $response = $this->attempt_phpseclib($host, $port, $username, $password, $command);
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('phpseclib failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('phpseclib error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }
        }

        if ($password_is_empty) {
            try {
                // Fallback finale: esecuzione diretta tramite SSH passwordless, riutilizzando la stessa logica dell'admin helper.
                $response = $this->connect_ssh_like_admin(
                    $host,
                    $port,
                    $username,
                    $password,
                    $command,
                    $private_key,
                    $private_key_passphrase
                );
                if ($response !== null) {
                    return $response;
                }
                $errors[] = __('Direct SSH (passwordless) failed to execute the command.', 'wp-gpt-automation-pro');
            } catch (Throwable $e) {
                $errors[] = sprintf(__('Direct SSH (passwordless) error: %s', 'wp-gpt-automation-pro'), $e->getMessage());
            }
        }

        return new WP_Error(
            'wp_gpt_ssh_all_strategies_failed',
            __('Unable to execute the remote SSH command.', 'wp-gpt-automation-pro'),
            [
                'status' => 500,
                'errors' => $errors,
            ]
        );
    }

    public function handle_request_advanced_command(WP_REST_Request $request) {
    $action      = strtolower(trim($request->get_param('action')));
    $filepath    = $request->get_param('filepath');
    $content     = $request->get_param('content');
    $directory   = $request->get_param('directory');
    $permissions = $request->get_param('permissions');
    $owner       = $request->get_param('owner');
    $group       = $request->get_param('group');
    $command     = $request->get_param('command');

    $params = $request->get_json_params();
    if (empty($params)) {
        $params = $request->get_body_params();
    }
    if (!is_array($params)) {
        $params = [];
    }

    if ( class_exists('WP_GPT_Email_Placeholder') && method_exists('WP_GPT_Email_Placeholder', 'replace_placeholders') ) {
        if ( null !== $filepath ) {
            $filepath = WP_GPT_Email_Placeholder::replace_placeholders( $filepath, 'ssh' );
        }
        if ( null !== $content ) {
            $content = WP_GPT_Email_Placeholder::replace_placeholders( $content, 'ssh' );
        }
        if ( null !== $directory ) {
            $directory = WP_GPT_Email_Placeholder::replace_placeholders( $directory, 'ssh' );
        }
        if ( null !== $permissions ) {
            $permissions = WP_GPT_Email_Placeholder::replace_placeholders( $permissions, 'ssh' );
        }
        if ( null !== $owner ) {
            $owner = WP_GPT_Email_Placeholder::replace_placeholders( $owner, 'ssh' );
        }
        if ( null !== $group ) {
            $group = WP_GPT_Email_Placeholder::replace_placeholders( $group, 'ssh' );
        }
        if ( null !== $command ) {
            $command = WP_GPT_Email_Placeholder::replace_placeholders( $command, 'ssh' );
        }
    }

    $creds                  = $this->resolve_credentials($request);
    $host                   = $creds['host'];
    $port                   = $creds['port'];
    $username               = $creds['username'];
    $password               = $creds['password'];
    $private_key            = $creds['private_key'];
    $private_key_passphrase = $creds['private_key_passphrase'];

    if (!$host || !$port || !$username) {
        return new WP_REST_Response(['error' => 'Missing SSH connection data'], 400);
    }

    $has_explicit_request_credentials =
        $request->get_param('host') !== null ||
        $request->get_param('port') !== null ||
        $request->get_param('username') !== null ||
        $request->get_param('password') !== null ||
        $request->get_param('private_key') !== null ||
        $request->get_param('private_key_passphrase') !== null;
    $credential_mode = $has_explicit_request_credentials ? 'request' : 'passive';

    $start_async_chunked_response = function($cmd) use (
        $credential_mode,
        $host,
        $port,
        $username,
        $password,
        $private_key,
        $private_key_passphrase
    ) {
        $transient_key = 'ssh_response_' . uniqid();
        $placeholder   = self::SSH_ASYNC_PLACEHOLDER_TEXT;

        set_transient(
            $transient_key,
            [
                0 => $placeholder,
                self::SSH_ASYNC_META_KEY => [
                    'status' => 'started',
                    'cmd'    => (string) $cmd,
                ],
            ],
            MONTH_IN_SECONDS
        );

        $async_result = $this->execute_command_async_with_credentials(
            [
                'host'                   => $host,
                'port'                   => $port,
                'username'               => $username,
                'password'               => $password,
                'private_key'            => $private_key,
                'private_key_passphrase' => $private_key_passphrase,
            ],
            $cmd
        );
        if (is_wp_error($async_result)) {
            delete_transient($transient_key);

            $data   = $async_result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            $body   = [
                'error' => $async_result->get_error_message(),
                'code'  => $async_result->get_error_code(),
                'cmd'   => $cmd,
            ];

            return new WP_REST_Response($body, $status);
        }

        $chunks = get_transient($transient_key);
        if (!is_array($chunks)) {
            $chunks = [0 => $placeholder];
        }

        $job_id = isset($async_result['job_id']) ? (string) $async_result['job_id'] : '';
        if ($job_id === '') {
            delete_transient($transient_key);

            return new WP_REST_Response([
                'error' => __('Unable to identify async SSH job.', 'wp-gpt-automation-pro'),
                'code'  => 'wp_gpt_async_missing_job_id',
                'cmd'   => $cmd,
            ], 500);
        }

        $credential_ref = '';
        if ($credential_mode === 'request') {
            $stored_ref = $this->store_async_credentials_for_job($job_id, [
                'host'                   => $host,
                'port'                   => $port,
                'username'               => $username,
                'password'               => $password,
                'private_key'            => $private_key,
                'private_key_passphrase' => $private_key_passphrase,
            ]);

            if (is_wp_error($stored_ref)) {
                delete_transient($transient_key);

                $data   = $stored_ref->get_error_data();
                $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

                return new WP_REST_Response([
                    'error' => $stored_ref->get_error_message(),
                    'code'  => $stored_ref->get_error_code(),
                    'cmd'   => $cmd,
                ], $status);
            }

            $credential_ref = (string) $stored_ref;
        }

        $chunks[self::SSH_ASYNC_META_KEY] = [
            'status'          => 'started',
            'job_id'          => $job_id,
            'log_file'        => isset($async_result['log_file']) ? (string) $async_result['log_file'] : '',
            'cmd'             => (string) $cmd,
            'credential_mode' => $credential_mode,
            'credential_ref'  => $credential_ref,
        ];

        set_transient($transient_key, $chunks, MONTH_IN_SECONDS);

        return new WP_REST_Response([
            'message'       => sprintf(
                'Output in preparazione. Ripeti la chiamata tra qualche secondo usando action=get_ssh_connection_single_response_in_chunk, transient_key=%s, chunk_index=0.',
                $transient_key
            ),
            'first_chunk'   => $placeholder,
            'transient_key' => $transient_key,
            'total_chunks'  => 1,
            'cmd'           => $cmd,
        ], 200);
    };

    switch ($action) {
        case 'upload-file-to-path':
            $result = $this->handle_upload_file_to_path($request, $params);
            if (is_wp_error($result)) {
                $data   = $result->get_error_data();
                $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
                $body   = [
                    'error' => $result->get_error_message(),
                    'code'  => $result->get_error_code(),
                ];
                if (is_array($data) && isset($data['details'])) {
                    $body['details'] = $data['details'];
                }

                return new WP_REST_Response($body, $status);
            }

            return new WP_REST_Response($result, 200);

        case 'create-file':
            if (empty($filepath) || !isset($content)) {
                return new WP_REST_Response(['error' => 'filepath e content obbligatori'], 400);
            }
            $cmd = "printf %s " . escapeshellarg($content) . " > " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'append-to-file':
            if (empty($filepath) || !isset($content)) {
                return new WP_REST_Response(['error' => 'filepath e content obbligatori'], 400);
            }
            $cmd = "printf %s " . escapeshellarg($content) . " >> " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'delete-file':
            if (empty($filepath)) {
                return new WP_REST_Response(['error' => 'filepath obbligatorio'], 400);
            }
            $cmd = "rm -f " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'create-script-sh':
            if (empty($filepath) || !isset($content)) {
                return new WP_REST_Response(['error' => 'filepath e content obbligatori'], 400);
            }
            $cmd = "printf %s " . escapeshellarg($content) . " > " . escapeshellarg($filepath) . " && chmod +x " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'execute-script':
            if (empty($filepath)) {
                return new WP_REST_Response(['error' => 'filepath obbligatorio'], 400);
            }
            $cmd = "bash " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'chmod':
            if (empty($filepath) || empty($permissions)) {
                return new WP_REST_Response(['error' => 'filepath e permissions obbligatori'], 400);
            }
            $cmd = "chmod " . escapeshellarg($permissions) . " " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'chown':
            if (empty($filepath) || empty($owner)) {
                return new WP_REST_Response(['error' => 'filepath e owner obbligatori'], 400);
            }
            $chown_value = escapeshellarg($owner . (!empty($group) ? ':' . $group : ''));
            $cmd = "chown $chown_value " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'read-file':
            if (empty($filepath)) {
                return new WP_REST_Response(['error' => 'filepath obbligatorio'], 400);
            }
            $cmd = "cat " . escapeshellarg($filepath);
            return $start_async_chunked_response($cmd);

        case 'list-directory':
            if (empty($directory)) {
                return new WP_REST_Response(['error' => 'directory obbligatoria'], 400);
            }
            $cmd = "ls -la " . escapeshellarg($directory);
            return $start_async_chunked_response($cmd);

        case 'custom-command':
            if (empty($command)) {
                return new WP_REST_Response(['error' => 'command obbligatorio'], 400);
            }
            $cmd = $command;
            return $start_async_chunked_response($cmd);

        default:
            return new WP_REST_Response(['error' => 'Invalid or missing action.'], 400);
    }
}


private function handle_upload_file_to_path(WP_REST_Request $request, array $params) {
    $raw_refs = null;
    foreach (['openaiFileIdRefs', 'files', 'file'] as $key) {
        if (array_key_exists($key, $params)) {
            $raw_refs = $params[$key];
            break;
        }
    }

    if ($raw_refs === null) {
        foreach (['openaiFileIdRefs', 'files', 'file'] as $key) {
            $candidate = $request->get_param($key);
            if ($candidate !== null) {
                $raw_refs = $candidate;
                break;
            }
        }
    }

    $file_refs = WP_GPT_File_Transfer_Helper::normalize_file_refs($raw_refs);
    if (empty($file_refs)) {
        return new WP_Error(
            'missing_file_refs',
            __('Nessun file fornito. Invia openaiFileIdRefs con almeno un elemento per upload-file-to-path.', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    $directory_input = null;
    foreach (['target_directory', 'destination_directory', 'directory'] as $key) {
        if (array_key_exists($key, $params) && !is_array($params[$key])) {
            $directory_input = (string) $params[$key];
            break;
        }
    }

    if ($directory_input === null) {
        foreach (['target_directory', 'destination_directory', 'directory'] as $key) {
            $candidate = $request->get_param($key);
            if ($candidate !== null && !is_array($candidate)) {
                $directory_input = (string) $candidate;
                break;
            }
        }
    }

    if ($directory_input === null || trim($directory_input) === '') {
        return new WP_Error(
            'missing_target_directory',
            __('target_directory è obbligatorio per upload-file-to-path.', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    $target_directory = $this->normalize_local_destination_directory($directory_input);
    if (is_wp_error($target_directory)) {
        return $target_directory;
    }

    $overwrite         = true;
    $overwrite_source  = null;
    if (array_key_exists('overwrite', $params)) {
        $overwrite_source = $params['overwrite'];
    } elseif ($request->get_param('overwrite') !== null) {
        $overwrite_source = $request->get_param('overwrite');
    }

    if ($overwrite_source !== null) {
        if (is_string($overwrite_source)) {
            $filtered = filter_var($overwrite_source, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                $overwrite = $filtered;
            }
        } elseif (is_bool($overwrite_source)) {
            $overwrite = $overwrite_source;
        } elseif (is_int($overwrite_source)) {
            $overwrite = (int) $overwrite_source !== 0;
        }
    }

    $filename_input = null;
    foreach (['target_filename', 'filename', 'rename_to'] as $key) {
        if (array_key_exists($key, $params) && !is_array($params[$key])) {
            $filename_input = (string) $params[$key];
            break;
        }
    }

    if ($filename_input === null) {
        foreach (['target_filename', 'filename', 'rename_to'] as $key) {
            $candidate = $request->get_param($key);
            if ($candidate !== null && !is_array($candidate)) {
                $filename_input = (string) $candidate;
                break;
            }
        }
    }

    $base_filename = '';
    if ($filename_input !== null && trim($filename_input) !== '') {
        $base_filename = sanitize_file_name($filename_input);
    }

    $results    = [];
    $errors     = [];
    $file_count = count($file_refs);

    foreach ($file_refs as $index => $file_ref) {
        $staged = WP_GPT_File_Transfer_Helper::stage_file(is_array($file_ref) ? $file_ref : (array) $file_ref, $index);
        if (is_wp_error($staged)) {
            $errors[] = $this->format_upload_file_error($file_ref, $staged);
            continue;
        }

        $destination_name = $staged['file_name'];
        if ($base_filename !== '') {
            if ($file_count === 1) {
                $destination_name = $base_filename;
            } else {
                $info       = pathinfo($base_filename);
                $name_part  = isset($info['filename']) ? $info['filename'] : $base_filename;
                $extension  = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
                $candidate  = sprintf('%s-%d%s', $name_part, $index + 1, $extension);
                $sanitized  = sanitize_file_name($candidate);
                $destination_name = $sanitized !== '' ? $sanitized : $staged['file_name'];
            }
        }

        $moved = WP_GPT_File_Transfer_Helper::move_staged_file(
            $staged['staged_path'],
            $target_directory,
            $destination_name,
            $overwrite
        );

        if (is_wp_error($moved)) {
            WP_GPT_File_Transfer_Helper::cleanup_path($staged['staged_path']);
            $errors[] = $this->format_upload_file_error($file_ref, $moved);
            continue;
        }

        $results[] = [
            'file_name'      => $destination_name,
            'absolute_path'  => $moved['final_path'],
            'size'           => $moved['size'],
            'openai_file_id' => isset($staged['openai_file_id']) ? $staged['openai_file_id'] : '',
        ];
    }

    if (empty($results)) {
        return new WP_Error(
            'ssh_file_transfer_failed',
            __('Nessun file è stato trasferito nella directory richiesta.', 'wp-gpt-automation-pro'),
            [
                'status'  => 500,
                'details' => $errors,
            ]
        );
    }

    $response = [
        'action'           => 'upload-file-to-path',
        'target_directory' => $target_directory,
        'files'            => $results,
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
    }

    return $response;
}


private function normalize_local_destination_directory($directory_input) {
    $trimmed = trim((string) $directory_input);
    if ($trimmed === '') {
        return new WP_Error(
            'missing_target_directory',
            __('target_directory è obbligatorio per upload-file-to-path.', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    $normalized = str_replace('\\', '/', $trimmed);
    $normalized = preg_replace('#/+#', '/', $normalized);

    if (strpos($normalized, '..') !== false) {
        return new WP_Error(
            'invalid_target_directory',
            __('Il percorso di destinazione non può contenere ..', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    if (strpos($normalized, '://') !== false) {
        return new WP_Error(
            'invalid_target_directory',
            __('Specificare un percorso locale valido.', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    if (preg_match('#^[A-Za-z]:/#', $normalized)) {
        $resolved = wp_normalize_path($normalized);
    } elseif (isset($normalized[0]) && $normalized[0] === '/') {
        $resolved = wp_normalize_path($normalized);
    } else {
        $base     = wp_normalize_path(ABSPATH);
        $resolved = wp_normalize_path(trailingslashit($base) . ltrim($normalized, '/'));
    }

    if ($resolved === '') {
        return new WP_Error(
            'invalid_target_directory',
            __('Impossibile determinare la directory di destinazione richiesta.', 'wp-gpt-automation-pro'),
            ['status' => 400]
        );
    }

    return $resolved;
}


private function format_upload_file_error($file_ref, WP_Error $error) {
    if (is_object($file_ref)) {
        $file_ref = (array) $file_ref;
    }

    $openai_file_id = '';
    if (is_array($file_ref) && isset($file_ref['id']) && !is_array($file_ref['id'])) {
        $openai_file_id = sanitize_text_field($file_ref['id']);
    }

    return [
        'openai_file_id' => $openai_file_id,
        'code'           => $error->get_error_code(),
        'message'        => $error->get_error_message(),
    ];
}


private function connect_ssh(WP_REST_Request $request) {
    $creds                  = $this->resolve_credentials($request);
    $host                   = $creds['host'];
    $port                   = $creds['port'];
    $username               = $creds['username'];
    $password               = $creds['password'];
    $private_key            = $creds['private_key'];
    $private_key_passphrase = $creds['private_key_passphrase'];
    $command                = sanitize_text_field($request->get_param('command'));

    if ( class_exists('WP_GPT_Email_Placeholder') && method_exists('WP_GPT_Email_Placeholder', 'replace_placeholders') ) {
        $command = WP_GPT_Email_Placeholder::replace_placeholders( $command, 'ssh' );
    }

    if (!$host || !$port || !$username) {
        return new WP_REST_Response(['error' => 'Missing SSH connection data'], 400);
    }

    $transient_key = 'ssh_response_' . uniqid();

    $result = $this->execute_ssh_with_fallbacks($host, $port, $username, $password, $private_key, $private_key_passphrase, $command);
    if (is_wp_error($result)) {
        $data   = $result->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        $body   = [
            'error' => $result->get_error_message(),
            'code'  => $result->get_error_code(),
        ];

        if (is_array($data) && isset($data['errors'])) {
            $body['details'] = $data['errors'];
        }

        return new WP_REST_Response($body, $status);
    }

    $response = (string) $result;

    $this->save_transient_in_chunks($transient_key, $response);
    $first_chunk = $this->get_transient_chunk_content($transient_key, 0);
    $total_chunks = ceil(strlen($response) / 50);

    return new WP_REST_Response([
        'message' => 'Command executed successfully',
        'first_chunk' => $first_chunk,
        'transient_key' => $transient_key,
        'total_chunks' => $total_chunks
    ], 200);
}


    private function attempt_ssh2_connect($host, $port, $username, $password, $command) {
        if (!function_exists('ssh2_connect')) return null;

        try {
            $connection = @ssh2_connect($host, $port);
            if (!$connection) return null;
            if (!@ssh2_auth_password($connection, $username, $password)) return null;
            $stream = @ssh2_exec($connection, $command);
            if (!$stream) return null;
            stream_set_blocking($stream, true);
            $output = stream_get_contents($stream);
            $stderr_output = '';

            if (function_exists('ssh2_fetch_stream')) {
                $stderr_stream = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                if (is_resource($stderr_stream)) {
                    stream_set_blocking($stderr_stream, true);
                    $stderr_output = stream_get_contents($stderr_stream);
                    fclose($stderr_stream);
                }
            }

            fclose($stream);
            if ($stderr_output !== '') {
                $output = $output . ($output !== '' ? "\n" : '') . $stderr_output;
            }

            return $output;
        } catch (Exception $e) {
            error_log('ssh2_connect failed: ' . $e->getMessage());
            return null;
        }
    }

    private function attempt_ssh2_pubkey_file($host, $port, $username, $private_key, $private_key_passphrase, $command) {
        if (!function_exists('ssh2_connect') || !function_exists('ssh2_auth_pubkey_file')) return null;

        $privateKeyFile = tempnam(sys_get_temp_dir(), 'wpgpt_priv_');
        if ($privateKeyFile === false) {
            throw new RuntimeException('Unable to create temporary private key file.');
        }

        $publicKeyFile = $privateKeyFile . '.pub';
        $stream = null;
        $stderr_stream = null;

        try {
            if (file_put_contents($privateKeyFile, $private_key) === false) {
                throw new RuntimeException('Unable to write private key to temporary file.');
            }

            @chmod($privateKeyFile, 0600);

            $publicKeyContent = $this->extract_public_key_from_private($private_key, $private_key_passphrase);
            if (file_put_contents($publicKeyFile, $publicKeyContent) === false) {
                throw new RuntimeException('Unable to write public key to temporary file.');
            }

            @chmod($publicKeyFile, 0600);

            $connection = @ssh2_connect($host, $port);
            if (!$connection) {
                return null;
            }

            $passphrase = $private_key_passphrase !== null ? (string) $private_key_passphrase : '';
            if (!@ssh2_auth_pubkey_file($connection, $username, $publicKeyFile, $privateKeyFile, $passphrase)) {
                return null;
            }

            $stream = @ssh2_exec($connection, $command);
            if (!is_resource($stream)) {
                return null;
            }

            stream_set_blocking($stream, true);
            $output = stream_get_contents($stream);
            $stderr_output = '';

            if (function_exists('ssh2_fetch_stream')) {
                $stderr_stream = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                if (is_resource($stderr_stream)) {
                    stream_set_blocking($stderr_stream, true);
                    $stderr_output = stream_get_contents($stderr_stream);
                }
            }

            if (is_resource($stderr_stream)) {
                fclose($stderr_stream);
                $stderr_stream = null;
            }

            if (is_resource($stream)) {
                fclose($stream);
                $stream = null;
            }

            if ($stderr_output !== '') {
                $output = $output . ($output !== '' ? "\n" : '') . $stderr_output;
            }

            return $output;
        } finally {
            if (is_resource($stderr_stream)) {
                fclose($stderr_stream);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_string($publicKeyFile) && file_exists($publicKeyFile)) {
                @unlink($publicKeyFile);
            }

            if (is_string($privateKeyFile) && file_exists($privateKeyFile)) {
                @unlink($privateKeyFile);
            }
        }
    }

    private function extract_public_key_from_private($private_key, $private_key_passphrase) {
        if (!class_exists(PublicKeyLoader::class)) {
            throw new RuntimeException('phpseclib PublicKeyLoader is not available.');
        }

        try {
            $passphrase = $private_key_passphrase !== null ? (string) $private_key_passphrase : '';
            $loadedKey  = PublicKeyLoader::load($private_key, $passphrase);
            if (!method_exists($loadedKey, 'getPublicKey')) {
                throw new RuntimeException('Unable to derive public key from the provided private key.');
            }

            $publicKey = $loadedKey->getPublicKey();
            if (!method_exists($publicKey, 'toString')) {
                throw new RuntimeException('Unable to convert public key to OpenSSH format.');
            }

            $publicKeyString = $publicKey->toString('OpenSSH');
            if (substr($publicKeyString, -1) !== "\n") {
                $publicKeyString .= "\n";
            }

            return $publicKeyString;
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to extract public key: ' . $e->getMessage(), 0, $e);
        }
    }

    private function attempt_sshpass_proc_open($host, $port, $username, $password, $command) {
        try {
            $sshCommand = $this->build_ssh_command($host, $port, $username, $command, $password);
            $process = proc_open($sshCommand, [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]], $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], true);
                stream_set_blocking($pipes[2], true);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                if ($stderr !== '') {
                    $stdout = $stdout . ($stdout !== '' ? "\n" : '') . $stderr;
                }
                return $stdout;
            }
        } catch (Exception $e) {
            error_log('proc_open SSH failed: ' . $e->getMessage());
        }
        return null;
    }

    private function attempt_sshpass_shell_exec($host, $port, $username, $password, $command) {
        try {
            $sshCommand = $this->build_ssh_command($host, $port, $username, $command, $password);
            return shell_exec($sshCommand . ' 2>&1');
        } catch (Exception $e) {
            error_log('shell_exec SSH failed: ' . $e->getMessage());
        }
        return null;
    }

    private function attempt_expect_script($host, $port, $username, $password, $command) {
        try {
            $script = "expect -c \"\n"
                   . "spawn ssh -o StrictHostKeyChecking=no -p $port $username@$host\n"
                   . "expect \\\"password:\\\"\n"
                   . "send \"$password\\r\"\n"
                   . "expect \\\"$\\\"\n"
                   . "send \"$command\\r\"\n"
                   . "expect eof\n"
                   . "\"";
            return shell_exec($script . ' 2>&1');
        } catch (Exception $e) {
            error_log('expect SSH failed: ' . $e->getMessage());
        }
        return null;
    }

    private function attempt_phpseclib($host, $port, $username, $password, $command, $private_key = '', $private_key_passphrase = null, $allow_password_fallback = true) {
        try {
            $ssh          = new SSH2($host, $port);
            $authenticated = false;
            $privateKeyString = is_string($private_key) ? $private_key : '';

            if (trim($privateKeyString) !== '') {
                if (!class_exists(PublicKeyLoader::class)) {
                    throw new RuntimeException('phpseclib PublicKeyLoader is not available.');
                }

                $passphrase = $private_key_passphrase !== null ? (string) $private_key_passphrase : '';
                $loadedKey  = PublicKeyLoader::load($privateKeyString, $passphrase);
                $authenticated = $ssh->login($username, $loadedKey);
            }

            if (!$authenticated && $allow_password_fallback && $password !== null && $password !== '') {
                $authenticated = $ssh->login($username, $password);
            }

            if (!$authenticated) {
                return null;
            }

            return $ssh->exec($this->ensure_command_redirects_stderr($command));
        } catch (Throwable $e) {
            error_log('phpseclib SSH failed: ' . $e->getMessage());
        }

        return null;
    }

    private function ensure_command_redirects_stderr($command) {
        $command = (string) $command;
        if ($command === '') {
            return $command;
        }

        if (preg_match('/2>\s*&1/', $command)) {
            return $command;
        }

        return rtrim($command) . ' 2>&1';
    }

    private function save_transient_in_chunks($transient_key, $response) {
        $chunk_size = WP_GPT_CHUNK_SIZE;
        $max_size = 500000;

        if (strlen($response) > $max_size) {
            error_log("Errore: Risposta supera la dimensione massima di {$max_size} bytes.");
            return;
        }

        $chunks = str_split($response, $chunk_size);
        set_transient($transient_key, $chunks, MONTH_IN_SECONDS);
    }

    private function get_transient_list(WP_REST_Request $request) {
        global $wpdb;

        $params      = $request->get_params();
        $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;
        if ($chunk_index < 0) {
            $chunk_index = 0;
        }

        $chunk_size = 20;
        $offset     = $chunk_index * $chunk_size;
        $like       = $wpdb->esc_like('_transient_ssh_response_') . '%';

        $transient_query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d OFFSET %d",
            $like,
            $chunk_size,
            $offset
        );

        $transients = $wpdb->get_col($transient_query);
        $keys = array_map(static function ($name) {
            return str_replace('_transient_', '', $name);
        }, $transients ?: []);

        $total_transients = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        $total_chunks = $chunk_size > 0 ? (int) ceil($total_transients / $chunk_size) : 0;

        return new WP_REST_Response([
            'transients'       => $keys,
            'chunk_index'      => $chunk_index,
            'total_chunks'     => $total_chunks,
            'total_transients' => $total_transients,
        ], 200);
    }

    private function get_transient_chunk(WP_REST_Request $request) {
        return $this->get_transient_chunk_content(
            $request->get_param('transient_key'),
            intval($request->get_param('chunk_index'))
        );
    }

    private function get_transient_chunk_content($transient_key, $chunk_index) {
        $chunks = get_transient($transient_key);
        if (!$chunks || !is_array($chunks)) return new WP_REST_Response(['error' => 'Invalid chunk'], 400);

        $chunks = $this->maybe_finalize_async_ssh_transient($transient_key, $chunks);

        if ($this->is_async_ssh_placeholder_transient($chunks)) {
            return new WP_REST_Response([
                'chunk_content' => self::SSH_ASYNC_PLACEHOLDER_TEXT,
                'chunk_index'   => 0,
                'total_chunks'  => 1,
            ], 200);
        }

        if (!isset($chunks[$chunk_index])) return new WP_REST_Response(['error' => 'Invalid chunk'], 400);

        return new WP_REST_Response([
            'chunk_content' => $chunks[$chunk_index],
            'chunk_index' => $chunk_index,
            'total_chunks' => count($chunks)
        ], 200);
    }

    private function is_async_ssh_placeholder_transient($chunks) {
        if (!is_array($chunks)) {
            return false;
        }

        if (!isset($chunks[self::SSH_ASYNC_META_KEY]) || !is_array($chunks[self::SSH_ASYNC_META_KEY])) {
            return false;
        }

        return isset($chunks[0]) && $chunks[0] === self::SSH_ASYNC_PLACEHOLDER_TEXT;
    }

    private function maybe_finalize_async_ssh_transient($transient_key, $chunks) {
        if (!$this->is_async_ssh_placeholder_transient($chunks)) {
            return $chunks;
        }

        $meta            = $chunks[self::SSH_ASYNC_META_KEY];
        $job_id          = isset($meta['job_id']) ? (string) $meta['job_id'] : '';
        $log_file        = isset($meta['log_file']) ? self::sanitize_remote_path($meta['log_file']) : '';
        $credential_mode = isset($meta['credential_mode']) ? (string) $meta['credential_mode'] : 'passive';
        $credential_ref  = isset($meta['credential_ref']) ? (string) $meta['credential_ref'] : '';

        if ($job_id === '' || $log_file === '') {
            return $chunks;
        }

        if ($credential_mode === 'request') {
            if ($credential_ref !== '') {
                $credentials = $this->load_async_credentials_from_storage_key($credential_ref);
            } else {
                $credentials = $this->load_async_credentials_for_job($job_id);
            }
        } else {
            $credentials = self::get_passive_credentials();
        }

        if (is_wp_error($credentials) || !is_array($credentials)) {
            return $chunks;
        }

        $log_output  = $this->execute_ssh_with_fallbacks(
            isset($credentials['host']) ? $credentials['host'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 22,
            isset($credentials['username']) ? $credentials['username'] : '',
            array_key_exists('password', $credentials) ? $credentials['password'] : null,
            isset($credentials['private_key']) ? $credentials['private_key'] : '',
            isset($credentials['private_key_passphrase']) ? $credentials['private_key_passphrase'] : null,
            'if [ -f ' . escapeshellarg($log_file) . ' ]; then cat ' . escapeshellarg($log_file) . '; fi'
        );

        if (is_wp_error($log_output)) {
            return $chunks;
        }

        $log_output = (string) $log_output;
        if ($log_output === '' || strpos($log_output, 'finished with exit code') === false) {
            return $chunks;
        }

        $chunk_size = WP_GPT_CHUNK_SIZE;
        $real_chunks = str_split($log_output, $chunk_size);
        if (empty($real_chunks)) {
            $real_chunks = [''];
        }

        set_transient($transient_key, $real_chunks, MONTH_IN_SECONDS);
        if ($credential_mode === 'request') {
            $this->delete_async_credentials_for_job($job_id);
        }

        return $real_chunks;
    }

    private function delete_transients(WP_REST_Request $request) {
        global $wpdb;
        $transients = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_ssh_response_%'", ARRAY_A);
        $deleted = 0;

        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient['option_name']);
            $value = get_transient($key);

            if (is_array($value) && isset($value[self::SSH_ASYNC_META_KEY]) && is_array($value[self::SSH_ASYNC_META_KEY])) {
                $job_id = isset($value[self::SSH_ASYNC_META_KEY]['job_id']) ? (string) $value[self::SSH_ASYNC_META_KEY]['job_id'] : '';
                if ($job_id !== '') {
                    $this->delete_async_credentials_for_job($job_id);
                }
            }

            if (delete_transient($key)) $deleted++;
        }

        return new WP_REST_Response([
            'message' => 'All SSH-related transients deleted successfully',
            'deleted_count' => $deleted
        ], 200);
    }

    public static function validate_request_with_user_code(WP_REST_Request $request) {
        $license_check = \WP_GPT_Automation_Pro\WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        // Prima valida tramite l'API centrale
        $core_validation = \WP_GPT_Automation_Pro\WP_GPT_API::validate_request_with_user_code($request);
        if (is_wp_error($core_validation)) {
            return $core_validation;
        }

        // Log aggiuntivo per debug
        $params = $request->get_json_params();
        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';

        $debug_context = [
            'client_ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'server_addr'          => $_SERVER['SERVER_ADDR'] ?? 'N/A',
            'http_x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A',
            'http_client_ip'       => $_SERVER['HTTP_CLIENT_IP'] ?? 'N/A',
            'remote_addr'          => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'user_code'            => $user_code,
            'source'               => __METHOD__,
            'timestamp'            => current_time('timestamp'),
        ];

        // Debug server snapshot disabled in Free version.

        return true;
    }


    private static function build_async_wrapper($job_id, $log_file, $command_b64) {
        $job_id_arg    = escapeshellarg($job_id);
        $log_file_arg  = escapeshellarg($log_file);
        $command_arg   = escapeshellarg($command_b64);
        $job_var       = 'WP_GPT_JOB_ID';
        $log_var       = 'WP_GPT_JOB_LOG';
        $command_var   = 'WP_GPT_JOB_COMMAND_B64';

        $wrapper = <<<'WRAP'
COMMAND=$(printf "%s" "$WP_GPT_JOB_COMMAND_B64" | base64 -d)
START_TS=$(date +%F\ %T)
printf "[%s] job %s started
" "$START_TS" "$WP_GPT_JOB_ID" >> "$WP_GPT_JOB_LOG"
sh -c "$COMMAND"
EXIT_CODE=$?
END_TS=$(date +%F\ %T)
printf "[%s] job %s finished with exit code %s
" "$END_TS" "$WP_GPT_JOB_ID" "$EXIT_CODE" >> "$WP_GPT_JOB_LOG"
printf "status=completed
job_id=%s
exit_code=%s
" "$WP_GPT_JOB_ID" "$EXIT_CODE" >> "$WP_GPT_JOB_LOG"
exit $EXIT_CODE
WRAP;
        $wrapper_b64 = base64_encode($wrapper);

        $script_parts   = [];
        $script_parts[] = $job_var . '=' . $job_id_arg;
        $script_parts[] = $log_var . '=' . $log_file_arg;
        $script_parts[] = $command_var . '=' . $command_arg;
        $script_parts[] = 'WRAPPER_B64=' . escapeshellarg($wrapper_b64);
        $script_parts[] = 'WRAPPER_SCRIPT=$(printf "%s" "$WRAPPER_B64" | base64 -d)';
        $script_parts[] = 'JOB_DIR=$(dirname -- "$' . $log_var . '")';
        $script_parts[] = 'mkdir -p "$JOB_DIR"';
        $script_parts[] = 'touch "$' . $log_var . '"';
        $script_parts[] = 'chmod 600 "$' . $log_var . '" >/dev/null 2>&1 || true';
        $script_parts[] = 'START_TS=$(date +%F\ %T)';
        $script_parts[] = 'printf "[%s] job %s queued
" "$START_TS" "$' . $job_var . '" >> "$' . $log_var . '"';
        $script_parts[] = 'export ' . $job_var . ' ' . $log_var . ' ' . $command_var . ' WRAPPER_B64 WRAPPER_SCRIPT';
        $script_parts[] = 'setsid nohup sh -c "$WRAPPER_SCRIPT" >> "$' . $log_var . '" 2>&1 &';
        $script_parts[] = 'PID=$!';
        $script_parts[] = 'printf "status=started
job_id=%s
log_file=%s
pid=%s
" "$' . $job_var . '" "$' . $log_var . '" "$PID"';

        return implode('; ', $script_parts) . ';';
    }

    private static function generate_job_id() {
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('job_', true);
        $uuid = (string) $uuid;
        $uuid = str_replace(['{', '}', '.'], '', $uuid);
        $uuid = strtolower($uuid);
        $uuid = preg_replace('/[^a-z0-9_-]/', '', $uuid);

        if ($uuid === '') {
            $uuid = 'job_' . time();
        }

        return $uuid;
    }

    private static function normalize_job_id($job_id) {
        $job_id = (string) $job_id;
        $job_id = strtolower($job_id);
        $job_id = preg_replace('/[^a-z0-9._-]/', '', $job_id);

        return $job_id;
    }

    private static function sanitize_remote_path($path) {
        $path = (string) $path;
        $path = trim($path);
        $path = preg_replace('/[^a-zA-Z0-9._\/-]/', '', $path);

        return $path;
    }

    private static function parse_key_value_output($output) {
        $data = [];
        $logs = [];

        $lines = preg_split("/\r?\n/", (string) $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key   = strtolower(trim($key));
                $value = trim($value);

                if ($key === 'log') {
                    $logs[] = $value;
                } else {
                    $data[$key] = $value;
                }
            }
        }

        if ($logs) {
            $data['log'] = $logs;
        }

        return $data;
    }

}

new WP_GPT_SSH();
