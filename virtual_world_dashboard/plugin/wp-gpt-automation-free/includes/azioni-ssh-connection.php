<?php
/**
 * Le action delle due rotte SSH.
 *
 * Descrizioni scritte leggendo class-wp-gpt-ssh-connection.php, che registra DUE
 * rotte con due gestori distinti: una si collega ed esegue, l altra lavora sui
 * file del server. Il convertitore automatico si rifiutava di trattarle come
 * una cosa sola, e faceva bene.
 *
 * COSA APRONO QUESTE ROTTE. Una connessione a un server vero, con le credenziali
 * di chi ci entra, e la possibilita di eseguire comandi e scrivere file. Non e
 * una funzione del sito: e il sito che diventa una porta verso un altra
 * macchina. Chi la espone a un assistente gli sta dando una shell.
 *
 * LA CHIAVE PRIVATA E MEGLIO DELLA PASSWORD, e sono alternative: private_key
 * con la sua passphrase evita di far viaggiare una password nella chiamata.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $accesso = [
    'host' => ['obbligatorio' => true, 'tipo' => 'string',
      'descrizione' => 'Indirizzo del server a cui collegarsi.'],
    'port' => ['obbligatorio' => true, 'tipo' => 'integer',
      'descrizione' => 'Porta SSH del server, di solito 22.'],
    'username' => ['obbligatorio' => true, 'tipo' => 'string',
      'descrizione' => 'Utente con cui entrare.'],
    'password' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Password dell utente. Alternativa a private_key: meglio la '
                     . 'chiave, cosi la password non viaggia nella chiamata.'],
    'private_key' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Chiave privata per entrare senza password.'],
    'private_key_passphrase' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Frase che sblocca la chiave privata, se ne ha una.'],
  ];

  $registro['ssh-connection'] = [
    'descrizione' => 'Si collega a un server via SSH ed esegue un comando, restituendo '
                   . 'quello che il server risponde.',
    'azioni' => [
      'ssh-connection' => [
        'descrizione' => 'Apre la connessione ed esegue il comando indicato. Il risultato '
                       . 'e l uscita del comando, come si vedrebbe in un terminale.',
        'parametri' => array_merge($accesso, [
          'command' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il comando da eseguire sul server.'],
        ]),
      ],
      'get_ssh_connection_response_list_in_chunk' => [
        'descrizione' => 'Elenca le risposte messe da parte perche troppo lunghe per una '
                       . 'sola chiamata.',
        'parametri' => [
          'transient_key' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'La chiave della risposta da riprendere.'],
          'chunk_index' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo dell elenco leggere, da 0.'],
        ],
      ],
      'get_ssh_connection_single_response_in_chunk' => [
        'descrizione' => 'Legge un pezzo di una risposta lunga messa da parte.',
        'parametri' => [
          'transient_key' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'La chiave della risposta, restituita dalla chiamata che era '
                           . 'stata troncata.'],
          'chunk_index' => ['obbligatorio' => true, 'tipo' => 'integer',
            'descrizione' => 'Quale pezzo leggere, da 0.'],
        ],
      ],
      'delete_ssh_connection_transients' => [
        'descrizione' => 'Butta via le risposte messe da parte, per liberare spazio.',
        'parametri' => [],
      ],
    ],
  ];

  $registro['ssh-connection-advanced-command'] = [
    'descrizione' => 'Lavora sui file di un server via SSH: legge, scrive, carica, '
                   . 'cambia permessi e proprietario, esegue script.',
    'azioni' => [
      'list-directory' => [
        'descrizione' => 'Elenca il contenuto di una cartella del server.',
        'parametri' => array_merge($accesso, [
          'directory' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'La cartella da guardare, con il percorso completo.']]),
      ],
      'read-file' => [
        'descrizione' => 'Legge un file del server.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Percorso completo del file.']]),
      ],
      'create-file' => [
        'descrizione' => 'Crea un file col contenuto indicato. Se il file esiste gia, il '
                       . 'contenuto di prima viene sostituito.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Dove creare il file.'],
          'content' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Cosa scriverci dentro.']]),
      ],
      'append-to-file' => [
        'descrizione' => 'Aggiunge in fondo a un file, senza toccare quello che c e gia. '
                       . 'E la scelta prudente quando il file esiste.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Il file.'],
          'content' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Cosa aggiungere.']]),
      ],
      'delete-file' => [
        'descrizione' => 'ATTENZIONE: cancella un file dal server. Non passa da un cestino.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il file da cancellare.']]),
      ],
      'upload-file-to-path' => [
        'descrizione' => 'Carica un file sul server nel percorso indicato.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Dove metterlo.'],
          'content' => ['obbligatorio' => false, 'tipo' => 'string', 'descrizione' => 'Il contenuto da caricare.']]),
      ],
      'create-script-sh' => [
        'descrizione' => 'Crea uno script di shell sul server. Non lo esegue: per quello '
                       . 'serve execute-script.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Dove creare lo script.'],
          'content' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Il contenuto dello script.']]),
      ],
      'execute-script' => [
        'descrizione' => 'ATTENZIONE: esegue uno script sul server. Fa tutto quello che lo '
                       . 'script dice di fare, con i permessi dell utente indicato.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Lo script da eseguire.']]),
      ],
      'custom-command' => [
        'descrizione' => 'ATTENZIONE: esegue un comando qualsiasi sul server. E una shell '
                       . 'a tutti gli effetti.',
        'parametri' => array_merge($accesso, [
          'command' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Il comando da eseguire.']]),
      ],
      'chmod' => [
        'descrizione' => 'Cambia i permessi di un file. Permessi troppo aperti su un '
                       . 'server esposto sono un problema di sicurezza, non di comodita.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Il file.'],
          'permissions' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'I permessi da mettere, nella forma numerica come 644.']]),
      ],
      'chown' => [
        'descrizione' => 'Cambia proprietario e gruppo di un file.',
        'parametri' => array_merge($accesso, [
          'filepath' => ['obbligatorio' => true, 'tipo' => 'string', 'descrizione' => 'Il file.'],
          'owner' => ['obbligatorio' => false, 'tipo' => 'string', 'descrizione' => 'Il nuovo proprietario.'],
          'group' => ['obbligatorio' => false, 'tipo' => 'string', 'descrizione' => 'Il nuovo gruppo.']]),
      ],
    ],
  ];

  return $registro;
});
