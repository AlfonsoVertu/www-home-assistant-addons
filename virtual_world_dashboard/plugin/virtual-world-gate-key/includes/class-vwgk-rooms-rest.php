<?php
/**
 * Rotte REST per stanze/piani e per gli shortcode delle pagine stanza.
 *  - POST /wp-json/vwgk/v1/rooms       (action: list|get|regenerate|delete)
 *  - POST /wp-json/vwgk/v1/shortcodes  (action: describe|list|update|add|remove)
 *
 * Autenticazione: header x-api-key = opzione vwgk_api_key (riusa VWGK_REST::authorize).
 */

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Rooms_Rest
{
    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register(): void
    {
        $auth = ['VWGK_REST', 'authorize'];
        register_rest_route('vwgk/v1', '/rooms', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rooms'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('vwgk/v1', '/shortcodes', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'shortcodes'],
            'permission_callback' => $auth,
        ]);
    }

    // ----------------- /rooms -----------------

    public static function rooms(WP_REST_Request $req)
    {
        $p = $req->get_json_params() ?: $req->get_params();
        $action = sanitize_text_field($p['action'] ?? 'list');

        switch ($action) {
            case 'list':
                $data = VWGK_HA_Rooms::fetch_structure();
                if (is_wp_error($data)) {
                    return $data;
                }
                foreach ($data['areas'] as &$a) {
                    $a['page_id'] = VWGK_HA_Rooms::page_for_area((string) ($a['id'] ?? ''));
                }
                return rest_ensure_response($data);

            case 'regenerate':
                $res = VWGK_HA_Rooms::generate_pages(!empty($p['rebuild']));
                return is_wp_error($res) ? $res : rest_ensure_response(['ok' => true, 'result' => $res]);

            case 'get':
                $pid = self::resolve_page($p);
                if (!$pid) {
                    return new WP_Error('vwgk_not_found', 'Stanza non trovata', ['status' => 404]);
                }
                return rest_ensure_response([
                    'page_id'    => $pid,
                    'title'      => get_the_title($pid),
                    'area_id'    => get_post_meta($pid, '_vwgk_area_id', true),
                    'entities'   => json_decode((string) get_post_meta($pid, '_vwgk_entities', true), true) ?: [],
                    'shortcodes' => self::list_shortcodes(get_post_field('post_content', $pid)),
                ]);

            case 'delete':
                $pid = self::resolve_page($p);
                if (!$pid) {
                    return new WP_Error('vwgk_not_found', 'Stanza non trovata', ['status' => 404]);
                }
                wp_delete_post($pid, true);
                return rest_ensure_response(['ok' => true, 'deleted' => $pid]);
        }
        return new WP_Error('vwgk_bad_action', 'Action non valida: ' . $action, ['status' => 400]);
    }

    // ----------------- /shortcodes -----------------

    public static function shortcodes(WP_REST_Request $req)
    {
        $p = $req->get_json_params() ?: $req->get_params();
        $action = sanitize_text_field($p['action'] ?? 'describe');

        // Action di scoperta: come funzionano gli shortcode e i loro parametri per tipo.
        if ($action === 'describe') {
            return rest_ensure_response([
                'schema'  => VWGK_Cards::schema(),
                'actions' => [
                    'describe' => 'Ritorna questo schema (parametri shortcode e card per dominio).',
                    'list'     => 'Elenca gli shortcode presenti nella pagina di una stanza. Param: area|page_id.',
                    'update'   => 'Modifica un card/shortcode. Param: area|page_id, + (index o entity), + attrs {card, icon, state, unit, decimals, title, ...}.',
                    'add'      => 'Aggiunge una card. Param: area|page_id, entity, [card].',
                    'remove'   => 'Rimuove una card. Param: area|page_id, index|entity.',
                ],
                'example' => [
                    'action' => 'update', 'area' => 'salone', 'entity' => 'light.lampada',
                    'attrs'  => ['card' => 'slider', 'brightness' => '1'],
                ],
            ]);
        }

        $pid = self::resolve_page($p);
        if (!$pid) {
            return new WP_Error('vwgk_not_found', 'Stanza/pagina non trovata', ['status' => 404]);
        }
        $content = (string) get_post_field('post_content', $pid);

        switch ($action) {
            case 'list':
                return rest_ensure_response([
                    'page_id'    => $pid,
                    'shortcodes' => self::list_shortcodes($content),
                ]);

            case 'update': {
                $list = self::list_shortcodes($content);
                $idx  = self::find_index($list, $p);
                if ($idx < 0) {
                    return new WP_Error('vwgk_sc_not_found', 'Shortcode non trovato (index/entity)', ['status' => 404]);
                }
                $atts = array_merge($list[$idx]['atts'], self::clean_attrs($p['attrs'] ?? []));
                $new  = self::build_tag($list[$idx]['tag'], $atts);
                $content = self::replace_nth($content, $list[$idx]['tag'], $list[$idx]['occurrence'], $new);
                wp_update_post(['ID' => $pid, 'post_content' => $content]);
                return rest_ensure_response(['ok' => true, 'updated' => $atts]);
            }

            case 'add': {
                $entity = sanitize_text_field($p['entity'] ?? '');
                if ($entity === '') {
                    return new WP_Error('vwgk_bad', 'entity obbligatoria', ['status' => 400]);
                }
                $atts = array_merge(['entity' => $entity, 'card' => sanitize_key($p['card'] ?? 'auto')], self::clean_attrs($p['attrs'] ?? []));
                $content = rtrim($content) . "\n" . self::build_tag('vwgk_card', $atts) . "\n";
                wp_update_post(['ID' => $pid, 'post_content' => $content]);
                return rest_ensure_response(['ok' => true, 'added' => $atts]);
            }

            case 'remove': {
                $list = self::list_shortcodes($content);
                $idx  = self::find_index($list, $p);
                if ($idx < 0) {
                    return new WP_Error('vwgk_sc_not_found', 'Shortcode non trovato', ['status' => 404]);
                }
                $content = self::replace_nth($content, $list[$idx]['tag'], $list[$idx]['occurrence'], '');
                wp_update_post(['ID' => $pid, 'post_content' => $content]);
                return rest_ensure_response(['ok' => true, 'removed' => $idx]);
            }
        }
        return new WP_Error('vwgk_bad_action', 'Action non valida: ' . $action, ['status' => 400]);
    }

    // ----------------- helpers -----------------

    private static function resolve_page(array $p): int
    {
        if (!empty($p['page_id'])) {
            return (int) $p['page_id'];
        }
        if (!empty($p['area'])) {
            return VWGK_HA_Rooms::page_for_area(sanitize_text_field((string) $p['area']));
        }
        return 0;
    }

    /** Elenca gli shortcode vwgk_card / vwgk_room nel contenuto, con attributi ed entità. */
    public static function list_shortcodes(string $content): array
    {
        $tags  = ['vwgk_card', 'vwgk_room'];
        $regex = get_shortcode_regex($tags);
        $out   = [];
        $occ   = [];
        if (preg_match_all('/' . $regex . '/', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $match) {
                $tag  = $match[2];
                $atts = shortcode_parse_atts($match[3]);
                $atts = is_array($atts) ? $atts : [];
                $occ[$tag] = ($occ[$tag] ?? -1) + 1;
                $entity = $atts['entity'] ?? '';
                $domain = $entity ? (string) strtok($entity, '.') : '';
                $card = $atts['card'] ?? 'auto';
                if ($card === 'auto' && $domain && class_exists('VWGK_Cards')) {
                    $card = VWGK_Cards::default_card($domain);
                }
                $out[] = [
                    'index'      => $i,
                    'occurrence' => $occ[$tag],
                    'tag'        => $tag,
                    'entity'     => $entity,
                    'domain'     => $domain,
                    'card'       => $card,
                    'atts'       => $atts,
                ];
            }
        }
        return $out;
    }

    private static function find_index(array $list, array $p): int
    {
        if (isset($p['index']) && $p['index'] !== '') {
            $i = (int) $p['index'];
            return isset($list[$i]) ? $i : -1;
        }
        if (!empty($p['entity'])) {
            foreach ($list as $i => $sc) {
                if (($sc['entity'] ?? '') === $p['entity']) {
                    return $i;
                }
            }
        }
        return -1;
    }

    private static function clean_attrs($attrs): array
    {
        if (!is_array($attrs)) {
            return [];
        }
        $allowed = ['card', 'title', 'icon', 'state', 'unit', 'decimals', 'brightness', 'position', 'target', 'entity', 'area'];
        $out = [];
        foreach ($attrs as $k => $v) {
            $k = sanitize_key((string) $k);
            if (in_array($k, $allowed, true)) {
                $out[$k] = sanitize_text_field((string) $v);
            }
        }
        return $out;
    }

    private static function build_tag(string $tag, array $atts): string
    {
        $parts = [];
        foreach ($atts as $k => $v) {
            if ($v === '' && $k !== 'title') {
                continue;
            }
            $parts[] = $k . '="' . esc_attr((string) $v) . '"';
        }
        return '[' . $tag . (empty($parts) ? '' : ' ' . implode(' ', $parts)) . ']';
    }

    /** Sostituisce l'occorrenza N-esima (0-based) di uno specifico tag shortcode. */
    private static function replace_nth(string $content, string $tag, int $occurrence, string $replacement): string
    {
        $regex = get_shortcode_regex([$tag]);
        $n = -1;
        return (string) preg_replace_callback('/' . $regex . '/', function ($m) use (&$n, $occurrence, $replacement) {
            $n++;
            return $n === $occurrence ? $replacement : $m[0];
        }, $content, -1);
    }
}
