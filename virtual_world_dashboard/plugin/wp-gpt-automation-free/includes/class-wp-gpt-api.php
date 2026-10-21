<?php

namespace WP_GPT_Automation_Pro;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Http_Cookie;
use WP_GPT_Automation_Pro\WP_GPT_User_Helper;

require_once plugin_dir_path(__FILE__) . 'class-wp-gpt-user-helper.php';

class WP_GPT_API
{

    /**
     * Expiration time for transient chunked responses (1 day).
     *
     * @var int
     */
    private static $transient_expiration = DAY_IN_SECONDS;

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('wp_kses_allowed_html', function ($allowed_tags, $context) {
            if ($context === 'post') {  // Applica il filtro solo ai contenuti dei post
                $allowed_tags['iframe'] = [
                    'src' => true,
                    'title' => true,
                    'aria-label' => true,
                    'role' => true,
                    'tabindex' => true,
                    'width' => true,
                    'height' => true,
                    'frameborder' => true,
                    'allow' => true,
                    'allowfullscreen' => true,
                    'referrerpolicy' => true,
                ];
            }
            return $allowed_tags;
        }, 10, 2);
    }

    public static function register_routes()
    {
        register_rest_route('wp-gpt/v1', '/manage-page-with-user-code/', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'manage_page_with_user_code'],
            'permission_callback' => [__CLASS__, 'validate_request_with_user_code'],
        ]);

        register_rest_route('wp-gpt/v1', '/manage-file/', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'manage_file'],
            'permission_callback' => [__CLASS__, 'validate_request_with_user_code'],
        ]);
    }
    public static function validate_always_true($request)
    {
        if (!$request instanceof WP_REST_Request) {
            return new WP_Error('invalid_request', __('Richiesta non valida', 'wp-gpt-automation-pro'), ['status' => 400]);
        }

        return self::validate_request_with_user_code($request);
    }
    public static function get_categories(WP_REST_Request $request)
    {
        $categories = get_categories(['hide_empty' => false]);
        return new WP_REST_Response($categories, 200);
    }
    public static function search_posts(WP_REST_Request $request)
    {
        $params = $request->get_query_params();

        $query_args = [
            's' => sanitize_text_field($params['search']),
            'post_type' => isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'post',
            'posts_per_page' => isset($params['limit']) ? intval($params['limit']) : 10,
        ];

        $query = new \WP_Query($query_args);

        return new WP_REST_Response($query->posts, 200);
    }
    public static function insert_image(WP_REST_Request $request)
    {
        $rate_check = self::enforce_rate_limit('insert_image');
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        $params = $request->get_json_params();
        $post_id = intval($params['post_id']);
        $image_url = esc_url_raw($params['image_url']);
        $log_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && get_option('wp_gpt_swagger_debug');

        // Validazione base
        if (!get_post($post_id)) {
            if ($log_enabled) {
                error_log('Post non trovato: ' . $post_id);
            }
            return new WP_REST_Response('Post non trovato', 404);
        }
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_REST_Response('URL immagine non valido', 400);
        }

        // Aggiungi MIME temporanei
        add_filter('upload_mimes', [__CLASS__, 'add_temp_mimes']);
        add_filter('wp_handle_sideload_prefilter', [__CLASS__, 'allow_custom_file_types'], 10, 4);

        // Converte Google Drive se necessario
        $image_url = self::convert_google_drive_url($image_url);

        // Scarica e imposta immagine
        $image_id = self::set_featured_image($post_id, $image_url);

        // Se fallisce prova il fallback analizzando l'HTML condiviso
        if (is_wp_error($image_id)) {
            if ($log_enabled) {
                error_log('Errore immagine: ' . $image_id->get_error_message() . ' - tentativo fallback HTML');
            }
            $image_id = self::fallback_set_image_from_page($post_id, $image_url);
        }

        // Fallback finale: prova il download diretto gestendo qualsiasi estensione
        if (is_wp_error($image_id)) {
            if ($log_enabled) {
                error_log('Errore fallback HTML: ' . $image_id->get_error_message() . ' - tentativo download diretto');
            }
            $image_id = self::fallback_direct_download_image($post_id, $image_url);
        }

        // Rimuove MIME temporanei
        remove_filter('upload_mimes', [__CLASS__, 'add_temp_mimes']);
        remove_filter('wp_handle_sideload_prefilter', [__CLASS__, 'allow_custom_file_types'], 10);

        if (is_wp_error($image_id)) {
            if ($log_enabled) {
                error_log('Errore immagine definitivo: ' . $image_id->get_error_message());
            }
            $prompt = 'Non ci sono riuscito usando insertimage, prova usando la rotta v1\\manage-file e successivamente inserisci l\'immagine nell\'articolo.';
            return new WP_REST_Response([
                'error' => $image_id->get_error_message(),
                'prompt' => $prompt,
            ], 500);
        }

        if ($log_enabled) {
            error_log('✅ Immagine inserita, ID: ' . $image_id);
        }
        $uploaded_url = wp_get_attachment_url($image_id);
        return new WP_REST_Response([
            'post_id' => $post_id,
            'image_id' => $image_id,
            'image_url' => $uploaded_url,
        ], 200);
    }
    public static function add_temp_mimes($mimes)
    {
        $mimes['webp'] = 'image/webp';
        // Support files that might expose the MIME type as part of the extension.
        $mimes['image/webp'] = 'image/webp';
        $mimes['png'] = 'image/png';
        $mimes['jpg'] = 'image/jpeg';
        $mimes['jpeg'] = 'image/jpeg';
        $mimes['gif'] = 'image/gif';
        $mimes['bmp'] = 'image/bmp';
        $mimes['tif'] = 'image/tiff';
        $mimes['tiff'] = 'image/tiff';
        $mimes['ico'] = 'image/x-icon';
        $mimes['svg'] = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
        $mimes['heic'] = 'image/heic';
        $mimes['heif'] = 'image/heif';
        $mimes['avif'] = 'image/avif';
        $mimes['apng'] = 'image/apng';
        return $mimes;
    }

    public static function allow_custom_file_types($file)
    {
        $filename = $file['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = !empty($file['type']) ? strtolower($file['type']) : '';
        $allowed = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'ico', 'svg', 'svgz', 'heic', 'heif', 'avif', 'apng'];

        if (in_array($ext, $allowed) || $mime === 'image/webp') {
            $ext = $mime === 'image/webp' ? 'webp' : $ext;
            $mime = $mime === 'image/webp' ? 'image/webp' : 'image/' . $ext;

            return [
                'ext' => $ext,
                'type' => $mime,
                'proper_filename' => $filename,
            ];
        }

        return $file;
    }


    private static function get_random_user_agent()
    {
        $user_agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_4) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/122.0.0.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 11_6_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Fedora; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
        ];
        return $user_agents[array_rand($user_agents)];
    }

    private static function get_random_timeout()
    {
        return rand(15, 45);
    }

    private static function get_random_referer()
    {
        $referers = [
            'https://www.google.com/',
            'https://www.bing.com/',
            'https://www.facebook.com/',
            'https://twitter.com/',
            'https://www.reddit.com/',
            'https://duckduckgo.com/',
            'https://news.ycombinator.com/'
        ];
        return $referers[array_rand($referers)];
    }

    private static function get_random_cookies()
    {
        $cookies = [
            new WP_Http_Cookie(['name' => 'sessionid', 'value' => md5(uniqid('', true))]),
            new WP_Http_Cookie(['name' => 'userid', 'value' => (string) rand(1000, 9999)]),
        ];
        return $cookies;
    }

    private static function get_random_cookie_header()
    {
        $cookies = self::get_random_cookies();
        $pairs = [];
        foreach ($cookies as $cookie) {
            $pairs[] = $cookie->name . '=' . $cookie->value;
        }
        return implode('; ', $pairs);
    }

    private static function enforce_rate_limit($context, $interval = 5)
    {
        $context = sanitize_key($context);
        $interval = (int) apply_filters('wp_gpt_rate_limit_interval', $interval, $context);

        if ($interval <= 0) {
            return true;
        }

        $transient_key = 'wp_gpt_rate_' . $context;
        $last_run = get_transient($transient_key);

        if ($last_run !== false) {
            $elapsed = time() - (int) $last_run;

            if ($elapsed < $interval) {
                $retry_after = max(1, $interval - $elapsed);

                return new WP_Error(
                    'rate_limited',
                    sprintf(
                        __('Rate limit exceeded. Please retry in %d seconds.', 'wp-gpt-automation-pro'),
                        $retry_after
                    ),
                    [
                        'status' => 429,
                        'retry_after' => $retry_after,
                    ]
                );
            }
        }

        set_transient($transient_key, time(), $interval);

        return true;
    }

    private static function get_random_proxy()
    {
        $proxies = apply_filters('wp_gpt_random_proxies', []);
        if (!empty($proxies)) {
            return $proxies[array_rand($proxies)];
        }
        return null;
    }


    private static function convert_google_drive_url($url)
    {
        if (preg_match('#drive\.google\.com/file/d/([^/]+)#', $url, $matches)) {
            return 'https://drive.google.com/uc?export=download&id=' . $matches[1];
        }
        return $url;
    }


    private static function safe_download_url($url)
    {
        $tmp_file = wp_tempnam($url);
        if (!$tmp_file) {
            return new WP_Error('temp_file_error', 'Impossibile creare file temporaneo');
        }

        $chunk_size = rand(1024, 8192);

        $head = wp_remote_head($url, [
            'timeout' => self::get_random_timeout(),
            'headers' => ['User-Agent' => self::get_random_user_agent()],
        ]);
        $head_code = is_wp_error($head) ? 'WP_Error: ' . $head->get_error_message() : wp_remote_retrieve_response_code($head);

        $common_headers = [
            'User-Agent' => self::get_random_user_agent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'it-IT,it;q=0.8,en-US;q=0.5,en;q=0.3',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Cache-Control' => 'max-age=0',
            'Referer' => self::get_random_referer(),
            'Cookie' => self::get_random_cookie_header(),
        ];

        $args = [
            'timeout' => self::get_random_timeout(),
            'stream' => true,
            'filename' => $tmp_file,
            'redirection' => 10,
            'headers' => $common_headers,
            'cookies' => self::get_random_cookies(),
            'reject_unsafe_urls' => false,
            'sslverify' => false,
        ];
        $proxy = self::get_random_proxy();
        if ($proxy) {
            $args['proxy'] = $proxy;
        }

        $response = wp_remote_get($url, $args);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && file_exists($tmp_file) && filesize($tmp_file) > 0) {
            return $tmp_file;
        }

        $image_data = false;
        if (rand(0, 1)) {
            if (self::download_with_chunks($url, $tmp_file, $chunk_size)) {
                return $tmp_file;
            }
        } else {
            $image_data = @file_get_contents($url);
            if ($image_data !== false) {
                file_put_contents($tmp_file, $image_data);
                return $tmp_file;
            }
        }

        $ch = curl_init($url);
        $fp = fopen($tmp_file, 'w');

        $curl_headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: it-IT,it;q=0.8,en-US;q=0.5,en;q=0.3',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: max-age=0',
            'Referer: ' . self::get_random_referer(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::get_random_user_agent(),
            CURLOPT_TIMEOUT => self::get_random_timeout(),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_BUFFERSIZE => $chunk_size,
        ]);
        $cookie_header = self::get_random_cookie_header();
        if ($cookie_header) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie_header);
        }
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        curl_exec($ch);

        $curl_error = curl_errno($ch) ? curl_error($ch) : null;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (file_exists($tmp_file) && filesize($tmp_file) > 0) {
            return $tmp_file;
        }

        return new WP_Error(
            'download_failed',
            'Errore nel download immagine da URL: ' . esc_url($url) .
            ' | HEAD: ' . print_r($head_code, true) .
            ' | wp_remote_get: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)) .
            ' | file_get_contents: ' . ($image_data === false ? 'fallito' : 'ok') .
            ' | curl: ' . ($curl_error ?: 'HTTP ' . $http_code)
        );
    }

    private static function download_with_chunks($url, $tmp_file, $chunk_size)
    {
        $input = @fopen($url, 'rb');
        $output = @fopen($tmp_file, 'wb');
        if (!$input || !$output) {
            if ($input) {
                fclose($input);
            }
            if ($output) {
                fclose($output);
            }
            return false;
        }

        while (!feof($input)) {
            fwrite($output, fread($input, $chunk_size));
            usleep(rand(100, 500) * 1000);
        }

        fclose($input);
        fclose($output);

        return file_exists($tmp_file) && filesize($tmp_file) > 0;
    }


    private static function ensure_media_dependencies()
    {
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
    }


    private static function optimize_and_attach_image($post_id, $tmp_file, $suggested_name = '', $provided_mime = '')
    {
        $file_info = wp_check_filetype($tmp_file);
        $ext = strtolower($file_info['ext']);
        $image_type = $provided_mime ?: $file_info['type'];

        if (!$image_type || $image_type === 'application/octet-stream' || $image_type === 'binary/octet-stream') {
            $detected = function_exists('mime_content_type') ? mime_content_type($tmp_file) : '';
            if ($detected) {
                $image_type = $detected;
                if (!$ext) {
                    $ext = strtolower(explode('/', $detected)[1] ?? '');
                }
            }
        }

        if (!$ext && $image_type) {
            $ext = self::guess_extension_from_mime($image_type);
        }

        if (!$ext && $suggested_name) {
            $ext = strtolower(pathinfo($suggested_name, PATHINFO_EXTENSION));
        }

        $supported = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'ico', 'svg', 'svgz', 'heic', 'heif', 'avif', 'webp', 'apng', 'image/webp'];

        if ($ext === 'webp' || $image_type === 'image/webp') {
            if (!function_exists('imagecreatefromwebp')) {
                return new WP_Error('webp_not_supported', 'Funzione imagecreatefromwebp non disponibile');
            }
            $image = @imagecreatefromwebp($tmp_file);
            if (!$image) {
                return new WP_Error('image_conversion_failed', 'Impossibile convertire immagine WebP');
            }
            $converted_file = strpos($tmp_file, '.webp') !== false ? str_replace('.webp', '.jpg', $tmp_file) : $tmp_file . '.jpg';
            imagejpeg($image, $converted_file, 85);
            imagedestroy($image);
            @unlink($tmp_file);
            $tmp_file = $converted_file;
            $ext = 'jpg';
            $image_type = 'image/jpeg';
        } elseif (!$image_type || !in_array($ext, $supported, true) || $ext === 'bin') {
            $image_data = @file_get_contents($tmp_file);
            $image = @imagecreatefromstring($image_data);
            if (!$image) {
                @unlink($tmp_file);
                return new WP_Error('unsupported_image_type', 'Tipo di file non supportato');
            }
            $converted_file = $tmp_file . '.jpg';
            imagejpeg($image, $converted_file, 85);
            imagedestroy($image);
            @unlink($tmp_file);
            $tmp_file = $converted_file;
            $ext = 'jpg';
            $image_type = 'image/jpeg';
        }

        $editor = wp_get_image_editor($tmp_file);
        if (!is_wp_error($editor)) {
            $editor->set_quality(82);
            $editor->resize(1200, 1200, false);
            $editor->save($tmp_file);
        }

        $ext = $ext ?: 'jpg';
        $image_type = $image_type ?: 'image/jpeg';

        $filename = $suggested_name ? sanitize_file_name($suggested_name) : '';
        if (!$filename) {
            $filename = 'image-' . time();
        }

        if (strpos($filename, '.') === false) {
            $filename .= '.' . $ext;
        } else {
            $filename = preg_replace('/\.[^.]+$/', '.' . $ext, $filename);
        }
        $filename = sanitize_file_name($filename);

        $image_id = media_handle_sideload([
            'name' => $filename,
            'tmp_name' => $tmp_file,
            'type' => $image_type,
        ], $post_id);

        if (is_wp_error($image_id)) {
            return $image_id;
        }

        set_post_thumbnail($post_id, $image_id);
        $alt = sanitize_text_field(get_the_title($post_id));
        if ($alt) {
            update_post_meta($image_id, '_wp_attachment_image_alt', $alt);
        }

        return $image_id;
    }


    private static function guess_extension_from_mime($mime)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/x-icon' => 'ico',
            'image/svg+xml' => 'svg',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/avif' => 'avif',
            'image/apng' => 'apng',
            'application/octet-stream' => 'bin',
            'binary/octet-stream' => 'bin',
        ];

        $mime = strtolower(trim((string) $mime));
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        if (strpos($mime, 'image/') === 0) {
            return substr($mime, strlen('image/')) ?: '';
        }

        return '';
    }


    private static function determine_filename_from_headers($image_url, $head_response, $get_response, $extension)
    {
        $filename = '';

        $responses = [$head_response, $get_response];
        foreach ($responses as $response) {
            if ($filename || !$response || is_wp_error($response)) {
                continue;
            }

            $header = wp_remote_retrieve_header($response, 'content-disposition');
            if (!$header) {
                continue;
            }

            if (preg_match('/filename\*?=([^;]+)/i', $header, $match)) {
                $candidate = trim($match[1]);
                $candidate = trim($candidate, " \"'\t");

                if (stripos($candidate, "utf-8''") === 0) {
                    $candidate = rawurldecode(substr($candidate, 7));
                }

                if ($candidate) {
                    $filename = $candidate;
                }
            }
        }

        if (!$filename) {
            $path = wp_parse_url($image_url, PHP_URL_PATH);
            if ($path) {
                $filename = basename($path);
            }
        }

        $filename = sanitize_file_name($filename);

        if (!$filename) {
            $filename = 'image-' . time();
        }

        if ($extension) {
            if (strpos($filename, '.') === false) {
                $filename .= '.' . $extension;
            } else {
                $filename = preg_replace('/\.[^.]+$/', '.' . $extension, $filename);
            }
        }

        return $filename;
    }


    private static function fallback_direct_download_image($post_id, $image_url)
    {
        self::ensure_media_dependencies();

        $head = wp_remote_head($image_url, [
            'timeout' => 15,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => self::get_random_user_agent(),
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ],
        ]);

        $content_type = '';
        if (!is_wp_error($head)) {
            $content_type = wp_remote_retrieve_header($head, 'content-type');
        }

        $response = wp_remote_get($image_url, [
            'timeout' => 30,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => self::get_random_user_agent(),
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer' => self::get_random_referer(),
            ],
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!$content_type) {
            $content_type = wp_remote_retrieve_header($response, 'content-type');
        }
        if (is_array($content_type)) {
            $content_type = reset($content_type);
        }
        if ($content_type) {
            $content_type = trim(explode(';', $content_type)[0]);
        }

        $extension = self::guess_extension_from_mime($content_type);
        $filename = self::determine_filename_from_headers($image_url, $head, $response, $extension);

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return new WP_Error('empty_image_body', 'Contenuto immagine vuoto');
        }

        $tmp_file = wp_tempnam($image_url);
        if (!$tmp_file) {
            return new WP_Error('temp_file_error', 'Impossibile creare file temporaneo');
        }

        file_put_contents($tmp_file, $body);

        if (!file_exists($tmp_file) || filesize($tmp_file) === 0) {
            @unlink($tmp_file);
            return new WP_Error('empty_image_body', 'Immagine scaricata vuota');
        }

        return self::optimize_and_attach_image($post_id, $tmp_file, $filename, $content_type);
    }


    private static function set_featured_image($post_id, $image_url)
    {
        $existing_attachment_id = self::maybe_use_existing_attachment($post_id, $image_url, true);
        if ($existing_attachment_id) {
            return $existing_attachment_id;
        }

        self::ensure_media_dependencies();

        $tmp_file = self::safe_download_url($image_url);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $path = wp_parse_url($image_url, PHP_URL_PATH);
        $suggested_name = $path ? basename($path) : '';

        return self::optimize_and_attach_image($post_id, $tmp_file, $suggested_name);
    }

    private static function maybe_use_existing_attachment($post_id, $image_url, $set_thumbnail = true)
    {
        $normalized_url = self::normalize_internal_image_url($image_url);
        if (!$normalized_url) {
            return 0;
        }

        $attachment_id = self::find_attachment_id_from_variants($normalized_url);
        if (!$attachment_id) {
            return 0;
        }

        if ($set_thumbnail) {
            set_post_thumbnail($post_id, $attachment_id);
        }

        $alt = sanitize_text_field(get_the_title($post_id));
        if ($alt) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }

        return (int) $attachment_id;
    }

    private static function normalize_internal_image_url($image_url)
    {
        if (empty($image_url)) {
            return '';
        }

        $clean_url = trim($image_url);
        if ($clean_url === '') {
            return '';
        }

        if (strpos($clean_url, '//') === 0) {
            $clean_url = (is_ssl() ? 'https:' : 'http:') . $clean_url;
        }

        $clean_url = str_replace(' ', '%20', $clean_url);
        $clean_url = preg_replace('/#.*$/', '', $clean_url);

        $parts = wp_parse_url($clean_url);
        if (empty($parts['host']) && !empty($parts['path'])) {
            $path = $parts['path'];
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
            $clean_url = home_url($path);
            $parts = wp_parse_url($clean_url);
        }

        $clean_url = explode('?', $clean_url, 2)[0];

        $url_host = isset($parts['host']) ? $parts['host'] : wp_parse_url($clean_url, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_host = wp_parse_url(site_url(), PHP_URL_HOST);

        if ($url_host && $home_host && $url_host !== $home_host && $site_host && $url_host !== $site_host) {
            return '';
        }

        return $clean_url;
    }

    private static function find_attachment_id_from_variants($url)
    {
        $candidates = [$url];

        $decoded = urldecode($url);
        if ($decoded !== $url) {
            $candidates[] = $decoded;
        }

        $base = $url;
        if (strpos($base, '?') !== false) {
            $base = explode('?', $base, 2)[0];
            $candidates[] = $base;
            $decoded_base = urldecode($base);
            if ($decoded_base !== $base) {
                $candidates[] = $decoded_base;
            }
        }

        $without_sizes = preg_replace('/-\d+x\d+(?=\.[^.]+$)/', '', $base);
        if ($without_sizes && $without_sizes !== $base) {
            $candidates[] = $without_sizes;
            $decoded_sizes = urldecode($without_sizes);
            if ($decoded_sizes !== $without_sizes) {
                $candidates[] = $decoded_sizes;
            }
        }

        $without_scaled = preg_replace('/-scaled(?=\.[^.]+$)/', '', $base);
        if ($without_scaled && $without_scaled !== $base) {
            $candidates[] = $without_scaled;
            $decoded_scaled = urldecode($without_scaled);
            if ($decoded_scaled !== $without_scaled) {
                $candidates[] = $decoded_scaled;
            }
        }

        $candidates = array_unique(array_filter($candidates));

        foreach ($candidates as $candidate) {
            $attachment_id = attachment_url_to_postid($candidate);
            if ($attachment_id) {
                return (int) $attachment_id;
            }
        }

        return 0;
    }

    /**
     * Fallback: estrae l'immagine da una pagina HTML condivisa e la imposta come featured image.
     */
    private static function fallback_set_image_from_page($post_id, $page_url)
    {
        // Wrapper per log condizionali
        $log = function ($msg) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log($msg);
            }
        };

        $log('🔍 [Fallback] Avvio per post_id=' . $post_id . ' | url=' . $page_url);

        $response = wp_remote_get($page_url, [
            'timeout' => 20,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => self::get_random_user_agent(),
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('html_download_failed', 'Errore nel recupero HTML: ' . $response->get_error_message());
        }

        $log('ℹ️ [Fallback] wp_remote_get completato');

        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return new WP_Error('html_download_failed', 'HTML vuoto ricevuto');
        }
        $log('ℹ️ [Fallback] Lunghezza HTML ricevuto: ' . strlen($html));

        // Pattern multipli
        $patterns = [
            '/\\\"url\\\",\\\"(https:\/\/chatgpt\.com\/backend-api\/public_content\/enc\/[^"]+=)\\\",\\\"width\\\"/',
            '/,"url","(https:\/\/chatgpt\.com\/backend-api\/public_content\/enc\/[^"]+=)","width"/',
            '/,"url","(https:\/\/chatgpt\.com\/backend-api\/public_content\/enc\/[^"]+)"/',
            '/<meta[^>]+property=["\']og:image:secure_url["\'][^>]+content=["\'](https:\/\/chatgpt\.com\/backend-api\/estuary\/public_content\/enc\/[^"\']+)["\'][^>]*>/',
            '/<img[^>]+src=["\']([^"\']+)["\']/',
        ];

        foreach ($patterns as $pattern) {
            $log('🔎 [Fallback] Test regex: ' . $pattern);

            if (preg_match_all($pattern, $html, $matches)) {
                $log('✅ [Fallback] Regex trovata: ' . count($matches[1]) . ' risultati');

                foreach ($matches[1] as $candidate) {
                    $candidate = html_entity_decode(stripslashes($candidate));
                    $log('🎯 [Fallback] Candidate trovato: ' . $candidate);

                    if (
                        strpos($candidate, 'chatgpt.com/backend-api/public_content/enc/') !== false ||
                        strpos($candidate, 'chatgpt.com/backend-api/estuary/public_content/enc/') !== false
                    ) {
                        // 📥 Scarica immagine originale
                        $plugin_dir = plugin_dir_path(__FILE__);
                        $original_path = $plugin_dir . 'immagine.png';
                        $optimized_path = $plugin_dir . 'immagine-optimized.jpg';

                        $log('📂 [Fallback] Path originale: ' . $original_path);
                        $log('📂 [Fallback] Path ottimizzato: ' . $optimized_path);

                        $image_data = wp_remote_get($candidate, ['timeout' => 30]);
                        if (is_wp_error($image_data)) {
                            $log('❌ [Fallback] Download fallito: ' . $image_data->get_error_message());
                            continue;
                        }

                        $body = wp_remote_retrieve_body($image_data);
                        if (!$body) {
                            $log('❌ [Fallback] Nessun contenuto scaricato da ' . $candidate);
                            continue;
                        }

                        file_put_contents($original_path, $body);
                        $log('✅ [Fallback] File scaricato: ' . $original_path . ' | size=' . filesize($original_path));

                        // Info immagine originale
                        $info = getimagesize($original_path);
                        if ($info) {
                            $log('ℹ️ [Fallback] Dimensioni originali: ' . $info[0] . 'x' . $info[1] . ' | MIME=' . $info['mime']);
                        }

                        // 🔧 Ottimizzazione: converte PNG → JPEG, ridimensiona e comprime
                        $editor = wp_get_image_editor($original_path);
                        if (!is_wp_error($editor)) {
                            $log('🔧 [Fallback] Ottimizzazione immagine avviata');
                            $editor->resize(1200, 1200, false); // ridimensionamento
                            $editor->set_quality(70);           // compressione
                            $saved = $editor->save($optimized_path, 'image/jpeg');

                            if (!is_wp_error($saved)) {
                                clearstatcache(true, $optimized_path);
                                $final_size = filesize($optimized_path);
                                $info_opt = getimagesize($optimized_path);

                                $log('✅ [Fallback] Immagine ottimizzata salvata: ' . $optimized_path . ' | size=' . $final_size);
                                if ($info_opt) {
                                    $log('ℹ️ [Fallback] Dimensioni ottimizzate: ' . $info_opt[0] . 'x' . $info_opt[1] . ' | MIME=' . $info_opt['mime']);
                                }
                            } else {
                                $log('❌ [Fallback] Errore salvataggio ottimizzato: ' . $saved->get_error_message());
                                continue;
                            }
                        } else {
                            $log('❌ [Fallback] wp_get_image_editor fallito: ' . $editor->get_error_message());
                            continue;
                        }

                        // 📤 Carica SOLO l'immagine ottimizzata
                        $upload = wp_upload_bits('immagine-optimized.jpg', null, file_get_contents($optimized_path));
                        if ($upload['error']) {
                            $log('[Fallback] wp_upload_bits fallito: ' . $upload['error']);
                            continue;
                        }
                        $log('✅ [Fallback] wp_upload_bits riuscito: ' . print_r($upload, true));

                        // 📥 Registra come media
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');
                        require_once(ABSPATH . 'wp-admin/includes/image.php');

                        $attachment = [
                            'post_mime_type' => 'image/jpeg',
                            'post_title' => sanitize_file_name('immagine-optimized.jpg'),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        ];

                        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $post_id);

                        if (!is_wp_error($attachment_id)) {
                            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                            wp_update_attachment_metadata($attachment_id, $attach_data);

                            set_post_thumbnail($post_id, $attachment_id);
                            $log('🏆 [Fallback] Immagine ottimizzata impostata come featured: ID=' . $attachment_id . ' | URL=' . $upload['url']);

                            // 🧹 Pulizia finale (solo se tutto OK)
                            if (file_exists($original_path)) {
                                unlink($original_path);
                                $log('🧹 [Fallback] File originale rimosso: ' . $original_path);
                            }
                            if (file_exists($optimized_path)) {
                                unlink($optimized_path);
                                $log('🧹 [Fallback] File ottimizzato rimosso: ' . $optimized_path);
                            }

                            return $attachment_id;
                        } else {
                            $log(' [Fallback] wp_insert_attachment fallito: ' . $attachment_id->get_error_message());
                        }
                    }
                }
            }
        }

        // Nessun match trovato
        $debug_file = dirname(__DIR__) . '/debug-image.log';
        file_put_contents($debug_file, $html);
        $log('⚠️ [Fallback] Nessun URL valido. HTML salvato in ' . $debug_file);

        return new WP_Error('image_not_found', 'Immagine non trovata nella pagina HTML');
    }


    public static function handle_dexscreener_action_bot(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        // Recupero dei parametri
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $token_address = isset($params['token_address']) ? sanitize_text_field($params['token_address']) : '';
        $chain_id = isset($params['chain_id']) ? sanitize_text_field($params['chain_id']) : '';
        $pair_id = isset($params['pair_id']) ? sanitize_text_field($params['pair_id']) : '';
        $query = isset($params['query']) ? sanitize_text_field($params['query']) : '';
        $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;

        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';
        if (!$user_code) {
            return new WP_REST_Response(['error' => 'User code non fornito'], 401);
        }

        $transient_key = "dexscreener_{$action}_{$token_address}_{$chain_id}_{$pair_id}_{$query}";
        $log = [];

        // Controllo cache
        $cached_response = get_transient($transient_key);
        if ($cached_response) {
            $log[] = "Dati recuperati dalla cache.";
            return self::prepare_response($cached_response, $chunk_index, $log);
        }

        // Definizione degli endpoint principali
        $base_url = "https://api.dexscreener.com/";
        $url = '';

        // Costruzione URL in base all'endpoint
        switch ($action) {
            case 'latest-token-profiles':
                $url = "{$base_url}token-profiles/latest/v1";
                break;
            case 'boosted-tokens':
                $url = "{$base_url}token-boosts/latest/v1";
                break;
            case 'top-boosts':
                $url = "{$base_url}token-boosts/top/v1";
                break;
            case 'token-orders':
                if (!$chain_id || !$token_address) {
                    $log[] = "Parametri mancanti: chain_id o token_address.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametri mancanti'], 400);
                }
                $url = "{$base_url}orders/v1/{$chain_id}/{$token_address}";
                break;
            case 'pairs-by-token':
                if (!$token_address) {
                    $log[] = "Parametro mancante: token_address.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametro mancante'], 400);
                }
                $url = "{$base_url}latest/dex/tokens/{$token_address}";
                break;
            case 'pairs-by-chain':
                if (!$chain_id || !$pair_id) {
                    $log[] = "Parametri mancanti: chain_id o pair_id.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametri mancanti'], 400);
                }
                $url = "{$base_url}latest/dex/pairs/{$chain_id}/{$pair_id}";
                break;
            case 'search-pairs':
                if (!$query) {
                    $log[] = "Parametro mancante: query.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametro mancante'], 400);
                }
                $url = "{$base_url}latest/dex/search?q={$query}";
                break;
            default:
                $log[] = "Endpoint non valido.";
                return new WP_REST_Response(['log' => $log, 'error' => 'Endpoint non valido'], 400);
        }

        $log[] = "Recupero dati dall'URL: $url";

        // Chiamata API
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            $log[] = "Errore durante la chiamata API: " . $response->get_error_message();
            return new WP_REST_Response(['log' => $log, 'error' => $response->get_error_message()], 400);
        }

        $data = wp_remote_retrieve_body($response);
        $decoded_data = json_decode($data, true);
        if (!$decoded_data) {
            $log[] = "Errore nella decodifica della risposta.";
            return new WP_REST_Response(['log' => $log, 'error' => 'Errore nella decodifica della risposta'], 400);
        }

        // Memorizzazione nella cache
        set_transient($transient_key, $decoded_data, 3 * HOUR_IN_SECONDS);
        $log[] = "Dati salvati nella cache con chiave: $transient_key";

        return self::prepare_response($decoded_data, $chunk_index, $log);
    }


    public static function handle_dexscreener_action(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        // Recupero dei parametri
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $token_address = isset($params['token_address']) ? sanitize_text_field($params['token_address']) : '';
        $chain_id = isset($params['chain_id']) ? sanitize_text_field($params['chain_id']) : '';
        $pair_id = isset($params['pair_id']) ? sanitize_text_field($params['pair_id']) : '';
        $query = isset($params['query']) ? sanitize_text_field($params['query']) : '';
        $chunk_index = isset($params['chunk_index']) ? $params['chunk_index'] : 0;

        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';
        if (!$user_code) {
            return new WP_REST_Response(['error' => 'User code non fornito'], 401);
        }

        $transient_key = "dexscreener_{$endpoint}_{$token_address}_{$chain_id}_{$pair_id}_{$query}";
        $log = [];

        // Controllo cache
        /* Controllo cache
        $cached_response = get_transient($transient_key);
        if ($cached_response) {
            $log[] = "Dati recuperati dalla cache.";
            return self::prepare_response($cached_response, $chunk_index, $log);
        }*/

        // Definizione degli endpoint principali
        $base_url = "https://api.dexscreener.com/";
        $url = '';

        // Costruzione URL in base all'endpoint
        switch ($action) {
            case 'latest-token-profiles':
                $url = "{$base_url}token-profiles/latest/v1";
                break;
            case 'boosted-tokens':
                $url = "{$base_url}token-boosts/latest/v1";
                break;
            case 'top-boosts':
                $url = "{$base_url}token-boosts/top/v1";
                break;
            case 'token-orders':
                if (!$chain_id || !$token_address) {
                    $log[] = "Parametri mancanti: chain_id o token_address.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametri mancanti'], 400);
                }
                $url = "{$base_url}orders/v1/{$chain_id}/{$token_address}";
                break;
            case 'pairs-by-token':
                if (!$token_address) {
                    $log[] = "Parametro mancante: token_address.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametro mancante'], 400);
                }
                $url = "{$base_url}latest/dex/tokens/{$token_address}";
                break;
            case 'pairs-by-chain':
                if (!$chain_id || !$pair_id) {
                    $log[] = "Parametri mancanti: chain_id o pair_id.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametri mancanti'], 400);
                }
                $url = "{$base_url}latest/dex/pairs/{$chain_id}/{$pair_id}";
                break;
            case 'search-pairs':
                if (!$query) {
                    $log[] = "Parametro mancante: query.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Parametro mancante'], 400);
                }
                $url = "{$base_url}latest/dex/search?q={$query}";
                break;
            case 'secure_analytics_on_crypto_token':
                if ($token_address || $query) {
                    $results = [];
                    $token_symbol = null;

                    // 1. Se viene fornito un simbolo, ricava il token address più rilevante
                    if (!$token_address && $query) {
                        $url_search_pairs = "{$base_url}latest/dex/search?q={$query}";
                        $log[] = "Chiamata API per search_pairs: {$url_search_pairs}";
                        $response_search = wp_remote_get($url_search_pairs);

                        if (is_wp_error($response_search)) {
                            $log[] = "Errore durante la chiamata API search_pairs: " . $response_search->get_error_message();
                        } else {
                            $data_search = wp_remote_retrieve_body($response_search);
                            $decoded_search = json_decode($data_search, true);

                            if ($decoded_search) {
                                $sorted_pairs = array_filter($decoded_search['pairs'], function ($pair) {
                                    return isset($pair['liquidity']['usd']) && isset($pair['volume']['h24']);
                                });
                                usort($sorted_pairs, function ($a, $b) {
                                    return $b['liquidity']['usd'] <=> $a['liquidity']['usd'];
                                });

                                $best_pair = $sorted_pairs[0] ?? null;
                                if ($best_pair) {
                                    $token_address = $best_pair['baseToken']['address'] ?? null;
                                    $token_symbol = $best_pair['baseToken']['symbol'] ?? null;
                                }
                            } else {
                                $log[] = "Errore nella decodifica della risposta per search_pairs.";
                            }
                        }
                    }

                    // 2. Recupero dati specifici del token usando il token address
                    if ($token_address) {
                        $url_pairs_by_token = "{$base_url}latest/dex/tokens/{$token_address}";
                        $log[] = "Chiamata API per pairs-by-token: {$url_pairs_by_token}";
                        $response_pairs = wp_remote_get($url_pairs_by_token);

                        if (is_wp_error($response_pairs)) {
                            $log[] = "Errore durante la chiamata API pairs-by-token: " . $response_pairs->get_error_message();
                        } else {
                            $data_pairs = wp_remote_retrieve_body($response_pairs);
                            $decoded_pairs = json_decode($data_pairs, true);

                            if ($decoded_pairs) {
                                $sorted_pairs = array_filter($decoded_pairs['pairs'], function ($pair) {
                                    return isset($pair['liquidity']['usd']) && isset($pair['volume']['h24']);
                                });
                                usort($sorted_pairs, function ($a, $b) {
                                    return $b['liquidity']['usd'] <=> $a['liquidity']['usd'];
                                });

                                $best_pair = $sorted_pairs[0] ?? null;
                                if ($best_pair) {
                                    $token_symbol = $best_pair['baseToken']['symbol'] ?? $token_symbol;

                                    // Calcola l'età del token
                                    $pair_created_at = $best_pair['pairCreatedAt'] ?? null;
                                    $age_years = $pair_created_at ? round((time() - $pair_created_at) / (365 * 24 * 60 * 60), 2) : 'N/A';

                                    // 3. Recupero dati sulle transazioni
                                    $url_token_transactions = "{$base_url}token-pairs/v1/{$best_pair['chainId']}/{$token_address}";
                                    $log[] = "Chiamata API per token-pairs (transazioni): {$url_token_transactions}";
                                    $response_transactions = wp_remote_get($url_token_transactions);

                                    $txns = null;
                                    if (!is_wp_error($response_transactions)) {
                                        $data_transactions = wp_remote_retrieve_body($response_transactions);
                                        $decoded_transactions = json_decode($data_transactions, true);
                                        $txns = $decoded_transactions['txns'] ?? null;
                                    } else {
                                        $log[] = "Errore durante la chiamata API token-pairs: " . $response_transactions->get_error_message();
                                    }

                                    // Schema iniziale
                                    $schema = [
                                        'Nome Token' => $token_symbol,
                                        '🌐' => "{$best_pair['chainId']} @ {$best_pair['dexId']}",
                                        '💰 Price [USD]' => $best_pair['priceUsd'] ?? 'N/A',
                                        '📉 1h' => $best_pair['priceChange']['h1'] ?? 'N/A',
                                        '📈 24h' => $best_pair['priceChange']['h24'] ?? 'N/A',
                                        '🏆 ATH' => $best_pair['ath'] ?? 'N/A',
                                        '🔥 24h Buys' => isset($decoded_pairs['txns']['h24']['buys']) ? array_sum(array_column($decoded_pairs['txns']['h24'], 'buys')) : 'N/A',
                                        '🔥 24h Sells' => isset($decoded_pairs['txns']['h24']['sells']) ? array_sum(array_column($decoded_pairs['txns']['h24'], 'sells')) : 'N/A',
                                        '💦 Liq [USD]' => $best_pair['liquidity']['usd'] ?? 'N/A',
                                        '🕰 Age [y]' => $age_years,
                                        '📊 Volume [USD]' => $best_pair['volume']['h24'] ?? 'N/A',
                                        '💎 FDV Mcap [USD]' => $best_pair['fdv'] ?? 'N/A',
                                        '📝 CA' => $token_address,
                                        '🔗 Social Links' => !empty($results['social_links']) ? implode(', ', array_column($results['social_links'], 'url')) : 'N/A',
                                        '🔄 Transactions' => [
                                            '5m' => "Buys: {$transactions['5m']['buys']}, Sells: {$transactions['5m']['sells']}",
                                            '1h' => "Buys: {$transactions['1h']['buys']}, Sells: {$transactions['1h']['sells']}",
                                            '6h' => "Buys: {$transactions['6h']['buys']}, Sells: {$transactions['6h']['sells']}",
                                            '24h' => "Buys: {$transactions['24h']['buys']}, Sells: {$transactions['24h']['sells']}"
                                        ],
                                    ];

                                    $results['schema'] = $schema;
                                    $results['pairs_data'] = $best_pair;
                                    $results['transactions'] = $txns;
                                }
                            } else {
                                $log[] = "Errore nella decodifica della risposta per pairs-by-token.";
                            }
                        }
                    }

                    // 4. Recupero dati di marketing e social (boosted_tokens, top_boosts, latest_token_profiles)
                    $endpoints = [
                        'boosted_tokens' => "{$base_url}token-boosts/latest/v1",
                        'top_boosts' => "{$base_url}token-boosts/top/v1",
                        'latest_token_profiles' => "{$base_url}token-profiles/latest/v1",
                    ];

                    foreach ($endpoints as $key => $url) {
                        $log[] = "Chiamata API per {$key}: {$url}";
                        $response = wp_remote_get($url);

                        if (is_wp_error($response)) {
                            $log[] = "Errore durante la chiamata API {$key}: " . $response->get_error_message();
                            continue;
                        }

                        $data = wp_remote_retrieve_body($response);
                        $decoded_data = json_decode($data, true);

                        if (!$decoded_data) {
                            $log[] = "Errore nella decodifica della risposta per {$key}.";
                            continue;
                        }

                        if ($key === 'boosted_tokens' || $key === 'top_boosts') {
                            $filtered_data = array_filter($decoded_data, function ($item) use ($token_address) {
                                return isset($item['tokenAddress']) && $item['tokenAddress'] === $token_address;
                            });
                            $results[$key] = $filtered_data;
                        } elseif ($key === 'latest_token_profiles') {
                            $filtered_data = array_filter($decoded_data, function ($item) use ($token_symbol) {
                                return isset($item['symbol']) && stripos($item['symbol'], strtoupper($token_symbol)) !== false;
                            });

                            if (!empty($filtered_data)) {
                                $results['social_links'] = array_map(function ($item) {
                                    return [
                                        'type' => $item['type'],
                                        'label' => $item['label'],
                                        'url' => $item['url'],
                                    ];
                                }, reset($filtered_data)['links'] ?? []);
                            }
                        }
                    }

                    return self::prepare_response($results, $chunk_index, $log);
                }

                // Caso di errore: né token_address né query forniti
                $log[] = "Parametro mancante: né token_address né query forniti.";
                return new WP_REST_Response(['log' => $log, 'error' => 'Parametro mancante'], 400);

            default:
                $log[] = "Endpoint non valido.";
                return new WP_REST_Response(['log' => $log, 'error' => 'Endpoint non valido'], 400);
        }

        $log[] = "Recupero dati dall'URL: $url";

        // Chiamata API
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            $log[] = "Errore durante la chiamata API: " . $response->get_error_message();
            return new WP_REST_Response(['log' => $log, 'error' => $response->get_error_message()], 400);
        }

        $data = wp_remote_retrieve_body($response);
        $decoded_data = json_decode($data, true);
        if (!$decoded_data) {
            $log[] = "Errore nella decodifica della risposta.";
            return new WP_REST_Response(['log' => $log, 'error' => 'Errore nella decodifica della risposta'], 400);
        }

        // Memorizzazione nella cache
        set_transient($transient_key, $decoded_data, 3 * HOUR_IN_SECONDS);
        $log[] = "Dati salvati nella cache con chiave: $transient_key";

        return self::prepare_response($decoded_data, $chunk_index, $log);
    }

    private static function filter_relevant_data($key, $data, $query)
    {
        switch ($key) {
            case 'latest_token_profiles':
                // Filtra i profili dei token mantenendo solo i dati rilevanti.
                return array_map(function ($item) {
                    return [
                        'symbol' => $item['symbol'],
                        'description' => $item['description'],
                        'icon' => $item['icon'],
                        'links' => array_filter($item['links'] ?? [], function ($link) {
                            return in_array($link['type'], ['twitter', 'telegram', 'discord', 'website']);
                        }),
                        'url' => $item['url']
                    ];
                }, array_filter($data, function ($item) use ($query) {
                    return stripos($item['symbol'], strtoupper($query)) !== false;
                }));

            case 'boosted_tokens':
                // Filtra e sintetizza i dati delle promozioni attive.
                $filtered = array_filter($data, function ($item) {
                    return isset($item['amount']) && $item['amount'] > 500; // Soglia minima
                });

                $aggregated = [
                    'totalBoostedTokens' => count($filtered),
                    'averageAmount' => array_sum(array_column($filtered, 'amount')) / max(1, count($filtered)),
                    'totalAmount' => array_sum(array_column($filtered, 'amount')),
                ];

                $boostedTokens = array_map(function ($item) {
                    return [
                        'tokenAddress' => $item['tokenAddress'] ?? null,
                        'amount' => $item['amount'] ?? null,
                        'totalAmount' => $item['totalAmount'] ?? null,
                        'description' => $item['description'] ?? null,
                        'links' => array_filter($item['links'] ?? [], function ($link) {
                            return in_array($link['type'], ['twitter', 'telegram', 'discord', 'website']);
                        })
                    ];
                }, $filtered);

                return [
                    'aggregated' => $aggregated,
                    'boostedTokens' => $boostedTokens
                ];

            case 'top_boosts':
                // Filtra e sintetizza i dati delle campagne di promozione.
                $filtered = array_filter($data, function ($item) {
                    return isset($item['totalAmount']) && $item['totalAmount'] > 500;
                });

                $aggregated = [
                    'totalBoosts' => count($filtered),
                    'averageSpend' => array_sum(array_column($filtered, 'totalAmount')) / max(1, count($filtered)),
                    'totalSpend' => array_sum(array_column($filtered, 'totalAmount'))
                ];

                $campaigns = array_map(function ($item) {
                    return [
                        'tokenAddress' => $item['tokenAddress'],
                        'amount' => $item['amount'] ?? null,
                        'totalAmount' => $item['totalAmount'] ?? null,
                        'description' => $item['description'] ?? null,
                        'links' => array_filter($item['links'] ?? [], function ($link) {
                            return in_array($link['type'], ['twitter', 'telegram', 'discord', 'website']);
                        })
                    ];
                }, $filtered);

                return [
                    'aggregated' => $aggregated,
                    'campaigns' => $campaigns
                ];

            case 'search_pairs':
                // Filtra coppie di mercato mantenendo solo i dati critici e sostituendo valori specifici.
                return array_map(function ($pair) {
                    $filteredInfo = isset($pair['info']) ? array_map(function ($value) {
                        // Rimuove URL irrilevanti.
                        return (is_string($value) && strpos($value, 'https://dd.dexscreener') === 0) ? null : $value;
                    }, $pair['info']) : null;

                    $priceUsd = isset($pair['priceUsd']) ? $pair['priceUsd'] : 0;

                    return [
                        'pairAddress' => $pair['pairAddress'],
                        'chainId' => $pair['chainId'],
                        'priceUsd' => $priceUsd,
                        'volume24h' => $pair['volume']['h24'],
                        'liquidityUsd' => $pair['liquidity']['usd'] ?? 0,
                        'priceChange' => $pair['priceChange']['h24'] ?? 0,
                        'fdv' => $pair['fdv'] ?? null,
                        'marketCap' => $pair['marketCap'] ?? null,
                        'labels' => $pair['labels'] ?? [],
                        'info' => $filteredInfo,
                        'priceForTenThousandTokens' => $priceUsd * 10000, // Calcola il prezzo di 10.000 token
                    ];
                }, array_filter($data['pairs'], function ($pair) {
                    return $pair['volume']['h24'] > 1000 && $pair['liquidity']['usd'] > 20000;
                }));


            case 'pairs-by-token':
                $filtered_pairs = array_filter($data['pairs'], function ($pair) {
                    return $pair['volume']['h24'] > 5000 && $pair['liquidity']['usd'] > 20000;
                });
                return [
                    'averageVolume24h' => array_sum(array_column($filtered_pairs, 'volume.h24')) / max(1, count($filtered_pairs)),
                    'averageLiquidity' => array_sum(array_column($filtered_pairs, 'liquidity.usd')) / max(1, count($filtered_pairs)),
                    'highRiskPairs' => array_filter($filtered_pairs, function ($pair) {
                        return abs($pair['priceChange']['h24'] ?? 0) > 5;
                    }),
                ];

            case 'token-orders':
                // Filtra e sintetizza gli ordini promozionali.
                $filtered_orders = array_filter($data, function ($order) {
                    return in_array($order['type'], ['tokenAd', 'trendingBarAd', 'communityTakeover']) &&
                        in_array($order['status'], ['approved', 'processing']);
                });
                return [
                    'totalOrders' => count($filtered_orders),
                    'approvedOrders' => count(array_filter($filtered_orders, function ($order) {
                        return $order['status'] === 'approved';
                    })),
                    'details' => array_values($filtered_orders)
                ];

            default:
                // Restituisce i dati originali per endpoint non definiti.
                return $data;
        }
    }


    private static function prepare_response($data, $chunk_index, $log)
    {
        $chunks = str_split(json_encode($data), 12000);
        $total_chunks = count($chunks);

        // Controllo se `chunk_index` è uguale a 'ALL'
        if ($chunk_index === 'ALL') {
            $log[] = "Tutti i chunk sono stati richiesti.";
            return new WP_REST_Response([
                'all_chunks' => $chunks,
                'total_chunks' => $total_chunks,
            ], 200);
        }

        // Controllo di validità per un chunk specifico
        if (!is_numeric($chunk_index) || $chunk_index < 0 || $chunk_index >= $total_chunks) {
            $log[] = "Indice chunk non valido.";
            return new WP_REST_Response(['log' => $log, 'error' => 'Indice chunk non valido'], 400);
        }

        // Restituisce un chunk specifico
        $log[] = "Recupero chunk completato. Indice chunk: $chunk_index di $total_chunks.";
        return new WP_REST_Response([
            'chunk_content' => $chunks[$chunk_index],
            'chunk_index' => $chunk_index,
            'total_chunks' => $total_chunks,
            'log' => $log,
        ], 200);
    }

    /**
     * Split data into chunks and store them in a transient.
     * Returns the first chunk with metadata.
     *
     * @param mixed  $data   Data to encode and split.
     * @param string $prefix Prefix used to generate the transient key.
     *
     * @return WP_REST_Response
     */
    private static function send_chunked_response($data, $prefix)
    {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $size = defined('WP_GPT_CHUNK_SIZE') ? WP_GPT_CHUNK_SIZE : 2000;
        $chunks = str_split($json, $size);
        $chunk_key = 'wp_gpt_api_' . md5($prefix . time());
        set_transient($chunk_key, $chunks, self::$transient_expiration);

        return new WP_REST_Response([
            'chunk_key' => $chunk_key,
            'chunk_index' => 0,
            'chunk' => $chunks[0],
            'total_chunks' => count($chunks),
        ], 200);
    }




    /*public static function manage_page_with_user_code(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $log = []; // Log delle operazioni
        $log[] = "Azione richiesta: $action";

        switch ($action) {
            case 'create-page':
                $log[] = "Inizio creazione nuova pagina.";
                $page_args = [
                    'post_type'    => 'page',
                    'post_title'   => sanitize_text_field($params['title']),
                    'post_content' => wp_kses_post($params['content']),
                    'post_status'  => isset($params['status']) && in_array($params['status'], ['publish', 'draft']) ? $params['status'] : 'draft',
                    'post_author'  => get_current_user_id(),
                    'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
                    'meta_input'   => [
                        'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : '',
                        'rank_math_description'   => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : '',
                        'rank_math_title'         => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '',
                    ],
                ];
                $page_id = wp_insert_post($page_args);

                if (is_wp_error($page_id)) {
                    $log[] = "Errore nella creazione della pagina: " . $page_id->get_error_message();
                    return new WP_REST_Response(['log' => $log, 'error' => $page_id->get_error_message()], 400);
                }

                // Gestione immagine in evidenza, se fornita
                if (isset($params['featured_image'])) {
                    self::set_featured_image($page_id, esc_url_raw($params['featured_image']));
                }

                $log[] = "Pagina creata con successo, ID: $page_id";
                return new WP_REST_Response(['page_id' => $page_id, 'log' => $log], 200);

            case 'update-page':
                $log[] = "Inizio aggiornamento della pagina.";
                $page_id = intval($params['id']);

                if (!get_post($page_id) || get_post_type($page_id) !== 'page') {
                    $log[] = "Errore: Pagina non trovata o tipo non valido.";
                    return new WP_REST_Response(['log' => $log, 'error' => 'Pagina non trovata'], 404);
                }

                $page_args = [
                    'ID'           => $page_id,
                    'post_title'   => isset($params['title']) ? sanitize_text_field($params['title']) : get_the_title($page_id),
                    'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : get_post_field('post_content', $page_id),
                    'post_status'  => isset($params['status']) && in_array($params['status'], ['publish', 'draft']) ? $params['status'] : get_post_status($page_id),
                    'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : get_the_excerpt($page_id),
                    'meta_input'   => [
                        'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : get_post_meta($page_id, 'rank_math_focus_keyword', true),
                        'rank_math_description'   => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : get_post_meta($page_id, 'rank_math_description', true),
                        'rank_math_title'         => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : get_post_meta($page_id, 'rank_math_title', true),
                    ],
                ];

                $updated_page_id = wp_update_post($page_args);

                if (is_wp_error($updated_page_id)) {
                    $log[] = "Errore nell'aggiornamento della pagina: " . $updated_page_id->get_error_message();
                    return new WP_REST_Response(['log' => $log, 'error' => $updated_page_id->get_error_message()], 400);
                }

                // Gestione immagine in evidenza, se fornita
                if (isset($params['featured_image'])) {
                    self::set_featured_image($page_id, esc_url_raw($params['featured_image']));
                }

                $log[] = "Pagina aggiornata con successo, ID: $page_id";
                return new WP_REST_Response(['page_id' => $updated_page_id, 'log' => $log], 200);

            default:
                $log[] = "Errore: Azione non supportata.";
                return new WP_REST_Response(['log' => $log, 'error' => 'Azione non supportata'], 400);
        }
    }*/


    public static function manage_page_with_user_code(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }
        if (!is_array($params)) {
            $params = [];
        }

        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $log = [];
        $log[] = sprintf('Azione richiesta: %s', $action ?: 'non specificata');

        $lowest_admin_id = WP_GPT_User_Helper::get_lowest_admin_id();
        if (is_wp_error($lowest_admin_id)) {
            $error_code = $lowest_admin_id->get_error_code() ?: 'wp_gpt_missing_admin_user';
            $error_message = $lowest_admin_id->get_error_message() ?: __('Impossibile determinare un autore amministratore.', 'wp-gpt-automation-pro');
            $error_data = $lowest_admin_id->get_error_data();

            if (!is_array($error_data)) {
                $error_data = [];
            }

            if (!isset($error_data['status']) || !is_int($error_data['status'])) {
                $error_data['status'] = 500;
            }

            $error_data['log'] = $log;

            return new WP_Error($error_code, $error_message, $error_data);
        }

        switch ($action) {
            case 'create-page':
                $log[] = 'Inizio creazione nuova pagina.';
                $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
                if ($title === '') {
                    $log[] = 'Titolo mancante per la creazione della pagina.';
                    return new WP_REST_Response(['error' => __('Titolo obbligatorio.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                }

                $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
                $status = isset($params['status']) && in_array($params['status'], ['publish', 'draft'], true) ? $params['status'] : 'draft';

                $meta_input = [
                    'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : '',
                    'rank_math_description' => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : '',
                    'rank_math_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '',
                ];

                $meta_input['wp_gpt_skipper_id'] = $lowest_admin_id;

                $page_args = [
                    'post_type' => 'page',
                    'post_title' => $title,
                    'post_content' => $content,
                    'post_status' => $status,
                    'post_author' => $lowest_admin_id,
                    'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
                    'meta_input' => $meta_input,
                ];

                $slug_value = '';
                if (array_key_exists('slug', $params) && !is_array($params['slug'])) {
                    $slug_value = sanitize_title((string) $params['slug']);
                }

                if ($slug_value === '') {
                    $slug_value = sanitize_title($title);
                }

                if ($slug_value !== '') {
                    $unique_slug = wp_unique_post_slug($slug_value, 0, $status, 'page', 0);
                    if ($unique_slug !== '') {
                        $page_args['post_name'] = $unique_slug;
                        $log[] = sprintf('Slug applicato alla pagina: %s.', $unique_slug);
                    }
                }

                $page_id = wp_insert_post($page_args);
                if (is_wp_error($page_id)) {
                    $log[] = 'Errore nella creazione della pagina: ' . $page_id->get_error_message();
                    return new WP_REST_Response(['error' => $page_id->get_error_message(), 'log' => $log], 400);
                }

                if (isset($params['featured_image'])) {
                    self::set_featured_image($page_id, esc_url_raw($params['featured_image']));
                }

                $log[] = sprintf('Pagina creata con successo, ID: %d.', $page_id);
                return new WP_REST_Response(['page_id' => $page_id, 'log' => $log], 200);

            case 'update-page':
                $page_id = isset($params['id']) ? intval($params['id']) : 0;
                $log[] = sprintf('Inizio aggiornamento della pagina %d.', $page_id);

                $page = $page_id ? get_post($page_id) : null;
                if (!$page || get_post_type($page) !== 'page') {
                    $log[] = 'Pagina non trovata o tipo non valido.';
                    return new WP_REST_Response(['error' => __('Pagina non trovata.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                }

                $meta_input = [
                    'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : get_post_meta($page_id, 'rank_math_focus_keyword', true),
                    'rank_math_description' => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : get_post_meta($page_id, 'rank_math_description', true),
                    'rank_math_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : get_post_meta($page_id, 'rank_math_title', true),
                ];

                $meta_input['wp_gpt_skipper_id'] = $lowest_admin_id;

                $page_args = [
                    'ID' => $page_id,
                    'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : $page->post_title,
                    'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : $page->post_content,
                    'post_status' => isset($params['status']) && in_array($params['status'], ['publish', 'draft'], true) ? $params['status'] : $page->post_status,
                    'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : $page->post_excerpt,
                    'meta_input' => $meta_input,
                ];

                $slug_value = '';
                if (array_key_exists('slug', $params) && !is_array($params['slug'])) {
                    $slug_value = sanitize_title((string) $params['slug']);
                }

                if ($slug_value !== '') {
                    $unique_slug = wp_unique_post_slug(
                        $slug_value,
                        $page_id,
                        $page_args['post_status'],
                        'page',
                        isset($page->post_parent) ? (int) $page->post_parent : 0
                    );

                    if ($unique_slug !== '') {
                        $page_args['post_name'] = $unique_slug;
                        $log[] = sprintf('Slug pagina aggiornato a: %s.', $unique_slug);
                    }
                }

                $updated_page_id = wp_update_post($page_args, true);
                if (is_wp_error($updated_page_id)) {
                    $log[] = 'Errore nell\'aggiornamento della pagina: ' . $updated_page_id->get_error_message();
                    return new WP_REST_Response(['error' => $updated_page_id->get_error_message(), 'log' => $log], 400);
                }

                if (isset($params['featured_image'])) {
                    self::set_featured_image($page_id, esc_url_raw($params['featured_image']));
                }

                $log[] = sprintf('Pagina aggiornata con successo, ID: %d.', $page_id);
                return new WP_REST_Response(['page_id' => $updated_page_id, 'log' => $log], 200);

            case 'retrieve':
                $page_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;
                $log[] = sprintf('Inizio recupero contenuto pagina %d (chunk %d).', $page_id, $chunk_index);

                $page = $page_id ? get_post($page_id) : null;
                if (!$page || get_post_type($page) !== 'page') {
                    $log[] = 'Pagina non trovata o tipo non valido.';
                    return new WP_REST_Response(['error' => __('Pagina non trovata.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                }

                $content = (string) $page->post_content;
                $chunks = str_split($content, 6000);
                $total_chunks = max(count($chunks), 1);

                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                    $log[] = 'Indice chunk non valido.';
                    return new WP_REST_Response(['error' => __('Indice chunk non valido.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                }

                $chunk = $total_chunks > 0 ? $chunks[$chunk_index] : '';
                $log[] = sprintf('Recupero chunk completato. Indice %d di %d.', $chunk_index, $total_chunks);

                return new WP_REST_Response([
                    'chunk_content' => $chunk,
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'log' => $log,
                ], 200);

            case 'view-menu':
                $menu_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                if ($menu_id) {
                    if (!is_nav_menu($menu_id)) {
                        $log[] = 'Menu non trovato.';
                        return new WP_REST_Response(['error' => __('Menu non trovato.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                    }

                    $menu_items = wp_get_nav_menu_items($menu_id);
                    $menu_data = array_map(function ($item) {
                        return [
                            'ID' => $item->ID,
                            'title' => $item->title,
                            'url' => $item->url,
                            'parent' => $item->menu_item_parent,
                        ];
                    }, $menu_items ?: []);

                    $log[] = 'Menu visualizzato con successo.';
                    return new WP_REST_Response(['menu_items' => $menu_data, 'log' => $log], 200);
                }

                $menus = wp_get_nav_menus();
                $list = array_map(function ($menu) {
                    return [
                        'id' => $menu->term_id,
                        'name' => $menu->name,
                        'slug' => $menu->slug,
                    ];
                }, $menus ?: []);

                $log[] = 'Lista dei menu recuperata con successo.';
                return new WP_REST_Response(['menus' => $list, 'log' => $log], 200);

            case 'update-menu':
                $menu_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                $menu_data = isset($params['menu_data']) && is_array($params['menu_data']) ? $params['menu_data'] : null;
                $log[] = sprintf('Inizio aggiornamento del menu %d.', $menu_id);

                if (!$menu_id || !is_nav_menu($menu_id)) {
                    $log[] = 'Menu non trovato.';
                    return new WP_REST_Response(['error' => __('Menu non trovato.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                }

                if (!$menu_data || !isset($menu_data['items']) || !is_array($menu_data['items'])) {
                    $log[] = 'Dati del menu non validi.';
                    return new WP_REST_Response(['error' => __('Dati del menu non validi.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                }

                foreach ($menu_data['items'] as $item) {
                    $menu_item_id = isset($item['ID']) ? intval($item['ID']) : 0;
                    $menu_item_args = [
                        'menu-item-title' => isset($item['title']) ? sanitize_text_field($item['title']) : '',
                        'menu-item-url' => isset($item['url']) ? esc_url_raw($item['url']) : '',
                        'menu-item-parent-id' => isset($item['parent']) ? intval($item['parent']) : 0,
                        'menu-item-status' => 'publish',
                    ];

                    $result = wp_update_nav_menu_item($menu_id, $menu_item_id, $menu_item_args);
                    if (is_wp_error($result)) {
                        $log[] = sprintf("Errore nell'aggiornamento della voce %d: %s", $menu_item_id, $result->get_error_message());
                        continue;
                    }

                    $log[] = sprintf('Voce del menu aggiornata con successo (ID: %d).', $result);
                }

                $log[] = 'Menu aggiornato con successo.';
                return new WP_REST_Response(['menu_id' => $menu_id, 'log' => $log], 200);

            case 'manage-template':
                $page_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                $template_name = isset($params['template_name']) ? sanitize_text_field($params['template_name']) : '';

                if ($page_id > 0) {
                    $log[] = sprintf('Impostazione template %s per la pagina %d.', $template_name ?: '-', $page_id);
                    $page = get_post($page_id);
                    if (!$page || get_post_type($page) !== 'page') {
                        $log[] = 'Pagina non trovata o tipo non valido.';
                        return new WP_REST_Response(['error' => __('Pagina non trovata.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                    }

                    if ($template_name === '') {
                        $log[] = 'Template non specificato.';
                        return new WP_REST_Response(['error' => __('Template non specificato.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                    }

                    $available_templates = self::get_available_page_templates_map();
                    if (!self::is_valid_page_template_slug($template_name, $available_templates)) {
                        $log[] = 'Template non valido.';
                        return new WP_REST_Response(['error' => __('Template non valido.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                    }

                    update_post_meta($page_id, '_wp_page_template', $template_name);
                    $log[] = sprintf("Template '%s' impostato sulla pagina %d.", $template_name, $page_id);

                    $response = [
                        'page_id' => $page_id,
                        'template' => $template_name,
                        'log' => $log,
                    ];

                    if (isset($available_templates[$template_name])) {
                        $response['template_label'] = $available_templates[$template_name];
                    }

                    return new WP_REST_Response($response, 200);
                }

                $log[] = 'Recupero della lista dei template disponibili.';
                $available_templates = self::get_available_page_templates_map();

                return new WP_REST_Response([
                    'templates' => self::format_page_templates_for_response($available_templates),
                    'templates_by_slug' => $available_templates,
                    'log' => $log,
                ], 200);

            case 'get-template':
                $page_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                if (!$page_id) {
                    $log[] = 'page_id mancante per get-template.';
                    return new WP_REST_Response(['error' => __('page_id è obbligatorio.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                }

                $page = get_post($page_id);
                if (!$page || get_post_type($page) !== 'page') {
                    $log[] = 'Pagina non trovata o tipo non valido.';
                    return new WP_REST_Response(['error' => __('Pagina non trovata.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                }

                $template_slug = get_page_template_slug($page_id);
                if ($template_slug === false || $template_slug === '') {
                    $template_slug = 'default';
                }

                $details = self::resolve_page_template_details($template_slug);
                $log[] = sprintf('Template corrente per la pagina %d: %s.', $page_id, $template_slug);

                return new WP_REST_Response([
                    'page_id' => $page_id,
                    'template_name' => $template_slug,
                    'template' => $details,
                    'log' => $log,
                ], 200);

            case 'set-homepage':
                $page_id = isset($params['page_id']) ? intval($params['page_id']) : 0;
                if (!$page_id) {
                    $log[] = 'page_id mancante per set-homepage.';
                    return new WP_REST_Response(['error' => __('page_id è obbligatorio.', 'wp-gpt-automation-pro'), 'log' => $log], 400);
                }

                $page = get_post($page_id);
                if (!$page || get_post_type($page) !== 'page') {
                    $log[] = 'Pagina non trovata o tipo non valido.';
                    return new WP_REST_Response(['error' => __('Pagina non trovata.', 'wp-gpt-automation-pro'), 'log' => $log], 404);
                }

                update_option('show_on_front', 'page');
                update_option('page_on_front', $page_id);

                $log[] = sprintf('Homepage aggiornata con la pagina %d.', $page_id);

                return new WP_REST_Response([
                    'page_id' => $page_id,
                    'title' => get_the_title($page_id),
                    'show_on_front' => 'page',
                    'message' => __('Homepage aggiornata con successo.', 'wp-gpt-automation-pro'),
                    'log' => $log,
                ], 200);

            case 'get-homepage':
                $show_on_front = get_option('show_on_front', 'posts');
                $page_id = (int) get_option('page_on_front');

                $log[] = 'Recupero impostazioni homepage.';

                $homepage = null;
                if ($show_on_front === 'page' && $page_id) {
                    $page = get_post($page_id);
                    if ($page && get_post_type($page) === 'page') {
                        $homepage = [
                            'page_id' => $page_id,
                            'title' => get_the_title($page_id),
                            'status' => get_post_status($page_id),
                            'url' => get_permalink($page_id),
                        ];
                    }
                }

                if ($homepage) {
                    $log[] = sprintf('Homepage corrente: pagina %d.', $homepage['page_id']);
                } else {
                    $log[] = 'Homepage non impostata su una pagina statica.';
                }

                return new WP_REST_Response([
                    'show_on_front' => $show_on_front,
                    'homepage' => $homepage,
                    'log' => $log,
                ], 200);

            default:
                $log[] = 'Azione non supportata.';
                return new WP_REST_Response([
                    'error' => __('Azione non supportata. Consulta la documentazione per le azioni disponibili.', 'wp-gpt-automation-pro'),
                    'supported_actions' => ['create-page', 'update-page', 'retrieve', 'view-menu', 'update-menu', 'manage-template', 'get-template', 'set-homepage', 'get-homepage'],
                    'log' => $log,
                ], 400);
        }
    }

    private static function resolve_page_template_details($template_slug)
    {
        $template_slug = is_string($template_slug) ? trim($template_slug) : '';
        $is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
        $stylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';

        if ($template_slug === '' || $template_slug === 'default') {
            return [
                'slug' => 'default',
                'label' => __('Template predefinito', 'wp-gpt-automation-pro'),
                'source' => 'default',
                'is_block_theme' => $is_block_theme,
            ];
        }

        $label = '';
        $source = 'custom';

        if ($is_block_theme && function_exists('get_block_template')) {
            $candidates = [$template_slug];
            if ($stylesheet !== '') {
                $candidates[] = $stylesheet . '//' . $template_slug;
            }

            foreach (array_unique($candidates) as $candidate) {
                $template = get_block_template($candidate, 'wp_template');
                if ($template) {
                    $label = isset($template->title) ? wp_strip_all_tags($template->title) : $label;
                    $source = !empty($template->wp_id) ? 'database' : 'theme_file';
                    break;
                }
            }
        }

        if ($label === '') {
            $available_templates = self::get_available_page_templates_map();
            if (isset($available_templates[$template_slug])) {
                $label = $available_templates[$template_slug];
                $source = $template_slug === 'default' ? 'default' : 'theme_file';
            }
        }

        if ($label === '') {
            $label = ucwords(str_replace(['-', '_'], ' ', $template_slug));
        }

        return [
            'slug' => $template_slug,
            'label' => $label,
            'source' => $source,
            'is_block_theme' => $is_block_theme,
        ];
    }

    private static function is_valid_page_template_slug($template_slug, ?array $available_templates = null)
    {
        $template_slug = is_string($template_slug) ? trim($template_slug) : '';

        if ($template_slug === '') {
            return false;
        }

        if (is_null($available_templates)) {
            $available_templates = self::get_available_page_templates_map();
        }

        return array_key_exists($template_slug, $available_templates);
    }

    private static function get_available_page_templates_map()
    {
        $templates = [
            'default' => __('Template predefinito', 'wp-gpt-automation-pro'),
        ];

        $theme_templates = wp_get_theme()->get_page_templates();
        if (is_array($theme_templates)) {
            foreach ($theme_templates as $label => $file) {
                if (!is_string($file) || $file === '') {
                    continue;
                }

                $templates[$file] = $label;
            }
        }

        $is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
        if ($is_block_theme) {
            $stylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';

            if (function_exists('get_block_templates')) {
                $block_templates = get_block_templates([
                    'theme' => $stylesheet,
                    'post_type' => 'page',
                ], 'wp_template');

                if (is_array($block_templates)) {
                    foreach ($block_templates as $template) {
                        $slug = isset($template->slug) ? $template->slug : '';
                        if ($slug === '') {
                            continue;
                        }

                        $label = isset($template->title) ? wp_strip_all_tags($template->title) : $slug;

                        if (!isset($templates[$slug])) {
                            $templates[$slug] = $label;
                        }
                    }
                }
            }

            if (!isset($templates['page'])) {
                $templates['page'] = __('Template pagina predefinito', 'wp-gpt-automation-pro');
            }
        }

        return $templates;
    }

    private static function format_page_templates_for_response(array $templates_map)
    {
        $formatted = [];

        foreach ($templates_map as $slug => $label) {
            $safe_label = $label !== '' ? $label : ucwords(str_replace(['-', '_'], ' ', $slug));
            $formatted[$safe_label] = $slug;
        }

        return $formatted;
    }

    /**
     * Handle various post management actions.
     *
     * Supported actions include chunked responses:
     * - read-post
     * - search-post
     * - get-chunk
     * - get-transient
     */
    public static function manage_file(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $supported_actions = ['send-file', 'send-image-base-64', 'send-image-base-64-in-chunk'];

        if (!in_array($action, $supported_actions, true)) {
            return new WP_REST_Response([
                'error' => __('Azione non supportata. Usa send-file, send-image-base-64 oppure send-image-base-64-in-chunk.', 'wp-gpt-automation-pro'),
                'supported_actions' => $supported_actions,
            ], 400);
        }

        if ($action === 'send-image-base-64') {
            return self::handle_send_image_base_64_action($params);
        }

        if ($action === 'send-image-base-64-in-chunk') {
            return self::handle_send_image_base_64_in_chunk_action($params);
        }

        return self::handle_send_file_action($params);
    }

    private static function handle_send_file_action(array $params)
    {
        $file_refs = $params['openaiFileIdRefs'] ?? [];
        if (is_string($file_refs)) {
            $decoded = json_decode($file_refs, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $file_refs = $decoded;
            }
        }

        if (!is_array($file_refs) || empty($file_refs)) {
            return new WP_REST_Response([
                'error' => __('Nessun file ricevuto. Invia openaiFileIdRefs con almeno un file.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        if (count($file_refs) > 10) {
            return new WP_REST_Response([
                'error' => __('È possibile inviare al massimo 10 file per richiesta.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $uploaded_files = [];
        $errors = [];

        foreach ($file_refs as $index => $file_ref) {
            if (is_object($file_ref)) {
                $file_ref = (array) $file_ref;
            }

            if (!is_array($file_ref)) {
                $errors[] = [
                    'index' => $index,
                    'message' => __('Formato file non valido.', 'wp-gpt-automation-pro'),
                ];
                continue;
            }

            $openai_file_id = isset($file_ref['id']) ? sanitize_text_field($file_ref['id']) : '';
            $download_link = isset($file_ref['download_link']) ? esc_url_raw($file_ref['download_link']) : '';

            if (!$download_link) {
                $errors[] = [
                    'openai_file_id' => $openai_file_id,
                    'message' => __('download_link mancante o non valido.', 'wp-gpt-automation-pro'),
                ];
                continue;
            }

            $tmp_file = download_url($download_link);
            if (is_wp_error($tmp_file)) {
                $errors[] = [
                    'openai_file_id' => $openai_file_id,
                    'message' => sprintf(__('Errore nel download del file: %s', 'wp-gpt-automation-pro'), $tmp_file->get_error_message()),
                ];
                continue;
            }

            $original_name = '';
            if (!empty($file_ref['name']) && is_string($file_ref['name'])) {
                $original_name = $file_ref['name'];
            }

            if (!$original_name) {
                $path = wp_parse_url($download_link, PHP_URL_PATH);
                if ($path) {
                    $original_name = basename($path);
                }
            }

            if (!$original_name) {
                $original_name = 'openai-file-' . ($index + 1);
            }

            $file_name = sanitize_file_name($original_name);
            if (!$file_name) {
                $file_name = 'openai-file-' . ($index + 1);
            }

            $mime_type = '';
            if (!empty($file_ref['mime_type']) && is_string($file_ref['mime_type'])) {
                $mime_type = sanitize_text_field($file_ref['mime_type']);
            }

            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            if (!$extension && $mime_type) {
                $mime_map = wp_get_mime_types();
                foreach ($mime_map as $extensions => $registered_mime) {
                    if ($registered_mime === $mime_type) {
                        $extension = explode('|', $extensions)[0];
                        break;
                    }
                }
                if ($extension) {
                    $file_name .= '.' . $extension;
                }
            }

            if (!$mime_type) {
                $filetype = wp_check_filetype_and_ext($tmp_file, $file_name);
                if (!empty($filetype['type'])) {
                    $mime_type = $filetype['type'];
                }
            }

            if (!$mime_type) {
                $mime_type = 'application/octet-stream';
            }

            $file_array = [
                'name' => $file_name,
                'tmp_name' => $tmp_file,
                'type' => $mime_type,
            ];

            $attachment_id = media_handle_sideload($file_array, 0);
            if (is_wp_error($attachment_id)) {
                @unlink($tmp_file);
                $errors[] = [
                    'openai_file_id' => $openai_file_id,
                    'message' => sprintf(__('Errore nel salvataggio del file: %s', 'wp-gpt-automation-pro'), $attachment_id->get_error_message()),
                ];
                continue;
            }

            $uploaded_files[] = [
                'id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id) ?: '',
                'name' => get_the_title($attachment_id) ?: $file_name,
                'openai_file_id' => $openai_file_id,
            ];
        }

        if (empty($uploaded_files)) {
            return new WP_REST_Response([
                'action' => 'send-file',
                'files' => [],
                'errors' => $errors,
            ], 500);
        }

        $response_data = [
            'action' => 'send-file',
            'files' => $uploaded_files,
        ];

        if (!empty($errors)) {
            $response_data['errors'] = $errors;
        }

        return new WP_REST_Response($response_data, 200);
    }

    private static function handle_send_image_base_64_action(array $params)
    {
        return self::finalize_image_upload_from_base64($params, 'send-image-base-64');
    }

    private static function handle_send_image_base_64_in_chunk_action(array $params)
    {
        $required_fields = ['user_code', 'filename', 'mime_type', 'chunk_base64', 'chunk_index', 'total_chunks'];
        foreach ($required_fields as $required_field) {
            if (!isset($params[$required_field]) || $params[$required_field] === '' || $params[$required_field] === null) {
                return new WP_REST_Response([
                    'error' => sprintf(__('Parametro obbligatorio mancante o non valido: %s', 'wp-gpt-automation-pro'), $required_field),
                ], 400);
            }
        }

        $mime_type = sanitize_text_field((string) $params['mime_type']);
        if (!self::is_allowed_manage_file_image_mime($mime_type)) {
            return new WP_REST_Response([
                'error' => __('mime_type non supportato. Sono consentiti solo file immagine (png, jpeg/jpg, webp, gif).', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $chunk_index = intval($params['chunk_index']);
        $total_chunks = intval($params['total_chunks']);
        if ($chunk_index < 0 || $total_chunks < 1 || $chunk_index >= $total_chunks) {
            return new WP_REST_Response([
                'error' => __('Valori chunk_index/total_chunks non coerenti.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $chunk_base64 = trim((string) $params['chunk_base64']);
        if ($chunk_base64 === '') {
            return new WP_REST_Response([
                'error' => __('chunk_base64 non può essere vuoto.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $normalized_chunk = preg_replace('/\s+/', '', $chunk_base64);
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $normalized_chunk)) {
            return new WP_REST_Response([
                'error' => __('chunk_base64 contiene caratteri non validi.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $upload_id = isset($params['upload_id']) ? sanitize_key((string) $params['upload_id']) : '';
        $transient_prefix = 'wp_gpt_manage_file_chunk_';
        $is_last_chunk = !empty($params['is_last_chunk']) && filter_var($params['is_last_chunk'], FILTER_VALIDATE_BOOLEAN);

        if ($upload_id === '') {
            if ($chunk_index !== 0) {
                return new WP_REST_Response([
                    'error' => __('upload_id mancante: il primo chunk deve avere chunk_index = 0.', 'wp-gpt-automation-pro'),
                ], 400);
            }
            $upload_id = sanitize_key(str_replace('-', '', wp_generate_uuid4()));
        }

        $transient_key = $transient_prefix . $upload_id;
        $upload_state = get_transient($transient_key);

        if (!is_array($upload_state)) {
            if ($chunk_index !== 0) {
                return new WP_REST_Response([
                    'error' => __('upload_id non trovato o scaduto.', 'wp-gpt-automation-pro'),
                    'upload_id' => $upload_id,
                ], 410);
            }

            $upload_state = [
                'user_code' => sanitize_text_field((string) $params['user_code']),
                'filename' => sanitize_file_name((string) $params['filename']),
                'mime_type' => $mime_type,
                'total_chunks' => $total_chunks,
                'metadata' => [
                    'title' => isset($params['title']) ? sanitize_text_field((string) $params['title']) : '',
                    'alt' => isset($params['alt']) ? sanitize_text_field((string) $params['alt']) : '',
                    'caption' => isset($params['caption']) ? sanitize_textarea_field((string) $params['caption']) : '',
                    'description' => isset($params['description']) ? sanitize_textarea_field((string) $params['description']) : '',
                ],
                'chunks' => [],
            ];
        }

        if ((int) $upload_state['total_chunks'] !== $total_chunks) {
            return new WP_REST_Response([
                'error' => __('total_chunks non corrisponde all\'upload inizializzato.', 'wp-gpt-automation-pro'),
                'upload_id' => $upload_id,
            ], 400);
        }

        if ($upload_state['mime_type'] !== $mime_type) {
            return new WP_REST_Response([
                'error' => __('mime_type non corrisponde all\'upload inizializzato.', 'wp-gpt-automation-pro'),
                'upload_id' => $upload_id,
            ], 400);
        }

        $upload_state['chunks'][$chunk_index] = $normalized_chunk;
        set_transient($transient_key, $upload_state, HOUR_IN_SECONDS);

        if (!$is_last_chunk) {
            return new WP_REST_Response([
                'ok' => true,
                'action' => 'send-image-base-64-in-chunk',
                'upload_id' => $upload_id,
                'received_chunk_index' => $chunk_index,
                'total_chunks' => $total_chunks,
                'status' => 'in_progress',
            ], 200);
        }

        $missing_chunks = [];
        for ($i = 0; $i < $total_chunks; $i++) {
            if (!isset($upload_state['chunks'][$i])) {
                $missing_chunks[] = $i;
            }
        }

        if (!empty($missing_chunks)) {
            return new WP_REST_Response([
                'error' => __('Upload incompleto: mancano uno o più chunk.', 'wp-gpt-automation-pro'),
                'upload_id' => $upload_id,
                'missing_chunks' => $missing_chunks,
            ], 400);
        }

        ksort($upload_state['chunks']);
        $full_base64 = implode('', $upload_state['chunks']);

        $finalize_params = [
            'user_code' => $upload_state['user_code'],
            'content_base64' => $full_base64,
            'filename' => $upload_state['filename'],
            'mime_type' => $upload_state['mime_type'],
            'title' => $upload_state['metadata']['title'],
            'alt' => $upload_state['metadata']['alt'],
            'caption' => $upload_state['metadata']['caption'],
            'description' => $upload_state['metadata']['description'],
        ];

        $finalize_response = self::finalize_image_upload_from_base64($finalize_params, 'send-image-base-64-in-chunk');
        if ($finalize_response->get_status() >= 400) {
            return $finalize_response;
        }

        delete_transient($transient_key);
        return $finalize_response;
    }

    private static function get_manage_file_allowed_image_mimes()
    {
        return [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
    }

    private static function is_allowed_manage_file_image_mime($mime_type)
    {
        $allowed_mimes = self::get_manage_file_allowed_image_mimes();
        return isset($allowed_mimes[$mime_type]);
    }

    private static function ensure_manage_file_filename_extension($file_name, $mime_type)
    {
        $safe_file_name = sanitize_file_name($file_name);
        if (!$safe_file_name) {
            $safe_file_name = 'image-upload';
        }

        if (!pathinfo($safe_file_name, PATHINFO_EXTENSION)) {
            $allowed_mimes = self::get_manage_file_allowed_image_mimes();
            if (isset($allowed_mimes[$mime_type])) {
                $safe_file_name .= '.' . $allowed_mimes[$mime_type];
            }
        }

        return $safe_file_name;
    }

    private static function finalize_image_upload_from_base64(array $params, $response_action = 'send-image-base-64')
    {
        $required_fields = ['user_code', 'content_base64', 'filename', 'mime_type'];
        foreach ($required_fields as $required_field) {
            if (!isset($params[$required_field]) || !is_string($params[$required_field]) || trim($params[$required_field]) === '') {
                return new WP_REST_Response([
                    'error' => sprintf(__('Parametro obbligatorio mancante o non valido: %s', 'wp-gpt-automation-pro'), $required_field),
                ], 400);
            }
        }

        $mime_type = sanitize_text_field($params['mime_type']);
        if (!self::is_allowed_manage_file_image_mime($mime_type)) {
            return new WP_REST_Response([
                'error' => __('mime_type non supportato. Sono consentiti solo file immagine (png, jpeg/jpg, webp, gif).', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $content_base64 = trim((string) $params['content_base64']);
        if (stripos($content_base64, 'data:') === 0) {
            if (!preg_match('#^data:([^;]+);base64,(.*)$#si', $content_base64, $matches)) {
                return new WP_REST_Response([
                    'error' => __('Formato data URI non valido per content_base64.', 'wp-gpt-automation-pro'),
                ], 400);
            }

            $data_uri_mime = strtolower(trim($matches[1]));
            if ($data_uri_mime !== strtolower($mime_type)) {
                return new WP_REST_Response([
                    'error' => __('Il MIME del data URI non corrisponde al mime_type fornito.', 'wp-gpt-automation-pro'),
                ], 400);
            }

            $content_base64 = trim($matches[2]);
        }

        $decoded_content = base64_decode($content_base64, true);
        if ($decoded_content === false) {
            return new WP_REST_Response([
                'error' => __('content_base64 non è una stringa base64 valida.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $max_size_bytes = 10 * 1024 * 1024;
        if (strlen($decoded_content) > $max_size_bytes) {
            return new WP_REST_Response([
                'error' => __('Il file decodificato supera la dimensione massima consentita di 10 MB.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $file_name = self::ensure_manage_file_filename_extension((string) $params['filename'], $mime_type);

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp_file = wp_tempnam($file_name);
        if (!$tmp_file) {
            return new WP_REST_Response([
                'error' => __('Impossibile creare un file temporaneo per il caricamento.', 'wp-gpt-automation-pro'),
            ], 500);
        }

        $bytes_written = file_put_contents($tmp_file, $decoded_content);
        if ($bytes_written === false) {
            @unlink($tmp_file);

            return new WP_REST_Response([
                'error' => __('Errore durante la scrittura del file temporaneo.', 'wp-gpt-automation-pro'),
            ], 500);
        }

        $file_array = [
            'name' => $file_name,
            'tmp_name' => $tmp_file,
            'type' => $mime_type,
            'error' => 0,
            'size' => $bytes_written,
        ];

        $attachment_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);

            return new WP_REST_Response([
                'error' => sprintf(__('Errore nel salvataggio del file: %s', 'wp-gpt-automation-pro'), $attachment_id->get_error_message()),
            ], 500);
        }

        $post_update = ['ID' => $attachment_id];
        $has_post_updates = false;

        if (isset($params['title']) && is_string($params['title']) && trim($params['title']) !== '') {
            $post_update['post_title'] = sanitize_text_field($params['title']);
            $has_post_updates = true;
        }

        if (isset($params['caption']) && is_string($params['caption']) && trim($params['caption']) !== '') {
            $post_update['post_excerpt'] = sanitize_textarea_field($params['caption']);
            $has_post_updates = true;
        }

        if (isset($params['description']) && is_string($params['description']) && trim($params['description']) !== '') {
            $post_update['post_content'] = sanitize_textarea_field($params['description']);
            $has_post_updates = true;
        }

        if ($has_post_updates) {
            wp_update_post($post_update);
        }

        if (isset($params['alt']) && is_string($params['alt']) && trim($params['alt']) !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt']));
        }

        return new WP_REST_Response([
            'action' => $response_action,
            'files' => [
                [
                    'id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id) ?: '',
                    'name' => get_the_title($attachment_id) ?: $file_name,
                ]
            ],
        ], 200);
    }

    public static function manage_articles_with_user_code(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $log = [];
        $log[] = "Azione richiesta: $action";
        $response = null;
        $result = null;
        $error = null;
        $status = 200;

        switch ($action) {
            case 'create-post':
                $log[] = 'Inizio creazione del post.';
                $response = self::create_post_with_user_code($request);
                break;

            case 'update-post':
                $log[] = 'Inizio aggiornamento del post.';
                $response = self::update_post($request);
                break;

            case 'append-content':
                $log[] = 'Inizio append del contenuto.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $position = isset($params['position']) ? sanitize_text_field($params['position']) : 'end';
                $allowed_positions = ['start', 'end', 'before', 'after'];
                if (!in_array($position, $allowed_positions, true)) {
                    $position = 'end';
                }

                if (in_array($position, ['before', 'after'], true)) {
                    $h2_number = null;
                    if (isset($params['h2_number'])) {
                        $h2_number = intval($params['h2_number']);
                    } elseif (isset($params['h2_index'])) {
                        $h2_number = intval($params['h2_index']);
                    }

                    if (!$h2_number || $h2_number < 1) {
                        $error = "Parametro 'h2_number' mancante o non valido per append-content.";
                        $status = 400;
                        break;
                    }

                    $position_label = $position === 'before' ? 'prima' : 'dopo';
                    $log[] = sprintf('Contenuto da inserire %s dell\'H2 numero %d.', $position_label, $h2_number);
                } else {
                    $log[] = sprintf('Contenuto da inserire in posizione: %s.', $position);
                }
                $response = self::append_content($request);
                break;

            case 'append-paragraph':
                $log[] = 'Inizio append del paragrafo.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::append_paragraph($request);
                break;

            case 'embed-video':
                $log[] = 'Inizio embed del video.';
                if (isset($params['id'])) {
                    $request->set_param('post_id', intval($params['id']));
                }
                $response = self::insert_embed_video($request);
                break;

            case 'replace-text':
                $log[] = 'Inizio sostituzione del testo.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::replace_txt_in_post($request);
                break;

            case 'replace-html':
                $log[] = "Inizio sostituzione dell'HTML.";
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::replace_html_in_post($request);
                break;

            case 'read-post':
                $log[] = 'Inizio lettura del post.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $post_response = self::get_post($request);
                if ($post_response instanceof WP_REST_Response) {
                    $data = $post_response->get_data();
                    // Suddivide i dati in chunk e li salva in un transient.
                    $chunk_response = self::send_chunked_response($data, 'read_post_' . $data['ID']);
                    $chunk_data = $chunk_response->get_data();
                    $log[] = sprintf('Risposta divisa in %d chunk. chunk_key: %s.', $chunk_data['total_chunks'], $chunk_data['chunk_key']);
                    $response = $chunk_response;
                } else {
                    $response = $post_response;
                }
                break;

            case 'get-post':
                $log[] = 'Inizio recupero del post.';
                if (empty($params['post_id'])) {
                    $error = "Parametro 'post_id' mancante.";
                    $status = 400;
                    break;
                }
                $post_id = intval($params['post_id']);
                $log[] = "Recupero del post $post_id.";
                $post = get_post($post_id);
                if (!$post) {
                    $error = "Post $post_id non trovato.";
                    $status = 404;
                } else {
                    $result = $post;
                }
                break;

            case 'search-post':
                $log[] = 'Inizio ricerca dei post.';
                $search_request = new WP_REST_Request('GET', '');
                $search_request->set_query_params([
                    'search' => isset($params['search']) ? sanitize_text_field($params['search']) : '',
                    'post_type' => isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'post',
                    'limit' => isset($params['limit']) ? intval($params['limit']) : 10,
                ]);
                $search_response = self::search_posts($search_request);
                if ($search_response instanceof WP_REST_Response) {
                    $data = $search_response->get_data();
                    // Suddivide i risultati della ricerca in chunk.
                    $chunk_response = self::send_chunked_response($data, 'search_post');
                    $chunk_data = $chunk_response->get_data();
                    $log[] = sprintf('Risultati divisi in %d chunk. chunk_key: %s.', $chunk_data['total_chunks'], $chunk_data['chunk_key']);
                    $response = $chunk_response;
                } else {
                    $response = $search_response;
                }
                break;

            case 'get-chunk':
                $chunk_key = isset($params['chunk_key']) ? sanitize_text_field($params['chunk_key']) : '';
                $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;
                $log[] = 'Recupero chunk.';
                if (empty($chunk_key)) {
                    $error = 'Parametro chunk_key mancante.';
                    $status = 400;
                    break;
                }
                $chunks = get_transient($chunk_key);
                if (!$chunks) {
                    $error = 'Nessun transient trovato.';
                    $status = 404;
                    break;
                }
                $chunk = $chunks[$chunk_index] ?? null;
                if ($chunk === null) {
                    $error = 'Indice chunk non valido.';
                    $status = 400;
                    break;
                }
                $result = [
                    'chunk_key' => $chunk_key,
                    'chunk_index' => $chunk_index,
                    'chunk' => $chunk,
                    'total_chunks' => count($chunks),
                ];
                $log[] = sprintf('Restituito chunk %d di %d.', $chunk_index, count($chunks));
                break;

            case 'get-transient':
                $chunk_key = isset($params['chunk_key']) ? sanitize_text_field($params['chunk_key']) : '';
                $log[] = 'Recupero di tutti i chunk.';
                if (empty($chunk_key)) {
                    $error = 'Parametro chunk_key mancante.';
                    $status = 400;
                    break;
                }
                $chunks = get_transient($chunk_key);
                if (!$chunks) {
                    $error = 'Nessun transient trovato.';
                    $status = 404;
                    break;
                }
                $result = [
                    'chunk_key' => $chunk_key,
                    'total_chunks' => count($chunks),
                    'chunks' => $chunks,
                ];
                $log[] = sprintf('Recuperati %d chunk.', count($chunks));
                break;

            case 'create-category':
                $log[] = 'Inizio creazione categoria.';
                if (empty($params['name'])) {
                    $error = "Parametro 'name' mancante.";
                    $status = 400;
                    break;
                }
                $name = sanitize_text_field($params['name']);
                $log[] = "Creazione della categoria '$name'.";
                $term = wp_insert_term($name, 'category');
                if (is_wp_error($term)) {
                    $error = $term->get_error_message();
                    $status = 400;
                } else {
                    $result = $term;
                    $log[] = "Categoria creata con ID " . $term['term_id'] . ".";
                }
                break;

            case 'assign-category':
                $log[] = 'Inizio assegnazione categoria.';
                if (empty($params['post_id']) || empty($params['category_ids'])) {
                    $error = "Parametri 'post_id' o 'category_ids' mancanti.";
                    $status = 400;
                    break;
                }
                $post_id = intval($params['post_id']);
                $category_ids = array_filter(array_map('intval', (array) $params['category_ids']));
                $log[] = "Assegnazione delle categorie [" . implode(', ', $category_ids) . "] al post $post_id.";
                $set = wp_set_post_terms($post_id, $category_ids, 'category', true);
                if (is_wp_error($set)) {
                    $error = $set->get_error_message();
                    $status = 400;
                } else {
                    $result = ['post_id' => $post_id, 'categories' => $set];
                    $log[] = 'Categorie assegnate con successo.';
                }
                break;

            case 'update-seo-title':
                $log[] = 'Inizio aggiornamento SEO title.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::update_seo_title($request);
                break;

            case 'update-meta-description':
                $log[] = 'Inizio aggiornamento meta description.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::update_meta_description($request);
                break;

            case 'update-focus-keywords':
                $log[] = 'Inizio aggiornamento focus keywords.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::update_focus_keywords($request);
                break;

            case 'update-slug':
                $log[] = 'Inizio aggiornamento slug.';
                if (isset($params['id'])) {
                    $request->set_param('id', intval($params['id']));
                }
                $response = self::update_slug($request);
                break;

            default:
                $error = 'Azione non supportata';
                $status = 400;
                break;
        }

        if ($response instanceof WP_REST_Response) {
            $result = $response->get_data();
            $status = $response->get_status();
        } elseif (is_wp_error($response)) {
            $error = $response->get_error_message();
            $status = 400;
        }

        if ($error) {
            $log[] = $error;
            return new WP_REST_Response(['log' => $log, 'error' => $error], $status);
        }

        return new WP_REST_Response(['log' => $log, 'result' => $result], $status);
    }


    public static function link_scraping(WP_REST_Request $request)
    {
        $log = []; // Array per raccogliere i log

        // Recupera parametri e valida il link
        $link = esc_url_raw($request->get_param('link'));
        $analysis_type = sanitize_text_field($request->get_param('analysis_type'));
        $chunk_index = intval($request->get_param('chunk_index')) ?: 0;
        $expression = sanitize_text_field($request->get_param('expression'));

        $log[] = "Link ricevuto: $link";
        $log[] = "Tipo di analisi: $analysis_type";

        if (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
            $log[] = "Link non valido";
            return new WP_REST_Response(['error' => 'Link non valido', 'log' => $log], 400);
        }

        // Recupera impostazioni del proxy e autenticazione
        $use_proxy = get_option('wp_gpt_usa_proxy');
        $proxy_endpoint = get_option('wp_gpt_proxy_endpoint');
        $proxy_port = get_option('wp_gpt_proxy_port');
        $proxy_timeout = get_option('wp_gpt_proxy_timeout', 10);  // Imposta un timeout predefinito di 10s
        $proxy_protocol = get_option('wp_gpt_proxy_protocol');
        $use_authentication = get_option('wp_gpt_usa_autenticazione');
        $proxy_username = get_option('wp_gpt_proxy_username');
        $proxy_password = get_option('wp_gpt_proxy_password');
        $custom_user_agent = get_option('wp_gpt_custom_user_agent', 'WP-GPT-Automation-Pro');

        $log[] = "Usa proxy: $use_proxy";
        $log[] = "Endpoint proxy: $proxy_endpoint";
        $log[] = "Porta proxy: $proxy_port";
        $log[] = "Timeout: $proxy_timeout";
        $log[] = "Protocollo proxy: $proxy_protocol";
        $log[] = "Usa autenticazione proxy: $use_authentication";

        // Configura i parametri della richiesta
        $args = [
            'timeout' => $proxy_timeout,
            'headers' => [
                'User-Agent' => $custom_user_agent,
            ],
        ];

        // Configura il proxy se abilitato
        if ($use_proxy && $proxy_endpoint && $proxy_port) {
            $args['proxy'] = [
                'http' => "$proxy_protocol://$proxy_endpoint:$proxy_port",
                'https' => "$proxy_protocol://$proxy_endpoint:$proxy_port",
            ];

            if ($use_authentication && $proxy_username && $proxy_password) {
                $args['headers']['Proxy-Authorization'] = 'Basic ' . base64_encode("$proxy_username:$proxy_password");
                $log[] = "Autenticazione proxy abilitata";
            }
        }

        $log[] = "Argomenti configurati per la richiesta: " . json_encode($args);

        // Effettua la richiesta
        $response = wp_remote_get($link, $args);

        if (is_wp_error($response)) {
            $log[] = "Errore nel recupero della pagina: " . $response->get_error_message();
            return new WP_REST_Response(['error' => 'Errore nel recupero della pagina', 'log' => $log], 500);
        }

        $html_content = wp_remote_retrieve_body($response);
        $log[] = "Contenuto HTML recuperato, lunghezza: " . strlen($html_content);

        // Inizia l'analisi del contenuto HTML
        $results = [];
        $chunks = str_split($html_content, 6000);
        $total_chunks = count($chunks);

        if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
            $log[] = "Indice chunk non valido";
            return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
        }

        $chunk = $chunks[$chunk_index];

        // Switch per azioni di scraping
        switch ($analysis_type) {
            case 'get_div_ids':
                // Estrai tutti gli ID dai div nel contenuto HTML
                preg_match_all('/<div[^>]*\sid=["\']([^"\']+)["\']/i', $html_content, $matches);
                $all_ids = $matches[1];

                // Suddividi gli ID in chunk
                $id_chunks = array_chunk($all_ids, 200); // Suddividi ogni chunk in 200 ID
                $total_chunks = count($id_chunks);

                // Verifica se l'indice del chunk richiesto è valido
                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                    $log[] = "Indice chunk non valido per gli ID";
                    return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
                }

                // Restituisci il chunk specifico e i metadati
                $results = [
                    'chunk_content' => $id_chunks[$chunk_index],
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks
                ];
                $log[] = "ID dei div estratti e divisi in chunk. Indice chunk: $chunk_index di $total_chunks";
                break;


            case 'get_div_classes':
                // Estrai tutte le classi dai div nel contenuto HTML
                preg_match_all('/<div[^>]+class="([^"]+)"/i', $html_content, $matches);
                $all_classes = [];

                // Dividi ogni stringa di classi in singoli nomi di classe
                foreach ($matches[1] as $class_string) {
                    $all_classes = array_merge($all_classes, explode(' ', $class_string));
                }

                // Rimuovi eventuali duplicati
                $unique_classes = array_unique($all_classes);

                // Suddividi le classi in chunk
                $class_chunks = array_chunk($unique_classes, 200); // Suddividi ogni chunk in 200 classi
                $total_chunks = count($class_chunks);

                // Verifica se l'indice del chunk richiesto è valido
                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                    $log[] = "Indice chunk non valido per le classi";
                    return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
                }

                // Restituisci il chunk specifico e i metadati
                $results = [
                    'chunk_content' => $class_chunks[$chunk_index],
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks
                ];
                $log[] = "Classi dei div estratte e divise in chunk. Indice chunk: $chunk_index di $total_chunks";
                break;


            case 'find_expression_in_div':
                // Rimuove temporaneamente i tag HTML per eseguire la ricerca dell'espressione ignorando l'HTML in mezzo
                $clean_html_content = preg_replace('/\s+/', ' ', strip_tags($html_content));

                // Verifica se l'espressione è presente nel testo pulito
                if (stripos($clean_html_content, $expression) !== false) {
                    // Trova la posizione dell'espressione nel contenuto HTML originale
                    $expression_pos = stripos($html_content, $expression);

                    if ($expression_pos !== false) {
                        // Risale fino all'inizio del primo tag <div> o <li> più vicino prima dell'espressione
                        $start_pos = strrpos(substr($html_content, 0, $expression_pos), '<div');
                        if ($start_pos === false) {
                            $start_pos = strrpos(substr($html_content, 0, $expression_pos), '<li');
                        }

                        // Se trova il tag di apertura <div> o <li>, continua fino al rispettivo tag di chiusura
                        if ($start_pos !== false) {
                            // Trova la fine del contenitore
                            $end_pos = strpos($html_content, ($html_content[$start_pos + 1] == 'd' ? '</div>' : '</li>'), $expression_pos);
                            if ($end_pos !== false) {
                                // Includi tutto il contenitore
                                $full_container = substr($html_content, $start_pos, $end_pos - $start_pos + 6);

                                // Divide il contenitore in chunk da 2000 caratteri
                                $container_chunks = str_split($full_container, WP_GPT_CHUNK_SIZE);
                                $total_chunks = count($container_chunks);

                                // Verifica l'indice del chunk richiesto
                                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                                    $log[] = "Indice chunk non valido per il contenitore div/li";
                                    return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
                                }

                                // Restituisce il chunk specifico e i metadati
                                $results = [
                                    'chunk_content' => $container_chunks[$chunk_index],
                                    'chunk_index' => $chunk_index,
                                    'total_chunks' => $total_chunks
                                ];
                                $log[] = "Contenitore HTML trovato con espressione '$expression' e diviso in chunk. Indice chunk: $chunk_index di $total_chunks";
                            } else {
                                $log[] = "Tag di chiusura mancante per il contenitore con espressione '$expression'.";
                            }
                        } else {
                            $log[] = "Nessun contenitore <div> o <li> trovato per l'espressione '$expression'.";
                        }
                    } else {
                        $log[] = "Espressione '$expression' non trovata nel contenuto HTML originale.";
                    }
                } else {
                    $log[] = "Espressione '$expression' non trovata nel testo pulito.";
                }
                break;


            case 'get_images':
                // Estrai tutte le immagini dall'intero contenuto HTML
                preg_match_all('/<img\s+[^>]*\s+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))["\'](?:\s+[^>]*alt=["\']([^"\']*)["\'])?(?:\s+[^>]*title=["\']([^"\']*)["\'])?[^>]*>/i', $html_content, $matches, PREG_SET_ORDER);
                $results = [];

                foreach ($matches as $match) {
                    $results[] = [
                        'src' => $match[1], // URL immagine
                        'alt' => isset($match[2]) ? $match[2] : null, // Testo alt
                        'title' => isset($match[3]) ? $match[3] : null // Titolo
                    ];
                }

                $log[] = "Immagini estratte dall'intero contenuto HTML: " . json_encode($results);
                break;


            case 'get_meta_tags':
                // Estrai tutti i tag meta dall'intero contenuto HTML
                preg_match_all('/<meta\s+(?:name|property|http-equiv)=["\']([^"\']+)["\'](?:[^>]*content=["\']([^"\']+)["\'])?/i', $html_content, $matches, PREG_SET_ORDER);
                $results = [];

                foreach ($matches as $match) {
                    $results[] = [
                        'type' => $match[1], // Tipo di meta tag (name, property, http-equiv)
                        'content' => isset($match[2]) ? $match[2] : null // Contenuto del meta tag
                    ];
                }

                $log[] = "Meta tag estratti dall'intero contenuto HTML: " . json_encode($results);
                break;



            case 'get_headers':
                // Estrai tutte le intestazioni (h1 - h6) dall'intero contenuto HTML
                preg_match_all('/<(h[1-6])[^>]*>\s*(.*?)\s*<\/\1>/is', $html_content, $matches, PREG_SET_ORDER);
                $results = [];

                foreach ($matches as $match) {
                    $results[] = [
                        'level' => $match[1], // Livello dell'intestazione (h1, h2, ecc.)
                        'content' => trim(strip_tags($match[2])) // Contenuto pulito dell'intestazione
                    ];
                }

                $log[] = "Intestazioni estratte dall'intero contenuto HTML: " . json_encode($results);
                break;



            case 'extract_emails':
                // Esegue la ricerca di tutte le email su tutto il contenuto HTML della pagina
                preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,4}\b/i', $html_content, $matches);

                // Rimuove i duplicati
                $unique_emails = array_unique($matches[0]);

                // Converte l'array di email in una stringa separata da virgole per una gestione più semplice dei chunk
                $all_emails_string = implode(", ", $unique_emails);

                // Divide la stringa di email in chunk da 2000 caratteri
                $email_chunks = str_split($all_emails_string, WP_GPT_CHUNK_SIZE);
                $total_chunks = count($email_chunks);

                // Verifica se l'indice del chunk richiesto è valido
                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                    $log[] = "Indice chunk non valido per le email";
                    return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
                }

                // Restituisce il chunk specifico e i metadati
                $results = [
                    'chunk_content' => $email_chunks[$chunk_index],
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks
                ];
                $log[] = "Email trovate su tutta la pagina e divise in chunk. Indice chunk: $chunk_index di $total_chunks";
                break;


            case 'get_text_chunks':
                $clean_html = preg_replace([
                    '/<script\b[^>]*>(.*?)<\/script>/is',       // Rimuove i tag <script>
                    '/<style\b[^>]*>(.*?)<\/style>/is',         // Rimuove i tag <style>
                    '/<noscript\b[^>]*>(.*?)<\/noscript>/is',   // Rimuove i tag <noscript>
                    '/<!--.*?-->/s',                            // Rimuove i commenti HTML
                    '/\/\*.*?\*\//s',                           // Rimuove i commenti CSS /* */
                    '/@font-face\s*{[^}]*}/is',                 // Rimuove i blocchi @font-face
                ], '', $html_content);

                // Rimuovi i tag HTML lasciando solo il testo
                $text_content = strip_tags($clean_html);

                // Rimuovi righe vuote, spazi multipli e tabulazioni
                $text_content = preg_replace("/\s+/", " ", $text_content);

                // Suddividi il testo pulito in chunk di dimensione definita
                $chunks = str_split($text_content, WP_GPT_CHUNK_SIZE); // 2000 è la dimensione massima del chunk
                $total_chunks = count($chunks);

                // Verifica se l'indice del chunk richiesto è valido
                if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
                    $log[] = "Indice chunk non valido per il testo significativo";
                    return new WP_REST_Response(['error' => 'Indice chunk non valido', 'log' => $log], 400);
                }

                // Restituisci il chunk specifico e i metadati
                $results = [
                    'chunk_content' => $chunks[$chunk_index],
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks
                ];
                $log[] = "Chunk di testo significativo estratto nel chunk $chunk_index di $total_chunks";
                break;





            case 'get_html_chunks':
                $results = ['chunk_content' => $chunk, 'chunk_index' => $chunk_index, 'total_chunks' => $total_chunks];
                $log[] = "HTML chunk estratto nel chunk $chunk_index";
                break;

            default:
                $log[] = "Tipo di analisi non valido";
                return new WP_REST_Response(['error' => 'Tipo di analisi non valido', 'log' => $log], 400);
        }

        return new WP_REST_Response(['results' => $results, 'log' => $log], 200);
    }







    public static function execute_minecraft_command(WP_REST_Request $request)
    {
        // Ottieni il comando Minecraft dalla richiesta
        $command = sanitize_text_field($request->get_param('command'));

        // Recupera la chiave Bearer, l'hostname e la porta dal sistema di opzioni di WordPress
        $bearer_code = get_option('minecraft_bearer_code');
        $hostname = get_option('minecraft_server_hostname');
        $port = get_option('minecraft_server_port');

        // Verifica che i parametri siano configurati
        if (empty($bearer_code) || empty($hostname) || empty($port)) {
            error_log('Errore: Bearer code, hostname o porta non configurati');
            return new WP_REST_Response(['error' => 'Bearer code, hostname o porta mancante'], 500);
        }

        // Costruisce l'URL dell'API Minecraft utilizzando hostname e porta
        $api_url = "http://{$hostname}:{$port}/execute";

        // Configura le intestazioni e il corpo della richiesta
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer_code,
            ],
            'body' => $command,
            'timeout' => 20,  // Timeout opzionale
        ];

        // Esegue la richiesta POST all'API di Minecraft
        $response = wp_remote_post($api_url, $args);

        // Gestisce la risposta
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Errore nella richiesta a Minecraft API: $error_message");
            return new WP_REST_Response(['error' => $error_message], 500);
        }

        $body = wp_remote_retrieve_body($response);

        // Verifica se il corpo della risposta è null, interpretandolo come successo
        if (is_null($body)) {
            return new WP_REST_Response(['error' => 'Esecuzione del comando fallita', 'details' => $result], 500);
        } else {
            // Interpreta qualsiasi altra risposta come un errore
            $result = json_decode($body, true);
            return new WP_REST_Response(['message' => 'Comando eseguito con successo'], 200);

        }
    }




    public static function scrape_twitter_data(WP_REST_Request $request)
    {
        $username = $request->get_param('username');
        $tweet_id = $request->get_param('tweet_id');
        $comments_limit = $request->get_param('comments_limit');

        // Inizializza la libreria TwitterScraper con il nome utente
        $twitter = new Twitter($username);

        try {
            // Carica i tweet dell'utente
            $tweets = $twitter->loadTweets();

            // Cerca il tweet specifico tramite ID
            $tweet_data = array_filter($tweets, function ($tweet) use ($tweet_id) {
                return $tweet['id'] == $tweet_id;
            });

            if (empty($tweet_data)) {
                return new WP_REST_Response(['error' => 'Tweet non trovato.'], 404);
            }

            $tweet = reset($tweet_data);

            // Estrarre testo, immagini, video e commenti
            $tweet_text = $tweet['text'] ?? '';
            $media_links = [];
            $comments = [];

            // Raccogli i link delle immagini e dei video
            if (isset($tweet['media'])) {
                foreach ($tweet['media'] as $media) {
                    if ($media['type'] == 'photo') {
                        $media_links['images'][] = $media['media_url'];
                    } elseif ($media['type'] == 'video') {
                        $media_links['videos'][] = $media['video_url'];
                    }
                }
            }

            // Carica i commenti (se specificato)
            if ($comments_limit > 0) {
                $comments = $twitter->loadComments($tweet_id, $comments_limit);
            }

            // Profilo dell'utente
            $profile_info = $twitter->getProfile();

            return new WP_REST_Response([
                'profile' => $profile_info,
                'tweet_text' => $tweet_text,
                'media' => $media_links,
                'comments' => $comments
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
    public static function get_youtube_transcription(WP_REST_Request $request)
    {
        global $wpdb; // Accesso al database di WordPress
        $params = $request->get_json_params();
        $log = []; // Array per memorizzare i log dell'operazione

        // 1. Verifica e sanitizza i parametri di richiesta
        $video_url = isset($params['video_url']) ? esc_url_raw($params['video_url']) : '';
        $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;

        if (empty($video_url)) {
            $log[] = "Errore: URL del video non fornito nella richiesta.";
            return new WP_REST_Response(['error' => 'URL del video non fornito.', 'log' => $log], 400);
        }
        $log[] = "URL del video fornito: $video_url";
        $log[] = "Chunk richiesto: $chunk_index";

        // 2. Verifica la presenza della trascrizione nel database
        $transcriptions_table = $wpdb->prefix . 'youtube_transcriptions';
        $transcription_record = $wpdb->get_row($wpdb->prepare("SELECT transcription FROM $transcriptions_table WHERE video_url = %s", $video_url));

        if ($transcription_record && !empty($transcription_record->transcription)) {
            $log[] = "Trascrizione trovata nel database. Recupero chunk richiesto...";
            return self::get_transcription_chunk($transcription_record->transcription, $chunk_index, $log);
        }
        $log[] = "Trascrizione non presente nel database, avvio richiesta all'API.";

        // 3. Recupera la chiave API per la trascrizione
        $api_key = get_option('wp_gpt_youtube_transcription_api_key');
        if (empty($api_key)) {
            $log[] = "Errore: Chiave API di trascrizione non trovata nelle impostazioni.";
            return new WP_REST_Response([
                'error' => 'Chiave API non configurata. Impostare la chiave API nelle impostazioni del plugin.',
                'log' => $log
            ], 500);
        }
        $log[] = "Chiave API di trascrizione recuperata correttamente.";

        // 4. Configura l'URL di richiesta all'API
        $api_url = "https://youtube-transcripts.p.rapidapi.com/youtube/transcript";
        $query_string = http_build_query(['url' => $video_url, 'chunkSize' => 500]);
        $log[] = "URL di richiesta configurato: $api_url?$query_string";

        // 5. Configura l'intestazione per la richiesta HTTP
        $headers = [
            'x-rapidapi-host: youtube-transcripts.p.rapidapi.com',
            'x-rapidapi-key: ' . $api_key,
        ];
        $log[] = "Intestazioni HTTP impostate: " . json_encode($headers);

        // 6. Esegui la richiesta API tramite cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . '?' . $query_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $log[] = "Errore nella richiesta cURL: $error";
            return new WP_REST_Response([
                'error' => 'Errore nella richiesta al servizio di trascrizione.',
                'details' => $error,
                'log' => $log
            ], 500);
        }
        $log[] = "Risposta API ricevuta con successo.";

        // 7. Decodifica la risposta JSON
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            $log[] = "Errore nel decodificare la risposta JSON: $json_error";
            return new WP_REST_Response([
                'error' => 'Errore nel decodificare la risposta JSON dal servizio di trascrizione.',
                'details' => $json_error,
                'log' => $log
            ], 500);
        }
        $log[] = "Risposta JSON decodificata con successo: " . json_encode($data);

        // 8. Verifica la presenza dei dati attesi nella risposta
        if (!isset($data['content']) || !isset($data['lang'])) {
            $log[] = "Errore: Dati mancanti nella risposta dell'API di trascrizione. Risposta completa: " . json_encode($data);
            return new WP_REST_Response([
                'error' => 'Dati mancanti nella risposta dall\'API di trascrizione.',
                'log' => $log
            ], 500);
        }

        // 9. Salva la trascrizione nel database
        $transcription_content = '';
        foreach ($data['content'] as $segment) {
            $transcription_content .= $segment['text'] . " ";
        }
        $log[] = "Trascrizione ottenuta: " . strlen($transcription_content) . " caratteri.";

        $wpdb->insert(
            $transcriptions_table,
            [
                'video_url' => $video_url,
                'transcription' => $transcription_content
            ],
            ['%s', '%s']
        );
        $log[] = "Trascrizione salvata nel database correttamente.";

        // 10. Restituisci il chunk richiesto e il numero totale dei chunk
        return self::get_transcription_chunk($transcription_content, $chunk_index, $log);
    }

    /**
     * Funzione di supporto per ottenere un chunk specifico dalla trascrizione
     */
    private static function get_transcription_chunk($transcription, $chunk_index, $log)
    {
        $chunk_size = WP_GPT_CHUNK_SIZE; // Dimensione del chunk in caratteri
        $total_length = strlen($transcription);
        $total_chunks = ceil($total_length / $chunk_size);

        $log[] = "Lunghezza totale della trascrizione: $total_length caratteri.";
        $log[] = "Numero totale di chunk: $total_chunks.";

        // Verifica e corregge l'indice del chunk
        if ($chunk_index < 0) {
            $log[] = "Indice del chunk negativo, impostato a 0.";
            $chunk_index = 0;
        } elseif ($chunk_index >= $total_chunks) {
            $log[] = "Indice del chunk fuori dal range, impostato all'ultimo chunk disponibile.";
            $chunk_index = $total_chunks - 1;
        }

        $start = $chunk_index * $chunk_size;
        $chunk_content = substr($transcription, $start, $chunk_size);

        $log[] = "Restituito chunk {$chunk_index} su {$total_chunks} chunk totali.";
        return new WP_REST_Response([
            'chunk_content' => $chunk_content,
            'chunk_index' => $chunk_index,
            'total_chunks' => $total_chunks,
            'total_length' => $total_length,
            'max_chunks' => $total_chunks,
            'log' => $log
        ], 200);
    }




    public static function read_post_in_chunks(WP_REST_Request $request)
    {
        // Recupera i parametri
        $post_id = intval($request->get_param('id'));
        $chunk_size = intval($request->get_param('chunk_size')) ?: 1000; // Dimensione del chunk (default 1000 caratteri)
        $chunk_index = intval($request->get_param('chunk_index')) ?: 0; // Indice del chunk da recuperare

        // Log di inizio metodo
        error_log("Inizio lettura del post in chunks - ID post: $post_id, chunk_size: $chunk_size, chunk_index: $chunk_index");

        // Verifica che il post esista
        $post = get_post($post_id);
        if (!$post) {
            // Log di errore nel caso in cui il post non esista
            error_log("Errore: Post con ID $post_id non trovato.");
            return new WP_REST_Response('Post non trovato', 404);
        }

        // Recupera il contenuto del post
        $post_content = get_post_field('post_content', $post_id);
        if (!$post_content) {
            // Log nel caso in cui non ci sia contenuto nel post
            error_log("Errore: Il post con ID $post_id non ha contenuto.");
            return new WP_REST_Response('Il post non ha contenuto', 404);
        }

        $total_length = strlen($post_content);
        // Log della lunghezza totale del contenuto
        error_log("Lunghezza totale del contenuto del post: $total_length caratteri.");

        // Calcola la posizione del chunk
        $start = $chunk_index * $chunk_size;
        $end = $start + $chunk_size;

        // Verifica se la posizione di inizio supera la lunghezza del contenuto
        if ($start >= $total_length) {
            // Log per indicare che non ci sono altri chunk da leggere
            error_log("Nessun altro contenuto da leggere. Chunk index: $chunk_index, totale chunk: " . ceil($total_length / $chunk_size));
            return new WP_REST_Response([
                'message' => 'Nessun altro contenuto da leggere.',
                'chunk_index' => $chunk_index,
                'total_chunks' => ceil($total_length / $chunk_size)
            ], 200);
        }

        // Estrai il chunk richiesto
        $chunk_content = substr($post_content, $start, $chunk_size);

        // Log del contenuto estratto
        error_log("Contenuto del chunk $chunk_index estratto con successo.");

        // Risposta con il chunk e le informazioni di stato
        return new WP_REST_Response([
            'chunk_content' => $chunk_content,
            'chunk_index' => $chunk_index,
            'total_chunks' => ceil($total_length / $chunk_size),
            'total_length' => $total_length
        ], 200);
    }




    public static function get_updates_telegram(WP_REST_Request $request)
    {
        $offset = sanitize_text_field($request->get_param('offset'));
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        $url = "https://api.telegram.org/bot" . $bot_token . "/getUpdates";
        $params = [
            'offset' => $offset,
        ];

        $response = wp_remote_post($url, [
            'body' => $params
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!$result || !$result['ok']) {
            return new WP_REST_Response(['error' => 'Failed to retrieve updates from Telegram.'], 500);
        }

        return new WP_REST_Response($result['result'], 200);
    }

    public static function handle_telegram_action(WP_REST_Request $request)
    {
        $action = sanitize_text_field($request->get_param('action'));

        switch ($action) {
            case 'send_message':
                $response = self::send_message_telegram($request);
                break;
            case 'forward_message':
                $response = self::forward_message_telegram($request);
                break;
            case 'send_photo':
                $response = self::send_photo_telegram($request);
                break;
            case 'send_document':
                $response = self::send_document_telegram($request);
                break;
            case 'get_chat_info':
                $response = self::get_chat_info_telegram($request);
                break;
            case 'get_updates':
                $response = self::get_updates_telegram($request);
                break;
            default:
                $response = new WP_REST_Response(['error' => 'Invalid action.'], 400);
        }

        if ($response instanceof WP_REST_Response) {
            $data = self::chunk_if_needed($response->get_data());
            return new WP_REST_Response($data, $response->get_status());
        }

        return $response;
    }

    private static function chunk_text($text, $length = 4000)
    {
        return str_split($text, $length);
    }

    private static function chunk_if_needed($data, $length = 4000)
    {
        if (is_string($data)) {
            return (strlen($data) > $length) ? ['chunks' => self::chunk_text($data, $length)] : $data;
        }

        $json = wp_json_encode($data);
        if (strlen($json) > $length) {
            return ['chunks' => self::chunk_text($json, $length)];
        }

        return $data;
    }

    public static function manage_mcp_server(WP_REST_Request $request)
    {
        $response = WP_GPT_MCP_Server_Manager::handle_request($request);

        if ($response instanceof WP_Error) {
            $data = $response->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

            return new WP_REST_Response([
                'success' => false,
                'code' => $response->get_error_code(),
                'message' => $response->get_error_message(),
                'data' => is_array($data) ? $data : [],
            ], $status);
        }

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            if (is_array($data) && !array_key_exists('success', $data)) {
                $data['success'] = true;
                $response->set_data($data);
            }

            return $response;
        }

        $ensured = rest_ensure_response($response);
        $data = $ensured->get_data();
        if (is_array($data) && !array_key_exists('success', $data)) {
            $data['success'] = true;
            $ensured->set_data($data);
        }

        return $ensured;
    }

    public static function validate_request_with_user_code_and_bearer(WP_REST_Request $request)
    {
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }

        $auth_header = get_bearer_token($request);
        if (empty($auth_header)) {
            return new WP_Error(
                'unauthorized',
                __('Authorization header with Bearer token is required.', 'wp-gpt-automation-pro'),
                ['status' => 401]
            );
        }

        return self::validate_request_with_user_code($request);
    }

    public static function validate_request_with_user_code(WP_REST_Request $request)
    {


        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $auth_header = get_bearer_token($request);

        // Log dell'header Authorization ricevuto solo se in debug
        if (defined('WP_DEBUG') && WP_DEBUG && $auth_header) {
            error_log('Authorization header received');
        }

        // Estrazione del token Bearer dall'header
        $received_token = $auth_header ? substr($auth_header, 7) : '';

        // Token salvato per confronto
        $saved_token = get_option('wp_gpt_api_token');

        // Parametri e user code
        $params = $request->get_json_params();
        $user_code = '';
        if (is_array($params) && array_key_exists('user_code', $params) && !is_array($params['user_code'])) {
            $user_code = sanitize_text_field($params['user_code']);
        } else {
            $user_code_param = $request->get_param('user_code');
            if (!is_array($user_code_param)) {
                $user_code = sanitize_text_field($user_code_param);
            }
        }
        $saved_user_code = get_option('wp_gpt_user_code');

        // Logga sempre il tentativo di autenticazione
        WP_GPT_Auth_Log::log($received_token, $saved_token, $user_code, $saved_user_code, $request->get_params());

        if (empty($received_token)) {
            error_log('Token non valido. Accesso negato.');
            return new WP_Error('unauthorized', __('Token non valido', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token);
        if (is_wp_error($token_validation)) {
            error_log('Token non valido. Accesso negato.');
            return $token_validation;
        }

        // Validazione dello user code
        if (empty($user_code) || $user_code !== $saved_user_code) {
            error_log('User code non valido o mancante. Accesso negato.');
            return new WP_Error('unauthorized', __('User code non valido o mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        error_log('Richiesta validata con successo');

        $debug_context = [
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'N/A',
            'http_x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A',
            'http_client_ip' => $_SERVER['HTTP_CLIENT_IP'] ?? 'N/A',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'user_code' => $user_code,
            'source' => __METHOD__,
            'timestamp' => current_time('timestamp'),
        ];

        // Debug server snapshot disabled in Free version.

        return true;
    }



    public static function create_post_with_user_code(WP_REST_Request $request)
    {
        error_log('Starting create_post_with_user_code API');

        $params = $request->get_json_params();
        if (!is_array($params) || empty($params)) {
            $params = $request->get_body_params();
        }

        if (!is_array($params)) {
            $params = [];
        }

        error_log('Parameters received: ' . json_encode($params));

        $raw_title = $params['title'] ?? $request->get_param('title');
        if (is_array($raw_title)) {
            $raw_title = '';
        }

        $raw_content = $params['content'] ?? $request->get_param('content');
        if (is_array($raw_content)) {
            $raw_content = '';
        }

        $title = sanitize_text_field((string) $raw_title);
        $content = (string) $raw_content;

        if ('' === $title || '' === $content) {
            return new WP_REST_Response(__('Titolo o contenuto mancante.', 'wp-gpt-automation-pro'), 400);
        }

        $lowest_admin_id = WP_GPT_User_Helper::get_lowest_admin_id();
        if (is_wp_error($lowest_admin_id)) {
            $error_code = $lowest_admin_id->get_error_code() ?: 'wp_gpt_missing_admin_user';
            $error_message = $lowest_admin_id->get_error_message() ?: __('Impossibile determinare un autore amministratore.', 'wp-gpt-automation-pro');
            $error_data = $lowest_admin_id->get_error_data();

            if (!is_array($error_data)) {
                $error_data = [];
            }

            if (!isset($error_data['status']) || !is_int($error_data['status'])) {
                $error_data['status'] = 500;
            }

            return new WP_Error($error_code, $error_message, $error_data);
        }

        $slug_param = '';
        if (array_key_exists('slug', $params) && !is_array($params['slug'])) {
            $slug_param = (string) $params['slug'];
        } else {
            $request_slug = $request->get_param('slug');
            if (!is_array($request_slug)) {
                $slug_param = (string) $request_slug;
            }
        }

        $base_slug = sanitize_title($slug_param);

        if ('' === $base_slug) {
            $base_slug = sanitize_title($title);
        }

        $post_status = isset($params['status']) && in_array($params['status'], ['publish', 'draft'], true)
            ? $params['status']
            : 'draft';

        $slug = '';
        if ('' !== $base_slug) {
            $slug = wp_unique_post_slug($base_slug, 0, $post_status, 'post', 0);
        }

        // Costruzione array per wp_insert_post
        $meta_input = [
            'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : '',
            'rank_math_description' => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : '',
            'rank_math_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '',
        ];

        if (isset($params['meta_input']) && is_array($params['meta_input'])) {
            $meta_input = array_merge($params['meta_input'], $meta_input);
        }

        $meta_input['wp_gpt_skipper_id'] = $lowest_admin_id;

        $post_args = [
            'post_title' => $title,
            'post_content' => wp_kses_post($content),
            'post_status' => $post_status,
            'post_type' => 'post',
            'post_author' => $lowest_admin_id,
            'post_category' => isset($params['categories']) ? array_map('intval', $params['categories']) : [],
            'tags_input' => isset($params['tags']) ? array_map('sanitize_text_field', $params['tags']) : [],
            'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
            'meta_input' => $meta_input,
        ];

        if ('' !== $slug) {
            $post_args['post_name'] = $slug;
        }

        // Crea il post
        $post_id = wp_insert_post($post_args);
        error_log('Post ID created: ' . $post_id);

        if (is_wp_error($post_id)) {
            error_log('Error creating post: ' . $post_id->get_error_message());
            return new WP_REST_Response($post_id->get_error_message(), 400);
        }

        // Gestione immagine in evidenza
        if (isset($params['featured_image'])) {
            self::set_featured_image($post_id, esc_url_raw($params['featured_image']));
        }

        error_log('Post creation completed successfully');
        $final_slug = get_post_field('post_name', $post_id);

        return new WP_REST_Response([
            'post_id' => $post_id,
            'final_slug' => $final_slug,
        ], 200);
    }


    public static function get_chat_info_telegram(WP_REST_Request $request)
    {
        $chat_id = sanitize_text_field($request->get_param('chat_id'));
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        $url = "https://api.telegram.org/bot" . $bot_token . "/getChat";
        $params = [
            'chat_id' => $chat_id,
        ];

        $response = wp_remote_post($url, [
            'body' => $params
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!$result || !$result['ok']) {
            return new WP_REST_Response(['error' => 'Failed to retrieve chat info from Telegram.'], 500);
        }

        return new WP_REST_Response($result['result'], 200);
    }

    public static function send_document_telegram(WP_REST_Request $request)
    {
        $chat_id = sanitize_text_field($request->get_param('chat_id'));
        $document_url = esc_url_raw($request->get_param('document_url'));
        $caption = sanitize_text_field($request->get_param('caption'));
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        $url = "https://api.telegram.org/bot" . $bot_token . "/sendDocument";
        $params = [
            'chat_id' => $chat_id,
            'document' => $document_url,
            'caption' => $caption
        ];

        $response = wp_remote_post($url, [
            'body' => $params
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!$result || !$result['ok']) {
            return new WP_REST_Response(['error' => 'Failed to send document to Telegram.'], 500);
        }

        return new WP_REST_Response(['message' => 'Document sent successfully to Telegram.'], 200);
    }

    public static function send_photo_telegram(WP_REST_Request $request)
    {
        $chat_id = sanitize_text_field($request->get_param('chat_id'));
        $photo_url = esc_url_raw($request->get_param('photo_url'));
        $caption = sanitize_text_field($request->get_param('caption'));
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        $url = "https://api.telegram.org/bot" . $bot_token . "/sendPhoto";
        $params = [
            'chat_id' => $chat_id,
            'photo' => $photo_url,
            'caption' => $caption
        ];

        $response = wp_remote_post($url, [
            'body' => $params
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!$result || !$result['ok']) {
            return new WP_REST_Response(['error' => 'Failed to send photo to Telegram.'], 500);
        }

        return new WP_REST_Response(['message' => 'Photo sent successfully to Telegram.'], 200);
    }

    public static function forward_message_telegram(WP_REST_Request $request)
    {
        $from_chat_id = sanitize_text_field($request->get_param('from_chat_id'));
        $message_id = sanitize_text_field($request->get_param('message_id'));
        $chat_ids = $request->get_param('chat_ids'); // Supponendo che questo sia un array di ID di chat
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        if (empty($chat_ids) || !is_array($chat_ids)) {
            return new WP_REST_Response(['error' => 'Invalid or missing chat_ids parameter.'], 400);
        }

        foreach ($chat_ids as $chat_id) {
            $url = "https://api.telegram.org/bot" . $bot_token . "/forwardMessage";
            $params = [
                'chat_id' => sanitize_text_field($chat_id),
                'from_chat_id' => $from_chat_id,
                'message_id' => $message_id
            ];

            $response = wp_remote_post($url, [
                'body' => $params
            ]);

            if (is_wp_error($response)) {
                return new WP_REST_Response(['error' => 'Failed to forward message to chat ID ' . $chat_id], 500);
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!$result || !$result['ok']) {
                return new WP_REST_Response(['error' => 'Failed to forward message to chat ID ' . $chat_id], 500);
            }
        }

        return new WP_REST_Response(['message' => 'Message forwarded successfully to all specified chat IDs.'], 200);
    }

    public static function send_message_telegram(WP_REST_Request $request)
    {
        $chat_id = sanitize_text_field($request->get_param('chat_id'));
        $message = sanitize_text_field($request->get_param('message'));
        $bot_token = get_option('wp_gpt_telegram_bot_token');

        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $chunks = self::chunk_text($message, 4096);

        foreach ($chunks as $chunk) {
            $params = [
                'chat_id' => $chat_id,
                'text' => $chunk
            ];

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Sending message to Telegram');
                error_log('Chat ID: ' . $chat_id);
                error_log('Message chunk: ' . $chunk);
            }

            $response = wp_remote_post($url, [
                'body' => $params
            ]);

            if (is_wp_error($response)) {
                error_log('Error sending message to Telegram: ' . $response->get_error_message());
                return new WP_REST_Response(['error' => $response->get_error_message()], 500);
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!$result || !$result['ok']) {
                error_log('Failed to send message to Telegram. Response: ' . $body);
                return new WP_REST_Response(['error' => 'Failed to send message to Telegram.'], 500);
            }
        }

        return new WP_REST_Response(['message' => 'Message sent successfully to Telegram.'], 200);
    }


    // Aggiungi questa funzione alla classe WP_GPT_API
/*
public static function insert_embed_video(WP_REST_Request $request) {
    // Recupera i parametri dalla richiesta
    $post_id = intval($request->get_param('post_id'));
    $video_url = esc_url_raw($request->get_param('video_url'));
    $video_title = sanitize_text_field($request->get_param('video_title'));
    $params = $request->get_json_params();

    // Verifica che il post esista
    if (!get_post($post_id)) {
        return new WP_REST_Response('Post non trovato', 404);
    }

    // Crea il contenuto con il titolo e lo shortcode [embed]
    $video_embed = "<h2>{$video_title}</h2>";
    $video_embed .= "[embed]{$video_url}[/embed]";

    // Recupera il contenuto corrente del post
    $current_content = get_post_field('post_content', $post_id);

    // Aggiunge il nuovo contenuto alla fine
    $updated_content = $current_content . "\n\n" . $video_embed;

    // Aggiorna il post con il nuovo contenuto
    wp_update_post([
        'ID' => $post_id,
        'post_content' => $updated_content,
    ]);

    return new WP_REST_Response(['post_id' => $post_id, 'updated' => true], 200);
}*/
    public static function insert_embed_video(WP_REST_Request $request)
    {
        // Recupera i parametri dalla richiesta
        $post_id = intval($request->get_param('post_id'));
        $video_url = esc_url_raw($request->get_param('video_url'));
        $video_title = sanitize_text_field($request->get_param('video_title'));

        // Verifica che il post esista
        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        // Modifica l'URL del video da "watch?v=" a "embed/"
        $embed_url = str_replace('watch?v=', 'embed/', $video_url);

        // Crea il contenuto con il titolo e l'iframe personalizzato per l'accessibilità e conformità con YouTube
        $video_embed = "<h2>{$video_title}</h2>";
        $video_embed .= '<iframe width="560" height="315" src="' . $embed_url . '" ';
        $video_embed .= 'title="' . $video_title . '" ';
        $video_embed .= 'role="document" ';
        $video_embed .= 'tabindex="0" ';
        $video_embed .= 'frameborder="0" ';
        $video_embed .= 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" ';
        $video_embed .= 'referrerpolicy="strict-origin-when-cross-origin" ';
        $video_embed .= 'allowfullscreen></iframe>';

        // Recupera il contenuto corrente del post
        $current_content = get_post_field('post_content', $post_id);

        $position = isset($params['position']) ? sanitize_text_field($params['position']) : 'end';
        $reference_h2 = isset($params['reference_h2']) ? sanitize_text_field($params['reference_h2']) : '';

        if (in_array($position, ['before', 'after'], true) && $reference_h2) {
            $pattern = '/<h2[^>]*>\s*' . preg_quote($reference_h2, '/') . '\s*<\/h2>/i';
            if (preg_match($pattern, $current_content, $matches, PREG_OFFSET_CAPTURE)) {
                $match = $matches[0];
                if ($position === 'before') {
                    $updated_content = substr($current_content, 0, $match[1]) . $video_embed . "\n\n" . substr($current_content, $match[1]);
                } else {
                    $offset = $match[1] + strlen($match[0]);
                    $updated_content = substr($current_content, 0, $offset) . "\n\n" . $video_embed . substr($current_content, $offset);
                }
            } else {
                $updated_content = $current_content . "\n\n" . $video_embed;
            }
        } elseif ($position === 'start') {
            $updated_content = $video_embed . "\n\n" . $current_content;
        } else {
            $updated_content = $current_content . "\n\n" . $video_embed;
        }

        // Aggiorna il post con il nuovo contenuto
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $updated_content,
        ]);

        return new WP_REST_Response(['post_id' => $post_id, 'updated' => true], 200);
    }



    // Aggiungi la registrazione della rotta nel metodo register_routes



    // Funzione per cercare i video su YouTube
    public static function search_youtube_video(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $query = $params['query'] ?? ''; // Assumiamo che il parametro 'query' contenga la query di ricerca per YouTube

        if (empty($query)) {
            error_log('search_youtube_video: Query parameter is required');
            return new WP_REST_Response(['error' => 'Query parameter is required'], 400);
        }

        // Recupera la chiave API di YouTube dalle opzioni di WordPress
        $youtube_api_key = get_option('wp_gpt_youtube_api_key');

        // Configura la URL per la ricerca dei video
        $search_url = 'https://yt-api.p.rapidapi.com/search?query=' . urlencode($query);

        // Configurazione dell'header per la richiesta API
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'X-RapidAPI-Host: yt-api.p.rapidapi.com',
                    'X-RapidAPI-Key: ' . $youtube_api_key,
                ],
            ],
        ];

        error_log('search_youtube_video: Sending search request to URL: ' . $search_url);

        // Esegui la richiesta di ricerca
        $context = stream_context_create($options);
        $search_result = file_get_contents($search_url, false, $context);

        if ($search_result === FALSE) {
            error_log('search_youtube_video: Unable to fetch data from YouTube API');
            return new WP_REST_Response(['error' => 'Unable to fetch data from YouTube API'], 500);
        }

        $search_data = json_decode($search_result, true);

        if ($search_data === NULL) {
            error_log('search_youtube_video: JSON decode failed - ' . json_last_error_msg());
            return new WP_REST_Response(['error' => 'Invalid JSON response from API'], 500);
        }

        if (!isset($search_data['data'])) {
            error_log('search_youtube_video: No data found in search response');
            return new WP_REST_Response(['error' => 'No data found in search response'], 404);
        }

        // Estrai i primi 5 videoId dalla ricerca
        $video_ids = [];
        foreach ($search_data['data'] as $key => $video) {
            if ($key >= 5)
                break;
            if (isset($video['videoId'])) {
                $video_ids[] = $video['videoId'];
            }
        }

        if (empty($video_ids)) {
            error_log('search_youtube_video: No video IDs found in search response');
            return new WP_REST_Response(['error' => 'No video IDs found in search response'], 404);
        }

        $videos_info = [];

        // Per ogni videoId, fare una richiesta separata per ottenere il link del video
        foreach ($video_ids as $video_id) {
            $dl_url = 'https://yt-api.p.rapidapi.com/dl?id=' . urlencode($video_id);
            error_log('search_youtube_video: Sending request for video URL to: ' . $dl_url);

            $dl_result = file_get_contents($dl_url, false, $context);

            if ($dl_result === FALSE) {
                error_log('search_youtube_video: Unable to fetch video URL for video ID: ' . $video_id);
                continue;
            }

            $dl_data = json_decode($dl_result, true);

            if ($dl_data === NULL) {
                error_log('search_youtube_video: JSON decode failed for video ID ' . $video_id . ' - ' . json_last_error_msg());
                continue;
            }

            if (isset($dl_data['formats'])) {
                $max_format = null;
                $max_value = 0;

                foreach ($dl_data['formats'] as $format) {
                    if (isset($format['bitrate']) && $format['bitrate'] > $max_value) {
                        $max_value = $format['bitrate'];
                        $max_format = $format;
                    }
                }

                if ($max_format && isset($max_format['url'])) {
                    $videos_info[] = [
                        'videoId' => $video_id,
                        'title' => $dl_data['title'] ?? 'No title available',
                        'description' => $dl_data['description'] ?? 'No description available',
                        'video_url' => $max_format['url']
                    ];
                } else {
                    error_log('search_youtube_video: No valid format with URL found for video ID ' . $video_id);
                }
            } else {
                error_log('search_youtube_video: Formats not found in response for video ID ' . $video_id);
            }
        }

        if (empty($videos_info)) {
            return new WP_REST_Response(['error' => 'No valid videos found'], 404);
        }

        return new WP_REST_Response($videos_info, 200);
    }


    public static function extract_image_links(WP_REST_Request $request)
    {
        // Recupera la keyword dal parametro della richiesta
        $keyword = $request->get_param('keyword');

        // Normalizza la keyword: sostituisce gli spazi e i caratteri non alfanumerici con un underscore
        $normalized_keyword = preg_replace('/[^a-zA-Z0-9]/', '_', $keyword);

        // Codifica la keyword per essere utilizzata nell'URL
        $encoded_keyword = urlencode($normalized_keyword);

        // Costruisce l'URL per la ricerca delle immagini
        $search_url = "https://commons.wikimedia.org/w/api.php?action=query&list=allimages&aiprefix=$encoded_keyword&ailimit=10&format=json";

        error_log("Tentativo di accesso a $search_url");
        $response = self::get_html_with_user_agent($search_url);

        if ($response === FALSE) {
            error_log("Errore durante il recupero della pagina da $search_url");
            return new WP_REST_Response('Errore durante il recupero della pagina', 500);
        }

        $data = json_decode($response, true);
        $image_links = [];

        if (isset($data['query']['allimages'])) {
            foreach ($data['query']['allimages'] as $image) {
                if (isset($image['url'])) {
                    $image_links[] = $image['url'];
                }
            }
        }

        error_log("Numero di immagini trovate: " . count($image_links));

        if (empty($image_links)) {
            error_log('Nessuna immagine trovata per la parola chiave data');
            return new WP_REST_Response('Nessuna immagine trovata', 404);
        }

        return new WP_REST_Response(['image_links' => $image_links], 200);
    }


    public static function get_html_with_user_agent($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);
        return $output;
    }

    public static function validate_request(WP_REST_Request $request)
    {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $auth_header = get_bearer_token($request);

        if (defined('WP_DEBUG') && WP_DEBUG && $auth_header) {
            error_log('Authorization header received');
        }

        // Estrarre il token dall'header "Authorization"
        $received_token = $auth_header ? substr($auth_header, 7) : '';

        // Recupera il token salvato nel sistema
        $saved_token = get_option('wp_gpt_api_token');

        // Log dettagliato solo in debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Tokens compared for validation');
        }

        // Controllo del token Bearer
        if (empty($received_token)) {
            error_log('Token mancante nella richiesta');
            return new WP_Error('unauthorized', __('Token mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token);
        if (is_wp_error($token_validation)) {
            error_log('Token non valido. Accesso negato.');
            return $token_validation;
        }

        // Verifica dello User Agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('User Agent received: ' . $user_agent);
        }
        /*
            if (strpos($user_agent, 'ChatGPT-User') === false || strpos($user_agent, 'https://openai.com/bot') === false) {
                error_log('User Agent non valido. Accesso negato.');
                return new WP_Error('unauthorized', __('User Agent non valido', 'wp-gpt-automation-pro'), ['status' => 403]);
            }*/

        error_log('Richiesta validata con successo');
        return true;
    }

    public static function validate_request_no_agent(WP_REST_Request $request)
    {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $auth_header = get_bearer_token($request);

        if (defined('WP_DEBUG') && WP_DEBUG && $auth_header) {
            error_log('Authorization header received');
        }

        // Extract token from "Authorization" header
        $received_token = $auth_header ? substr($auth_header, 7) : '';

        // Retrieve saved token from system
        $saved_token = get_option('wp_gpt_api_token');

        // Detailed logging solo in debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Tokens compared for validation');
        }

        // Bearer token check
        if (empty($received_token)) {
            error_log('Token missing in request');
            return new WP_Error('unauthorized', __('Token missing', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token, [
            'error_message' => __('Invalid token', 'wp-gpt-automation-pro'),
        ]);
        if (is_wp_error($token_validation)) {
            error_log('Invalid token. Access denied.');
            return $token_validation;
        }

        // Removed User Agent and Domain Verification

        error_log('Request validated successfully');
        return true;
    }

    public static function validate_request_2(WP_REST_Request $request)
    {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $auth_header = get_bearer_token($request);

        if (defined('WP_DEBUG') && WP_DEBUG && $auth_header) {
            error_log('Authorization header received');
        }

        $received_token = $auth_header ? substr($auth_header, 7) : '';

        $saved_token = get_option('wp_gpt_api_token');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Tokens compared for validation');
        }

        if (empty($received_token)) {
            error_log('Token mancante nella richiesta');
            return new WP_Error('unauthorized', __('Token mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token);
        if (is_wp_error($token_validation)) {
            error_log('Token non valido. Accesso negato.');
            return $token_validation;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('User Agent received: ' . $user_agent);
        }

        if (strpos($user_agent, 'ChatGPT-User') === false || strpos($user_agent, 'https://openai.com/bot') === false) {
            error_log('User Agent non valido. Accesso negato.');
            return new WP_Error('unauthorized', __('User Agent non valido', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        error_log('Richiesta validata con successo');
        return true;
    }

    public static function create_post(WP_REST_Request $request)
    {
        error_log('Starting create_post API');

        $params = $request->get_json_params();
        error_log('Parameters received: ' . json_encode($params));

        $lowest_admin_id = WP_GPT_User_Helper::get_lowest_admin_id();
        if (is_wp_error($lowest_admin_id)) {
            $error_code = $lowest_admin_id->get_error_code() ?: 'wp_gpt_missing_admin_user';
            $error_message = $lowest_admin_id->get_error_message() ?: __('Impossibile determinare un autore amministratore.', 'wp-gpt-automation-pro');
            $error_data = $lowest_admin_id->get_error_data();

            if (!is_array($error_data)) {
                $error_data = [];
            }

            if (!isset($error_data['status']) || !is_int($error_data['status'])) {
                $error_data['status'] = 500;
            }

            return new WP_Error($error_code, $error_message, $error_data);
        }

        $meta_input = [
            'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : '',
            'rank_math_description' => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : '',
            'rank_math_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '',
        ];

        if (isset($params['meta_input']) && is_array($params['meta_input'])) {
            $meta_input = array_merge($params['meta_input'], $meta_input);
        }

        $meta_input['wp_gpt_skipper_id'] = $lowest_admin_id;

        $post_args = [
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status' => in_array($params['status'], ['publish', 'draft']) ? $params['status'] : 'draft',
            'post_author' => $lowest_admin_id,
            'post_category' => isset($params['categories']) ? array_map('intval', $params['categories']) : [],
            'tags_input' => isset($params['tags']) ? array_map('sanitize_text_field', $params['tags']) : [],
            'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
            'meta_input' => $meta_input,
        ];

        $post_id = wp_insert_post($post_args);
        error_log('Post ID created: ' . $post_id);

        if (is_wp_error($post_id)) {
            error_log('Error creating post: ' . $post_id->get_error_message());
            return new WP_REST_Response($post_id->get_error_message(), 400);
        }

        // Gestione immagine in evidenza, se fornita
        if (isset($params['featured_image'])) {
            self::set_featured_image($post_id, esc_url_raw($params['featured_image']));
        }

        error_log('Post creation completed successfully');
        return new WP_REST_Response(['post_id' => $post_id], 200);
    }
    public static function update_post(WP_REST_Request $request)
    {
        $post_id = intval($request->get_param('id'));
        $params = $request->get_json_params();

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        $post_args = [
            'ID' => $post_id,
            'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : get_the_title($post_id),
            'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : get_post_field('post_content', $post_id),
            'post_status' => isset($params['status']) && in_array($params['status'], ['publish', 'draft']) ? $params['status'] : get_post_status($post_id),
            'post_category' => isset($params['categories']) ? array_map('intval', $params['categories']) : wp_get_post_categories($post_id),
            'tags_input' => isset($params['tags']) ? array_map('sanitize_text_field', $params['tags']) : wp_get_post_tags($post_id, ['fields' => 'names']),
            'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : get_the_excerpt($post_id),
            'meta_input' => [
                'rank_math_focus_keyword' => isset($params['focus_keywords']) ? sanitize_text_field($params['focus_keywords']) : get_post_meta($post_id, 'rank_math_focus_keyword', true),
                'rank_math_description' => isset($params['meta_description']) ? sanitize_text_field($params['meta_description']) : get_post_meta($post_id, 'rank_math_description', true),
                'rank_math_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : get_post_meta($post_id, 'rank_math_title', true),
            ],
        ];

        $updated_post_id = wp_update_post($post_args);

        if (is_wp_error($updated_post_id)) {
            return new WP_REST_Response($updated_post_id->get_error_message(), 400);
        }

        // Gestione immagine in evidenza, se fornita
        if (isset($params['featured_image'])) {
            self::set_featured_image($post_id, esc_url_raw($params['featured_image']));
        }

        return new WP_REST_Response(['post_id' => $updated_post_id], 200);
    }






    /*
    public static function insert_image(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $post_id = intval($params['post_id']);
        $image_url = esc_url_raw($params['image_url']);

        if (!get_post($post_id)) {
            error_log('Post non trovato: ' . $post_id);
            return new WP_REST_Response('Post non trovato', 404);
        }

        // Scarica e imposta l'immagine come immagine in evidenza
        $image_id = self::set_featured_image($post_id, $image_url);

        if (is_wp_error($image_id)) {
            error_log('Errore nel caricamento dell\'immagine: ' . $image_id->get_error_message());
            return new WP_REST_Response($image_id->get_error_message(), 500);
        }

        error_log('Immagine inserita correttamente, ID: ' . $image_id);
        return new WP_REST_Response(['post_id' => $post_id, 'image_id' => $image_id], 200);
    }

    private static function set_featured_image($post_id, $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Scarica l'immagine e ottieni l'ID dell'allegato
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');

        if (is_wp_error($image_id)) {
            error_log('Errore nel caricamento dell\'immagine: ' . $image_id->get_error_message());
            return $image_id;
        }

        // Imposta l'immagine come immagine in evidenza
        set_post_thumbnail($post_id, $image_id);
        return $image_id;
    }*/



    public static function seo_optimize(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $post_id = intval($params['post_id']);

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        WP_GPT_SEO::optimize_post($post_id, $params['seo']);

        return new WP_REST_Response(['optimized' => true], 200);
    }

    public static function humanize_text(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            return new WP_REST_Response('Invalid post ID', 404);
        }

        $content = get_post_field('post_content', $post_id);
        $mode = get_post_meta($post_id, '_gpt_humanizer_mode', true) ?: 'balanced';
        $word_limit = get_option('wp_gpt_hix_word_limit', 2000);

        $humanized_content = self::invoke_hix_humanization($content, $mode, $word_limit);

        if ($humanized_content === false) {
            return new WP_REST_Response('Humanization failed', 500);
        }

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $humanized_content,
        ]);

        return new WP_REST_Response(['post_id' => $post_id, 'humanized' => true], 200);
    }


    public static function update_seo_title(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $seo_title = sanitize_text_field($request->get_param('seo_title'));

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        update_post_meta($post_id, 'rank_math_title', $seo_title);
        return new WP_REST_Response(['updated' => true], 200);
    }

    // API per aggiornare la meta description
    public static function update_meta_description(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $meta_description = sanitize_text_field($request->get_param('meta_description'));

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        update_post_meta($post_id, 'rank_math_description', $meta_description);
        return new WP_REST_Response(['updated' => true], 200);
    }

    // API per aggiornare le focus keywords
    public static function update_focus_keywords(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $focus_keywords = sanitize_text_field($request->get_param('focus_keywords'));

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keywords);
        return new WP_REST_Response(['updated' => true], 200);
    }

    // API per aggiornare lo slug
    public static function update_slug(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $slug = sanitize_title($request->get_param('slug'));

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        wp_update_post(['ID' => $post_id, 'post_name' => $slug]);
        return new WP_REST_Response(['updated' => true], 200);
    }

    // API per leggere il contenuto di un post
    public static function get_post(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        $post = get_post($post_id);
        $post_link = get_permalink($post_id); // Ottieni il permalink del post

        // Costruisci la risposta personalizzata
        $response = [
            'ID' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'author' => $post->post_author,
            'link' => $post_link, // Aggiungi il permalink alla risposta
            // Puoi aggiungere altri campi se necessario
        ];

        return new WP_REST_Response($response, 200);
    }


    public static function append_content(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('id');
        $params = $request->get_json_params() ?: [];

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        if (empty($params['content'])) {
            return new WP_REST_Response('Parametro content mancante', 400);
        }

        $position = isset($params['position']) ? sanitize_text_field($params['position']) : 'end';
        $allowed_positions = ['start', 'end', 'before', 'after'];
        if (!in_array($position, $allowed_positions, true)) {
            $position = 'end';
        }

        $h2_number = null;
        if (isset($params['h2_number'])) {
            $h2_number = intval($params['h2_number']);
        } elseif (isset($params['h2_index'])) { // Mantiene la compatibilità con eventuali richieste precedenti
            $h2_number = intval($params['h2_index']);
        }

        if (in_array($position, ['before', 'after'], true) && (!$h2_number || $h2_number < 1)) {
            return new WP_REST_Response('Parametro h2_number mancante o non valido', 400);
        }

        remove_filter('content_save_pre', 'wp_filter_post_kses');
        remove_filter('content_filtered_save_pre', 'wp_filter_post_kses');

        try {
            // Definisci i tag consentiti, inclusi gli iframe
            $allowed_tags = [
                'iframe' => [
                    'src' => true,
                    'width' => true,
                    'height' => true,
                    'frameborder' => true,
                    'allow' => true,
                    'allowfullscreen' => true,
                    'title' => true,
                ],
                'a' => [
                    'src' => true,
                    'href' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'p' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'img' => [
                    'src' => true,
                    'alt' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'h2' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'h3' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'strong' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'i' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'em' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'embed' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'title' => true,
                ],
                'script' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'onclick' => true,
                    'data' => true,
                ],
                'li' => [
                    'id' => true,
                    'class' => true,
                    'width' => true,
                    'height' => true,
                    'onclick' => true,
                    'data' => true,
                ],
            ];

            // Applica i tag consentiti utilizzando wp_kses
            $new_content = wp_kses($params['content'], $allowed_tags);
            $current_content = get_post_field('post_content', $post_id);

            if (in_array($position, ['before', 'after'], true)) {
                $pattern = '/<h2\\b[^>]*>.*?<\\/h2>/is';
                if (!preg_match_all($pattern, $current_content, $matches, PREG_OFFSET_CAPTURE)) {
                    return new WP_REST_Response('Nessun tag H2 trovato nel contenuto', 404);
                }

                $target_index = $h2_number - 1;
                if (!isset($matches[0][$target_index])) {
                    return new WP_REST_Response('H2 specificato non trovato', 404);
                }

                $match = $matches[0][$target_index];
                if ($position === 'before') {
                    $updated_content = substr($current_content, 0, $match[1]) . $new_content . "\n\n" . substr($current_content, $match[1]);
                } else {
                    $offset = $match[1] + strlen($match[0]);
                    $updated_content = substr($current_content, 0, $offset) . "\n\n" . $new_content . substr($current_content, $offset);
                }
            } elseif ($position === 'start') {
                $updated_content = $new_content . "\n\n" . $current_content;
            } else {
                $updated_content = $current_content . "\n\n" . $new_content;
            }

            wp_update_post([
                'ID' => $post_id,
                'post_content' => $updated_content,
            ]);

            return new WP_REST_Response(['post_id' => $post_id, 'updated' => true], 200);
        } finally {
            add_filter('content_save_pre', 'wp_filter_post_kses');
            add_filter('content_filtered_save_pre', 'wp_filter_post_kses');
        }
    }

    public static function append_paragraph(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $params = $request->get_json_params();

        if (!get_post($post_id)) {
            return new WP_REST_Response('Post non trovato', 404);
        }

        if (empty($params['paragraph'])) {
            return new WP_REST_Response('Parametro paragraph mancante', 400);
        }

        $paragraph = wp_kses_post($params['paragraph']);
        $new_content = '<p>' . $paragraph . '</p>';
        $current_content = get_post_field('post_content', $post_id);

        $position = isset($params['position']) ? sanitize_text_field($params['position']) : 'end';
        $reference_h2 = isset($params['reference_h2']) ? sanitize_text_field($params['reference_h2']) : '';

        if (in_array($position, ['before', 'after'], true) && $reference_h2) {
            $pattern = '/<h2[^>]*>\s*' . preg_quote($reference_h2, '/') . '\s*<\/h2>/i';
            if (preg_match($pattern, $current_content, $matches, PREG_OFFSET_CAPTURE)) {
                $match = $matches[0];
                if ($position === 'before') {
                    $updated_content = substr($current_content, 0, $match[1]) . $new_content . "\n\n" . substr($current_content, $match[1]);
                } else {
                    $offset = $match[1] + strlen($match[0]);
                    $updated_content = substr($current_content, 0, $offset) . "\n\n" . $new_content . substr($current_content, $offset);
                }
            } else {
                $updated_content = $current_content . "\n\n" . $new_content;
            }
        } elseif ($position === 'start') {
            $updated_content = $new_content . "\n\n" . $current_content;
        } else {
            $updated_content = $current_content . "\n\n" . $new_content;
        }

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $updated_content,
        ]);

        return new WP_REST_Response(['post_id' => $post_id, 'updated' => true], 200);
    }

    public static function replace_txt_in_post(WP_REST_Request $request)
    {
        $log = [];  // Array per memorizzare i log

        $post_id = $request->get_param('id');
        $old_html = $request->get_param('old_html');
        $new_html = $request->get_param('new_html');
        $occurrence_index = $request->get_param('occurrence_index');

        $log[] = 'replace_txt_in_post invoked';
        $log[] = 'Post ID: ' . $post_id;
        $log[] = 'Old HTML: ' . $old_html;
        $log[] = 'New HTML: ' . $new_html;
        $log[] = 'Occurrence Index: ' . $occurrence_index;

        // Controlla se il post esiste
        if (!get_post($post_id)) {
            $log[] = 'Errore: Post non trovato';
            return new WP_REST_Response(['log' => $log, 'message' => 'Post non trovato'], 404);
        }

        // Recupera il contenuto del post
        $post_content = get_post_field('post_content', $post_id);
        $log[] = 'Contenuto del post: ' . substr($post_content, 0, 100) . '...';  // Mostra solo i primi 100 caratteri per sicurezza

        // Verifica che old_html e new_html non siano vuoti
        if (empty($old_html) || empty($new_html)) {
            $log[] = 'Errore: old_html o new_html non possono essere vuoti';
            return new WP_REST_Response(['log' => $log, 'message' => 'old_html o new_html non possono essere vuoti'], 400);
        }

        // Trova tutte le occorrenze del vecchio HTML
        $occurrences = [];
        $offset = 0;
        while (($pos = strpos($post_content, $old_html, $offset)) !== false) {
            $occurrences[] = [
                'context' => substr($post_content, max($pos - 30, 0), strlen($old_html) + 60), // Contesto intorno all'HTML trovato
                'position' => $pos,
            ];
            $log[] = "Trovata occorrenza alla posizione: $pos";
            $offset = $pos + strlen($old_html);
        }

        $total_occurrences = count($occurrences);
        $log[] = "Numero totale di occorrenze trovate: $total_occurrences";

        // Gestione degli errori per il numero di occorrenze
        if ($total_occurrences === 0) {
            $log[] = 'Errore: Nessuna occorrenza trovata';
            return new WP_REST_Response(['log' => $log, 'message' => 'Nessuna occorrenza trovata.'], 404);
        } elseif ($total_occurrences > 1 && ($occurrence_index === null || !is_numeric($occurrence_index) || !isset($occurrences[$occurrence_index]))) {
            // Occorrenze multiple, ma l'indice non è valido
            $log[] = 'Errore: Indice di occorrenza non valido';
            return new WP_REST_Response([
                'log' => $log,
                'message' => 'Sono state trovate più occorrenze. Specificare un occurrence_index valido.',
                'total_occurrences' => $total_occurrences,
                'occurrences' => $occurrences
            ], 300);
        }

        // Sostituzione dell'occorrenza corretta
        $target_index = ($total_occurrences === 1) ? 0 : $occurrence_index;
        $log[] = "Sostituzione dell'occorrenza all'indice: $target_index";

        $post_content = substr_replace($post_content, $new_html, $occurrences[$target_index]['position'], strlen($old_html));

        // Aggiorna il contenuto del post
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $post_content
        ]);

        $log[] = 'Contenuto del post aggiornato con successo';
        return new WP_REST_Response(['log' => $log, 'post_id' => $post_id, 'updated' => true], 200);
    }
    public static function replace_html_in_post(WP_REST_Request $request)
    {
        $log = [];  // Array per memorizzare i log

        $post_id = $request->get_param('id');
        $old_html = $request->get_param('old_html');
        $new_html = $request->get_param('new_html');
        $occurrence_index = $request->get_param('occurrence_index');

        $log[] = 'replace_html_in_post invoked';
        $log[] = 'Post ID: ' . $post_id;
        $log[] = 'Old HTML: ' . $old_html;
        $log[] = 'New HTML: ' . $new_html;
        $log[] = 'Occurrence Index: ' . $occurrence_index;

        // Controlla se il post esiste
        if (!get_post($post_id)) {
            $log[] = 'Errore: Post non trovato';
            return new WP_REST_Response(['log' => $log, 'message' => 'Post non trovato'], 404);
        }

        // Recupera il contenuto del post
        $post_content = get_post_field('post_content', $post_id);
        $log[] = 'Contenuto del post: ' . substr($post_content, 0, 100) . '...';  // Mostra solo i primi 100 caratteri per sicurezza

        // Verifica che old_html e new_html non siano vuoti
        if (empty($old_html) || empty($new_html)) {
            $log[] = 'Errore: old_html o new_html non possono essere vuoti';
            return new WP_REST_Response(['log' => $log, 'message' => 'old_html o new_html non possono essere vuoti'], 400);
        }

        // Trova tutte le occorrenze del vecchio HTML
        $occurrences = [];
        $offset = 0;
        while (($pos = strpos($post_content, $old_html, $offset)) !== false) {
            $occurrences[] = [
                'context' => substr($post_content, max($pos - 30, 0), strlen($old_html) + 60), // Contesto intorno all'HTML trovato
                'position' => $pos,
            ];
            $log[] = "Trovata occorrenza alla posizione: $pos";
            $offset = $pos + strlen($old_html);
        }

        $total_occurrences = count($occurrences);
        $log[] = "Numero totale di occorrenze trovate: $total_occurrences";

        // Gestione degli errori per il numero di occorrenze
        if ($total_occurrences === 0) {
            $log[] = 'Errore: Nessuna occorrenza trovata';
            return new WP_REST_Response(['log' => $log, 'message' => 'Nessuna occorrenza trovata.'], 404);
        } elseif ($total_occurrences > 1 && ($occurrence_index === null || !is_numeric($occurrence_index) || !isset($occurrences[$occurrence_index]))) {
            $log[] = 'Errore: Indice di occorrenza non valido';
            return new WP_REST_Response([
                'log' => $log,
                'message' => 'Sono state trovate più occorrenze. Specificare un occurrence_index valido.',
                'total_occurrences' => $total_occurrences,
                'occurrences' => $occurrences
            ], 300);
        }

        // Sostituzione dell'occorrenza corretta
        $target_index = ($total_occurrences === 1) ? 0 : $occurrence_index;
        $log[] = "Sostituzione dell'occorrenza all'indice: $target_index";

        $post_content = substr_replace($post_content, $new_html, $occurrences[$target_index]['position'], strlen($old_html));

        // Aggiorna il contenuto del post
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $post_content
        ]);

        $log[] = 'Contenuto del post aggiornato con successo';
        return new WP_REST_Response(['log' => $log, 'post_id' => $post_id, 'updated' => true], 200);
    }


    /*

    private static function set_featured_image($post_id, $image_url) {
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');
        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }
    }*/

    private static function invoke_hix_humanization($text, $mode, $word_limit)
    {
        $api_key = get_option('wp_gpt_hix_api_key');
        $endpoint = get_option('wp_gpt_hix_endpoint', 'https://bypass.hix.ai/humanize-ai');

        $response = wp_remote_post($endpoint, [
            'body' => json_encode([
                'text' => $text,
                'mode' => $mode,
                'word_limit' => $word_limit,
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        return $result['humanized_text'] ?? false;
    }
}

WP_GPT_API::init();
