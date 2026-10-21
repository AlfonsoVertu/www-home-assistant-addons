<?php
/**
 * Le action di ricerca e raccolta informazioni.
 *
 * Descrizioni scritte leggendo class-wp-gpt-gather-information.php, con i
 * parametri presi dai corpi dei singoli gestori.
 *
 * QUESTO TOOL HA ASSORBITO TRE ROTTE. youtube-transcription,
 * search-youtube-video ed extract-image-links erano rotte a se, con lo stesso
 * identico nome che hanno qui come action: la migrazione era gia stata fatta e
 * mancava solo di chiudere le porte vecchie.
 *
 * QUASI TUTTE LE RICERCHE CONDIVIDONO QUATTRO PARAMETRI: keyword, language,
 * country e location. Cambiano il motore e cio che restituiscono, non il modo
 * di chiedere - quindi chi ne ha imparata una le sa usare tutte.
 *
 * DUE COSE CHE NON TORNANO, e le lascio scritte invece di lisciarle:
 * serpapi-keyword-analysis accetta video_url, che in un analisi di parole
 * chiave e insolito, ed extract-image-links accetta country, language e
 * location, cioe i parametri di una ricerca. Hanno l aria di residui copiati
 * fra gestori simili. Sono documentati come sono, non come dovrebbero essere.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $kw = ['obbligatorio' => true, 'tipo' => 'string',
    'descrizione' => 'Le parole da cercare.'];
  $lingua = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Lingua dei risultati, per esempio it. Cambia cosa si trova, non '
                   . 'solo come e scritto.'];
  $paese = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Paese da cui si cerca, per esempio it: i risultati di Google '
                   . 'cambiano parecchio da un paese all altro.'];
  $luogo = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Citta o zona da cui simulare la ricerca, per i risultati locali.'];
  $pagina = ['obbligatorio' => false, 'tipo' => 'integer',
    'descrizione' => 'Pagina dei risultati, da 1.'];
  $quanti = ['obbligatorio' => false, 'tipo' => 'integer',
    'descrizione' => 'Quanti risultati per pagina.'];
  $sicura = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Filtro dei contenuti per adulti.'];
  $periodo = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Limita ai risultati di un periodo, per esempio l ultimo mese.'];
  $dispositivo = ['obbligatorio' => false, 'tipo' => 'string',
    'descrizione' => 'Se cercare come da telefono o da computer: i risultati differiscono.'];

  $base = ['keyword' => $kw, 'language' => $lingua, 'country' => $paese,
           'location' => $luogo, 'page_number' => $pagina, 'results_per_page' => $quanti];

  $registro['gather-information-youtube-google-serp-keywrd-analisys-image-link'] = [
    'descrizione' => 'Cerca informazioni fuori dal sito: risultati di Google, notizie, '
                   . 'immagini, tendenze, mappe, video di YouTube e trascrizioni.',
    'azioni' => [

      'youtube-transcription' => [
        'descrizione' => 'Il testo parlato di un video di YouTube, a pezzi. Serve per '
                       . 'leggere un video senza guardarlo.',
        'parametri' => [
          'video_url' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Indirizzo del video.'],
          'chunk_index' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo della trascrizione leggere, da 0. Le '
                           . 'trascrizioni lunghe non entrano in una risposta sola.'],
        ],
      ],

      'search-youtube-video' => [
        'descrizione' => 'Cerca video su YouTube.',
        'parametri' => [
          'query' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Cosa cercare. Qui il parametro si chiama query, non keyword.'],
        ],
      ],

      'extract-image-links' => [
        'descrizione' => 'Trova indirizzi di immagini a partire da una ricerca.',
        'parametri' => ['keyword' => $kw, 'language' => $lingua,
                        'country' => $paese, 'location' => $luogo],
      ],

      'serpapi-serp-analysis' => [
        'descrizione' => 'Analizza i risultati di Google per una parola chiave: cosa esce, '
                       . 'in che ordine, da quali siti.',
        'parametri' => [
          'keyword' => $kw,
          'hl' => ['obbligatorio' => false, 'tipo' => 'string', 'predefinito' => 'it',
            'descrizione' => 'Lingua dell interfaccia di ricerca, due lettere.'],
          'gl' => ['obbligatorio' => false, 'tipo' => 'string', 'predefinito' => 'it',
            'descrizione' => 'Paese da cui si cerca, due lettere.'],
          'chunk_index' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo dei risultati leggere, da 0.'],
          'chunk_size' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 10,
            'descrizione' => 'Quanti risultati per pezzo.'],
        ],
      ],

      // serpapi-keyword-analysis non c'e' in questa edizione: analizzare le
      // keyword e' un mestiere da SEO e sta nella Pro. Qui serve cercare,
      // non posizionarsi.

      'seo_analisys_serpapi_google_search' => [
        'descrizione' => 'I risultati di una ricerca su Google, come li vedrebbe una '
                       . 'persona dal paese e dal dispositivo indicati.',
        'parametri' => array_merge($base, ['device' => $dispositivo, 'safe_search' => $sicura,
          'time_filter' => $periodo,
          'output_format' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'In che forma restituire i risultati.'],
          'api_domain' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Quale dominio di Google interrogare, per esempio google.it.'],
        ]),
      ],

      'seo_analisys_serpapi_google_images' => [
        'descrizione' => 'I risultati di Google Immagini.',
        'parametri' => array_merge($base, ['device' => $dispositivo, 'safe_search' => $sicura,
          'time_filter' => $periodo,
          'query' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Nome alternativo di keyword.'],
        ]),
      ],

      'seo_analisys_serpapi_google_news' => [
        'descrizione' => 'Le notizie di Google su un argomento.',
        'parametri' => array_merge($base, ['safe_search' => $sicura, 'time_filter' => $periodo,
          'category' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Categoria di notizie.'],
          'geo' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Zona geografica delle notizie.'],
          'trend_type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Tipo di andamento da guardare.'],
        ]),
      ],

      'seo_analisys_serpapi_google_trends' => [
        'descrizione' => 'Le tendenze di ricerca: come cambia nel tempo l interesse per '
                       . 'un argomento.',
        'parametri' => array_merge($base, [
          'date_range' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Periodo da osservare.'],
          'geo' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Zona geografica.'],
          'category' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Categoria merceologica.'],
          'trend_type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Tipo di andamento.'],
          'shopping_condition' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Condizione del prodotto, per le tendenze di acquisto.'],
        ]),
      ],

      'seo_analisys_serpapi_google_shopping' => [
        'descrizione' => 'I prodotti che Google Shopping mostra per una ricerca: utile per '
                       . 'guardare i prezzi della concorrenza.',
        'parametri' => array_merge($base, ['device' => $dispositivo, 'safe_search' => $sicura,
          'shopping_condition' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Nuovo o usato.'],
        ]),
      ],

      'seo_analisys_serpapi_google_scholar' => [
        'descrizione' => 'I risultati accademici di Google Scholar.',
        'parametri' => array_merge($base, [
          'query' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Nome alternativo di keyword.'],
          'author_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Cerca i lavori di un autore preciso.'],
          'cite_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Le citazioni di un lavoro preciso.'],
          'cluster_id' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Le versioni di uno stesso lavoro.'],
          'type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Che genere di ricerca fare.'],
          'latitude' => ['obbligatorio' => false, 'tipo' => 'string', 'descrizione' => 'Latitudine.'],
          'longitude' => ['obbligatorio' => false, 'tipo' => 'string', 'descrizione' => 'Longitudine.'],
        ]),
      ],

      'seo_analisys_serpapi_google_maps' => [
        'descrizione' => 'I risultati di Google Maps: attivita, indirizzi, recensioni.',
        'parametri' => [
          'keyword' => $kw, 'language' => $lingua, 'country' => $paese, 'location' => $luogo,
          'query' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Nome alternativo di keyword.'],
          'latitude' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Latitudine del punto da cui cercare.'],
          'longitude' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Longitudine del punto da cui cercare.'],
          'zoom_level' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Quanto stretta e la zona: cambia quante attivita si vedono.'],
          'data_type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Che dati chiedere della scheda.'],
          'type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Tipo di ricerca sulla mappa.'],
        ],
      ],

      'seo_analisys_serpapi_google_ai_overview' => [
        'descrizione' => 'Il riassunto generato da Google in cima ai risultati, quando c e.',
        'parametri' => $base,
      ],

      'google_related_questions_api' => [
        'descrizione' => 'Le domande correlate che Google mostra sotto i risultati: sono '
                       . 'le domande che le persone fanno davvero su quell argomento.',
        'parametri' => [
          'query' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Da quale ricerca partire.'],
          'language' => $lingua, 'country' => $paese,
          'next_page_token' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Il segnaposto restituito dalla chiamata precedente, per '
                           . 'avere le domande successive.'],
        ],
      ],

      'google_autocomplete_api' => [
        'descrizione' => 'I completamenti che Google propone mentre si digita: dicono '
                       . 'come le persone cercano davvero una cosa.',
        'parametri' => [
          'keyword' => $kw,
          'query' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Nome alternativo di keyword.'],
          'language' => $lingua, 'country' => $paese, 'results_per_page' => $quanti,
        ],
      ],
    ],
  ];

  return $registro;
});
