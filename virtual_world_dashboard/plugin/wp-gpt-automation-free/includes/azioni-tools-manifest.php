<?php
/**
 * Le action di tools-manifest, dichiarate come quelle di tutti gli altri tool.
 *
 * PERCHE' ANCHE LUI. Era escluso come "rotta di servizio", e cosi' sarebbe
 * stato l'unico tool del sito a comportarsi in modo diverso da quello che
 * insegna a tutti gli altri: sbagliandolo si prendeva un errore secco, e
 * chiedergli documentazione_<action> non funzionava. Un attrezzo che non
 * rispetta la regola che spiega e' il primo posto dove chi legge perde fiducia
 * nella regola.
 *
 * L'AZIONE PREDEFINITA E' L'ELENCO, e non e' un dettaglio: chiamarlo nudo e'
 * sempre stato il modo di dire "fammi vedere cosa c'e'", e deve restare tale.
 * Senza dichiararla, l'aiuto a chi sbaglia trasformerebbe in errore una
 * chiamata che oggi funziona per tutti gli integratori esistenti.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $registro['tools-manifest'] = [
    'descrizione' => 'L\'attrezzo per orientarsi: elenca i tool di questo sito, le action '
                   . 'di ognuno e i parametri di ogni action.',
    'action_predefinita' => 'lista-dei-tool-che-contengono-le-action',
    'azioni' => [

      'lista-dei-tool-che-contengono-le-action' => [
        'descrizione' => 'Elenca i tool disponibili alle tue credenziali, con le action di ognuno.',
        'parametri' => [
          'cerca' => ['obbligatorio' => false, 'tipo' => 'string',
                      'descrizione' => 'Filtra per nome o descrizione. Accetta un pezzo di '
                                     . 'parola, e perdona una lettera fuori posto.'],
          'pagina' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 1,
                       'descrizione' => 'Pagina da leggere, a partire da 1.'],
          'per_pagina' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 20,
                           'descrizione' => 'Quanti tool per pagina.'],
        ],
      ],

      'scopri-parametri-dei-tool' => [
        'descrizione' => 'Apre un tool: cosa fa, quali action ha e cosa fa ognuna.',
        'parametri' => [
          'tool' => ['obbligatorio' => true, 'tipo' => 'string',
                     'descrizione' => 'Il nome del tool, che e\' il nome della sua rotta.'],
        ],
      ],

      'scopri-parametri-di-un-action-in-un-tool' => [
        'descrizione' => 'Apre una singola action: parametri obbligatori, opzionali e '
                       . 'valori ammessi.',
        'parametri' => [
          'tool' => ['obbligatorio' => true, 'tipo' => 'string',
                     'descrizione' => 'Il nome del tool.'],
          'nome_action' => ['obbligatorio' => true, 'tipo' => 'string',
                            'descrizione' => 'L\'action da aprire.'],
        ],
      ],

      'istruzioni' => [
        'descrizione' => 'Le istruzioni di sistema per il modello: quali tool ci sono '
                       . 'su questo sito, come si chiedono le schede, come si costruisce '
                       . 'una dashboard. Da chiedere una volta a chi collega il sito, '
                       . 'invece di tenerne una copia scritta a mano che invecchia.',
        'parametri' => [],
      ],

      'schema' => [
        'descrizione' => 'Restituisce l\'OpenAPI, di un tool o di tutti insieme.',
        'parametri' => [
          'tool' => ['obbligatorio' => false, 'tipo' => 'string',
                     'descrizione' => 'Limita lo schema a un tool solo.'],
        ],
      ],

      'stretta-di-mano' => [
        'descrizione' => 'Lascia a questo sito il tuo indirizzo, cosi\' ti avvisa da solo '
                       . 'quando le sue credenziali cambiano, invece di lasciarti trovare '
                       . 'una porta chiusa.',
        'parametri' => [
          'odoo_url' => ['obbligatorio' => true, 'tipo' => 'string',
                         'descrizione' => 'L\'indirizzo a cui questo sito ti richiamera\'.'],
          'odoo_token' => ['obbligatorio' => true, 'tipo' => 'string',
                           'descrizione' => 'Il token con cui ti riconoscerai.'],
          'odoo_user_code' => ['obbligatorio' => true, 'tipo' => 'string',
                               'descrizione' => 'Il tuo codice utente.'],
          'riferimento' => ['obbligatorio' => false, 'tipo' => 'string',
                            'descrizione' => 'Un\'etichetta tua, per ritrovare l\'accordo.'],
        ],
      ],
    ],
  ];

  return $registro;
});
