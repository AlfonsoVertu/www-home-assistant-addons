jQuery(document).ready(function($) {
    if (typeof vwgkHaParams === 'undefined') {
        return;
    }

    // 1. Gestione Refresh Shortcode — unified AJAX handler
    function doRefresh($btn, $container, action) {
        var entities = $container.data('entities') || '';
        var limit    = $container.data('limit')    || 0;
        var template = $container.data('template') || '';

        $container.addClass('vwgk-ha-loading').html('');

        $.post(vwgkHaParams.ajax_url, {
            action:   action,
            nonce:    vwgkHaParams.nonce,
            entities: entities,
            limit:    limit,
            template: template
        }, function(response) {
            $container.removeClass('vwgk-ha-loading');
            $btn.prop('disabled', false).text('Aggiorna Dati');
            if (response.success) {
                $container.html(response.data);
                attachCardEvents($container);
            } else {
                $container.html('<p style="color:red;padding:20px;">Errore: ' + (response.data || 'Richiesta fallita') + '</p>');
            }
        }).fail(function() {
            $container.removeClass('vwgk-ha-loading');
            $btn.prop('disabled', false).text('Aggiorna Dati');
            $container.html('<p style="color:red;padding:20px;">Errore di connessione.</p>');
        });
    }

    $(document).on('click', '.vwgk-ha-refresh-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var action = $btn.data('action');
        var $container = $btn.siblings('.vwgk-ha-grid');

        $btn.prop('disabled', true).text('Aggiornamento...');
        doRefresh($btn, $container, action);
    });

    // Auto-fetch iniziale per tutti i pannelli
    $('.vwgk-ha-refresh-btn').each(function() {
        $(this).trigger('click');
    });

    // 2. Eventi Interattivi sulle Card (Delegati Globalmente)
    window.vwgkPending = {};

    function markDirty($card, entityId, attr, value) {
        if (!window.vwgkPending[entityId]) {
            window.vwgkPending[entityId] = {};
        }
        window.vwgkPending[entityId][attr] = value;
        $card.addClass('vwgk-ha-card--dirty');
    }

    function clearDirty($card, entityId) {
        delete window.vwgkPending[entityId];
        $card.removeClass('vwgk-ha-card--dirty');
    }

    // Toggle (Switch/Light/Climate Power) -> ATTIVAZIONE IMMEDIATA
    $(document).on('change', '.vwgk-ha-toggle', function() {
        var $input = $(this);
        var $card = $input.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var newState = $input.is(':checked') ? 'on' : 'off';
        
        $card.css('opacity', '0.5');

        $.post(vwgkHaParams.ajax_url, {
            action: 'vwgk_ha_execute',
            nonce: vwgkHaParams.nonce,
            entity_id: entityId,
            state: newState
        }, function(response) {
            $card.css('opacity', '1');
            if (response.success) {
                $card.attr('data-state', newState);
                $card.find('.vwgk-ha-card-state--live').text(newState);
            } else {
                alert('Errore: ' + (response.data || 'Impossibile cambiare stato'));
                $input.prop('checked', !$input.is(':checked')); 
            }
        });
    });

    // Esegui (Script/Automation/Lock/Generic) -> ATTIVAZIONE IMMEDIATA
    $(document).on('click', '.vwgk-ha-btn-execute', function() {
        var $btn = $(this);
        var $card = $btn.closest('.vwgk-ha-card');
        var entityId = $btn.data('entity') || $card.data('id');
        var action = $btn.data('action') || '';
        var originalText = $btn.text();
        
        $btn.prop('disabled', true).text('⏳...');

        $.post(vwgkHaParams.ajax_url, {
            action: 'vwgk_ha_execute',
            nonce: vwgkHaParams.nonce,
            entity_id: entityId,
            state: action
        }, function(response) {
            $btn.prop('disabled', false).text(originalText);
            if (response.success) {
                $card.addClass('vwgk-ha-card-pulse');
                setTimeout(() => $card.removeClass('vwgk-ha-card-pulse'), 1000);
            } else {
                alert('Errore: ' + (response.data || 'Esecuzione fallita'));
            }
        });
    });

    // Gestione bottoni Media Player -> ATTIVAZIONE IMMEDIATA
    $(document).on('click', '.vwgk-ha-btn-media', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $card = $btn.closest('.vwgk-ha-card');
        var entityId = $btn.data('entity') || $card.data('id');
        var action = $btn.data('action'); 
        
        $card.css('opacity', '0.5');

        $.post(vwgkHaParams.ajax_url, {
            action: 'vwgk_ha_execute',
            nonce: vwgkHaParams.nonce,
            entity_id: entityId,
            state: action
        }, function(response) {
            $card.css('opacity', '1');
            if (!response.success) {
                alert('Errore: ' + (response.data || 'Impossibile inviare il comando media'));
            }
        });
    });

    // --- AZIONI DIFFERITE (Richiedono "Applica") ---

    // Clima (+/- Temperatura)
    $(document).on('click', '.vwgk-ha-btn-circle', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $card = $btn.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var $targetSpan = $card.find('.vwgk-ha-temp-target--live');
        
        var currentPriceText = $targetSpan.text();
        var currentTarget = parseFloat(currentPriceText) || 20;
        var step = parseFloat($btn.data('step'));
        var newTarget = currentTarget + step;
        
        $targetSpan.text(newTarget);
        markDirty($card, entityId, 'temperature', newTarget);
    });

    // Clima (Modalità)
    $(document).on('click', '.vwgk-ha-btn-mode', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $card = $btn.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var mode = $btn.data('mode');

        $card.find('.vwgk-ha-btn-mode').removeClass('vwgk-ha-btn-mode--active');
        $btn.addClass('vwgk-ha-btn-mode--active');
        
        markDirty($card, entityId, 'hvac_mode', mode);
    });

    // Selezionatori (Fan, Preset, Swing)
    $(document).on('change', '.vwgk-ha-select', function() {
        var $select = $(this);
        var $card = $select.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var action = $select.data('action');
        var value = $select.val();

        var attrMap = {
            'set_fan_mode': 'fan_mode',
            'set_preset_mode': 'preset_mode',
            'set_swing_mode': 'swing_mode'
        };
        
        if (attrMap[action]) {
            markDirty($card, entityId, attrMap[action], value);
        }
    });

    // Slider (Luminosità, Tapparella, Volume)
    $(document).on('input change', '.vwgk-ha-brightness-slider, .vwgk-ha-cover-slider, .vwgk-ha-volume-slider', function() {
        var $input = $(this);
        var $card = $input.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var val = parseInt($input.val(), 10);
        
        var attr = 'brightness_pct';
        if ($input.hasClass('vwgk-ha-cover-slider')) attr = 'position';
        if ($input.hasClass('vwgk-ha-volume-slider')) {
             attr = 'volume_level';
             val = val / 100;
        }

        markDirty($card, entityId, attr, val);
    });

    // Picker Colore
    $(document).on('input change', '.vwgk-ha-color-picker', function() {
        var $input = $(this);
        var $card = $input.closest('.vwgk-ha-card');
        var entityId = $card.data('id');
        var hex = $input.val();
        
        var r = parseInt(hex.slice(1, 3), 16),
            g = parseInt(hex.slice(3, 5), 16),
            b = parseInt(hex.slice(5, 7), 16);

        markDirty($card, entityId, 'rgb_color', [r, g, b]);
    });

    // --- TASTO APPLICA (Submit) ---
    $(document).on('click', '.vwgk-ha-btn-submit', function() {
        var $btn = $(this);
        var $card = $btn.closest('.vwgk-ha-card');
        var entityId = $btn.data('entity');
        var domain = $card.data('domain');
        var pending = window.vwgkPending[entityId];

        if (!pending) return;

        $btn.prop('disabled', true).text('invio...');
        $card.css('opacity', '0.5');

        // Determiniamo quali servizi chiamare
        var calls = [];

        if (domain === 'climate') {
            // In HA set_temperature può impostare anche hvac_mode
            var payload = {};
            if (pending.temperature) payload.temperature = pending.temperature;
            if (pending.hvac_mode)   payload.hvac_mode   = pending.hvac_mode;
            
            if (Object.keys(payload).length > 0) {
                calls.push({ state: 'set_temperature', payload: payload });
            }
            if (pending.fan_mode)    calls.push({ state: 'set_fan_mode', payload: { fan_mode: pending.fan_mode }});
            if (pending.preset_mode) calls.push({ state: 'set_preset_mode', payload: { preset_mode: pending.preset_mode }});
            if (pending.swing_mode)  calls.push({ state: 'set_swing_mode', payload: { swing_mode: pending.swing_mode }});
            
        } else if (domain === 'light') {
            var payload = { state: 'on' }; // Assumiamo 'on' per cambiare attributi
            if (pending.brightness_pct !== undefined) payload.brightness_pct = pending.brightness_pct;
            if (pending.rgb_color) payload.rgb_color = pending.rgb_color;
            calls.push({ state: 'on', payload: payload });

        } else if (domain === 'cover') {
            if (pending.position !== undefined) {
                calls.push({ state: 'set_cover_position', payload: { position: pending.position }});
            }
        } else if (domain === 'media_player') {
            if (pending.volume_level !== undefined) {
                calls.push({ state: 'volume_set', payload: { volume_level: pending.volume_level }});
            }
        }

        // Eseguiamo le chiamate (sequenzialmente semplificato per ora, o solo l'ultima/principale)
        // Per ora gestiamo la sottomissione come singola chiamata AJAX aggregata se possibile, 
        // o inviamo la principale. HA REST API richiede una chiamata per servizio.
        
        // Semplificazione: Inviamo una chiamata per ogni servizio pendente
        var completed = 0;
        if (calls.length === 0) {
             finalize();
             return;
        }

        calls.forEach(function(call) {
            $.post(vwgkHaParams.ajax_url, {
                action: 'vwgk_ha_execute',
                nonce: vwgkHaParams.nonce,
                entity_id: entityId,
                state: call.state,
                payload: call.payload
            }, function(response) {
                completed++;
                if (completed === calls.length) {
                    finalize(response.success);
                }
            });
        });

        function finalize(success) {
            $btn.prop('disabled', false).text('Applica');
            $card.css('opacity', '1');
            if (success !== false) {
                // Aggiorna lo stato visivo prima di pulire il dirty
                if (pending.hvac_mode)   $card.find('.vwgk-ha-card-state--live').first().text(pending.hvac_mode.toUpperCase());
                if (pending.temperature) $card.find('.vwgk-ha-temp-target--live').text(pending.temperature);
                
                // Per luci e tapparelle, lo stato testuale è spesso gestito dall'attributo data-state
                if (pending.brightness_pct !== undefined) $card.find('.vwgk-ha-card-state--live').text('Luminosità: ' + pending.brightness_pct + '%');
                if (pending.position !== undefined)       $card.find('.vwgk-ha-card-state--live').text('Posizione: ' + pending.position + '%');

                clearDirty($card, entityId);
            } else {
                alert('Errore durante l\'aggiornamento dell\'entità.');
            }
        }
    });

    // Gestione Modal Dettagli Tecnici
    $(document).on('click', '.vwgk-ha-info-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var entityId = $btn.data('entity');
        var attrs = $btn.data('attrs'); 

        var $modal = $('#vwgk-ha-modal-overlay');
        var $tbody = $('#vwgk-ha-modal-data');
        
        $('#vwgk-ha-modal-title').text('Dettagli: ' + entityId);
        $tbody.empty();

        $tbody.append('<tr><th>entity_id</th><td>' + entityId + '</td></tr>');

        if (attrs && typeof attrs === 'object') {
            Object.keys(attrs).forEach(function(key) {
                var val = attrs[key];
                if (typeof val === 'object') val = JSON.stringify(val);
                $tbody.append('<tr><th>' + key + '</th><td>' + val + '</td></tr>');
            });
        }

        $modal.removeClass('vwgk-ha-modal-hidden');
    });

    // Auto-refresh fotocamere
    setInterval(function() {
        $('.vwgk-ha-camera-stream').each(function() {
            var $img = $(this);
            if ($img.is(':visible')) {
                var currentSrc = $img.attr('src');
                var newSrc = currentSrc.replace(/&t=\d+/, '&t=' + Date.now());
                $img.attr('src', newSrc);
            }
        });
    }, 10000);

    // Chiusura Modale
    $(document).on('click', '#vwgk-ha-modal-close, #vwgk-ha-modal-overlay', function(e) {
        if (e.target === this || e.target.id === 'vwgk-ha-modal-close') {
            $('#vwgk-ha-modal-overlay').addClass('vwgk-ha-modal-hidden');
        }
    });

    // Blocca chiusura se si clicca dentro il contenuto della modale
    $(document).on('click', '.vwgk-ha-modal-content', function(e) {
        e.stopPropagation();
    });

    // 3. Gestione Chat Assistente
    var chatConversationId = null;

    $('#vwgk-ha-chat-send').on('click', function(e) {
        e.preventDefault();
        sendChatMessage();
    });

    $('#vwgk-ha-chat-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            sendChatMessage();
        }
    });

    function sendChatMessage() {
        var $input = $('#vwgk-ha-chat-input');
        var text = $input.val().trim();
        var agentId = $('#vwgk-ha-chat-agent-id').val();
        var $box = $('#vwgk-ha-chat-box');
        var $btn = $('#vwgk-ha-chat-send');

        if (!text) return;

        // Aggiungi messaggio utente
        $box.append('<div class="vwgk-ha-chat-msg vwgk-ha-chat-user">' + escapeHtml(text) + '</div>');
        $input.val('');
        scrollToBottom($box);

        $btn.prop('disabled', true);
        $input.prop('disabled', true);

        // Placeholder bot
        var $typing = $('<div class="vwgk-ha-chat-msg vwgk-ha-chat-bot">...</div>');
        $box.append($typing);
        scrollToBottom($box);

        $.post(vwgkHaParams.ajax_url, {
            action: 'vwgk_chat_send',
            nonce: vwgkHaParams.nonce,
            text: text,
            agent_id: agentId,
            conversation_id: chatConversationId || ''
        }, function(response) {
            $btn.prop('disabled', false);
            $input.prop('disabled', false).focus();
            $typing.remove();

            if (response.success && response.data) {
                // Aggiornato per il nuovo formato di risposta REST (se presente) o fallback
                var reply = '*(Nessuna risposta)*';
                if (response.data.response && response.data.response.speech && response.data.response.speech.plain) {
                    reply = response.data.response.speech.plain.speech;
                } else if (response.data.speech) {
                    reply = response.data.speech;
                }
                
                if (response.data.conversation_id) {
                    chatConversationId = response.data.conversation_id;
                }

                $box.append('<div class="vwgk-ha-chat-msg vwgk-ha-chat-bot">' + escapeHtml(reply) + '</div>');
            } else {
                $box.append('<div class="vwgk-ha-chat-msg vwgk-ha-chat-bot" style="color:red;">Errore: ' + (response.data || 'Richiesta fallita') + '</div>');
            }
            scrollToBottom($box);

        }).fail(function() {
            $btn.prop('disabled', false);
            $input.prop('disabled', false).focus();
            $typing.remove();
            $box.append('<div class="vwgk-ha-chat-msg vwgk-ha-chat-bot" style="color:red;">Errore di connessione al server.</div>');
            scrollToBottom($box);
        });
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    function scrollToBottom($el) {
        $el.scrollTop($el[0].scrollHeight);
    }

    // 4. Gestione Dashboard (Supporto per layout dinamici se presente sidebar)
    var $dashboardMain = $('#vwgk-ha-dashboard-main');
    if ($dashboardMain.length) {
        // ... (manteniamo logica esistente se presente, adattandola alle nuove grid cards)
        $('#vwgk-ha-dashboard-sidebar input, #vwgk-layout-select').on('change', function() {
            // Ricarica tutto con le nuove impostazioni se necessario, 
            // ma lo shortcode ora gestisce le colonne via CSS Variable --ha-cols
        });
    }
});
