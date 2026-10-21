<?php
/**
 * Il "+": creare una pagina scegliendo le entita', dal sito e non dal codice.
 *
 * PERCHE' STA NEL TEMA. Le pagine di stanza le genera il plugin, in blocco,
 * una per area. Ma le pagine che servono davvero non sono quasi mai una
 * stanza: "i quattro condizionatori" stanno in quattro stanze diverse, e non
 * esiste nessuna area che li contenga. Quella pagina la puo' comporre solo chi
 * guarda la casa, sceglie e mette in fila - cioe' una scelta di presentazione,
 * che e' il mestiere del tema.
 *
 * PERCHE' NON UNA SCHERMATA DI AMMINISTRAZIONE. Chi usa questa dashboard ci
 * sta dentro, non nel pannello di WordPress: mandarlo in bacheca per fare una
 * pagina che poi guardera' qui vuol dire due posti da imparare invece di uno.
 *
 * COSA NON FA, ed e' voluto: non tocca le pagine di stanza generate dal
 * plugin. Quelle si rigenerano, e una modifica fatta qui verrebbe
 * sovrascritta al primo giro senza avvisare nessuno.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Chi puo' comporre pagine: chi le puo' gia' scrivere in WordPress. */
function vwg_creatore_permesso(): bool
{
    return is_user_logged_in() && current_user_can('edit_pages');
}

/**
 * L'elenco delle entita', raggruppate per stanza e con il nome che si legge.
 *
 * DUE CHIAMATE E NON UNA PER ENTITA': la struttura dice chi sta dove, gli
 * stati dicono come si chiamano. Chiedere il nome una entita' alla volta
 * vorrebbe dire centinaia di richieste per aprire un elenco.
 */
function vwg_creatore_entita()
{
    if (!class_exists('VWGK_HA_Rooms')) {
        return new WP_Error('vwg_no_plugin', __('Manca il plugin che parla con Home Assistant.', 'vwg-dashboard'));
    }
    $st = VWGK_HA_Rooms::fetch_structure();
    if (is_wp_error($st)) {
        return $st;
    }
    $nomi = VWGK_HA_Rooms::states_map();
    $fuori = [];
    foreach (($st['areas'] ?? []) as $a) {
        $voci = [];
        foreach (($a['entities'] ?? []) as $eid) {
            $punto = strpos($eid, '.');
            $voci[] = [
                'id'      => $eid,
                'nome'    => $nomi[$eid]['attributes']['friendly_name'] ?? $eid,
                'dominio' => $punto === false ? '' : substr($eid, 0, $punto),
            ];
        }
        if ($voci) {
            usort($voci, static function ($x, $y) {
                return strcasecmp($x['nome'], $y['nome']);
            });
            $fuori[] = ['nome' => $a['name'] ?? $a['id'], 'entita' => $voci];
        }
    }
    usort($fuori, static function ($x, $y) {
        return strcasecmp($x['nome'], $y['nome']);
    });
    return $fuori;
}

/**
 * Quanto "pesa" un dominio: chi ha piu' controlli finisce in cima.
 *
 * E' lo stesso criterio con cui il plugin ordina le pagine di stanza. Ripetuto
 * qui e non condiviso perche' il tema deve poter comporre una pagina anche
 * quando il generatore del plugin non e' di mezzo - ma se cambia uno dei due,
 * va cambiato anche l'altro.
 */
function vwg_creatore_peso(string $dominio): int
{
    $peso = [
        'climate' => 100, 'media_player' => 80, 'cover' => 70, 'fan' => 60,
        'light' => 50, 'switch' => 40, 'lock' => 35, 'camera' => 30,
        'binary_sensor' => 10, 'sensor' => 5,
    ];
    return $peso[$dominio] ?? 20;
}

/**
 * La pagina che ospita il modulo: trovata, o fatta una volta sola.
 *
 * L'id sta in un'opzione e non si cerca per titolo: un titolo lo si rinomina,
 * e al primo rinomino ne nascerebbe una seconda uguale senza che nessuno
 * capisca perche'. Se la pagina viene cancellata l'opzione resta indietro,
 * quindi si controlla che esista davvero prima di fidarsi.
 */
function vwg_creatore_pagina_id(): int
{
    $id = (int) get_option('vwg_creatore_pagina', 0);
    if ($id && get_post_status($id) === 'publish') {
        return $id;
    }
    if (!vwg_creatore_permesso()) {
        return 0;   // non la crea chi non potrebbe comunque usarla
    }
    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => __('Nuova pagina', 'vwg-dashboard'),
        'post_content' => '[vwg_nuova_pagina]',
    ], true);
    if (is_wp_error($id)) {
        return 0;
    }
    update_post_meta($id, '_vwgk_protected', 1);
    update_post_meta($id, '_vwg_creatore', 1);
    update_option('vwg_creatore_pagina', (int) $id);
    return (int) $id;
}

