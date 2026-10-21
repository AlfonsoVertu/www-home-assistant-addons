<?php
/**
 * Le action della rotta di Home Assistant, dichiarate.
 *
 * PERCHE' STANNO QUI E NON NEL REGISTRO. Il registro e' vuoto e si riempie per
 * filtro: ogni pezzo dichiara le proprie, anche un plugin di terzi. Se le
 * scrivessimo dentro il registro, aggiungere una rotta vorrebbe dire modificare
 * il registro - ed e' cosi' che nascono gli elenchi centrali che nessuno
 * aggiorna.
 *
 * PERCHE' A MANO E NON PER INTROSPEZIONE. Questa rotta si registra in WordPress
 * con `args` vuoti: un endpoint solo, un parametro `action`, e un corpo che
 * cambia forma quindici volte. WordPress non ha modo di dichiarare "se
 * action=control-entity allora servono domain e service", quindi non c'e' niente
 * da introspezionare - e finche' la rotta non imparera' a dichiararsi da sola,
 * questa e' la sola fonte che esista. Se cambi un parametro la', cambialo qui.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $pagina = [
    'page'  => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 1,
                'descrizione' => 'Pagina da leggere, a partire da 1.'],
    'limit' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 10,
                'descrizione' => 'Quanti risultati per pagina.'],
  ];

  $registro['manage-casahomeassistant-domoticandashboard-videocam'] = [
    'descrizione' => 'Legge e comanda l\'impianto di Home Assistant: entita\', scene, '
                   . 'script, automazioni, telecamere, cronologia e calendari.',
    'azioni' => [

      'get-entities' => [
        'descrizione' => 'Elenca tutte le entita\' dell\'impianto, a pagine.',
        'parametri' => $pagina,
      ],

      'search' => [
        'descrizione' => 'Cerca un\'entita\' per nome: confronta sia l\'entity_id sia il '
                       . 'nome amichevole, su tutti i domini. E\' il modo giusto di '
                       . 'trovare "il condizionatore del soggiorno" senza leggersi tutto.',
        'parametri' => [
          'query' => ['obbligatorio' => true, 'tipo' => 'string',
                      'descrizione' => 'Pezzo di nome da cercare, anche parziale.'],
          'page'  => $pagina['page'],
          'limit' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 20,
                      'descrizione' => 'Quanti risultati per pagina.'],
        ],
      ],

      'control-entity' => [
        'descrizione' => 'ESEGUE un comando sull\'impianto: accende, spegne, imposta. '
                       . 'Tutto cio\' che non e\' un parametro di sistema viene passato '
                       . 'al servizio come dato.',
        'parametri' => [
          'domain' => ['obbligatorio' => true, 'tipo' => 'string',
                       'descrizione' => 'Il dominio del servizio: light, switch, climate, '
                                      . 'media_player, cover, scene, script...'],
          'service' => ['obbligatorio' => true, 'tipo' => 'string',
                        'descrizione' => 'Il servizio da chiamare: turn_on, turn_off, '
                                       . 'toggle, set_temperature...'],
          'entity_id' => ['obbligatorio' => false, 'tipo' => 'string',
                          'descrizione' => 'L\'entita\' su cui agire. Si ricava con search.'],
          'payload' => ['obbligatorio' => false, 'tipo' => 'object',
                        'descrizione' => 'Dati aggiuntivi per il servizio.'],
        ],
      ],

      'get-script' => ['descrizione' => 'Elenca gli script.', 'parametri' => $pagina],
      'get-automation' => ['descrizione' => 'Elenca le automazioni.', 'parametri' => $pagina],
      'get-scene' => ['descrizione' => 'Elenca le scene.', 'parametri' => $pagina],
      'get-camera-list' => ['descrizione' => 'Elenca le telecamere.', 'parametri' => $pagina],

      'get-camera-screen' => [
        'descrizione' => 'Cattura un fermo immagine da una telecamera e lo salva '
                       . 'come file su WordPress, restituendone l\'indirizzo.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => true, 'tipo' => 'string',
                          'descrizione' => 'La telecamera, es. camera.ingresso.'],
        ],
      ],

      'get-assistant-list' => [
        'descrizione' => 'Elenca gli assistenti conversazionali configurati.',
        'parametri' => [],
      ],

      'send-command-to-assistant' => [
        'descrizione' => 'Manda una frase all\'assistente di Home Assistant e ne '
                       . 'riporta la risposta.',
        'parametri' => [
          'text' => ['obbligatorio' => true, 'tipo' => 'string',
                     'descrizione' => 'La frase da dire all\'assistente.'],
          'agent_id' => ['obbligatorio' => false, 'tipo' => 'string',
                         'predefinito' => 'homeassistant',
                         'descrizione' => 'Quale assistente, se ce n\'e\' piu\' d\'uno.'],
        ],
      ],

      'get-history' => [
        'descrizione' => 'La cronologia degli stati di un\'entita\' in un periodo.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => true, 'tipo' => 'string',
                          'descrizione' => 'L\'entita\' di cui leggere la storia.'],
          'start' => ['obbligatorio' => false, 'tipo' => 'string',
                      'descrizione' => 'Inizio del periodo, in formato ISO 8601.'],
          'end' => ['obbligatorio' => false, 'tipo' => 'string',
                    'descrizione' => 'Fine del periodo, in formato ISO 8601.'],
        ],
      ],

      'get-logbook' => [
        'descrizione' => 'Il diario degli eventi dell\'impianto.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => false, 'tipo' => 'string',
                          'descrizione' => 'Limita il diario a una sola entita\'.'],
          'start' => ['obbligatorio' => false, 'tipo' => 'string',
                      'descrizione' => 'Inizio del periodo, in formato ISO 8601.'],
          'end' => ['obbligatorio' => false, 'tipo' => 'string',
                    'descrizione' => 'Fine del periodo, in formato ISO 8601.'],
        ],
      ],

      'get-calendars' => [
        'descrizione' => 'Elenca i calendari collegati.',
        'parametri' => [],
      ],

      'get-calendar-events' => [
        'descrizione' => 'Gli appuntamenti di un calendario in un periodo.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => true, 'tipo' => 'string',
                          'descrizione' => 'Il calendario, es. calendar.famiglia.'],
          'start' => ['obbligatorio' => false, 'tipo' => 'string',
                      'descrizione' => 'Inizio del periodo, in formato ISO 8601.'],
          'end' => ['obbligatorio' => false, 'tipo' => 'string',
                    'descrizione' => 'Fine del periodo, in formato ISO 8601.'],
        ],
      ],

      'get-shortcode' => [
        'descrizione' => 'Restituisce lo shortcode con cui mostrare un\'entita\' '
                       . 'dentro una pagina del sito.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => true, 'tipo' => 'string',
                          'descrizione' => 'L\'entita\' da mostrare.'],
          'columns' => ['obbligatorio' => false, 'tipo' => 'integer',
                        'descrizione' => 'Su quante colonne disporla.'],
        ],
      ],
    ],
  ];


  // Le quattro action del ponte generico, che stava su una rotta sua:
  // scoprire cosa c'e', ricaricare la configurazione, eseguire un servizio
  // e parlare all'assistente vocale di Home Assistant.
  $registro['manage-casahomeassistant-domoticandashboard-videocam']['azioni'] += [

      'discover' => [
        'descrizione' => 'Elenca quello che Home Assistant conosce, con lo stato di ogni '
                       . 'cosa. Da chiamare per prima: da qui si prendono gli '
                       . 'identificativi che servono a execute.',
        'parametri' => [
          'type' => ['obbligatorio' => false, 'tipo' => 'string', 'predefinito' => 'all',
            'descrizione' => 'Che genere di cose elencare: all tutto, entities i '
                           . 'dispositivi, scripts gli script, automations le '
                           . 'automazioni, assistants gli assistenti vocali.'],
          'entities' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Restringe a identificativi precisi, separati da virgola.'],
          'page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 1,
            'descrizione' => 'Quale pagina di risultati, da 1.'],
          'per_page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 20,
            'descrizione' => 'Quanti risultati per pagina, al massimo 100.'],
          'limit' => ['obbligatorio' => false, 'tipo' => 'integer',
            'descrizione' => 'Tetto complessivo ai risultati, indipendente dalle pagine.'],
        ],
      ],

      'execute' => [
        'descrizione' => 'ATTENZIONE: fa succedere qualcosa nella casa vera. Chiama un '
                       . 'servizio di Home Assistant, per esempio accendere una luce o '
                       . 'aprire una serranda.',
        'parametri' => [
          'domain' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il genere di cosa da comandare, per esempio light o '
                           . 'switch. E la prima parte dell entity_id, quella prima '
                           . 'del punto.'],
          'service' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Cosa fargli fare, per esempio turn_on o turn_off.'],
          'entity_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Su quale cosa preciso agire, come lo restituisce discover. '
                           . 'Senza, il comando vale per tutto il dominio: e il modo di '
                           . 'spegnere per sbaglio ogni luce della casa.'],
          'data' => ['obbligatorio' => false, 'tipo' => 'object',
            'descrizione' => 'Dettagli in piu per il servizio, per esempio la luminosita '
                           . 'o il colore.'],
        ],
      ],

      'send-message-home-assistant-agent' => [
        'descrizione' => 'Scrive una frase all assistente vocale di Home Assistant, come '
                       . 'se gliela si dicesse a voce, e riporta la sua risposta. Quello '
                       . 'che dice puo far agire la casa.',
        'parametri' => [
          'text' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'La frase da dire allassistente.'],
          'agent_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Quale assistente, se ce ne e piu di uno. Si trovano con '
                           . 'discover chiedendo type assistants.'],
          'conversation_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Il filo di una conversazione gia cominciata, per farsi '
                           . 'capire quando la frase si riferisce a quella prima.'],
        ],
      ],

      'reload' => [
        'descrizione' => 'Rilegge la configurazione del collegamento a Home Assistant. Da '
                       . 'usare dopo averla cambiata, oppure quando le altre action '
                       . 'falliscono tutte allo stesso modo.',
        'parametri' => [],
      ]
  ];

  return $registro;
});
