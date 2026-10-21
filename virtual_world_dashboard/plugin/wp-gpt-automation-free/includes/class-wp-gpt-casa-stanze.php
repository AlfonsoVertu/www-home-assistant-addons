<?php
/**
 * Le stanze della casa, cosa c'e' dentro, e le pagine che le mostrano.
 *
 * PERCHE' UNA ROTTA A PARTE E NON UN'ALTRA ACTION DI QUELLA DELL'IMPIANTO.
 * Quella risponde alla domanda "cosa c'e' e come lo accendo": entita', servizi,
 * stati. Questa risponde a "com'e' fatta la casa": quali stanze esistono, cosa
 * sta in quale, e come si costruisce una pagina che ne mostri un pezzo. Sono
 * due domande diverse, e tenerle insieme avrebbe voluto dire una rotta con
 * trenta action dove le meta' non c'entrano niente con l'altra meta'.
 *
 * NON SOSTITUISCE L'ALTRA, LA COMPLETA. Chi deve accendere una luce continua a
 * usare control-entity di la'. Qui si viene per sapere DOVE sono le cose, per
 * spostarle di stanza, e per fare le pagine.
 *
 * LA RICERCA CERCA PAROLE, NON FRASI. E' la differenza che decide se si trova
 * qualcosa: chiedendo "la lampada del soggiorno" una ricerca per frase non
 * trova niente, perche' nessuna entita' si chiama cosi'. Cercando le parole
 * una per una - e tenendo cio' che ne contiene almeno una, ordinato per quante
 * ne contiene - si torna sempre con qualcosa di pertinente, e il piu'
 * pertinente sta in cima.
 */

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) exit;

if (!class_exists(__NAMESPACE__ . '\WP_GPT_Casa_Stanze')) :

class WP_GPT_Casa_Stanze {

    const NAMESPACE_REST = 'wp-gpt/v1';
    const ROUTE          = '/manage-casa-stanze-dispositivi-e-pagine';