/** Il modulo, dentro la dashboard. Si mette in una pagina con [vwg_nuova_pagina]. */
add_shortcode('vwg_nuova_pagina', function () {
    if (!vwg_creatore_permesso()) {
        return '<p>' . esc_html__('Non hai i permessi per creare pagine.', 'vwg-dashboard') . '</p>';
    }
    $gruppi = vwg_creatore_entita();
    if (is_wp_error($gruppi)) {
        return '<p class="vwg-errore">' . esc_html($gruppi->get_error_message()) . '</p>';
    }

    ob_start();
    ?>
    <form class="vwg-creatore" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="vwg_crea_pagina">
      <?php wp_nonce_field('vwg_crea_pagina'); ?>

      <p><label><?php esc_html_e('Titolo della pagina', 'vwg-dashboard'); ?><br>
        <input type="text" name="titolo" required style="width:100%;max-width:420px"></label></p>

      <p><label><input type="checkbox" name="casa" value="1" checked>
        <?php esc_html_e('Metti la casa in cima', 'vwg-dashboard'); ?></label></p>

      <p><input type="search" id="vwg-cerca" style="width:100%;max-width:420px"
                placeholder="<?php esc_attr_e('Filtra per nome...', 'vwg-dashboard'); ?>"></p>

      <?php foreach ($gruppi as $g) : ?>
        <fieldset class="vwg-gruppo" style="border:1px solid #ccc;border-radius:8px;margin:10px 0;padding:8px">
          <legend><b><?php echo esc_html($g['nome']); ?></b></legend>
          <?php foreach ($g['entita'] as $e) : ?>
            <label class="vwg-voce" style="display:block;padding:2px 0"
                   data-nome="<?php echo esc_attr(mb_strtolower($e['nome'] . ' ' . $e['id'])); ?>">
              <input type="checkbox" name="entita[]" value="<?php echo esc_attr($e['id']); ?>">
              <?php echo esc_html($e['nome']); ?>
              <small style="opacity:.6"><?php echo esc_html($e['id']); ?></small>
            </label>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>

      <p><button type="submit"><?php esc_html_e('Crea la pagina', 'vwg-dashboard'); ?></button></p>
    </form>
    <script>
    // Il filtro sta solo qui davanti: le entita' sono gia' tutte nella pagina,
    // e richiederle al server a ogni lettera vorrebbe dire aspettare Home
    // Assistant per scrivere una parola.
    (function () {
      var c = document.getElementById('vwg-cerca');
      if (!c) { return; }
      c.addEventListener('input', function () {
        var q = c.value.trim().toLowerCase();
        document.querySelectorAll('.vwg-creatore .vwg-gruppo').forEach(function (g) {
          var visti = 0;
          g.querySelectorAll('.vwg-voce').forEach(function (v) {
            var ok = !q || v.dataset.nome.indexOf(q) >= 0;
            v.hidden = !ok;
            if (ok) { visti++; }
          });
          g.hidden = (visti === 0);
        });
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * La creazione vera.
 *
 * L'ORDINE LO DECIDE IL PESO, non l'ordine in cui uno ha spuntato le caselle:
 * chi sceglie sta leggendo un elenco alfabetico, non sta componendo. Il numero
 * finisce nell'attributo "ordine" della card, quindi resta modificabile dopo -
 * a mano o trascinando - senza rimescolare il testo della pagina.
 */
add_action('admin_post_vwg_crea_pagina', function () {
    if (!vwg_creatore_permesso() || !check_admin_referer('vwg_crea_pagina')) {
        wp_die(esc_html__('Non autorizzato.', 'vwg-dashboard'));
    }
    $titolo = sanitize_text_field(wp_unslash($_POST['titolo'] ?? ''));
    $scelte = array_map('sanitize_text_field', (array) wp_unslash($_POST['entita'] ?? []));
    $casa   = !empty($_POST['casa']);
    if ($titolo === '' || !$scelte) {
        wp_die(esc_html__('Serve un titolo e almeno una entita.', 'vwg-dashboard'));
    }

    $nomi  = class_exists('VWGK_HA_Rooms') ? VWGK_HA_Rooms::states_map() : [];
    $righe = [];
    foreach ($scelte as $eid) {
        $punto   = strpos($eid, '.');
        $dominio = $punto === false ? '' : substr($eid, 0, $punto);
        $righe[] = [
            'id'   => $eid,
            'peso' => vwg_creatore_peso($dominio),
            'nome' => $nomi[$eid]['attributes']['friendly_name'] ?? '',
        ];
    }
    usort($righe, static function ($x, $y) {
        return $y['peso'] <=> $x['peso'];
    });

    $corpo = $casa ? "[vwg_casa]\n\n" : '';
    $n = 10;
    foreach ($righe as $r) {
        $corpo .= sprintf(
            '[vwgk_ha_card entity_id="%s"%s ordine="%d"]' . "\n",
            $r['id'],
            $r['nome'] !== '' ? ' name="' . esc_attr($r['nome']) . '"' : '',
            $n
        );
        $n += 10;
    }

    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $titolo,
        'post_content' => $corpo,
    ], true);
    if (is_wp_error($id)) {
        wp_die(esc_html($id->get_error_message()));
    }
    // IL MARCHIO CHE LA PROTEGGE. L'autenticazione non deve dipendere da cosa
    // c'e' scritto dentro: una pagina svuotata, o che un domani usa un altro
    // shortcode, smetterebbe di essere protetta senza che nessuno se ne accorga.
    update_post_meta($id, '_vwgk_protected', 1);

    wp_safe_redirect(get_permalink($id));
    exit;
});
