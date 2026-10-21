<?php
/**
 * Legge da Home Assistant piani (floors) e stanze (areas) con le entità assegnate
 * e genera/aggiorna pagine WordPress: una pagina per stanza (con le sue entità),
 * eventualmente sotto una pagina-contenitore per piano.
 *
 * Usa la REST API di HA /api/template (Jinja) tramite VWGK_HA_Client::request().
 */

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_HA_Rooms
{
    const META_AREA   = '_vwgk_area_id';
    const META_FLOOR  = '_vwgk_floor_id';
    const META_ENTS   = '_vwgk_entities';
    const META_ECL    = '_vwgk_eclipse_note';
    const NOTICE_TRANS = 'vwgk_rooms_last_result';

    public static function init(): void
    {
        add_action('admin_post_vwgk_generate_rooms', [__CLASS__, 'handle_generate']);
        add_shortcode('vwgk_room', [__CLASS__, 'render_room_shortcode']);
    }

    /** Chiama /api/template con un template Jinja e ritorna il testo (o WP_Error). */
    private static function render_template(string $tpl)
    {
        $res = VWGK_HA_Client::request('POST', '/template', ['template' => $tpl]);
        if (is_wp_error($res)) {
            return $res;
        }
        if (($res['status_code'] ?? 0) >= 400) {
            return new WP_Error('vwgk_template_http', sprintf(
                /* translators: %d http status */
                __('Home Assistant ha risposto %d alla richiesta template.', 'virtual-world-gate-key'),
                (int) $res['status_code']
            ), ['body' => $res['raw_body'] ?? '']);
        }
        // /template ritorna testo grezzo: preferisci raw_body
        return isset($res['raw_body']) ? (string) $res['raw_body'] : '';
    }

    /**
     * Struttura completa: ['floors' => [...], 'areas' => [...]].
     * Ogni area: ['id','name','entities'=>[]]. Ogni floor: ['id','name','areas'=>[]].
     */
    public static function fetch_structure()
    {
        $tpl = <<<'JINJA'
{"floors": [
{%- if floors is defined -%}
{%- for f in floors() -%}
{"id": {{ f | tojson }}, "name": {{ floor_name(f) | tojson }}, "areas": {{ floor_areas(f) | tojson }}}{{ "," if not loop.last else "" }}
{%- endfor -%}
{%- endif -%}
], "areas": [
{%- for a in areas() -%}
{"id": {{ a | tojson }}, "name": {{ area_name(a) | tojson }}, "entities": {{ area_entities(a) | tojson }}}{{ "," if not loop.last else "" }}
{%- endfor -%}
]}
JINJA;

        $raw = self::render_template($tpl);
        if (is_wp_error($raw)) {
            return $raw;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new WP_Error('vwgk_template_parse', __('Risposta template non interpretabile (versione HA senza floors/areas?).', 'virtual-world-gate-key'), ['raw' => $raw]);
        }
        $data['floors'] = isset($data['floors']) && is_array($data['floors']) ? $data['floors'] : [];
        $data['areas']  = isset($data['areas']) && is_array($data['areas']) ? $data['areas'] : [];
        return $data;
    }

    /** Entità di una singola area (fallback quando manca il meta di pagina). */
    public static function area_entities(string $area_id)
    {
        $safe = str_replace("'", "\\'", $area_id);
        $raw  = self::render_template("{{ area_entities('{$safe}') | tojson }}");
        if (is_wp_error($raw)) {
            return $raw;
        }
        $list = json_decode($raw, true);
        return is_array($list) ? $list : [];
    }

    /** Mappa entity_id => {state, attributes} dagli stati live (una sola chiamata per request). */
    public static function states_map(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $res = VWGK_HA_Client::request('GET', '/states');
        if (!is_wp_error($res) && is_array($res['body'] ?? null)) {
            foreach ($res['body'] as $s) {
                if (isset($s['entity_id'])) {
                    $cache[$s['entity_id']] = $s;
                }
            }
        }
        return $cache;
    }

    // ---------------- arricchimento da add-on Eclipse ----------------

    /** Scarica l'export protetto dell'add-on Eclipse (plan + eclipse). Null se non configurato. */
    public static function fetch_eclipse()
    {
        $url = trim((string) get_option('vwgk_eclipse_url', ''));
        $key = (string) get_option('vwgk_eclipse_api_key', '');
        if ($url === '' || $key === '') {
            return null;
        }
        $endpoint = rtrim($url, '/') . '/api/export';
        $res = wp_remote_get($endpoint, [
            'timeout' => 20,
            'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) wp_remote_retrieve_response_code($res) >= 400) {
            return new WP_Error('vwgk_eclipse_http', 'Eclipse HTTP ' . wp_remote_retrieve_response_code($res));
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : null;
    }

    /** Poligono (lista [x,y]) di una stanza del piano Eclipse (poly o rettangolo). */
    private static function room_poly(array $r): array
    {
        if (!empty($r['poly']) && is_array($r['poly'])) {
            return array_map(function ($p) { return [(float) $p[0], (float) $p[1]]; }, $r['poly']);
        }
        $x = (float) ($r['x'] ?? 0); $y = (float) ($r['y'] ?? 0);
        $w = (float) ($r['w'] ?? 0); $h = (float) ($r['h'] ?? 0);
        return [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
    }

    private static function point_in_poly(float $px, float $py, array $poly): bool
    {
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $poly[$i][0]; $yi = $poly[$i][1];
            $xj = $poly[$j][0]; $yj = $poly[$j][1];
            if ((($yi > $py) !== ($yj > $py)) && ($px < ($xj - $xi) * ($py - $yi) / (($yj - $yi) ?: 1e-9) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Nota di arricchimento per una stanza (match per nome con i dati Eclipse). */
    private static function eclipse_note_for(string $name, $eclipse): string
    {
        if (!is_array($eclipse)) {
            return '';
        }
        $plan  = $eclipse['plan'] ?? [];
        $rooms = $plan['rooms'] ?? [];
        $furn  = $plan['furniture'] ?? [];
        $norm  = function ($s) { return trim(function_exists('mb_strtolower') ? mb_strtolower((string) $s) : strtolower((string) $s)); };

        $target = null;
        foreach ($rooms as $r) {
            if ($norm($r['name'] ?? '') === $norm($name)) {
                $target = $r;
                break;
            }
        }
        if (!$target) {
            return '';
        }

        $poly = self::room_poly($target);
        $count = 0;
        foreach ($furn as $f) {
            if (isset($f['x'], $f['y']) && self::point_in_poly((float) $f['x'], (float) $f['y'], $poly)) {
                $count++;
            }
        }

        $bits = [sprintf(_n('%d mobile', '%d mobili', $count, 'virtual-world-gate-key'), $count)];
        if (isset($target['base']) && (int) $target['base'] !== 0) {
            $bits[] = 'quota Z ' . (int) $target['base'];
        }
        if (isset($target['height'])) {
            $bits[] = 'altezza ' . (int) $target['height'];
        }
        $solar = $eclipse['eclipse']['solar'] ?? null;
        if (is_array($solar) && !empty($solar['peak_utc'])) {
            $bits[] = 'prossima eclissi solare ' . substr((string) $solar['peak_utc'], 0, 10)
                . (isset($solar['compass']) ? ' (' . $solar['compass'] . ')' : '');
        }
        return implode(' · ', $bits);
    }

    // ---------------- generazione pagine ----------------

    public static function handle_generate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permessi insufficienti.', 'virtual-world-gate-key'));
        }
        check_admin_referer('vwgk_generate_rooms');

        // PRIMA LE STANZE, POI LE PAGINE.
        //
        // La pianta di Eclipse e' il posto dove la casa viene disegnata: se
        // c'e' uno studio li' e non c'e' l'area in Home Assistant, la pagina
        // dello studio non nascerebbe - e chi guarda non capirebbe perche' una
        // stanza che ha appena disegnato non compare. Si allinea prima, poi si
        // genera su cio' che esiste davvero.
        //
        // NON BLOCCA. Se Eclipse non e' configurato, o il canale non si apre,
        // si tira dritto con le aree che Home Assistant ha gia': le pagine
        // sono la cosa utile, la sincronia e' un di piu'. L'esito si porta
        // avanti e si mostra insieme al resto.
        $sync = self::sync_eclipse_areas();

        $result = self::generate_pages();
        if (!is_wp_error($result) && is_array($result)) {
            $result['eclipse'] = is_wp_error($sync)
                ? ['errore' => $sync->get_error_message()]
                : $sync;
        }
        if (is_wp_error($result)) {
            set_transient(self::NOTICE_TRANS, ['error' => $result->get_error_message()], 60);
        } else {
            set_transient(self::NOTICE_TRANS, $result, 60);
        }
        wp_safe_redirect(add_query_arg(['page' => 'vwgk-settings', 'vwgk_rooms' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Le stanze disegnate dentro Eclipse, in forma corta.
     *
     * Non e' l'export intero: quello porta anche mobili, foto e modelli 3D, e
     * per sapere QUALI stanze esistono non servono.
     */
    public static function fetch_eclipse_rooms()
    {
        $url = trim((string) get_option('vwgk_eclipse_url', ''));
        $key = (string) get_option('vwgk_eclipse_api_key', '');
        if ($url === '' || $key === '') {
            return new WP_Error('vwgk_eclipse_config', __('Eclipse non risulta configurato: mancano indirizzo o chiave.', 'virtual-world-gate-key'));
        }
        $res = wp_remote_get(rtrim($url, '/') . '/api/rooms', [
            'timeout' => 15,
            'headers' => ['X-API-Key' => $key],
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return new WP_Error('vwgk_eclipse_http', 'Eclipse HTTP ' . wp_remote_retrieve_response_code($res));
        }
        $d = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($d) && isset($d['stanze']) ? $d['stanze'] : [];
    }

    /**
     * Due nomi sono la stessa stanza?
     *
     * SI CONFRONTA SENZA ACCENTI, MAIUSCOLE E SPAZI DOPPI, perche' la stessa
     * stanza viene scritta in due posti da due persone in due momenti: "Camera
     * da letto" qui, "camera da  letto" di la'. Trattarle come diverse
     * significa creare un doppione e poi non capire perche' le entita' sono
     * finite nell'altra.
     */
    private static function stesso_nome(string $a, string $b): bool
    {
        $n = static function (string $t): string {
            $t = remove_accents($t);
            $t = strtolower(trim($t));
            return preg_replace('/\s+/', ' ', $t);
        };
        return $n($a) === $n($b);
    }

    /**
     * Le stanze di Eclipse diventano aree di Home Assistant, se non ci sono.
     *
     * PERCHE' IN QUESTA DIREZIONE. Eclipse e' il posto dove la casa viene
     * disegnata: e' li' che uno decide che esiste uno studio. Home Assistant
     * conosce i dispositivi, non le stanze vuote - una stanza senza niente
     * dentro nessuno la crea. Quindi la pianta comanda e il registro segue.
     *
     * NON SI CANCELLA NIENTE, mai: una stanza tolta dalla pianta non fa
     * sparire l'area con i suoi dispositivi assegnati. Cancellare in
     * automatico cio' che qualcun altro ha collegato a mano e' il modo piu'
     * veloce di far perdere fiducia a un sistema che sincronizza.
     */
    public static function sync_eclipse_areas()
    {
        $stanze = self::fetch_eclipse_rooms();
        if (is_wp_error($stanze)) {
            return $stanze;
        }
        $struttura = self::fetch_structure();
        if (is_wp_error($struttura)) {
            return $struttura;
        }
        $aree = $struttura['areas'] ?? [];

        $mancanti = [];
        foreach ($stanze as $st) {
            $nome = trim((string) ($st['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $trovata = false;
            foreach ($aree as $a) {
                if (self::stesso_nome($nome, (string) ($a['name'] ?? ''))) {
                    $trovata = true;
                    break;
                }
            }
            if (!$trovata) {
                $mancanti[] = $nome;
            }
        }

        if (!$mancanti) {
            return ['create' => [], 'gia_presenti' => count($stanze)];
        }

        $comandi = [];
        foreach ($mancanti as $nome) {
            $comandi[] = ['type' => 'config/area_registry/create', 'name' => $nome];
        }
        $esiti = VWGK_HA_WS::esegui($comandi);
        if (is_wp_error($esiti)) {
            return $esiti;
        }

        $create = [];
        $falliti = [];
        foreach ($mancanti as $i => $nome) {
            if (!empty($esiti[$i]['success'])) {
                $create[] = $nome;
            } else {
                $falliti[$nome] = $esiti[$i]['error']['message'] ?? 'errore sconosciuto';
            }
        }
        return ['create' => $create, 'falliti' => $falliti, 'gia_presenti' => count($stanze) - count($mancanti)];
    }

    /** Contenuto pagina stanza: una card [vwgk_card] per entità (fallback [vwgk_room]). */
    /**
     * Quanto "vale" un'entita' in una pagina di stanza.
     *
     * PIU' ALTO = PIU' IN ALTO. Chi apre la pagina della camera vuole il
     * condizionatore e la luce, non il sensore che dice se il condizionatore e'
     * online. Prima l'ordine era quello in cui Home Assistant restituiva le
     * entita' - cioe' nessun ordine - e in soggiorno le prime quindici card
     * erano consumi e diagnostica, con le cose da accendere sepolte piu' giu'.
     *
     * SI GUARDA IL DOMINIO, NON IL NOME. Il dominio dice cosa una cosa SA FARE:
     * climate ha alimentazione, gradi, modalita' e ventola; sensor ha un
     * numero e basta. E' la misura giusta di "quanti controlli ha", e non
     * dipende da come e' stata chiamata.
     */
    private static function peso_entita(string $entity_id): int
    {
        $dominio = (string) strtok($entity_id, '.');
        $scala = [
            'climate'       => 100,  // alimentazione, gradi, modalita', ventola
            'media_player'  => 90,
            'cover'         => 85,
            'light'         => 80,   // interruttore, luminosita', colore
            'fan'           => 75,
            'water_heater'  => 70,
            'humidifier'    => 70,
            'vacuum'        => 65,
            'lock'          => 60,
            'switch'        => 50,   // acceso/spento e basta
            'input_boolean' => 45,
            'scene'         => 40,
            'script'        => 35,
            'automation'    => 30,
            'button'        => 25,
            'camera'        => 20,
            'binary_sensor' => 10,
            'sensor'        => 5,    // solo da leggere
        ];
        return $scala[$dominio] ?? 15;
    }

    /**
     * Contenuto pagina stanza: le card comandabili, quelle piu' ricche in cima.
     *
     * SI USA [vwgk_ha_card], NON [vwgk_card]. Sembrano la stessa cosa e non lo
     * sono: [vwgk_card] disegna una targhetta di stato - ON/OFF, un numero, una
     * temperatura - e non si puo' premere. [vwgk_ha_card] passa dal card
     * factory, che per ogni dominio costruisce i comandi VERI. E' lo stesso
     * shortcode della pagina "controllo condizionatori" (alias ha_ac_card): le
     * pagine stanza ereditano quello che funziona gia'.
     *
     * L'ORDINE E' SCRITTO, non lasciato all'ordine di arrivo: ogni card porta
     * il suo numero, cosi' chi poi le trascina deve cambiare un numero e non
     * rimescolare il testo della pagina.
     */
    public static function build_cards_content(array $entities, string $aid): string
    {
        $entities = array_values(array_unique(array_map('strval', $entities)));
        usort($entities, static function ($a, $b) {
            $d = self::peso_entita($b) <=> self::peso_entita($a);
            // A parita' di peso l'ordine alfabetico: due generazioni di seguito
            // devono dare la stessa pagina, o ogni rigenerazione sembrerebbe
            // aver cambiato qualcosa.
            return $d !== 0 ? $d : strcasecmp($a, $b);
        });

        $out = '';
        $n = 0;
        foreach ($entities as $eid) {
            $n += 10;   // a passi di dieci: cosi' se ne infila una in mezzo
            $out .= '[vwgk_ha_card entity_id="' . esc_attr($eid) . '" ordine="' . $n . '"]' . "
";
        }
        return $out !== '' ? $out : '[vwgk_room area="' . esc_attr($aid) . '"]';
    }

    /** Page id della stanza (per area_id) o 0. */
    public static function page_for_area(string $area_id): int
    {
        return self::find_page_by_meta(self::META_AREA, $area_id);
    }

    /** Crea/aggiorna le pagine di piani e stanze. $rebuild=true riscrive anche il contenuto (card). */
    public static function generate_pages(bool $rebuild = false)
    {
        $data = self::fetch_structure();
        if (is_wp_error($data)) {
            return $data;
        }
        $floors = $data['floors'];
        $areas  = $data['areas'];

        // area_id => floor descriptor
        $area_floor = [];
        foreach ($floors as $f) {
            foreach ((array) ($f['areas'] ?? []) as $aid) {
                $area_floor[$aid] = $f;
            }
        }

        // pagina-contenitore per ogni piano
        $floor_page = [];
        foreach ($floors as $f) {
            $fid = (string) ($f['id'] ?? '');
            if ($fid === '') {
                continue;
            }
            $existing = self::find_page_by_meta(self::META_FLOOR, $fid);
            $floor_page[$fid] = self::upsert_page(
                (string) ($f['name'] ?: $fid),
                0,
                '',
                [self::META_FLOOR => $fid],
                $existing
            );
        }

        // dati Eclipse (opzionali) per arricchire le stanze
        $eclipse = self::fetch_eclipse();
        if (is_wp_error($eclipse)) {
            $eclipse = null;
        }

        $created = 0;
        $updated = 0;
        $enriched = 0;
        foreach ($areas as $a) {
            $aid = (string) ($a['id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $parent = 0;
            if (isset($area_floor[$aid])) {
                $pfid = (string) ($area_floor[$aid]['id'] ?? '');
                $parent = $floor_page[$pfid] ?? 0;
            }
            $existing = self::find_page_by_meta(self::META_AREA, $aid);
            $content  = self::build_cards_content((array) ($a['entities'] ?? []), $aid);
            $note = self::eclipse_note_for((string) ($a['name'] ?? ''), $eclipse);
            if ($note !== '') {
                $enriched++;
            }
            self::upsert_page(
                (string) ($a['name'] ?: $aid),
                (int) $parent,
                $content,
                [
                    self::META_AREA => $aid,
                    self::META_ENTS => wp_json_encode(array_values((array) ($a['entities'] ?? []))),
                    self::META_ECL  => $note,
                ],
                $existing,
                $rebuild
            );
            if ($existing) {
                $updated++;
            } else {
                $created++;
            }
        }

        return [
            'floors'   => count($floor_page),
            'areas'    => count($areas),
            'created'  => $created,
            'updated'  => $updated,
            'enriched' => $enriched,
        ];
    }

    private static function find_page_by_meta(string $key, string $value): int
    {
        $q = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'draft', 'private', 'pending'],
            'meta_key'    => $key,
            'meta_value'  => $value,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        return !empty($q) ? (int) $q[0] : 0;
    }

    private static function upsert_page(string $title, int $parent, string $content, array $meta, int $existing = 0, bool $overwrite_content = false): int
    {
        $postarr = [
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_parent'  => $parent,
            'post_content' => $content,
        ];
        if ($existing > 0) {
            $postarr['ID'] = $existing;
            // Per le pagine esistenti non sovrascrivere il contenuto personalizzato,
            // a meno di rebuild esplicito (overwrite_content).
            if (!$overwrite_content) {
                unset($postarr['post_content']);
            }
            $id = wp_update_post($postarr, true);
        } else {
            $id = wp_insert_post($postarr, true);
        }
        if (is_wp_error($id) || !$id) {
            return $existing;
        }
        foreach ($meta as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        // marca la pagina come protetta (login forzato dallo shortcode vwgk_room)
        update_post_meta($id, '_vwgk_protected', '1');
        return (int) $id;
    }

    // ---------------- shortcode di rendering stanza ----------------

    public static function render_room_shortcode($atts): string
    {
        $atts = shortcode_atts(['area' => ''], $atts, 'vwgk_room');

        if (!is_user_logged_in()) {
            return '<p class="vwgk-room-locked">' . esc_html__('Contenuto riservato: effettua il login.', 'virtual-world-gate-key') . '</p>';
        }

        $area = sanitize_text_field((string) $atts['area']);
        if ($area === '') {
            return '';
        }

        // entità: preferisci il meta salvato sulla pagina, altrimenti chiedi a HA
        $entities = [];
        $meta = get_post_meta(get_the_ID(), self::META_ENTS, true);
        if (!empty($meta)) {
            $decoded = json_decode((string) $meta, true);
            if (is_array($decoded)) {
                $entities = $decoded;
            }
        }
        if (empty($entities)) {
            $fetched = self::area_entities($area);
            if (!is_wp_error($fetched)) {
                $entities = $fetched;
            }
        }
        if (empty($entities)) {
            return '<p class="vwgk-room-empty">' . esc_html__('Nessuna entità assegnata a questa stanza in Home Assistant.', 'virtual-world-gate-key') . '</p>';
        }

        $states = self::states_map();

        $out  = self::inline_style();
        $note = get_post_meta(get_the_ID(), self::META_ECL, true);
        if (!empty($note)) {
            $out .= '<p class="vwgk-room-eclipse">🌒 ' . esc_html($note) . '</p>';
        }
        $out .= '<div class="vwgk-room-grid">';
        foreach ($entities as $eid) {
            $eid   = (string) $eid;
            $s     = $states[$eid] ?? null;
            $name  = $s['attributes']['friendly_name'] ?? $eid;
            $state = $s['state'] ?? 'n/d';
            $dom   = strtok($eid, '.');
            $out  .= '<div class="vwgk-room-card" data-domain="' . esc_attr($dom) . '">'
                . '<span class="vwgk-ent-name">' . esc_html($name) . '</span>'
                . '<span class="vwgk-ent-state">' . esc_html($state) . '</span>'
                . '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    private static function inline_style(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>'
            . '.vwgk-room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:12px 0}'
            . '.vwgk-room-card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:6px;color:#e6edf3}'
            . '.vwgk-ent-name{font-weight:600}.vwgk-ent-state{color:#8b949e;font-size:.9em}'
            . '</style>';
    }

    /** Notice nella pagina impostazioni dopo la generazione. */
    public static function render_notice(): void
    {
        $r = get_transient(self::NOTICE_TRANS);
        if (!$r) {
            return;
        }
        delete_transient(self::NOTICE_TRANS);
        if (!empty($r['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Generazione stanze fallita: ', 'virtual-world-gate-key') . esc_html($r['error']) . '</p></div>';
            return;
        }
        echo '<div class="notice notice-success"><p>' . sprintf(
            esc_html__('Pagine generate: %1$d piani, %2$d stanze (%3$d create, %4$d aggiornate, %5$d arricchite con Eclipse).', 'virtual-world-gate-key'),
            (int) ($r['floors'] ?? 0),
            (int) ($r['areas'] ?? 0),
            (int) ($r['created'] ?? 0),
            (int) ($r['updated'] ?? 0),
            (int) ($r['enriched'] ?? 0)
        ) . '</p></div>';
    }
}
