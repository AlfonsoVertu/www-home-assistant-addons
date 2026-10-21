<?php
/**
 * Ritaglia da un file OpenAPI un pezzo di testo per ogni operazione.
 *
 * E' il cuore del selezionatore: la pagina Guida alla Configurazione GPTS
 * mostra un elenco di rotte con una casella ciascuna, e quello che l'utente
 * spunta viene rimontato sotto una intestazione e incollato dentro ChatGPT.
 *
 * PERCHE' STA QUI E NON DENTRO LA CLASSE. Stava dentro la classe, e non si
 * poteva provare: per provarlo servivano WordPress e una pagina di
 * amministrazione aperta. Cosi' invece la stessa funzione la usano la pagina e
 * la prova, e la prova misura il codice vero e non una sua copia che invecchia.
 *
 * NON E' UN LETTORE YAML. Taglia per indentazione e tiene il testo com'e',
 * perche' quello che serve e' restituire le righe originali, non un albero di
 * dati da riscrivere. Un vero lettore YAML rigenererebbe il file perdendo la
 * forma, e la forma qui e' il prodotto.
 */

if (!function_exists('wp_gpt_leggi_operazioni')) {

    /**
     * @param string $file Il percorso di un file OpenAPI.
     * @return array<int,array{key:string,operationId:string,snippet:string,summary:string,description:string}>
     */
    function wp_gpt_leggi_operazioni($file) {
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }

        $operations = [];
        $in_paths = false;
        $current_path = '';
        $current_method = '';
        $buffer = [];
        $operation_id = '';
        $current_summary = '';
        $current_description = '';
        $collecting_description = false;

        // La riga del percorso va tenuta da parte: quando dentro allo stesso
        // percorso si incontra un secondo metodo, il pezzo nuovo deve ricominciare
        // da quella riga. Senza, il secondo pezzo esce come un blocco post: che
        // non sta sotto nessun percorso, e lo schema montato non e' valido.
        $riga_percorso = '';

        $chiudi = function () use (&$operations, &$current_path, &$current_method, &$buffer,
                                  &$operation_id, &$current_summary, &$current_description,
                                  &$collecting_description) {
            if (!$current_path || !$current_method) return;
            $operations[] = [
                'key'         => $current_path . '|' . $current_method,
                'operationId' => $operation_id,
                'snippet'     => implode("\n", $buffer),
                'summary'     => $current_summary,
                'description' => trim($current_description),
            ];
            $buffer = [];
            $current_method = '';
            $operation_id = '';
            $current_summary = '';
            $current_description = '';
            $collecting_description = false;
        };

        foreach ($lines as $line) {
            if (!$in_paths) {
                if (preg_match('/^\s*paths:\s*$/', $line)) {
                    $in_paths = true;
                }
                continue;
            }

            if (preg_match('/^\s{2}[^\s][^:]*:\s*$/', $line)) {
                $chiudi();
                $current_path  = trim($line, " :");
                $riga_percorso = $line;
                $buffer = [$line];
                continue;
            }

            if (preg_match('/^\s{4}([a-zA-Z0-9_]+):/', $line, $m)) {
                $http_method = strtolower($m[1]);
                $valid_methods = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head', 'trace'];

                if (in_array($http_method, $valid_methods, true)) {
                    $chiudi();
                    // Il pezzo riparte sempre dalla riga del percorso, cosi' ogni
                    // operazione e' un blocco che sta in piedi da solo.
                    $buffer = ('' !== $riga_percorso) ? [$riga_percorso, $line] : [$line];
                    $current_method = $http_method;
                    continue;
                }
            }

            if ($current_method) {
                $buffer[] = $line;
                if ($collecting_description) {
                    if (preg_match('/^\s{8,}(.*)$/', $line, $desc_line)) {
                        $desc_content = trim($desc_line[1]);
                        if ('' !== $desc_content) {
                            $current_description .= ('' !== $current_description ? "\n" : '') . $desc_content;
                        }
                        continue;
                    }
                    $collecting_description = false;
                }
                if (preg_match('/operationId:\s*(.+)/', $line, $m)) {
                    $operation_id = trim($m[1], "'\" ");
                }
                if (preg_match('/^\s{6}summary:\s*(.+)/', $line, $m)) {
                    $current_summary = trim($m[1], "'\" ");
                }
                if (preg_match('/^\s{6}description:\s*(.+)/', $line, $m)) {
                    $desc_value = trim($m[1]);
                    if (in_array($desc_value, ['>', '|', '|-', '>-', '|+'], true)) {
                        $collecting_description = true;
                        $current_description = '';
                    } else {
                        $current_description = trim($desc_value, "'\" ");
                        $collecting_description = false;
                    }
                }
            }
        }

        $chiudi();

        return $operations;
    }
}
