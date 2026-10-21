<?php
/**
 * Le action della rotta delle stanze, dichiarate.
 *
 * Stessa disciplina dell'altra rotta: le action si dichiarano qui, non dentro
 * il registro. Chi aggiunge un pezzo dichiara il suo, e nessuno deve andare a
 * modificare un elenco centrale che poi resta indietro.
 *
 * SE CAMBI UN PARAMETRO NELLA ROTTA, CAMBIALO QUI. La rotta si registra con un
 * `action` solo e un corpo che cambia forma: WordPress non ha niente da
 * introspezionare, quindi questa e' l'unica fonte che l'assistente legge.
 */
if (!defined('ABSPATH')) exit;

/**
 * La scheda: come si chiama questo tool e cosa fa, in una riga.
 *
 * NON E' UN DOPPIONE DELLE AZIONI QUI SOTTO. Quelle dicono i parametri di
 * ogni operazione e si leggono chiedendo il dettaglio; questa e' il titolo e
 * la riga di descrizione che compaiono nell'ELENCO - quello corto, che un
 * assistente guarda per decidere se aprire la scheda - e nello schema che si
 * incolla dentro un GPT. Senza, il tool si presentava col nome della rotta
 * fatto a parole ("Manage casa stanze dispositivi e pagine") e con la frase
 * di ripiego "Rotta disponibile alle tue chiavi": vera, e inutile a chiunque
 * debba scegliere fra quindici tool.
 *
 * STA QUI e non nell'elenco dentro WGA_Guida per la stessa ragione delle
 * azioni: chi aggiunge un pezzo dichiara il suo, e nessuno deve ricordarsi di
 * andare a modificare una lista centrale che poi resta indietro.
 */
add_filter('wga_schede_guida', function (array $schede) {
  $schede['manage-casa-stanze-dispositivi-e-pagine'] = [
    'titolo' => "Com'e' fatta la casa: stanze, dispositivi e pagine",
    'metodo' => 'POST',
    'cosa'   => 'Dice quali stanze ci sono e cosa contengono, trova dove sta una cosa '
              . 'cercandola per parole, sposta un dispositivo di stanza e costruisce le '
              . 'pagine che lo mostrano. Le stanze e gli spostamenti finiscono dritti in '
              . 'Home Assistant.',
  ];
  return $schede;
});

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $registro['manage-casa-stanze-dispositivi-e-pagine'] = [
    'descrizione' => 'Com\'e\' fatta la casa: quali stanze esistono, cosa c\'e\' in ognuna, '
                   . 'dove sta una cosa che stai cercando, e come costruire una pagina che '
                   . 'ne mostri un pezzo. NON serve ad accendere o spegnere: per quello c\'e\' '
                   . 'manage-casahomeassistant-domoticandashboard-videocam, che questa rotta '
                   . 'completa e non sostituisce.',
    'azioni' => [

      'stanze' => [
        'descrizione' => 'Le stanze della casa, con quanti dispositivi contengono e se hanno '
                       . 'gia\' una pagina. E\' il punto di partenza: gli id che torna si usano '
                       . 'in tutte le altre action.',
        'parametri' => [],
      ],

      'dispositivi' => [
        'descrizione' => 'Cosa c\'e\' dentro una stanza.',
        'parametri' => [
          'stanza' => ['obbligatorio' => true, 'tipo' => 'string',
                       'descrizione' => 'Id o nome della stanza, come li torna action=stanze.'],
        ],
      ],

      'cerca' => [
        'descrizione' => 'Cerca una cosa in casa PER PAROLE, non per frase. "la lampada del '
                       . 'soggiorno" non e\' il nome di nessuna entita\': cercata cosi\' non '
                       . 'torna niente. Spezzata in parole, e ordinando per quante ne '
                       . 'compaiono, la lampada esce per prima. Con "stanza" si cerca solo li\'.',
        'parametri' => [
          'query'  => ['obbligatorio' => true, 'tipo' => 'string',
                       'descrizione' => 'Le parole da cercare. Quelle sotto le tre lettere si '
                                      . 'scartano: compaiono ovunque e sporcherebbero l\'ordine.'],
          'stanza' => ['obbligatorio' => false, 'tipo' => 'string',
                       'descrizione' => 'Cerca solo dentro questa stanza. Vuoto: tutta la casa.'],
          'limit'  => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 15,
                       'descrizione' => 'Quanti risultati, al massimo cinquanta.'],
        ],
      ],

      'card-disponibili' => [
        'descrizione' => 'Che aspetto puo\' avere un\'entita\' dentro una pagina. Senza '
                       . 'entity_id torna l\'elenco per dominio.',
        'parametri' => [
          'entity_id' => ['obbligatorio' => false, 'tipo' => 'string',
                          'descrizione' => 'L\'entita\' di cui si vogliono le card possibili.'],
        ],
      ],

      'sposta-dispositivo' => [
        'descrizione' => 'Sposta un dispositivo in un\'altra stanza. Passando un entity_id '
                       . 'sposta il DISPOSITIVO INTERO a cui appartiene: un condizionatore '
                       . 'porta con se\' temperatura, consumi e stato, e spostare la sola '
                       . 'entita\' lascerebbe indietro il resto.',
        'parametri' => [
          'dispositivo' => ['obbligatorio' => true, 'tipo' => 'string',
                            'descrizione' => 'device_id, oppure un entity_id: in quel caso si '
                                           . 'risale al dispositivo.'],
          'stanza'      => ['obbligatorio' => true, 'tipo' => 'string',
                            'descrizione' => 'Stanza di destinazione: deve gia\' esistere.'],
        ],
      ],

      'crea-stanza' => [
        'descrizione' => 'Crea una stanza in Home Assistant. Se c\'e\' gia\' non fa niente e '
                       . 'lo dice: due stanze con lo stesso nome sono peggio di nessuna.',
        'parametri' => [
          'nome' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Come si chiama.'],
        ],
      ],

      'crea-pagina' => [
        'descrizione' => 'Crea una pagina con dentro SOLO le card che scegli. Serve al caso '
                       . 'opposto della pagina di stanza: "una pagina con i quattro '
                       . 'condizionatori", che stanno in quattro stanze diverse. La mappa '
                       . 'della casa compare in cima da sola.',
        'parametri' => [
          'titolo' => ['obbligatorio' => true, 'tipo' => 'string',
                       'descrizione' => 'Titolo della pagina.'],
          'entita' => ['obbligatorio' => true, 'tipo' => 'array',
                       'descrizione' => 'Elenco di entity_id, oppure di oggetti '
                                      . '{entity_id, card} per scegliere anche l\'aspetto. '
                                      . 'Le card possibili le dice action=card-disponibili.'],
        ],
      ],

    ],
  ];

  return $registro;
});
