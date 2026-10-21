<?php
/**
 * Le action della rotta di rinnovo, dichiarate.
 *
 * Stanno qui per la stessa ragione delle altre: il registro si riempie per
 * filtro, cosi' aggiungere una rotta non vuol dire modificare un elenco
 * centrale che poi nessuno aggiorna.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $chiave = ['obbligatorio' => true, 'tipo' => 'string',
             'descrizione' => 'La chiave di rinnovo di quel genere, quella che il '
                            . 'sito ti ha consegnato quando vi siete collegati.'];
  $genere = ['obbligatorio' => true, 'tipo' => 'string',
             'valori' => ['user_code', 'header', 'parametro'],
             'descrizione' => 'Quale credenziale. Il bearer non si tratta da qui: '
                            . 'si rigenera dalla bacheca del sito.'];

  $registro['manage-credentials-rotation-refresh-token'] = [
    'descrizione' => 'Rinnova le credenziali di questo sito usando la chiave di '
                   . 'rinnovo. Si usa proprio quando il codice utente non vale '
                   . 'piu\', quindi non ne chiede uno.',
    'azioni' => [
      'chiedi-rotazione' => [
        'descrizione' => 'Chiede al sito di ruotare una credenziale. La ruota il '
                       . 'sito, non tu: torna il valore nuovo e parte l\'annuncio '
                       . 'a sowai. Il valore vecchio resta buono per la finestra '
                       . 'di grazia, cosi\' le chiamate gia\' partite non cadono.',
        'parametri' => ['genere' => $genere, 'chiave' => $chiave],
      ],
      'riprendi-credenziale' => [
        'descrizione' => 'Ti riconsegna la credenziale ATTUALE senza cambiarla. '
                       . 'Serve a chi l\'ha persa: se e\' ancora valida non c\'e\' '
                       . 'motivo di ruotarla e far cadere gli altri collegamenti.',
        'parametri' => ['genere' => $genere, 'chiave' => $chiave],
      ],
      'stato' => [
        'descrizione' => 'Quanti usi restano alla tua chiave di rinnovo, senza '
                       . 'consumarne nessuno e senza mai dire il valore della '
                       . 'credenziale.',
        'parametri' => ['genere' => $genere, 'chiave' => $chiave],
      ],
    ],
  ];

  return $registro;
});
