<?php
/**
 * Card per singolo device/entità Home Assistant, con opzioni di visualizzazione
 * per tipo (dominio). Shortcode [vwgk_card entity="light.salone" card="auto" ...].
 *
 * Lo schema (self-describing) è esposto anche via REST (action "describe") così un
 * GPT/tema può scoprire i parametri disponibili per tipo.
 */

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Cards
{
    public static function init(): void
    {
        add_shortcode('vwgk_card', [__CLASS__, 'render_card']);
    }

    /** Schema auto-descrittivo: shortcode, attributi, card e opzioni per dominio. */
    public static function schema(): array
    {
        return [
            'shortcodes' => [
                'vwgk_card' => [
                    'description' => 'Card di un singolo device/entità HA.',
                    'attributes'  => [
                        'entity'   => 'entity_id HA, es. light.salone (obbligatorio)',
                        'card'     => 'tipo di card; "auto" sceglie in base al dominio',
                        'title'    => 'titolo personalizzato (default: friendly_name)',
                        'icon'     => '1/0 mostra icona',
                        'state'    => '1/0 mostra lo stato',
                        'unit'     => '1/0 mostra l\'unità di misura',
                        'decimals' => 'numero di decimali per i valori numerici',
                    ],
                ],
                'vwgk_room' => [
                    'description' => 'Contenitore che rende tutte le entità di una stanza (area).',
                    'attributes'  => ['area' => 'area_id HA'],
                ],
            ],
            'card_types_by_domain' => [
                'light'         => ['cards' => ['toggle', 'slider', 'badge'], 'options' => ['brightness']],
                'switch'        => ['cards' => ['toggle', 'badge']],
                'input_boolean' => ['cards' => ['toggle', 'badge']],
                'fan'           => ['cards' => ['toggle', 'badge']],
                'cover'         => ['cards' => ['buttons', 'badge'], 'options' => ['position']],
                'climate'       => ['cards' => ['thermostat', 'badge'], 'options' => ['target']],
                'sensor'        => ['cards' => ['value', 'gauge', 'badge'], 'options' => ['unit', 'decimals']],
                'binary_sensor' => ['cards' => ['badge', 'icon']],
                'camera'        => ['cards' => ['snapshot', 'badge']],
                'media_player'  => ['cards' => ['mini', 'badge']],
                'scene'         => ['cards' => ['button']],
                'script'        => ['cards' => ['button']],
                'automation'    => ['cards' => ['toggle', 'button']],
                '_default'      => ['cards' => ['badge', 'value']],
            ],
        ];
    }

    public static function default_card(string $domain): string
    {
        $map = [
            'light' => 'toggle', 'switch' => 'toggle', 'input_boolean' => 'toggle', 'fan' => 'toggle',
            'automation' => 'toggle', 'cover' => 'buttons', 'climate' => 'thermostat',
            'sensor' => 'value', 'binary_sensor' => 'badge', 'camera' => 'snapshot',
            'media_player' => 'mini', 'scene' => 'button', 'script' => 'button',
        ];
        return $map[$domain] ?? 'badge';
    }

    private static function state(string $eid): array
    {
        if (class_exists('VWGK_HA_Rooms')) {
            $map = VWGK_HA_Rooms::states_map();
            if (isset($map[$eid])) {
                return $map[$eid];
            }
        }
        $res = VWGK_HA_Client::request('GET', '/states/' . rawurlencode($eid));
        if (!is_wp_error($res) && is_array($res['body'] ?? null)) {
            return $res['body'];
        }
        return [];
    }

    public static function render_card($atts): string
    {
        $a = shortcode_atts([
            'entity' => '', 'card' => 'auto', 'title' => '', 'icon' => '1',
            'state' => '1', 'unit' => '1', 'decimals' => '',
        ], $atts, 'vwgk_card');

        if (!is_user_logged_in()) {
            return '<span class="vwgk-card-locked">🔒</span>';
        }
        $eid = sanitize_text_field((string) $a['entity']);
        if ($eid === '') {
            return '';
        }
        $domain = (string) strtok($eid, '.');
        $card = ($a['card'] === 'auto' || $a['card'] === '') ? self::default_card($domain) : sanitize_key($a['card']);

        $s = self::state($eid);
        $name  = $a['title'] !== '' ? $a['title'] : ($s['attributes']['friendly_name'] ?? $eid);
        $val   = $s['state'] ?? 'n/d';
        $unit  = ($a['unit'] !== '0' && !empty($s['attributes']['unit_of_measurement'])) ? ' ' . $s['attributes']['unit_of_measurement'] : '';
        if ($a['decimals'] !== '' && is_numeric($val)) {
            $val = number_format((float) $val, (int) $a['decimals']);
        }
        $on = in_array(strtolower((string) $val), ['on', 'open', 'home', 'playing', 'active'], true);

        $body = '';
        switch ($card) {
            case 'toggle':
                $body = '<span class="vwgk-pill ' . ($on ? 'on' : 'off') . '">' . ($on ? 'ON' : 'OFF') . '</span>';
                break;
            case 'value':
                $body = '<span class="vwgk-val">' . esc_html((string) $val) . esc_html($unit) . '</span>';
                break;
            case 'gauge':
                $pct = is_numeric($val) ? max(0, min(100, (float) $val)) : 0;
                $body = '<span class="vwgk-val">' . esc_html((string) $val) . esc_html($unit) . '</span>'
                    . '<span class="vwgk-gauge"><i style="width:' . $pct . '%"></i></span>';
                break;
            case 'buttons':
                $body = '<span class="vwgk-btns">▲ ■ ▼</span><small>' . esc_html((string) $val) . '</small>';
                break;
            case 'thermostat':
                $cur = $s['attributes']['current_temperature'] ?? $val;
                $tgt = $s['attributes']['temperature'] ?? null;
                $body = '<span class="vwgk-val">' . esc_html((string) $cur) . '°</span>'
                    . ($tgt !== null ? '<small>→ ' . esc_html((string) $tgt) . '°</small>' : '');
                break;
            case 'snapshot':
                $body = '<span class="vwgk-cam">📷 ' . esc_html((string) $val) . '</span>';
                break;
            case 'mini':
                $body = '<span class="vwgk-media">▶ ' . esc_html((string) $val) . '</span>';
                break;
            case 'button':
                $body = '<span class="vwgk-runbtn">' . esc_html__('Esegui', 'virtual-world-gate-key') . '</span>';
                break;
            case 'icon':
                $body = '<span class="vwgk-dot ' . ($on ? 'on' : 'off') . '"></span>';
                break;
            case 'badge':
            default:
                $body = '<span class="vwgk-badge ' . ($on ? 'on' : 'off') . '">' . esc_html((string) $val) . esc_html($unit) . '</span>';
                break;
        }

        $ico = ($a['icon'] !== '0') ? '<span class="vwgk-ic" data-d="' . esc_attr($domain) . '"></span>' : '';
        return self::inline_style()
            . '<span class="vwgk-card2" data-entity="' . esc_attr($eid) . '" data-card="' . esc_attr($card) . '">'
            . $ico . '<span class="vwgk-nm">' . esc_html($name) . '</span>' . $body . '</span>';
    }

    private static function inline_style(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>'
            . '.vwgk-card2{display:inline-flex;align-items:center;gap:8px;background:#161b22;border:1px solid #30363d;border-radius:10px;padding:8px 12px;margin:4px;color:#e6edf3;font-size:.9rem}'
            . '.vwgk-nm{font-weight:600}'
            . '.vwgk-pill{padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700}.vwgk-pill.on{background:rgba(63,185,80,.2);color:#3fb950}.vwgk-pill.off{background:rgba(139,148,158,.2);color:#8b949e}'
            . '.vwgk-badge{color:#8b949e}.vwgk-badge.on{color:#3fb950}'
            . '.vwgk-val{font-weight:700}'
            . '.vwgk-gauge{display:inline-block;width:70px;height:6px;background:#30363d;border-radius:6px;overflow:hidden}.vwgk-gauge i{display:block;height:100%;background:#f0a500}'
            . '.vwgk-dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:#8b949e}.vwgk-dot.on{background:#3fb950}'
            . '.vwgk-btns{letter-spacing:4px}.vwgk-runbtn{background:#f0a500;color:#111;border-radius:8px;padding:3px 10px;font-weight:700;font-size:.8rem}'
            . '</style>';
    }
}
