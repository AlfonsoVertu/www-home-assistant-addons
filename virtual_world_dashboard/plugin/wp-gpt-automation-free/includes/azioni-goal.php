<?php
/**
 * Le action di goal.
 *
 * Estratta dalla versione Pro, che la dichiara insieme ad altri tool: qui si
 * tiene solo questa, perche' questo plugin ha solo questa rotta e dichiarare
 * quello che non c'e' manda un assistente a chiamare un indirizzo che non
 * risponde.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_gpt_azioni_dei_tool', function (array $registro) {

  $campi_goal = [
    'goal_title' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Come si chiama l obiettivo.'],
    'goal_type' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Di che genere e l obiettivo.'],
    'keyword' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Una parola per ritrovarlo.'],
    'meta' => ['obbligatorio' => false, 'tipo' => 'object',
      'descrizione' => 'Dati in piu da tenere insieme all obiettivo.'],
  ];
  $elenco = [
    'limit' => ['obbligatorio' => false, 'tipo' => 'integer',
      'descrizione' => 'Quanti risultati per volta.'],
    'offset' => ['obbligatorio' => false, 'tipo' => 'integer',
      'descrizione' => 'Da quale risultato cominciare, per scorrere le pagine.'],
    'status' => ['obbligatorio' => false, 'tipo' => 'string',
      'descrizione' => 'Restringe a uno stato.'],
    'include_deleted' => ['obbligatorio' => false, 'tipo' => 'boolean',
      'descrizione' => 'Se mostrare anche quelli messi da parte con delete.'],
  ];
  $goal_id = ['obbligatorio' => true, 'tipo' => 'integer',
    'descrizione' => 'Id dell obiettivo, preso da list_goals.'];
  $step_id = ['obbligatorio' => true, 'tipo' => 'integer',
    'descrizione' => 'Id del passo, preso da list_steps.'];

  $registro['goal'] = [
    'descrizione' => 'Tiene gli obiettivi e i passi per arrivarci: li crea, ne segue lo '
                   . 'stato e dice a che punto sono.',
    'azioni' => [

      'list_goals' => [
        'descrizione' => 'Elenca gli obiettivi. Da chiamare per prima: le altre action '
                       . 'partono da un id.',
        'parametri' => $elenco,
      ],
      'get_goal' => [
        'descrizione' => 'Legge un obiettivo.',
        'parametri' => ['goal_id' => $goal_id],
      ],
      'create_goal' => [
        'descrizione' => 'Crea un obiettivo nuovo.',
        'parametri' => array_merge(['goal_title' => array_merge($campi_goal['goal_title'],
          ['obbligatorio' => true])], array_slice($campi_goal, 1)),
      ],
      'update_goal' => [
        'descrizione' => 'Cambia un obiettivo esistente.',
        'parametri' => array_merge(['goal_id' => $goal_id], $campi_goal, [
          'status' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'A che punto e l obiettivo.'],
        ]),
      ],
      'delete_goal' => [
        'descrizione' => 'Mette da parte un obiettivo. Si puo ancora rivedere chiedendo '
                       . 'include_deleted a list_goals.',
        'parametri' => ['goal_id' => $goal_id],
      ],
      'hard_delete_goal' => [
        'descrizione' => 'ATTENZIONE: cancella davvero un obiettivo. Non si recupera.',
        'parametri' => ['goal_id' => $goal_id],
      ],
      'delete_all_goals' => [
        'descrizione' => 'ATTENZIONE: toglie tutti gli obiettivi in un colpo. Con hard '
                       . 'vero non si recupera piu niente.',
        'parametri' => ['hard' => ['obbligatorio' => false, 'tipo' => 'boolean',
          'descrizione' => 'Vero per cancellare davvero invece di mettere da parte.']],
      ],
      'get_status' => [
        'descrizione' => 'Dice a che punto e un obiettivo, guardando i suoi passi.',
        'parametri' => ['goal_id' => $goal_id],
      ],

      'list_steps' => [
        'descrizione' => 'Elenca i passi di un obiettivo.',
        'parametri' => array_merge(['goal_id' => $goal_id], $elenco),
      ],
      'get_step' => [
        'descrizione' => 'Legge un passo.',
        'parametri' => ['step_id' => $step_id],
      ],
      'add_step' => [
        'descrizione' => 'Aggiunge un passo a un obiettivo.',
        'parametri' => [
          'goal_id' => $goal_id,
          'step_label' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'Cosa dice il passo.'],
          'step_type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Di che genere e il passo.'],
          'status' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'A che punto e il passo.'],
          'meta' => ['obbligatorio' => false, 'tipo' => 'object',
            'descrizione' => 'Dati in piu da tenere insieme al passo.'],
        ],
      ],
      'update_step' => [
        'descrizione' => 'Cambia un passo: e cosi che si segna fatto quello che e fatto.',
        'parametri' => [
          'step_id' => $step_id,
          'step_label' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Il testo nuovo del passo.'],
          'step_type' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Il genere del passo.'],
          'status' => ['obbligatorio' => false, 'tipo' => 'string',
            'descrizione' => 'Lo stato nuovo.'],
          'meta' => ['obbligatorio' => false, 'tipo' => 'object',
            'descrizione' => 'Dati in piu.'],
        ],
      ],
      'delete_step' => [
        'descrizione' => 'Mette da parte un passo.',
        'parametri' => ['step_id' => $step_id],
      ],
      'hard_delete_step' => [
        'descrizione' => 'ATTENZIONE: cancella davvero un passo. Non si recupera.',
        'parametri' => ['step_id' => $step_id],
      ],
      'delete_all_steps' => [
        'descrizione' => 'ATTENZIONE: toglie tutti i passi di un obiettivo in un colpo.',
        'parametri' => ['goal_id' => $goal_id,
          'hard' => ['obbligatorio' => false, 'tipo' => 'boolean',
            'descrizione' => 'Vero per cancellare davvero invece di mettere da parte.']],
      ],
      'hard_delete_steps_by_goal' => [
        'descrizione' => 'ATTENZIONE: cancella davvero tutti i passi di un obiettivo. Non '
                       . 'si recuperano.',
        'parametri' => ['goal_id' => $goal_id],
      ],

      'get_chunked_response' => [
        'descrizione' => 'Legge un pezzo di una risposta messa da parte perche troppo '
                       . 'lunga.',
        'parametri' => [
          'chunk_key' => ['obbligatorio' => true, 'tipo' => 'string',
            'descrizione' => 'La chiave restituita dalla risposta troncata.'],
          'chunk_index' => ['obbligatorio' => false, 'tipo' => 'integer', 'predefinito' => 0,
            'descrizione' => 'Quale pezzo leggere, da 0.'],
        ],
      ],
      'delete_chunk' => [
        'descrizione' => 'Butta via una risposta messa da parte, per liberare spazio.',
        'parametri' => ['chunk_key' => ['obbligatorio' => true, 'tipo' => 'string',
          'descrizione' => 'La chiave della risposta da buttare.']],
      ],
    ],
  ];

  return $registro;
});
