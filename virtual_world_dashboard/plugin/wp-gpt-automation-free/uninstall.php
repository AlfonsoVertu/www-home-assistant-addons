<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

global $wpdb;
$queue_table = $wpdb->prefix . 'gpt_queue';
$queue_log_table = $wpdb->prefix . 'gpt_queue_log';
$wpdb->query("DROP TABLE IF EXISTS $queue_table");
$wpdb->query("DROP TABLE IF EXISTS $queue_log_table");

delete_option('wp_gpt_api_token');
delete_option('wp_gpt_openai_key');
delete_option('wp_gpt_temperature');
delete_option('wp_gpt_max_tokens');
delete_option('wp_gpt_email_server');
delete_option('wp_gpt_email_port');
delete_option('wp_gpt_email_username');
delete_option('wp_gpt_email_password');
delete_option('wp_gpt_email_from');
delete_option('wp_gpt_email_passive_mode');
delete_option('wp_gpt_email_multiple_server_send');
delete_option('wp_gpt_email_multiple_port_send');
delete_option('wp_gpt_email_multiple_server_receive');
delete_option('wp_gpt_email_multiple_port_receive');
delete_option('wp_gpt_email_multiple_username');
delete_option('wp_gpt_email_multiple_password');
delete_option('wp_gpt_email_multiple_from');
delete_option('wp_gpt_email_multiple_passive_mode');

// Elimina eventuali file temporanei (aggiungere la logica appropriata)
$upload_dir = wp_get_upload_dir();
$plugin_dir = $upload_dir['basedir'] . '/wp-gpt-automation-pro';
if (is_dir($plugin_dir)) {
    array_map('unlink', glob("$plugin_dir/*.*"));
    rmdir($plugin_dir);
}
