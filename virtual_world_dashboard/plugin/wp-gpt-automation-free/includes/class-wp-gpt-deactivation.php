<?php

namespace WP_GPT_Automation_Pro;

class WP_GPT_Deactivation {
    public static function deactivate() {
        // Cancella il cron job
        wp_clear_scheduled_hook('wp_gpt_cron_job');
    }
}
