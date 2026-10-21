<?php
/**
 * Le action delle pagine.
 *
 * Descrizioni scritte leggendo manage_page_with_user_code in
 * class-wp-gpt-api.php.
 *
 * PAGINE E ARTICOLI NON SONO LA STESSA COSA. Le pagine sono le parti fisse del
 * sito - chi siamo, contatti, la pagina iniziale - e stanno nei menu. Gli
 * articoli sono i contenuti che si susseguono nel tempo e per quelli c e
 * manage-articles-with-user-code. Un modello che cerca di creare una pagina
 * con il tool degli articoli ottiene un articolo, non una pagina.
 *
 * DUE COSE QUI DENTRO CAMBIANO IL SITO PER TUTTI: set-homepage decide cosa
 * vedono i visitatori arrivando, e update-menu riscrive un menu. Non toccano
 * una pagina sola.
 *
 * COME PER GLI ARTICOLI, update-page sostituisce il contenuto per intero.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $page_id = ['obbligatorio' => true, 'tipo' => 'integer',
    'descrizione' => 'Id della pagina.'];

  $comuni = [
    'title' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il titolo della pagina.'],
    'content' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il contenuto della pagina, in HTML.'],
    'excerpt' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il riassunto della pagina.'],
    'status' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Se pubblicarla o tenerla in bozza: publish la rende visibile a '
                     . 'chiunque visiti il sito.'],
    'slug' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il pezzo finale dell indirizzo. Cambiandolo su una pagina gia '
                     . 'pubblicata, i collegamenti vecchi smettono di funzionare.'],
    'featured_image' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'L immagine di copertina.'],
    'seo_title' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il titolo mostrato nei risultati di ricerca.'],
    'meta_description' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Il riassunto mostrato nei risultati di ricerca.'],
    'focus_keywords' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Le parole su cui la pagina punta a farsi trovare.'],
  ];

  $registro['manage-page-with-user-code'] = [
    'descrizione' => 'Crea e modifica le pagine del sito, ne governa il modello di '
                   . 'impaginazione, i menu e quale pagina fa da pagina iniziale.',
    'azioni' => [

      'retrieve' => [
        'descrizione' => 'Legge una pagina. Da fare prima di modificarla, se non si sa '
                       . 'gia cosa contiene.',
        'parametri' => [
          'page_id' => $page_id,
          'chunk_index' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo leggere, per le pagine troppo lunghe per stare '
                           . 'in una risposta sola.'],
        ],
      ],
      'create-page' => [
        'descrizione' => 'Crea una pagina nuova. Con status publish e subito visibile a '
                       . 'chiunque visiti il sito.',
        'parametri' => $comuni,
      ],
      'update-page' => [
        'descrizione' => 'ATTENZIONE: sostituisce il contenuto della pagina per intero. '
                       . 'Quello che cera prima non e recuperabile: conviene leggerla '
                       . 'con retrieve.',
        'parametri' => array_merge(['id' => $page_id], $comuni),
      ],

      'get-homepage' => [
        'descrizione' => 'Dice quale pagina fa da pagina iniziale del sito.',
        'parametri' => [],
      ],
      'set-homepage' => [
        'descrizione' => 'ATTENZIONE: decide quale pagina vedono i visitatori arrivando '
                       . 'sul sito. Cambia la prima cosa che vede chiunque.',
        'parametri' => ['page_id' => $page_id],
      ],

      'view-menu' => [
        'descrizione' => 'Mostra il menu legato a una pagina. Da chiamare prima di '
                       . 'update-menu: quella riscrive tutto e conviene sapere cosa cera.',
        'parametri' => ['page_id' => $page_id],
      ],
      'update-menu' => [
        'descrizione' => 'ATTENZIONE: riscrive un menu del sito. Il menu si vede su tutte '
                       . 'le pagine, non solo su questa, e la struttura di prima non e '
                       . 'recuperabile.',
        'parametri' => [
          'page_id' => $page_id,
          'menu_data' => ['obbligatorio' => true, 'tipo' => 'object',
            'descrizione' => 'La struttura nuova del menu, che prende il posto di quella '
                           . 'di prima. Conviene ricavarla da view-menu e cambiarne solo '
                           . 'quello che serve.'],
        ],
      ],

      'get-template' => [
        'descrizione' => 'Dice con quale modello di impaginazione viene mostrata una '
                       . 'pagina.',
        'parametri' => ['page_id' => $page_id],
      ],
      'manage-template' => [
        'descrizione' => 'Cambia il modello di impaginazione di una pagina: cambia come '
                       . 'la pagina si vede, non cosa contiene.',
        'parametri' => [
          'page_id' => $page_id,
          'template_name' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il modello da usare, fra quelli che il tema mette a '
                           . 'disposizione. Un nome che il tema non conosce non funziona.'],
        ],
      ],
    ],
  ];

  return $registro;
});
