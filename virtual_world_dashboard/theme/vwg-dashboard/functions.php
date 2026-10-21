<?php
/**
 * VWG Dashboard - tema che presenta e protegge le pagine stanze/piani
 * generate dal plugin Virtual World Gate Key.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('VWG_DASHBOARD_VER')) {
    define('VWG_DASHBOARD_VER', '1.0.0');
}

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => __('Menu principale', 'vwg-dashboard')]);
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('vwg-dashboard', get_stylesheet_uri(), [], VWG_DASHBOARD_VER);
});

/**
 * Protezione lato tema (in coordinamento col plugin): qualsiasi pagina marcata
 * come protetta (_vwgk_protected) o stanza/piano richiede il login.
 */
add_action('template_redirect', function () {
    if (is_user_logged_in() || is_admin()) {
        return;
    }
    if (!is_singular()) {
        return;
    }
    $id = get_queried_object_id();
    if (!$id) {
        return;
    }
    $protected = get_post_meta($id, '_vwgk_protected', true)
        || get_post_meta($id, '_vwgk_area_id', true)
        || get_post_meta($id, '_vwgk_floor_id', true);
    if ($protected) {
        auth_redirect();
        exit;
    }
}, 5);

/**
 * Elenco piani -> stanze basato sui meta scritti dal plugin.
 * Ritorna [ ['id'=>floor_page_id,'title'=>..,'rooms'=>[ ['id'=>page_id,'title'=>..], .. ] ], .. ]
 * più una voce "senza piano" per le stanze senza floor.
 */
function vwg_dashboard_tree(): array
{
    $floors = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'meta_key'    => '_vwgk_floor_id',
        'meta_compare'=> 'EXISTS',
    ]);

    $tree = [];
    $seen_rooms = [];
    foreach ($floors as $f) {
        $rooms = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'post_parent' => $f->ID,
            'meta_key'    => '_vwgk_area_id',
            'meta_compare'=> 'EXISTS',
        ]);
        $rlist = [];
        foreach ($rooms as $r) {
            $seen_rooms[$r->ID] = true;
            $rlist[] = ['id' => $r->ID, 'title' => get_the_title($r)];
        }
        $tree[] = ['id' => $f->ID, 'title' => get_the_title($f), 'rooms' => $rlist];
    }

    // stanze senza piano
    $orphans = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'meta_key'    => '_vwgk_area_id',
        'meta_compare'=> 'EXISTS',
    ]);
    $olist = [];
    foreach ($orphans as $r) {
        if (empty($seen_rooms[$r->ID])) {
            $olist[] = ['id' => $r->ID, 'title' => get_the_title($r)];
        }
    }
    if ($olist) {
        $tree[] = ['id' => 0, 'title' => __('Senza piano', 'vwg-dashboard'), 'rooms' => $olist];
    }
    return $tree;
}

/** Sidebar di navigazione piani/stanze. */
function vwg_dashboard_nav(): void
{
    $tree = vwg_dashboard_tree();
    $current = get_queried_object_id();
    if (!$tree) {
        echo '<p class="mut">' . esc_html__('Nessuna stanza. Genera le pagine dal plugin VWGK.', 'vwg-dashboard') . '</p>';
    } else {
    echo '<h3>' . esc_html__('Casa', 'vwg-dashboard') . '</h3>';
    foreach ($tree as $floor) {
        echo '<div class="floor">' . esc_html($floor['title']) . '</div>';
        echo '<ul>';
        foreach ($floor['rooms'] as $room) {
            $cls = ((int) $room['id'] === (int) $current) ? ' class="active"' : '';
            printf(
                '<li class="room"><a%s href="%s">%s</a></li>',
                $cls,
                esc_url(get_permalink($room['id'])),
                esc_html($room['title'])
            );
        }
        echo '</ul>';
    }
    }

    vwg_dashboard_nav_pagine($current);
}

/**
 * Le pagine che non sono stanze: quelle fatte a mano o dal tasto +.
 *
 * PERCHE' SERVE. Il menu elencava solo piani e stanze, riconosciuti dai loro
 * meta. Una pagina come "Controllo condizionatori" - quattro climatizzatori di
 * stanze diverse messi insieme - non e' una stanza e non ha quei meta: c'era,
 * funzionava, e dal menu non si raggiungeva. Chi l'aveva fatta poteva solo
 * ricordarsi il suo indirizzo.
 *
 * SI RICONOSCONO DA _vwgk_protected: e' il marchio che il tema usa gia' per
 * decidere che una pagina vuole il login, quindi dice esattamente "questa e'
 * una pagina della domotica". Le stanze si escludono perche' hanno gia' la
 * loro sezione.
 */
