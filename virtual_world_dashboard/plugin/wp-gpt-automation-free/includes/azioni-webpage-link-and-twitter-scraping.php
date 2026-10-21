<?php
/**
 * Le action della rotta che legge pagine web e Twitter.
 *
 * Scritte a mano leggendo le due funzioni che stanno sotto - link_scraping e
 * scrape_twitter_data - non generate: il codice dice quali parametri esistono,
 * non a cosa servono, e questa e' la parte che vale.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  // il link e la pagina da cui si legge: uguale per tutte le analisi
  $link = ['obbligatorio' => true, 'tipo' => 'string',
           'descrizione' => 'Indirizzo completo della pagina da leggere, con http o https.'];
  $pezzo = ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo del risultato leggere quando e\' troppo '
                           . 'lungo per una risposta sola. Si parte da 0.'];

  $registro['webpage-link-and-twitter-scraping'] = [
    'descrizione' => 'Legge una pagina web e ne estrae parti - immagini, intestazioni, '
                   . 'email, testo - oppure legge un profilo o un tweet di Twitter.',
    'azioni' => [

      'get-images' => [
        'descrizione' => 'Gli indirizzi di tutte le immagini della pagina.',
        'parametri' => ['link' => $link],
      ],
      'get-meta-tags' => [
        'descrizione' => 'I meta tag della pagina: titolo, descrizione, open graph.',
        'parametri' => ['link' => $link],
      ],
      'get-headers' => [
        'descrizione' => 'Le intestazioni della pagina, dal titolo ai sottotitoli.',
        'parametri' => ['link' => $link],
      ],
      'extract-emails' => [
        'descrizione' => 'Gli indirizzi di posta scritti nella pagina.',
        'parametri' => ['link' => $link],
      ],
      'get-div-ids' => [
        'descrizione' => 'Gli id dei blocchi della pagina: servono a mirare una zona '
                       . 'precisa con find-expression-in-div.',
        'parametri' => ['link' => $link],
      ],
      'get-div-classes' => [
        'descrizione' => 'Le classi dei blocchi della pagina, per lo stesso scopo degli id.',
        'parametri' => ['link' => $link],
      ],
      'find-expression-in-div' => [
        'descrizione' => 'Cerca un\'espressione dentro un blocco della pagina.',
        'parametri' => [
          'link' => $link,
          'expression' => ['obbligatorio' => true, 'tipo' => 'string',
                           'descrizione' => 'Il testo o l\'espressione da cercare.'],
        ],
      ],
      'get-text-chunks' => [
        'descrizione' => 'Il testo della pagina a pezzi: per pagine lunghe che non '
                       . 'starebbero in una risposta sola.',
        'parametri' => ['link' => $link, 'chunk_index' => $pezzo],
      ],
      'get-html-chunks' => [
        'descrizione' => 'L\'HTML della pagina a pezzi, quando serve il codice e non '
                       . 'solo il testo.',
        'parametri' => ['link' => $link, 'chunk_index' => $pezzo],
      ],

      'scrape-twitter-profile-or-tweet' => [
        'descrizione' => 'Legge un profilo Twitter, oppure un singolo tweet con i '
                       . 'suoi commenti se si passa anche tweet_id.',
        'parametri' => [
          'username' => ['obbligatorio' => true, 'tipo' => 'string',
                         'descrizione' => 'Il nome utente, senza la chiocciola.'],
          'tweet_id' => ['obbligatorio' => false, 'tipo' => 'string',
                         'descrizione' => 'L\'id di un tweet: senza, si legge il profilo.'],
          'comments_limit' => ['obbligatorio' => false, 'tipo' => 'integer',
                               'descrizione' => 'Quanti commenti leggere al massimo.'],
        ],
      ],
    ],
  ];

  return $registro;
});
