<?php
/**
 * Le action di manage-file.
 *
 * Estratta dalla versione Pro, che la dichiara insieme ad altri tool: qui si
 * tiene solo questa, perche' questo plugin ha solo questa rotta e dichiarare
 * quello che non c'e' manda un assistente a chiamare un indirizzo che non
 * risponde.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $immagine = [
    'filename' => ['obbligatorio' => true, 'tipo' => 'string',
      'descrizione' => 'Il nome del file, estensione compresa.'],
    'mime_type' => ['obbligatorio' => true, 'tipo' => 'string',
      'descrizione' => 'Che tipo di file e. Solo immagini: png, jpeg, jpg, webp o gif. '
                     . 'Qualunque altro tipo viene rifiutato.'],
    'title' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il titolo con cui l immagine entra nella libreria del sito.'],
    'alt' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'La descrizione per chi non vede l immagine. Serve a chi usa un '
                     . 'lettore di schermo, e i motori di ricerca la leggono.'],
    'caption' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'La didascalia mostrata sotto l immagine.'],
    'description' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Una descrizione piu lunga, nella libreria del sito.'],
  ];

  $registro['manage-file'] = [
    'descrizione' => 'Manda file e immagini al sito, in un colpo solo o a pezzi quando '
                   . 'sono troppo grandi per una chiamata sola.',
    'azioni' => [

      'send-file' => [
        'descrizione' => 'Carica sul sito i file che l assistente ha gia a disposizione.',
        'parametri' => [
          'openaiFileIdRefs' => ['obbligatorio' => true, 'tipo' => 'array',
            'descrizione' => 'I riferimenti ai file da caricare, almeno uno.'],
        ],
      ],

      'send-image-base-64' => [
        'descrizione' => 'Carica una immagine mandandone il contenuto codificato dentro '
                       . 'la chiamata. Va bene per le immagini piccole: se la chiamata '
                       . 'diventa troppo grande serve send-image-base-64-in-chunk.',
        'parametri' => array_merge([
          'content_base64' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il contenuto dell immagine codificato in base64.'],
        ], $immagine),
      ],

      'send-image-base-64-in-chunk' => [
        'descrizione' => 'Carica una immagine grande mandandola a pezzi. I pezzi si '
                       . 'mandano uno dopo l altro con lo stesso upload_id, e l immagine '
                       . 'viene messa insieme solo quando arriva lultimo.',
        'parametri' => array_merge([
          'chunk_base64' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Questo pezzo dell immagine, codificato in base64.'],
          'chunk_index' => ['obbligatorio' => true, 'tipo' => 'integer',
            'descrizione' => 'Quale pezzo e questo, da 0.'],
          'total_chunks' => ['obbligatorio' => true, 'tipo' => 'integer',
            'descrizione' => 'Quanti pezzi in tutto.'],
          'upload_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Il codice che tiene insieme i pezzi della stessa immagine. '
                           . 'Va ripetuto uguale a ogni pezzo, altrimenti i pezzi non si '
                           . 'ritrovano fra loro.'],
          'is_last_chunk' => ['obbligatorio' => false, 'tipo' => 'boolean',
            'descrizione' => 'Vero sull ultimo pezzo: e quello che fa mettere insieme '
                           . 'l immagine.'],
        ], $immagine),
      ],
    ],
  ];

  return $registro;
});
