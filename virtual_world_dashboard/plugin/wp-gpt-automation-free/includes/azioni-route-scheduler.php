<?php
/**
 * Le action del programmatore di chiamate.
 *
 * Descrizioni scritte leggendo class-wp-gpt-v1-route-scheduler.php.
 *
 * COSA FA. Programma una chiamata a un altra rotta di questo stesso plugin
 * perche avvenga piu tardi, una volta sola o a ripetizione. E il modo di dire
 * al sito fai questa cosa domani mattina, oppure ogni ora.
 *
 * PUNTA SOLO DENTRO QUESTO SITO. target_route deve essere un percorso relativo:
 * un indirizzo completo viene rifiutato. Il programmatore non puo essere usato
 * per far chiamare al sito un indirizzo di fuori.
 *
 * QUELLO CHE VA SAPUTO PRIMA DI PROGRAMMARE: il lavoro salvato conserva le
 * credenziali di chi lo programma. Lo user_code finisce dentro la definizione e
 * anche l intestazione Authorization della chiamata viene copiata nel lavoro,
 * perche piu tardi il lavoro possa presentarsi con la stessa identita. In
 * pratica il proprio accesso resta scritto nel database finche il lavoro
 * esiste: rimuovendo il lavoro con remove_job si toglie anche quello.
 *
 * TRE MODI DI RIPETERE, nel campo schedule.type: once una volta sola,
 * interval a intervalli, condition_polling quando controlla un valore e parte
 * solo se e cambiato.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $chiave = ['obbligatorio' => true, 'tipo' => 'string',
    'descrizione' => 'La chiave del lavoro, quella scelta con add_job. Serve a '
                   . 'ritrovarlo: conviene un nome che dica cosa fa.'];

  $registro['route-scheduler'] = [
    'descrizione' => 'Programma chiamate ad altre rotte di questo sito perche avvengano '
                   . 'piu tardi, una volta sola o a ripetizione, e ne segue lesito.',
    'azioni' => [

      'list_jobs' => [
        'descrizione' => 'Elenca i lavori programmati con il loro stato. Da chiamare per '
                       . 'prima: le altre action partono da una chiave.',
        'parametri' => [
          'page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 1,
            'descrizione' => 'Quale pagina di risultati, da 1.'],
          'per_page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 20,
            'descrizione' => 'Quanti lavori per pagina, al massimo 100.'],
          'status' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Restringe a uno stato, per esempio active.'],
          'search' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Cerca nel nome del lavoro e dentro la sua definizione, '
                           . 'quindi trova anche per rotta chiamata.'],
          'job_key' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Restringe a un lavoro solo.'],
        ],
      ],

      'add_job' => [
        'descrizione' => 'Programma una chiamata. Il lavoro conserva le credenziali di '
                       . 'chi lo crea per potersi presentare piu tardi con la stessa '
                       . 'identita.',
        'parametri' => [
          'job_key' => $chiave,
          'definition' => ['obbligatorio' => true, 'tipo' => 'object',
            'descrizione' => 'Cosa fare: target_route, obbligatorio, il percorso REST '
                           . 'relativo da chiamare, per esempio /wp-gpt/v1/rotta - un '
                           . 'indirizzo completo viene rifiutato; payload, i parametri '
                           . 'da mandare; schedule, quando e ogni quanto; headers, '
                           . 'intestazioni in piu.'],
        ],
      ],

      'remove_job' => [
        'descrizione' => 'Toglie un lavoro programmato: smette di partire e le credenziali '
                       . 'che aveva conservato spariscono con lui.',
        'parametri' => ['job_key' => $chiave],
      ],

      'run_job' => [
        'descrizione' => 'Esegue subito un lavoro programmato, senza aspettare il suo '
                       . 'momento. La chiamata avviene davvero, con tutti i suoi effetti: '
                       . 'non e una prova a vuoto.',
        'parametri' => ['job_key' => $chiave],
      ],

      'get_log' => [
        'descrizione' => 'Legge il registro di un lavoro: quando e partito e come e finito. '
                       . 'Le righe piu recenti per prime.',
        'parametri' => [
          'job_key' => $chiave,
          'page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 1,
            'descrizione' => 'Quale pagina di righe, da 1.'],
          'per_page' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 100,
            'descrizione' => 'Quante righe per pagina, al massimo 500.'],
          'search' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Tiene solo le righe che contengono questo testo. Maiuscole '
                           . 'e minuscole non contano.'],
        ],
      ],

      'set_logging' => [
        'descrizione' => 'Accende o spegne la scrittura del registro dei lavori. Spento, '
                       . 'get_log non avra piu niente di nuovo da mostrare.',
        'parametri' => [
          'logging' => ['obbligatorio' => true, 'tipo' => 'boolean',
            'descrizione' => 'Vero per scrivere il registro, falso per smettere.'],
        ],
      ],
    ],
  ];

  return $registro;
});