function vwg_dashboard_nav_pagine(int $current): void
{
    $pagine = get_posts([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'numberposts'  => -1,
        'orderby'      => 'title',
        'order'        => 'ASC',
        'meta_key'     => '_vwgk_protected',
        'meta_compare' => 'EXISTS',
    ]);
    $fuori = [];
    foreach ($pagine as $p) {
        if (get_post_meta($p->ID, '_vwgk_area_id', true) || get_post_meta($p->ID, '_vwgk_floor_id', true)) {
            continue;   // e' una stanza o un piano: sta gia' sopra
        }
        $fuori[] = $p;
    }
    // IL CREATORE NON SI ELENCA FRA LE PAGINE: non e' una pagina da guardare,
    // e' il bottone per farne una. Sta in fondo con il suo "+".
    $creatore = function_exists('vwg_creatore_pagina_id') ? vwg_creatore_pagina_id() : 0;
    $fuori = array_values(array_filter($fuori, static function ($p) use ($creatore) {
        return (int) $p->ID !== (int) $creatore;
    }));
    if (!$fuori && !$creatore) {
        return;
    }
    echo '<h3>' . esc_html__('Le tue pagine', 'vwg-dashboard') . '</h3><ul>';
    foreach ($fuori as $p) {
        $cls = ((int) $p->ID === $current) ? ' class="active"' : '';
        printf(
            '<li class="room"><a%s href="%s">%s</a></li>',
            $cls,
            esc_url(get_permalink($p->ID)),
            esc_html(get_the_title($p->ID))
        );
    }
    if ($creatore) {
        printf(
            '<li class="room nuova"><a%s href="%s">%s</a></li>',
            ((int) $creatore === $current) ? ' class="active"' : '',
            esc_url(get_permalink($creatore)),
            esc_html__('+ Nuova pagina', 'vwg-dashboard')
        );
    }
    echo '</ul>';
}

/* ============================================================ la casa
 *
 * LA PLANIMETRIA DELL'ECLISSI, DENTRO LE PAGINE, SENZA DARE VIA LA CHIAVE.
 *
 * L'add-on Eclipse Finder disegna gia' la casa - in 2D e in 3D - e la
 * espone con /embed. Ridisegnarla qui vorrebbe dire due renderer per la
 * stessa casa, che il giorno dopo mostrano cose diverse: uno sa dei
 * poligoni e l'altro no, uno disegna le finestre e l'altro le dimentica.
 * Quindi si incorpora quella vera.
 *
 * MA NON CON UN IFRAME DIRETTO, per due motivi indipendenti.
 *
 * Il primo: /embed vuole la chiave dell'add-on, e una chiave in un
 * <iframe src> finisce nell'HTML. La pagina e' dietro login, quindi non la
 * vede un estraneo - ma la vede ogni utente loggato, e quella chiave apre
 * anche /api/export, cioe' la planimetria intera della casa.
 *
 * Il secondo: l'add-on vive su un indirizzo INTERNO di Home Assistant
 * (local-eclipse-finder:8099). Il browser di chi guarda non lo raggiunge:
 * un iframe verso quell'indirizzo mostrerebbe una pagina vuota.
 *
 * Tutti e due si risolvono nello stesso modo: e' WordPress a parlare con
 * l'add-on, dal lato server, e a ripassare quello che riceve.
 *
 * SI PASSA ANCHE IL RESTO, non solo la pagina. Quella pagina chiede da sola
 * api/plan, i suoi script e le sue foto con indirizzi RELATIVI: servendo
 * solo l'HTML, quelle richieste finirebbero su WordPress e tornerebbero 404,
 * e si vedrebbe un riquadro bianco. Per questo il tramite vive sotto un
 * prefisso - /vwg-casa/ - e tutto quello che sta sotto viene inoltrato: cosi'
 * gli indirizzi relativi continuano a cadere dove devono.
 */

require_once __DIR__ . '/inc-creatore-pagine.php';

define('VWG_CASA_PREFISSO', 'vwg-casa');

add_action('init', function () {
    add_rewrite_rule('^' . VWG_CASA_PREFISSO . '/?(.*)$', 'index.php?vwg_casa=$matches[1]', 'top');
    add_rewrite_tag('%vwg_casa%', '(.*)');
});

/** Il prefisso e' nuovo: senza questo le regole non entrano in vigore fino a
 *  quando qualcuno non salva i permalink, e la casa resta un 404 misterioso. */
add_action('after_switch_theme', function () { flush_rewrite_rules(); });

