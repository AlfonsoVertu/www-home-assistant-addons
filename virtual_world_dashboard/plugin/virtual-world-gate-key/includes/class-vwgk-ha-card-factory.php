<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Factory per generare dinamicamente le card (schede) HTML
 * in base al dominio e stato dell'entità Home Assistant.
 */
class VWGK_HA_Card_Factory
{
    /**
     * Entry point principale: riceve un JSON payload di un'entità HA e restituisce l'HTML.
     */
    public static function render_entity(array $entity, array $options = []): string
    {
        $entity_id = $entity['entity_id'] ?? '';
        if (empty($entity_id)) {
            return '';
        }

        $parts = explode('.', $entity_id);
        $domain = $parts[0] ?? 'unknown';
        
        // Estrai data e fallback
        $state = $entity['state'] ?? 'unknown';
        $attributes = $entity['attributes'] ?? [];
        $friendly_name = $attributes['friendly_name'] ?? $entity_id;
        
        // Gestione Icona override
        $icon = self::resolve_icon($domain, $state, $options['icon'] ?? '');

        // Dati di base condivisi
        $data = [
            'entity_id' => $entity_id,
            'domain'    => $domain,
            'state'     => $state,
            'name'      => $friendly_name,
            'icon'      => $icon,
            'attributes'=> $attributes,
            'options'   => $options // Parametri passati dallo shortcode (es. template)
        ];

        // Se è stato specificato un template Shortcoder personalizzato, diamo priorità a quello
        if (!empty($options['template_content'])) {
            return self::render_custom_template($data, $options['template_content']);
        }

        // Routing interno verso il renderer specifico per dominIo
        $method_name = 'render_' . $domain;
        if (method_exists(__CLASS__, $method_name)) {
            return self::$method_name($data);
        }

        // Fallback generico per entità sconosciute
        return self::render_generic($data);
    }

    // --- DOMINI BASE (Sola Lettura) --- //

    private static function render_sensor(array $data): string
    {
        $unit = $data['attributes']['unit_of_measurement'] ?? '';
        $value_display = esc_html($data['state']) . ($unit ? ' ' . esc_html($unit) : '');
        
        // Sensori solitamente non hanno controlli
        return self::build_card_shell($data, "<div class='vwgk-ha-sensor-value'>{$value_display}</div>");
    }

    private static function render_binary_sensor(array $data): string
    {
        $is_on = in_array(strtolower((string) $data['state']), ['on', 'open', 'true', 'active']);
        $state_label = $is_on ? 'Aperto/Attivo' : 'Chiuso/Inattivo';
        
        // CSS specificato per binary sensor: colore on/off
        $status_class = $is_on ? 'vwgk-ha-status-on' : 'vwgk-ha-status-off';
        
        $controls = "<div class='vwgk-ha-binary-status {$status_class}'>{$state_label}</div>";
        return self::build_card_shell($data, $controls);
    }

    private static function render_sun(array $data): string
    {
        $label = ($data['state'] === 'above_horizon') ? 'Sopra orizzonte' : 'Sotto orizzonte';
        return self::build_card_shell($data, "<div class='vwgk-ha-sensor-value'>{$label}</div>");
    }

    private static function render_person(array $data): string
    {
        $label = ($data['state'] === 'home') ? 'A Casa' : 'Fuori';
        $status_class = ($data['state'] === 'home') ? 'vwgk-ha-status-on' : 'vwgk-ha-status-off';
        
        return self::build_card_shell($data, "<div class='vwgk-ha-binary-status {$status_class}'>{$label}</div>");
    }
    
    // --- DOMINI ATTIVI --- //

