<?php

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) {
    exit;
}

class WP_GPT_Guide_Categories {
    private const FILE_NAME = 'gpt-route-categories.json';

    /**
     * Returns a list of candidate storage paths for the categories file.
     *
     * The uploads directory is preferred so that the plugin does not need to
     * write inside its own folder (which is often read-only on production
     * servers). The legacy assets path is kept as a fallback for backwards
     * compatibility and for environments where the uploads directory is not
     * available.
     *
     * @return array
     */
    private static function get_storage_paths() {
        $paths = [];

        $uploads_path = self::get_uploads_file_path();
        if ('' !== $uploads_path) {
            $paths[] = $uploads_path;
        }

        $legacy_path = trailingslashit(WP_GPT_PLUGIN_DIR . 'assets') . self::FILE_NAME;
        if (!in_array($legacy_path, $paths, true)) {
            $paths[] = $legacy_path;
        }

        return $paths;
    }

    /**
     * Returns the categories file path inside the uploads directory.
     *
     * @return string
     */
    private static function get_uploads_file_path() {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }

        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }

        $directory = trailingslashit($uploads['basedir']) . 'wp-gpt-automation-pro';

        return trailingslashit($directory) . self::FILE_NAME;
    }

    /**
     * Ensures the WordPress filesystem API is initialised.
     *
     * @return \WP_Filesystem_Base|null
     */
    private static function get_wp_filesystem() {
        global $wp_filesystem;

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return $wp_filesystem;
        }

        if (!function_exists('WP_Filesystem')) {
            if (defined('ABSPATH')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            } else {
                return null;
            }
        }

        if (!function_exists('WP_Filesystem')) {
            return null;
        }

        if (!WP_Filesystem()) {
            return null;
        }

        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }

    /**
     * Returns the absolute path of the categories JSON file.
     *
     * @return string
     */
    public static function get_file_path() {
        $paths = self::get_storage_paths();

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $paths[0];
    }

    /**
     * Returns the default categories configuration.
     *
     * @param array $operation_ids Optional list of operationIds to assign to the Premium Full category.
     *
     * @return array
     */
    public static function get_default_categories(array $operation_ids = []) {
        $operation_ids = array_values(array_unique(array_filter(array_map('strval', $operation_ids))));

        return [
            [
                'name'         => 'Free',
                'active'       => true,
                'operationIds' => $operation_ids,
            ],
            [
                'name'         => 'BookWriter',
                'active'       => false,
                'operationIds' => [],
            ],
            [
                'name'         => 'E-Commerce',
                'active'       => false,
                'operationIds' => [],
            ],
            [
                'name'         => 'Marketer',
                'active'       => false,
                'operationIds' => [],
            ],
            [
                'name'         => 'Blog',
                'active'       => false,
                'operationIds' => [],
            ],
            [
                'name'         => 'Custom',
                'active'       => false,
                'operationIds' => [],
            ],
        ];
    }

    /**
     * Loads the categories configuration from disk.
     * If the file does not exist or is invalid, a default configuration is generated.
     *
     * @param array $operation_ids Optional list of valid operationIds.
     *
     * @return array
     */
    public static function load_categories(array $operation_ids = []) {
        $file_path = self::get_file_path();
        $operation_lookup = [];

        if ($operation_ids) {
            $operation_lookup = array_fill_keys(array_map('strval', $operation_ids), true);
        }

        if (!file_exists($file_path)) {
            $defaults = self::get_default_categories($operation_ids);
            self::save_categories($defaults);

            return $defaults;
        }

        $raw = file_get_contents($file_path);
        if (false === $raw) {
            return self::get_default_categories($operation_ids);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $defaults = self::get_default_categories($operation_ids);
            self::save_categories($defaults);

            return $defaults;
        }

        $categories = [];

        foreach ($decoded as $category) {
            if (!is_array($category)) {
                continue;
            }

            $name = isset($category['name']) ? (string) $category['name'] : '';
            if ('' === $name) {
                continue;
            }

            $active = !empty($category['active']);
            $category_operation_ids = [];

            if (!empty($category['operationIds']) && is_array($category['operationIds'])) {
                foreach ($category['operationIds'] as $operation_id) {
                    $operation_id = (string) $operation_id;
                    if ('' === $operation_id) {
                        continue;
                    }
                    if ($operation_lookup && !isset($operation_lookup[$operation_id])) {
                        continue;
                    }
                    $category_operation_ids[$operation_id] = true;
                }
            }

            $categories[] = [
                'name'         => $name,
                'active'       => $active,
                'operationIds' => array_keys($category_operation_ids),
            ];
        }

        if (empty($categories)) {
            $defaults = self::get_default_categories($operation_ids);
            self::save_categories($defaults);

            return $defaults;
        }

        return $categories;
    }

    /**
     * Persists the categories configuration to disk.
     *
     * @param array $categories Categories data to store.
     *
     * @return bool True on success, false on failure.
     */
    public static function save_categories(array $categories) {
        $payload = wp_json_encode(array_values($categories), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (false === $payload) {
            return false;
        }

        $filesystem = self::get_wp_filesystem();
        $chmod      = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false;

        foreach (self::get_storage_paths() as $file_path) {
            $directory = dirname($file_path);

            if (!is_dir($directory)) {
                wp_mkdir_p($directory);
            }

            if ($filesystem instanceof \WP_Filesystem_Base && $filesystem->put_contents($file_path, $payload, $chmod)) {
                return true;
            }

            if (false !== @file_put_contents($file_path, $payload)) {
                return true;
            }
        }

        return false;
    }
}