    /**
     * Le card che il tema sa disegnare, per dominio.
     *
     * NON E' UN ELENCO DECORATIVO: e' la risposta a "che aspetto puo' avere
     * questa entita' in una pagina". Chi costruisce una pagina deve poter
     * scegliere, e per scegliere deve sapere cosa esiste - altrimenti mette
     * ovunque la card automatica e non usa mai niente di meglio.
     */
    const CARD_PER_DOMINIO = [
        'climate'       => ['auto', 'thermostat', 'badge'],
        'light'         => ['auto', 'toggle', 'icon', 'badge'],
        'switch'        => ['auto', 'toggle', 'icon', 'badge'],
        'fan'           => ['auto', 'toggle', 'badge'],
        'cover'         => ['auto', 'buttons', 'badge'],
        'media_player'  => ['auto', 'mini', 'badge'],
        'lock'          => ['auto', 'toggle', 'badge'],
        'vacuum'        => ['auto', 'buttons', 'badge'],
        'camera'        => ['auto', 'snapshot'],
        'sensor'        => ['auto', 'value', 'gauge', 'badge'],
        'binary_sensor' => ['auto', 'icon', 'badge'],
        'script'        => ['auto', 'button'],
        'scene'         => ['auto', 'button'],
        'automation'    => ['auto', 'toggle', 'button'],
        'button'        => ['auto', 'button'],
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'registra']);
    }

    public static function registra() {
        register_rest_route(self::NAMESPACE_REST, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'smista'],
            'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code'],
        ]);
    }

    public static function smista(\WP_REST_Request $r) {
        $p = $r->get_json_params();
        if (!is_array($p)) $p = [];
        $azione = strtolower(trim((string) ($p['action'] ?? $r->get_param('action'))));

        switch ($azione) {
            case 'stanze':             return self::stanze();
            case 'dispositivi':        return self::dispositivi($p);
            case 'cerca':              return self::cerca($p);
            case 'card-disponibili':   return self::card_disponibili($p);
            case 'sposta-dispositivo': return self::sposta($p);
            case 'crea-stanza':        return self::crea_stanza($p);
            case 'crea-pagina':        return self::crea_pagina($p);
        }
        return new \WP_Error('azione_sconosciuta',
            'Azione non riconosciuta. Chiama action=documentazione per l\'elenco.',
            ['status' => 400]);
    }

    // ------------------------------------------------------------ leggere

    /** Le stanze: quelle di Home Assistant, con quanto contengono e se hanno una pagina. */
    private static function stanze() {
        $s = self::struttura();
        if (is_wp_error($s)) return $s;

        $fuori = [];
        foreach ($s['areas'] as $a) {
            $id = (string) ($a['id'] ?? '');
            $fuori[] = [
                'id'          => $id,
                'nome'        => (string) ($a['name'] ?? ''),
                'dispositivi' => count((array) ($a['entities'] ?? [])),
                'pagina'      => self::pagina_di($id),
            ];
        }
        return ['stanze' => $fuori, 'quante' => count($fuori)];
    }

    /** Cosa c'e' dentro una stanza. */
    private static function dispositivi(array $p) {
        $quale = trim((string) ($p['stanza'] ?? ''));
        if ($quale === '') {
            return new \WP_Error('stanza_mancante',
                'Serve "stanza": l\'id o il nome. Chiama action=stanze per vedere quali ci sono.',
                ['status' => 400]);
        }
        $s = self::struttura();
        if (is_wp_error($s)) return $s;

        foreach ($s['areas'] as $a) {
            if (self::stesso((string) ($a['id'] ?? ''), $quale)
                || self::stesso((string) ($a['name'] ?? ''), $quale)) {
                $e = array_values((array) ($a['entities'] ?? []));
                return [
                    'stanza'      => $a['name'] ?? $quale,
                    'id'          => $a['id'] ?? '',
                    'dispositivi' => $e,
                    'quanti'      => count($e),
                    'nota'        => 'Per accendere o spegnere usa control-entity sull\'altra rotta, '
                                   . 'quella dell\'impianto: qui si guarda soltanto.',
                ];
            }
        }
        return new \WP_Error('stanza_non_trovata',
            'Nessuna stanza con questo nome. Chiama action=stanze per l\'elenco.',
            ['status' => 404]);
    }

    /**
     * Cerca nella casa, per PAROLE, con la stanza come filtro facoltativo.
     *
     * PERCHE' NON PER FRASE. "la lampada del soggiorno" non e' il nome di
     * nessuna entita': cercata cosi' non torna niente, e chi ha chiesto si
     * sente dire che in casa non c'e' nessuna lampada. Spezzata in parole, e
     * ordinando per quante ne contiene, la lampada esce prima - e anche se
     * nessuna entita' le contiene tutte, qualcosa di pertinente torna sempre.
     *
     * LE PAROLE CORTE SI BUTTANO: "la", "del", "di" stanno dentro meta' degli
     * identificativi e farebbero salire in cima cose a caso.
     */
    private static function cerca(array $p) {
        $q = trim((string) ($p['query'] ?? $p['q'] ?? ''));
        if ($q === '') {
            return new \WP_Error('query_mancante', 'Serve "query": le parole da cercare.', ['status' => 400]);
        }
        $stanza = trim((string) ($p['stanza'] ?? ''));
        $limite = max(1, min(50, (int) ($p['limit'] ?? 15)));

        // LE PAROLE DI SERVIZIO SI BUTTANO, e non basta guardare la lunghezza.
        //
        // "del" ha tre lettere e passava il filtro: cercando "la lampada DEL
        // soggiorno" faceva punto su meta' della casa, e il primo risultato
        // era un sensore del router. Un elenco corto di articoli e
        // preposizioni costa niente e toglie di mezzo proprio quelle parole
        // che compaiono ovunque e non distinguono niente.
        $servizio = ['il','lo','la','le','gli','un','uno','una','del','dello','della','dei',
                     'degli','delle','al','allo','alla','ai','agli','alle','dal','dalla',
                     'nel','nella','nei','con','per','tra','fra','che','chi','cosa','mio',
                     'mia','miei','mie','the','and'];
        $parole = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [],
            static function ($w) use ($servizio) {
                return mb_strlen($w) >= 3 && !in_array($w, $servizio, true);
            }
        ));
        if (!$parole) {
            return new \WP_Error('query_corta',
                'Servono parole di almeno tre lettere: quelle piu\' corte compaiono ovunque.',
                ['status' => 400]);
        }

        $s = self::struttura();
        if (is_wp_error($s)) return $s;

        // I NOMI VISIBILI, non solo gli identificativi.
        //
        // E' il pezzo senza il quale la ricerca non trova le cose che contano.
        // La lampada del soggiorno e' una presa Sonoff: le sue entita' si
        // chiamano switch.sonoff_1000fbdb5e, e "Lamapada Di Legno" e' solo il
        // nome che le e' stato dato. Cercando negli identificativi non la si
        // trova MAI - eppure e' l'unico nome con cui qualcuno la chiamerebbe.
        $nomi = self::nomi_visibili();

        $risultati = [];
        foreach ($s['areas'] as $a) {
            $nome_stanza = (string) ($a['name'] ?? '');
            if ($stanza !== ''
                && !self::stesso($nome_stanza, $stanza)
                && !self::stesso((string) ($a['id'] ?? ''), $stanza)) {
                continue;
            }
            foreach ((array) ($a['entities'] ?? []) as $eid) {
                // IL NOME DELLA COSA VALE PIU' DEL NOME DELLA STANZA.
                //
                // Contando allo stesso modo, ogni entita' del soggiorno faceva
                // punto sulla parola "soggiorno" - centoquarantaquattro
                // pareggi, sciolti in ordine alfabetico, e la lampada finiva
                // dietro a un sensore del router. La stanza dice DOVE cercare,
                // non COSA si cerca: vale, ma meno.
                $suo    = mb_strtolower((string) $eid . ' ' . ($nomi[(string) $eid] ?? ''));
                $dove   = mb_strtolower($nome_stanza);
                $punti  = 0;
                $centri = 0;
                foreach ($parole as $w) {
                    if (mb_strpos($suo, $w) !== false)  { $punti += 3; $centri++; }
                    elseif (self::somiglia($w, $suo))   { $punti += 2; $centri++; }
                    elseif (mb_strpos($dove, $w) !== false) { $punti += 1; }
                }
                if ($punti > 0) {
                    $risultati[] = [
                        'entity_id' => (string) $eid,
                        'stanza'    => $nome_stanza,
                        'punti'     => $punti,
                        'parole'    => $centri,
                    ];
                }
            }
        }
        usort($risultati, static function ($x, $y) {
            return ($y['punti'] <=> $x['punti'])
                ?: (strlen($x['entity_id']) <=> strlen($y['entity_id']))
                ?: strcmp($x['entity_id'], $y['entity_id']);
        });

        return [
            'cercate'    => $parole,
            'stanza'     => $stanza !== '' ? $stanza : 'tutte',
            'trovati'    => count($risultati),
            'risultati'  => array_slice($risultati, 0, $limite),
            'come_legge' => 'Ordinati per quante parole della richiesta compaiono: il primo e\' il piu\' pertinente.',
        ];
    }

    /** Che aspetto puo' avere un'entita' in una pagina. */
    private static function card_disponibili(array $p) {
        $eid = trim((string) ($p['entity_id'] ?? ''));
        if ($eid === '') {
            return [
                'card_per_dominio' => self::CARD_PER_DOMINIO,
                'nota' => 'Passa entity_id per sapere quali valgono per una entita\' precisa.',
            ];
        }
        $dominio = (string) strtok($eid, '.');
        return [
            'entity_id' => $eid,
            'dominio'   => $dominio,
            'card'      => self::CARD_PER_DOMINIO[$dominio] ?? ['auto', 'badge'],
            'nota'      => '"auto" lascia scegliere al tema in base al dominio, ed e\' quasi sempre '
                         . 'la scelta giusta: le altre servono quando si vuole una vista piu\' stretta.',
        ];
    }

    // ------------------------------------------------------------ cambiare

    /** Sposta un dispositivo in un'altra stanza. */
    private static function sposta(array $p) {
        $dev = trim((string) ($p['dispositivo'] ?? $p['device_id'] ?? ''));
        $dove = trim((string) ($p['stanza'] ?? ''));
        if ($dev === '' || $dove === '') {
            return new \WP_Error('parametri_mancanti',
                'Servono "dispositivo" (device_id o entity_id) e "stanza".', ['status' => 400]);
        }
        $area = self::area_id_di($dove);
        if ($area === null) {
            return new \WP_Error('stanza_non_trovata',
                'Nessuna stanza con questo nome. Creala prima con action=crea-stanza.', ['status' => 404]);
        }

        // SI SPOSTA IL DISPOSITIVO, NON L'ENTITA', quando si puo': un
        // condizionatore porta con se' la temperatura, i consumi e lo stato
        // online. Spostando la sola entita' il resto resterebbe indietro, e la
        // stanza mostrerebbe meta' delle cose.
        if (strpos($dev, '.') !== false) {
            $trovato = self::device_di_entita($dev);
            if (is_wp_error($trovato)) return $trovato;
            if ($trovato === null) {
                $r = WP_GPT_Casa_WS::esegui([[
                    'type' => 'config/entity_registry/update',
                    'entity_id' => $dev, 'area_id' => $area,
                ]]);
                if (is_wp_error($r)) return $r;
                return ['spostata' => $dev, 'in' => $dove, 'come' => 'entita\' singola'];
            }
            $dev = $trovato;
        }

        $r = WP_GPT_Casa_WS::esegui([[
            'type' => 'config/device_registry/update',
            'device_id' => $dev, 'area_id' => $area,
        ]]);
        if (is_wp_error($r)) return $r;
        if (empty($r[0]['success'])) {
            return new \WP_Error('spostamento_fallito',
                (string) ($r[0]['error']['message'] ?? 'Home Assistant ha rifiutato lo spostamento.'),
                ['status' => 502]);
        }
        return [
            'spostato' => $dev,
            'in'       => $dove,
            'come'     => 'dispositivo intero, con tutte le sue entita\'',
        ];
    }

    /** Crea una stanza in Home Assistant. */
    private static function crea_stanza(array $p) {
        $nome = trim((string) ($p['nome'] ?? ''));
        if ($nome === '') {
            return new \WP_Error('nome_mancante', 'Serve "nome".', ['status' => 400]);
        }
        if (self::area_id_di($nome) !== null) {
            return ['creata' => false, 'gia_esiste' => true, 'nome' => $nome];
        }
        $r = WP_GPT_Casa_WS::esegui([['type' => 'config/area_registry/create', 'name' => $nome]]);
        if (is_wp_error($r)) return $r;
        if (empty($r[0]['success'])) {
            return new \WP_Error('creazione_fallita',
                (string) ($r[0]['error']['message'] ?? 'Home Assistant ha rifiutato la creazione.'),
                ['status' => 502]);
        }
        return ['creata' => true, 'nome' => $nome, 'id' => $r[0]['result']['area_id'] ?? ''];
    }

    /**
     * Crea una pagina con dentro solo le card che si sono scelte.
     *
     * NON E' LA PAGINA DI UNA STANZA: quelle le fa il generatore, una per area,
     * con tutto quello che l'area contiene. Questa serve al caso opposto -
     * "voglio una pagina con i quattro condizionatori", che stanno in quattro
     * stanze diverse.
     */
    private static function crea_pagina(array $p) {
        $titolo = trim((string) ($p['titolo'] ?? ''));
        $entita = (array) ($p['entita'] ?? $p['entities'] ?? []);
        if ($titolo === '' || !$entita) {
            return new \WP_Error('parametri_mancanti',
                'Servono "titolo" e "entita" (elenco di entity_id, o di {entity_id, card}).',
                ['status' => 400]);
        }

        $corpo = '';
        $n = 0;
        foreach ($entita as $e) {
            $eid  = is_array($e) ? (string) ($e['entity_id'] ?? '') : (string) $e;
            $card = is_array($e) ? (string) ($e['card'] ?? '') : '';
            if ($eid === '') continue;
            $n += 10;
            $corpo .= '[vwgk_ha_card entity_id="' . esc_attr($eid) . '" ordine="' . $n . '"'
                   . ($card !== '' && $card !== 'auto' ? ' mode="' . esc_attr($card) . '"' : '')
                   . ']' . "\n";
        }
        if ($corpo === '') {
            return new \WP_Error('entita_vuote', 'Nessun entity_id valido nell\'elenco.', ['status' => 400]);
        }

        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $titolo,
            'post_content' => $corpo,
        ], true);
        if (is_wp_error($id)) return $id;

        // IL MARCHIO E' QUELLO CHE LA RENDE VISIBILE E PROTETTA: il tema chiede
        // il login sulle pagine marcate e le elenca sotto "Le tue pagine".
        // Senza, la pagina esisterebbe e nessuno saprebbe raggiungerla.
        update_post_meta($id, '_vwgk_protected', '1');

        return [
            'creata'    => true,
            'id'        => $id,
            'titolo'    => $titolo,
            'indirizzo' => get_permalink($id),
            'card'      => $n / 10,
            'nota'      => 'La mappa della casa compare in cima da sola: la mette il tema.',
        ];
    }

    // ------------------------------------------------------------- dentro

    /** Piani e aree di Home Assistant, con le entita' di ognuna. */
    private static function struttura() {
        $modello = '{"areas": [' . "\n"
            . '{%- for a in areas() -%}' . "\n"
            . '{"id": {{ a | tojson }}, "name": {{ area_name(a) | tojson }}, '
            . '"entities": {{ area_entities(a) | tojson }}}{{ "," if not loop.last else "" }}' . "\n"
            . '{%- endfor -%}' . "\n" . ']}';

        $res = self::ha('POST', '/template', ['template' => $modello]);
        if (is_wp_error($res)) return $res;
        $dati = json_decode((string) $res, true);
        if (!is_array($dati) || !isset($dati['areas'])) {
            return new \WP_Error('struttura_illeggibile',
                'Home Assistant ha risposto in un formato che non so leggere.', ['status' => 502]);
        }
        return $dati;
    }

    /**
     * entity_id -> nome visibile, per tutta la casa, in una chiamata sola.
     *
     * Si legge dagli stati e non dal registro perche' li' il nome c'e' sempre:
     * quello del registro e' vuoto quando nessuno l'ha cambiato a mano, e
     * proprio le cose mai rinominate sono quelle che si cercano per nome.
     */
    private static function nomi_visibili(): array {
        $res = self::ha('GET', '/states');
        if (is_wp_error($res)) return [];
        $stati = json_decode((string) $res, true);
        if (!is_array($stati)) return [];
        $fuori = [];
        foreach ($stati as $st) {
            $eid = (string) ($st['entity_id'] ?? '');
            $nome = (string) ($st['attributes']['friendly_name'] ?? '');
            if ($eid !== '' && $nome !== '') $fuori[$eid] = $nome;
        }
        return $fuori;
    }

    private static function ha(string $metodo, string $percorso, ?array $corpo = null) {
        $url = trim((string) get_option('vwgk_ha_url', ''));
        $tok = (string) get_option('vwgk_ha_long_lived_token', '');
        if ($url === '' || $tok === '') {
            return new \WP_Error('ha_non_configurato',
                'Mancano indirizzo o token di Home Assistant nelle impostazioni.', ['status' => 503]);
        }
        $args = [
            'method'  => $metodo,
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'],
        ];
        if ($corpo !== null) $args['body'] = wp_json_encode($corpo);
        $r = wp_remote_request(rtrim($url, '/') . '/api' . $percorso, $args);
        if (is_wp_error($r)) return $r;
        $c = (int) wp_remote_retrieve_response_code($r);
        if ($c < 200 || $c > 299) {
            return new \WP_Error('ha_http', 'Home Assistant ha risposto ' . $c, ['status' => 502]);
        }
        return wp_remote_retrieve_body($r);
    }

    /**
     * La parola c'e' quasi: un refuso non deve nascondere una cosa.
     *
     * IL CASO CHE L'HA RESA NECESSARIA e' vero e sta in questa casa: la lampada
     * del soggiorno si chiama "Lamapada Di Legno". Cercando "lampada" nessuna
     * sottostringa combacia, e l'unica lampada di casa non compariva - mentre
     * comparivano quindici cose che c'entravano meno ma erano scritte giuste.
     *
     * SI CONFRONTA PEZZO PER PEZZO, non tutta la stringa: un entity_id e' lungo
     * e la distanza fra "lampada" e l'intero "light.lamapada_di_legno" sarebbe
     * enorme comunque. Spezzandolo, "lamapada" dista uno da "lampada" - due
     * lettere scambiate - e la si trova.
     *
     * LA SOGLIA CRESCE CON LA PAROLA: su parole corte una sola lettera di
     * differenza cambia gia' il significato ("luce" e "voce"), su parole lunghe
     * no. Sotto le cinque lettere non si tollera niente.
     */
    private static function somiglia(string $parola, string $testo): bool {
        $len = mb_strlen($parola);
        if ($len < 5) return false;
        $soglia = $len >= 8 ? 2 : 1;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $testo) ?: [] as $pezzo) {
            if ($pezzo === '' || abs(mb_strlen($pezzo) - $len) > $soglia + 1) continue;
            if (levenshtein($parola, $pezzo) <= $soglia) return true;
        }
        return false;
    }

    /** Confronto tollerante: accenti, maiuscole e spazi doppi non contano. */
    private static function stesso(string $a, string $b): bool {
        $n = static function (string $t): string {
            $t = function_exists('remove_accents') ? remove_accents($t) : $t;
            return preg_replace('/\s+/', ' ', trim(mb_strtolower(str_replace('_', ' ', $t))));
        };
        return $n($a) === $n($b);
    }

    private static function area_id_di(string $quale): ?string {
        $s = self::struttura();
        if (is_wp_error($s)) return null;
        foreach ($s['areas'] as $a) {
            if (self::stesso((string) ($a['id'] ?? ''), $quale)
                || self::stesso((string) ($a['name'] ?? ''), $quale)) {
                return (string) $a['id'];
            }
        }
        return null;
    }

    /** Il dispositivo a cui appartiene un'entita', o null se non ne ha uno. */
    private static function device_di_entita(string $entity_id) {
        $r = WP_GPT_Casa_WS::esegui([['type' => 'config/entity_registry/get', 'entity_id' => $entity_id]]);
        if (is_wp_error($r)) return $r;
        $d = $r[0]['result']['device_id'] ?? null;
        return $d ? (string) $d : null;
    }

    /** L'id della pagina WordPress di una stanza, o 0. */
    private static function pagina_di(string $area_id): int {
        if ($area_id === '') return 0;
        $q = get_posts([
            'post_type'   => 'page',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => '_vwgk_area_id',
            'meta_value'  => $area_id,
        ]);
        return $q ? (int) $q[0] : 0;
    }
}

endif;
