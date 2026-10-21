<?php
/**
 * Controlla le istruzioni dello step 4: lunghezza e nomi di rotte vive.
 *
 * DUE MODI DI ROMPERLE, tutti e due silenziosi.
 *
 * Il primo e' la lunghezza: OpenAI accetta 8000 caratteri nel campo Istruzioni,
 * spazi compresi, e oltre quel punto tronca senza dire niente. Un testo
 * troncato non da' errore: da' un GPT che ha perso l'ultimo pezzo delle sue
 * regole, e nessuno se ne accorge finche' non si comporta male.
 *
 * Il secondo sono i nomi. Le istruzioni citano dei tool per nome, e quei nomi
 * invecchiano: la versione di prima mandava il modello a chiamare
 * extract-image-links, append-content ed execute-minecraft-command, che erano
 * diventate action dentro altri tool. Qui si controlla che ogni nome citato
 * corrisponda a un tool che esiste davvero nel registro.
 *
 *   php tools/prova-istruzioni.php
 */

define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }
function home_url() { return 'https://esempio.it'; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }

$radice = dirname(__DIR__);
require $radice . '/includes/class-wp-gpt-ambito-azioni.php';
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;
require $radice . '/includes/class-wp-gpt-generate-instructions.php';

$gen = new \WP_GPT_Automation_Pro\Istruzioni_Personalizzate_GPTS();
$testo = $gen->generate_instructions();

$TETTO = 8000;
$errori = 0;

$lunghezza = mb_strlen($testo, 'UTF-8');
if ($lunghezza > $TETTO) {
    echo "FALLITO: $lunghezza caratteri, il tetto e' $TETTO. Verrebbero troncati "
       . ($lunghezza - $TETTO) . " caratteri senza avviso.\n";
    $errori++;
} else {
    echo "ok   lunghezza: $lunghezza / $TETTO (margine " . ($TETTO - $lunghezza) . ")\n";
}

// I nomi di tool citati nel testo: si cercano quelli che assomigliano a una
// rotta, cioe' parole con dei trattini in mezzo.
$registro = WGA_Azioni::registro();
$esistono = array_map([WGA_Azioni::class, 'nome_pulito'], array_keys($registro));

preg_match_all('/\b([a-z][a-z0-9]*(?:-[a-z0-9]+){2,})\b/', $testo, $m);
$citati = array_values(array_unique($m[1]));

// Parole con i trattini che non sono nomi di rotta.
$non_sono_rotte = ['lista-dei-tool-che-contengono-le-action', 'call-to-action'];

$morti = [];
foreach ($citati as $c) {
    if (in_array($c, $non_sono_rotte, true)) continue;
    if (in_array($c, $esistono, true)) continue;
    // Puo' essere il nome di una action invece che di un tool: si accetta.
    $e_una_action = false;
    foreach ($registro as $t) {
        if (isset($t['azioni'][$c])) { $e_una_action = true; break; }
    }
    if (!$e_una_action) $morti[] = $c;
}

if ($morti) {
    echo "FALLITO: nomi citati che non esistono ne' come tool ne' come action:\n";
    foreach ($morti as $x) echo "       $x\n";
    $errori++;
} else {
    echo "ok   i " . count($citati) . " nomi citati esistono tutti\n";
}

if (stripos($testo, 'minecraft') !== false) {
    echo "FALLITO: Minecraft e' citato nelle istruzioni, e non deve esserci.\n";
    $errori++;
} else {
    echo "ok   nessun riferimento a Minecraft\n";
}

echo $errori ? "\n$errori CONTROLLI FALLITI\n" : "\nistruzioni a posto\n";
exit($errori ? 1 : 0);
