<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Entity_Manager
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_submenu']);
        
        // Handle actions
        add_action('admin_post_vwgk_sync_entities', [__CLASS__, 'handle_sync']);
        add_action('wp_ajax_vwgk_toggle_hide_entity', [__CLASS__, 'ajax_toggle_hide_entity']);
    }

    public static function add_submenu(): void
    {
        add_submenu_page(
            'vwgk-settings',
            __('Gestione Entità & Generatore', 'virtual-world-gate-key'),
            __('Gestione Entità', 'virtual-world-gate-key'),
            'manage_options',
            'vwgk-entities',
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_sync(): void
    {
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'vwgk_sync_nonce')) {
            wp_die('Permessi insufficienti o nonce non valido.');
        }

        // Forza la cancellazione della cache per fare un nuovo fetching alle API
        delete_transient('vwgk_ha_entities_cache');
        
        // Prepara url di reindirizzamento
        $redirect_url = add_query_arg(['page' => 'vwgk-entities', 'synced' => '1'], admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public static function ajax_toggle_hide_entity(): void
    {
        check_ajax_referer('vwgk_entity_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission');
        }

        $entity_id = sanitize_text_field($_POST['entity_id'] ?? '');
        $hide = sanitize_text_field($_POST['hide'] ?? '1');

        if (empty($entity_id)) {
            wp_send_json_error('Missing ID');
        }

        $hidden_entities = get_option('vwgk_hidden_entities', []);
        
        if ($hide === '1') {
            if (!in_array($entity_id, $hidden_entities)) {
                $hidden_entities[] = $entity_id;
            }
        } else {
            $hidden_entities = array_diff($hidden_entities, [$entity_id]);
        }

        update_option('vwgk_hidden_entities', $hidden_entities);
        wp_send_json_success(['hidden' => ($hide === '1')]);
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Recupera gli hidden per pre-selezionarli o segnalarli
        $hidden_entities = get_option('vwgk_hidden_entities', []);
        
        // Recupera le entità. Usiamo getAll senza i filtri base di restrizione per vederle tutte,
        // o applichiamo la logica locale se preferito. Per l'elenco completo backend:
        $all_states = VWGK_HA_API::get_states(); // Recupera tutto da cache o HA vivo

        if (isset($_GET['synced']) && $_GET['synced'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Cache entità sincronizzata con successo tramite API di Home Assistant.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-grid-view"></span> Gestione Entità e Costruttore Interfacce</h1>
            <p>Seleziona le entità che desideri includere nelle tue pagine e clicca "Genera Shortcode". Puoi anche nascondere entità inutili.</p>

            <!-- SEZIONE BUILDER (Fissa in alto) -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px; border-radius: 4px;">
                <h3><span class="dashicons dashicons-editor-code"></span> Generatore Shortcode</h3>
                
                <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label for="vwgk_builder_columns"><strong>Numero di colonne guscio (Layout):</strong></label><br>
                        <select id="vwgk_builder_columns" style="min-width: 150px;">
                            <option value="1">1 Colonna (Lista)</option>
                            <option value="2">2 Colonne</option>
                            <option value="3" selected>3 Colonne</option>
                            <option value="4">4 Colonne</option>
                        </select>
                    </div>

                    <div>
                        <button id="vwgk_generate_btn" class="button button-primary" style="height: 30px;">Genera Shortcode <span class="dashicons dashicons-admin-links" style="line-height: inherit; vertical-align: middle;"></span></button>
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <label><strong>Risultato (Copia e Incolla dove preferisci):</strong></label>
                    <div style="display:flex; gap:10px;">
                        <input type="text" id="vwgk_shortcode_result" class="large-text code" readonly placeholder="Il codice apparirà qui...">
                        <button id="vwgk_copy_btn" class="button" title="Copia negli appunti"><span class="dashicons dashicons-clipboard"></span> Copia</button>
                    </div>
                </div>
            </div>
            
            <!-- AZIONI GLOBALI -->
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; align-items: center;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('vwgk_sync_nonce'); ?>
                    <input type="hidden" name="action" value="vwgk_sync_entities">
                    <button type="submit" class="button"><span class="dashicons dashicons-image-rotate"></span> Svuota Cache / Sincronizza API</button>
                </form>

                <div>
                    <input type="text" id="vwgk_entity_search" placeholder="Cerca per nome o ID..." style="width: 250px;">
                </div>
            </div>

            <!-- TABELLA ENTITA -->
            <table class="wp-list-table widefat fixed striped" id="vwgk_entity_table">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column">
                            <input id="cb-select-all" type="checkbox" title="Seleziona tutte">
                        </td>
                        <th scope="col" style="width: 60px;">Icona</th>
                        <th scope="col">Nome (Friendly Name)</th>
                        <th scope="col">Entity ID</th>
                        <th scope="col">Dominio</th>
                        <th scope="col">Stato Attuale</th>
                        <th scope="col" style="width: 120px; text-align: center;">Visibilità</th>
                    </tr>
                </thead>
                <tbody id="the-list">
                    <?php if (empty($all_states)) : ?>
                        <tr><td colspan="7">Nessuna entità trovata. Controlla la connessione ad Home Assistant.</td></tr>
                    <?php else : ?>
                        <?php foreach ($all_states as $state) : 
                            $e_id = esc_attr($state['entity_id']);
                            $domain = explode('.', $e_id)[0];
                            $fname = esc_html($state['attributes']['friendly_name'] ?? $e_id);
                            $is_hidden = in_array($state['entity_id'], $hidden_entities);
                            $state_val = esc_html($state['state']);
                            ?>
                            <tr class="<?php echo $is_hidden ? 'vwgk-hidden-row' : ''; ?>" style="<?php echo $is_hidden ? 'opacity:0.5;' : ''; ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="vwgk-entity-cb" value="<?php echo $e_id; ?>" <?php echo $is_hidden ? 'disabled title="Entità nascosta"' : ''; ?>>
                                </th>
                                <td>
                                    <?php echo self::get_basic_icon_for_domain($domain); ?>
                                </td>
                                <td><strong><?php echo $fname; ?></strong></td>
                                <td><code><?php echo $e_id; ?></code></td>
                                <td><span class="badge" style="background:#555; color:#fff; padding:2px 6px; border-radius:10px; font-size:11px;"><?php echo esc_html($domain); ?></span></td>
                                <td><?php echo $state_val; ?></td>
                                <td style="text-align: center;">
                                    <button class="button vwgk-toggle-visibility" data-id="<?php echo $e_id; ?>" data-hide="<?php echo $is_hidden ? '0' : '1'; ?>" title="Mostra/Nascondi nei frontend">
                                        <span class="dashicons <?php echo $is_hidden ? 'dashicons-hidden' : 'dashicons-visibility'; ?>"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Ricerca testuale
            $('#vwgk_entity_search').on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $("#the-list tr").filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            });

            // Seleziona tutto
            $('#cb-select-all').on('change', function() {
                var checked = $(this).prop('checked');
                $('.vwgk-entity-cb:not(:disabled)').prop('checked', checked);
            });

            // Nascondi / Mostra Entità tramite Ajax
            $('.vwgk-toggle-visibility').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var entity_id = $btn.data('id');
                var hide_val = $btn.data('hide');
                var $row = $btn.closest('tr');
                var $cb = $row.find('.vwgk-entity-cb');

                $.post(ajaxurl, {
                    action: 'vwgk_toggle_hide_entity',
                    nonce: '<?php echo wp_create_nonce("vwgk_entity_nonce"); ?>',
                    entity_id: entity_id,
                    hide: hide_val
                }, function(res) {
                    if(res.success) {
                        if(res.data.hidden) {
                            $row.css('opacity', '0.5');
                            $btn.data('hide', '0');
                            $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                            $cb.prop('checked', false).prop('disabled', true);
                        } else {
                            $row.css('opacity', '1');
                            $btn.data('hide', '1');
                            $btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                            $cb.prop('disabled', false);
                        }
                    } else {
                        alert('Errore: impossibile cambiare visibilità.');
                    }
                });
            });

            // Generatore Shortcode
            $('#vwgk_generate_btn').on('click', function() {
                var selected = [];
                $('.vwgk-entity-cb:checked').each(function() {
                    selected.push($(this).val());
                });

                if(selected.length === 0) {
                    alert('Seleziona almeno un\'entità dalla tabella mettendo la spunta!');
                    return;
                }

                var columns = $('#vwgk_builder_columns').val();
                var entitiesStr = selected.join(', ');
                
                var shortcode = '[vwgk_ha_entities entities="' + entitiesStr + '" columns="' + columns + '"]';
                $('#vwgk_shortcode_result').val(shortcode);
            });

            // Copia negli appunti
            $('#vwgk_copy_btn').on('click', function() {
                var $input = $('#vwgk_shortcode_result');
                if($input.val() === '') return;
                
                $input.select();
                document.execCommand('copy');
                
                var $btn = $(this);
                var originalHtml = $btn.html();
                $btn.html('<span class="dashicons dashicons-yes-alt"></span> Copiato!');
                setTimeout(function() {
                    $btn.html(originalHtml);
                }, 2000);
            });
        });
        </script>
        <?php
    }

    private static function get_basic_icon_for_domain($domain) {
        $icon = 'admin-generic'; // default
        switch($domain) {
            case 'light': $icon = 'lightbulb'; break;
            case 'switch':
            case 'input_boolean': $icon = 'admin-plugins'; break;
            case 'sensor': $icon = 'dashboard'; break;
            case 'binary_sensor': $icon = 'shield-alt'; break;
            case 'cover': $icon = 'align-center'; break;
            case 'media_player': $icon = 'controls-play'; break;
            case 'climate': $icon = 'marker'; break;
            case 'camera': $icon = 'video-alt3'; break;
            case 'person': $icon = 'admin-users'; break;
            case 'lock': $icon = 'lock'; break;
            case 'sun': $icon = 'visibility'; break;
            case 'script': 
            case 'automation': $icon = 'editor-code'; break;
        }
        return '<span class="dashicons dashicons-' . $icon . '"></span>';
    }
}