add_action('template_redirect', function () {
    $percorso = get_query_var('vwg_casa', null);
    if ($percorso === null || $percorso === false) {
        return;
    }

    // CHI NON E' ENTRATO NON VEDE LA CASA. Vale qui e non solo sulla pagina
    // che la ospita: un indirizzo diretto e' comunque un indirizzo.
    if (!is_user_logged_in()) {
        auth_redirect();
        exit;
    }

    $base = trim((string) get_option('vwgk_eclipse_url', ''));
    $chiave = (string) get_option('vwgk_eclipse_api_key', '');
    if ($base === '') {
        status_header(503);
        exit(esc_html__('Eclipse Finder non e\' configurato: manca il suo indirizzo.', 'vwg-dashboard'));
    }

    $percorso = ltrim((string) $percorso, '/');
    if ($percorso === '') {
        $percorso = 'embed';
    }
    // Non si esce dall'add-on: niente "..", niente indirizzi assoluti.
    if (strpos($percorso, '..') !== false || preg_match('#^[a-z]+://#i', $percorso)) {
        status_header(400);
        exit('percorso non valido');
    }

    $url = rtrim($base, '/') . '/' . $percorso;
    $q = [];
    // La chiave la mette WordPress, qui, e non esce mai dal server.
    if (strpos($percorso, 'embed') === 0 || strpos($percorso, 'api/') === 0) {
        if ($chiave !== '') {
            $q['key'] = $chiave;
        }
    }
    if (!empty($_GET['room'])) {
        $q['room'] = sanitize_text_field(wp_unslash($_GET['room']));
    }
    if ($q) {
        $url = add_query_arg($q, $url);
    }

    $res = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0]);
    if (is_wp_error($res)) {
        status_header(502);
        exit(esc_html__('Eclipse Finder non risponde.', 'vwg-dashboard'));
    }

    $codice = (int) wp_remote_retrieve_response_code($res);
    $tipo = wp_remote_retrieve_header($res, 'content-type');
    status_header($codice ?: 200);
    if ($tipo) {
        header('Content-Type: ' . $tipo);
    }
    // Non si mette in cache: la casa cambia quando qualcuno sposta una stanza.
    nocache_headers();
    echo wp_remote_retrieve_body($res);
    exit;
}, 1);

/**
 * [vwg_casa] - la casa, da mettere in cima a una pagina stanza.
 *
 * L'altezza si puo' cambiare; la stanza si passa con room="", e se non la si
 * passa il tema usa quella associata alla pagina - cosi' nelle pagine
 * generate lo shortcode si scrive una volta sola, uguale per tutte.
 */
add_shortcode('vwg_casa', function ($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    $a = shortcode_atts(['room' => '', 'altezza' => '420'], $atts, 'vwg_casa');
    $stanza = $a['room'];
    if ($stanza === '') {
        $stanza = (string) get_post_meta(get_the_ID(), '_vwgk_eclipse_room', true);
    }
    // SE NESSUNO L'HA DETTO, LO DICE IL TITOLO. Il meta _vwgk_eclipse_room non
    // lo scrive nessuno: era pensato per un collegamento esplicito che non e'
    // mai stato costruito, quindi la casa arrivava sempre senza stanza e la
    // pagina della camera da letto mostrava la casa intera senza indicare la
    // camera da letto. Il titolo della pagina stanza E' il nome dell'area, e
    // l'add-on accetta il nome oltre all'id: basta quello, e non c'e' una
    // terza lista da tenere allineata a mano.
    if ($stanza === '' && get_post_meta(get_the_ID(), '_vwgk_area_id', true)) {
        $stanza = (string) get_the_title(get_the_ID());
    }
    $src = home_url('/' . VWG_CASA_PREFISSO . '/embed');
    if ($stanza !== '') {
        $src = add_query_arg('room', rawurlencode($stanza), $src);
    }
    return sprintf(
        '<div class="vwg-casa"><iframe src="%s" loading="lazy" style="width:100%%;height:%dpx;border:0;display:block" title="%s"></iframe></div>',
        esc_url($src),
        (int) $a['altezza'],
        esc_attr__('La casa', 'vwg-dashboard')
    );
});

/**
 * La casa in cima alle pagine stanza, senza scriverla in ognuna.
 *
 * PERCHE' NON NEL CONTENUTO. Le pagine stanza le genera un programma: se la
 * casa fosse una riga scritta dentro ogni pagina, cambiarla vorrebbe dire
 * rigenerarle tutte, e chi ne ha modificata una a mano se la vedrebbe
 * sovrascritta. Messa qui, la decide il tema - che e' il posto in cui ha
 * senso, perche' e' una scelta di presentazione, non un contenuto.
 *
 * SI RISPETTA CHI L'HA GIA' MESSA: se nella pagina c'e' gia' [vwg_casa],
 * quella vince e qui non si aggiunge niente. Chi ha voluto metterla in mezzo
 * al testo, o non metterla affatto, ha deciso.
 */
add_filter('the_content', function ($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (!is_user_logged_in()) {
        return $content;
    }
    $id = get_the_ID();
    // solo le pagine stanza: quelle marcate con l'area di Home Assistant
    if (!get_post_meta($id, '_vwgk_area_id', true)) {
        return $content;
    }
    if (has_shortcode((string) $content, 'vwg_casa')) {
        return $content;
    }
    return do_shortcode('[vwg_casa]') . $content;
}, 9);
