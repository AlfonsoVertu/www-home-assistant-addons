<?php

namespace WP_GPT_Cron_Job;

class WP_GPT_Cron_Job {

    // Costruttore della classe
    public function __construct() {
        // Registriamo il cron job all'attivazione del plugin
        add_action('wp_loaded', [$this, 'schedule_cron_job']);
        // Definiamo il nostro cron job
        add_action('wp_gpt_regenerate_encryption_key', [$this, 'regenerate_encryption_key']);
        // Assicuriamoci che il cron job venga rimosso alla disattivazione del plugin
        register_deactivation_hook(__FILE__, [$this, 'unschedule_cron_job']);
    }

    /**
     * Pianifica il cron job
     */
    public function schedule_cron_job() {
        if (!wp_next_scheduled('wp_gpt_regenerate_encryption_key')) {
            wp_schedule_event(time(), 'hourly', 'wp_gpt_regenerate_encryption_key');
        }
    }

    /**
     * Gestisce la rigenerazione della chiave di criptazione
     */
    public function regenerate_encryption_key() {
        $interval = get_option('wp_gpt_key_regeneration_interval', 24);
        $current_time = current_time('timestamp');

        // Verifica se è il momento di rigenerare la chiave
        if (time() - get_option('wp_gpt_last_key_regeneration', 0) >= $interval * 3600) {
            $new_key = wp_generate_password(32, false);
            update_option('wp_gpt_encryption_key', $new_key);
            update_option('wp_gpt_last_key_regeneration', $current_time);

            // Invia una notifica via email
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, 'Chiave di Criptazione Rigenerata', 'La chiave di criptazione è stata rigenerata correttamente.');
        }
    }

    /**
     * Annulla la pianificazione del cron job
     */
    public function unschedule_cron_job() {
        $timestamp = wp_next_scheduled('wp_gpt_regenerate_encryption_key');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wp_gpt_regenerate_encryption_key');
        }
    }
}

// Inizializza la classe
new WP_GPT_Cron_Job();