    private static function render_light(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $state     = $data['state'];
        $attrs     = $data['attributes'];

        $is_on   = ($state === 'on');
        $checked = $is_on ? 'checked' : '';

        // Controlli base (Switch On/Off)
        $html = '<div class="vwgk-ha-ctrl-row">';
        $html .= '<label class="vwgk-ha-switch">';
        $html .= '  <input type="checkbox" class="vwgk-ha-toggle" data-entity="' . $entity_id . '" ' . $checked . '>';
        $html .= '  <span class="vwgk-ha-slider"></span>';
        $html .= '</label>';
        $html .= '</div>';

        // Check color modes
        $color_modes = $attrs['supported_color_modes'] ?? [];
        $supports_brightness = in_array('brightness', $color_modes) || in_array('color_temp', $color_modes) || in_array('hs', $color_modes) || in_array('rgb', $color_modes) || in_array('xy', $color_modes);
        
        if ($supports_brightness && $is_on) {
            $brightness = $attrs['brightness'] ?? 0;
            $brightness_pct = round(($brightness / 255) * 100);

            $html .= '<div class="vwgk-ha-ctrl-row vwgk-ha-dimmer">';
            $html .= '  <span class="vwgk-ha-icon-small">🔅</span>';
            $html .= '  <input type="range" class="vwgk-ha-brightness-slider" data-entity="' . $entity_id . '" min="1" max="100" value="' . $brightness_pct . '">';
            $html .= '  <span class="vwgk-ha-icon-small">🔆</span>';
            $html .= '</div>';
        }

        $supports_color = in_array('hs', $color_modes) || in_array('rgb', $color_modes) || in_array('xy', $color_modes);
        if ($supports_color && $is_on) {
            $rgb = $attrs['rgb_color'] ?? [255, 255, 255];
            $hex = sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);

            $html .= '<div class="vwgk-ha-ctrl-row vwgk-ha-color">';
            $html .= '  <span class="vwgk-ha-ctrl-label">Colore:</span>';
            $html .= '  <input type="color" class="vwgk-ha-color-picker" data-entity="' . $entity_id . '" value="' . $hex . '">';
            $html .= '</div>';
        }

