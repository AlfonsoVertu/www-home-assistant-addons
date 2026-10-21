<?php

namespace WP_GPT_Automation_Pro;

class WP_GPT_Generate_Schema {
    /**
     * Output the OpenAPI schema. If no filter is provided, the entire schema is printed.
     * When a list of operation keys (path|method) is supplied, only those operations are included.
     *
     * @param array $operations_filter Optional list of operations to include.
     * @return void
     */
    public static function output_schema_yaml(array $operations_filter = []) {
        $yaml_file = WP_GPT_PLUGIN_DIR . 'docs/rotte_normalizzate_senza_duplicati.yml';
        if (!file_exists($yaml_file)) {
            return;
        }

        $lines = file($yaml_file, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return;
        }

        // Replace server URL with the current site URL
        $base_url = site_url();
        foreach ($lines as &$line) {
            if (preg_match('/^- url:/', trim($line))) {
                $line = "- url: {$base_url}/wp-json/wp-gpt";
                break;
            }
        }
        unset($line);

        if (empty($operations_filter)) {
            echo implode("\n", $lines);
            return;
        }

        // Extract header section until 'paths:'
        $header = [];
        $in_paths = false;
        foreach ($lines as $line) {
            $header[] = $line;
            if (preg_match('/^\s*paths:\s*$/', $line)) {
                $in_paths = true;
                break;
            }
        }

        if (!$in_paths) {
            echo implode("\n", $lines);
            return;
        }

        // Use the same YAML parser from Step 6 to load operations
        $operations = WP_GPT_Schema_Guide::load_yaml_operations($yaml_file);
        $map = [];
        foreach ($operations as $op) {
            $map[$op['key']] = $op['snippet'];
        }

        $output = $header;
        foreach ($operations_filter as $key) {
            if (isset($map[$key])) {
                $output[] = $map[$key];
            }
        }

        echo implode("\n", $output);
    }
}