        return self::build_card_shell($data, $html);
    }

    private static function render_cover(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $state     = $data['state'];
        
        $html = '<div class="vwgk-ha-cover-ctrl">';
        $html .= '<button class="vwgk-ha-btn-cover" data-action="cover_open" data-entity="'.$entity_id.'">🔼</button>';
        $html .= '<button class="vwgk-ha-btn-cover" data-action="cover_stop" data-entity="'.$entity_id.'">⏹️</button>';
        $html .= '<button class="vwgk-ha-btn-cover" data-action="cover_close" data-entity="'.$entity_id.'">🔽</button>';
        $html .= '</div>';

        if (isset($data['attributes']['current_position'])) {
            $pos = (int) $data['attributes']['current_position'];
            $html .= '<div class="vwgk-ha-ctrl-row vwgk-ha-dimmer">';
            $html .= '  <input type="range" class="vwgk-ha-cover-slider" data-entity="' . $entity_id . '" min="0" max="100" value="' . $pos . '">';
            $html .= '</div>';
        }

        return self::build_card_shell($data, $html);
    }

    private static function render_media_player(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $state     = $data['state'];
        $attrs     = $data['attributes'];

        $is_playing = ($state === 'playing');
        $play_icon = $is_playing ? '⏸️' : '▶️';
        $play_action = $is_playing ? 'media_pause' : 'media_play';

        $html = '<div class="vwgk-ha-media-ctrl">';
        $html .= '<button class="vwgk-ha-btn-media" data-action="media_previous_track" data-entity="'.$entity_id.'">⏮️</button>';
        $html .= '<button class="vwgk-ha-btn-media vwgk-ha-btn-play" data-action="'.$play_action.'" data-entity="'.$entity_id.'">'.$play_icon.'</button>';
        $html .= '<button class="vwgk-ha-btn-media" data-action="media_next_track" data-entity="'.$entity_id.'">⏭️</button>';
        $html .= '</div>';

        if (isset($attrs['volume_level'])) {
            $vol = round($attrs['volume_level'] * 100);
            $html .= '<div class="vwgk-ha-ctrl-row vwgk-ha-dimmer">';
            $html .= '  <span class="vwgk-ha-icon-small">🔈</span>';
            $html .= '  <input type="range" class="vwgk-ha-volume-slider" data-entity="' . $entity_id . '" min="0" max="100" value="' . $vol . '">';
            $html .= '  <span class="vwgk-ha-icon-small">🔊</span>';
            $html .= '</div>';
        }
        
        $media_title = $attrs['media_title'] ?? '';
        $media_artist = $attrs['media_artist'] ?? '';
        if ($media_title) {
            $html .= '<div class="vwgk-ha-media-track">' . esc_html($media_artist . ' - ' . $media_title) . '</div>';
        }

        return self::build_card_shell($data, $html);
    }

    private static function render_lock(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $state     = $data['state'];
        
        $is_locked = ($state === 'locked');
        $lock_icon = $is_locked ? '🔓 Apri' : '🔒 Chiudi';
        $lock_action = $is_locked ? 'unlock' : 'lock';

        $html = '<div class="vwgk-ha-ctrl-row">';
        $html .= '<button class="vwgk-ha-btn-execute" data-action="'.$lock_action.'" data-entity="'.$entity_id.'">'.$lock_icon.'</button>';
        $html .= '</div>';

        return self::build_card_shell($data, $html);
    }
    private static function render_camera(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('vwgk_ha_nonce');
        
        // Timeout parameter added to bust browser cache on initial load
        $img_src = esc_url($ajax_url . '?action=vwgk_camera_proxy&entity_id=' . $entity_id . '&nonce=' . $nonce . '&t=' . time());
        
        $html = '<div class="vwgk-ha-camera-ctrl">';
        $html .= '<img src="' . $img_src . '" alt="Camera Stream" class="vwgk-ha-camera-stream" style="width:100%; border-radius:8px; margin-top:10px;" />';
        $html .= '</div>';

        return self::build_card_shell($data, $html);
    }

    private static function render_climate(array $data): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $attrs     = $data['attributes'];
        $state     = $data['state']; // HVAC mode attuale

        $is_on   = ($state !== 'off');
        $checked = $is_on ? 'checked' : '';

        $temp        = $attrs['current_temperature'] ?? '--';
        $target      = $attrs['temperature'] ?? $attrs['target_temp_high'] ?? '--';
        $hvac_modes  = $attrs['hvac_modes'] ?? ['off', 'heat', 'cool', 'auto', 'dry', 'fan_only'];
        $fan_modes   = $attrs['fan_modes'] ?? [];
        $preset_modes= $attrs['preset_modes'] ?? [];
        $swing_modes = $attrs['swing_modes'] ?? [];

        $html = '<div class="vwgk-ha-climate-ui">';
        
        // 0. Power Toggle (Direct Action)
        $html .= '<div class="vwgk-ha-power-row">';
        $html .= '  <span style="font-size: 13px; font-weight: 500;">Alimentazione</span>';
        $html .= '  <label class="vwgk-ha-switch">';
        $html .= '    <input type="checkbox" class="vwgk-ha-toggle" data-entity="' . $entity_id . '" ' . $checked . '>';
        $html .= '    <span class="vwgk-ha-slider"></span>';
        $html .= '  </label>';
        $html .= '</div>';

        // 1. Target Temperature Control (Deferred Action)
        $html .= '<div class="vwgk-ha-temp-ctrl vwgk-ha-margin-b">';
        $html .= '  <button class="vwgk-ha-btn-circle" data-action="set_temp" data-entity="'.$entity_id.'" data-step="-0.5">−</button>';
        $html .= '  <span class="vwgk-ha-target-label">Target: <span class="vwgk-ha-temp-value vwgk-ha-temp-target--live">'.$target.'</span>°C</span>';
        $html .= '  <button class="vwgk-ha-btn-circle" data-action="set_temp" data-entity="'.$entity_id.'" data-step="0.5">+</button>';
        $html .= '</div>';

        // 2. HVAC Modes (Dynamic Buttons)
        $html .= '<div class="vwgk-ha-mode-ctrl vwgk-ha-margin-b">';
        foreach ($hvac_modes as $m) {
            $icon = self::resolve_mode_icon($m);
            $active_class = ($state === $m) ? 'vwgk-ha-btn-mode--active' : '';
            $html .= '<button class="vwgk-ha-btn-mode '.$active_class.'" data-action="set_mode" data-entity="'.$entity_id.'" data-mode="'.$m.'" title="'.ucfirst($m).'">'.$icon.'</button>';
        }
        $html .= '</div>';

        // 3. Extra Controls (Fan, Preset, Swing)
        if (!empty($fan_modes) || !empty($preset_modes) || !empty($swing_modes)) {
            $html .= '<div class="vwgk-ha-extra-ctrls">';
            
            // FAN MODE
            if (!empty($fan_modes)) {
                $current_fan = $attrs['fan_mode'] ?? '';
                $html .= '<div class="vwgk-ha-select-wrapper">';
                $html .= '<span class="dashicons dashicons-wind"></span>';
                $html .= '<select class="vwgk-ha-select" data-action="set_fan_mode" data-entity="'.$entity_id.'">';
                foreach ($fan_modes as $f) {
                    $sel = ($current_fan === $f) ? 'selected' : '';
                    $html .= '<option value="'.$f.'" '.$sel.'>Fan: '.ucfirst($f).'</option>';
                }
                $html .= '</select></div>';
            }

            // PRESET MODE
            if (!empty($preset_modes)) {
                $current_preset = $attrs['preset_mode'] ?? '';
                $html .= '<div class="vwgk-ha-select-wrapper">';
                $html .= '<span class="dashicons dashicons-admin-generic"></span>';
                $html .= '<select class="vwgk-ha-select" data-action="set_preset_mode" data-entity="'.$entity_id.'">';
                $html .= '<option value="">Preset: None</option>';
                foreach ($preset_modes as $p) {
                    $sel = ($current_preset === $p) ? 'selected' : '';
                    $html .= '<option value="'.$p.'" '.$sel.'>'.ucfirst($p).'</option>';
                }
                $html .= '</select></div>';
            }

            // SWING MODE
            if (!empty($swing_modes)) {
                $current_swing = $attrs['swing_mode'] ?? '';
                $html .= '<div class="vwgk-ha-select-wrapper">';
                $html .= '<span class="dashicons dashicons-sort"></span>';
                $html .= '<select class="vwgk-ha-select" data-action="set_swing_mode" data-entity="'.$entity_id.'">';
                foreach ($swing_modes as $s) {
                    $sel = ($current_swing === $s) ? 'selected' : '';
                    $html .= '<option value="'.$s.'" '.$sel.'>Swing: '.ucfirst($s).'</option>';
                }
                $html .= '</select></div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>'; // close climate-ui

        return self::build_card_shell($data, $html);
    }

    private static function resolve_mode_icon(string $mode): string
    {
        $map = [
            'cool'     => '❄️',
            'heat'     => '🔥',
            'auto'     => '♻️',
            'dry'      => '💧',
            'fan_only' => '🌀',
            'off'      => '⭕',
            'heat_cool'=> '🌓'
        ];
        return $map[$mode] ?? '🔸';
    }

    private static function render_generic(array $data): string
    {
        // Interruttore base per switch, light, etc (come era originariamente)
        $domain = $data['domain'];
        $state = $data['state'];
        $controls = '';

        if (in_array($domain, ['switch', 'light', 'input_boolean'])) {
            $checked = ($state === 'on') ? 'checked' : '';
            $controls .= '<label class="vwgk-ha-switch">';
            $controls .= '  <input type="checkbox" class="vwgk-ha-toggle" ' . $checked . '>';
            $controls .= '  <span class="vwgk-ha-slider"></span>';
            $controls .= '</label>';
        } elseif (in_array($domain, ['script', 'automation', 'scene'])) {
            $btn_text = ($domain === 'scene') ? 'Attiva' : 'Esegui';
            $controls .= '<button class="vwgk-ha-btn-execute">' . esc_html($btn_text) . '</button>';
        } else {
            // Selezionatore vuoto o bottone generico
            $controls .= '<div class="vwgk-ha-state-label">Stato: ' . esc_html($state) . '</div>';
        }

        return self::build_card_shell($data, $controls);
    }

    // --- HELPER METODI --- //

    /**
     * Costruisce il guscio esterno comune a tutte le card (Icona, Nome, Controlli)
     */
    private static function build_card_shell(array $data, string $controls_html): string
    {
        $entity_id = esc_attr($data['entity_id']);
        $domain    = esc_attr($data['domain']);
        $state     = esc_attr($data['state']);
        $name      = esc_html($data['name']);
        $icon      = $data['icon'];
        $mode      = esc_attr($data['options']['mode'] ?? '');

        // Formattazione temperatura attuale se disponibile (utile per climate/sensor)
        $current_temp = '';
        if (isset($data['attributes']['current_temperature'])) {
            $current_temp = ' - <span class="vwgk-ha-temp-value">' . esc_html($data['attributes']['current_temperature']) . '</span>°C';
        }

        ob_start();
        ?>
        <div class="vwgk-ha-card vwgk-card-<?php echo $domain; ?>"
             data-id="<?php echo $entity_id; ?>"
             data-domain="<?php echo $domain; ?>"
             data-state="<?php echo $state; ?>"
             data-mode="<?php echo $mode; ?>">

            <!-- Technical Info Trigger -->
            <button class="vwgk-ha-info-btn" 
                    title="Dettagli Tecnici" 
                    data-entity="<?php echo $entity_id; ?>"
                    data-attrs='<?php echo esc_attr(json_encode($data['attributes'])); ?>'>ℹ️</button>

            <div class="vwgk-ha-card-content">
                <div class="vwgk-ha-card-icon"><?php echo $icon; ?></div>
                <div class="vwgk-ha-card-info">
                    <div class="vwgk-ha-card-name"><?php echo $name; ?></div>
                    <div class="vwgk-ha-card-state vwgk-ha-card-state--live">
                        <?php echo $state . $current_temp; ?>
                    </div>
                </div>
            </div>

            <div class="vwgk-ha-card-controls">
                <?php echo $controls_html; ?>
            </div>

            <!-- Global Submit Area (Hidden by default) -->
            <div class="vwgk-ha-card-submit-area">
                <span class="vwgk-ha-pending-label">⚠️ Modifiche in sospeso...</span>
                <button class="vwgk-ha-btn-submit" data-entity="<?php echo $entity_id; ?>">Applica</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Processa un template Shortcoder personalizzato
     */
    private static function render_custom_template(array $data, string $template_content): string
    {
        $card = $template_content;
        $card = str_replace('{{entity_id}}', $data['entity_id'], $card);
        $card = str_replace('{{name}}', $data['name'], $card);
        $card = str_replace('{{state}}', $data['state'], $card);
        $card = str_replace('{{domain}}', $data['domain'], $card);
        $card = str_replace('{{icon}}', $data['icon'], $card);
        
        $card = str_replace('{{temp}}', $data['attributes']['current_temperature'] ?? '--', $card);
        $card = str_replace('{{target_temp}}', $data['attributes']['temperature'] ?? '--', $card);
        
        return $card;
    }

    /**
     * Risolve l'icona da mostrare
     */
    private static function resolve_icon(string $domain, string $state, string $override_icon = ''): string
    {
        if (!empty($override_icon)) {
            $icon_map = [
                'HEAT' => '🔥', 'COOL' => '❄️', 'FAN'  => '🌀', 
                'AUTO' => '♻️', 'DRY'  => '💧', 'OFF'  => '⭕',
            ];
            $icon = $icon_map[strtoupper($override_icon)] ?? $override_icon;
            return '<span class="ha-icon">' . $icon . '</span>';
        }

        $icons = [
            'light'        => '💡',
            'switch'       => '🔌',
            'script'       => '📜',
            'automation'   => '🤖',
            'sensor'       => '📊',
            'binary_sensor'=> '🔘',
            'climate'      => '🌡️',
            'fan'          => '🌀',
            'media_player' => '🔊',
            'scene'        => '🖼️',
            'camera'       => '📷',
            'lock'         => '🔒',
            'cover'        => '🪟',
            'sun'          => '☀️',
            'person'       => '👤',
        ];

        $icon = $icons[$domain] ?? '🔹';
        
        if ($domain === 'light' && strtolower($state) === 'off') $icon = '⚫';
        if ($domain === 'lock' && strtolower($state) === 'unlocked') $icon = '🔓';

        return '<span class="ha-icon">' . $icon . '</span>';
    }
}
