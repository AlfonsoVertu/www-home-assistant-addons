<?php
/**
 * Ogni action ha la sua documentazione_<nomeaction>? Su ogni tool?
 *
 * LA REGOLA. Un modello che sbaglia una chiamata deve poter chiedere la scheda
 * di quella singola operazione sulla rotta stessa, passando action uguale a
 * documentazione_ seguito dal nome dell'operazione. Non su un'altra rotta, non
 * su tools-manifest: proprio li' dove ha appena sbagliato, che e' l'unico posto
 * dove sicuramente sa arrivare.
 *
 * PERCHE' UNA PROVA E NON UN'OCCHIATA. La documentazione la costruisce
 * WGA_Azioni::documentazione_di() a partire dal registro: se una scheda non
 * dichiara i parametri di una action, quella action risponde con una pagina
 * vuota - che e' peggio di un errore, perche' sembra una risposta.
 *
 *   php tools/prova-documentazione-per-action.php
 */

define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

$radice = dirname(__DIR__);
require $radice . '/includes/class-wp-gpt-ambito-azioni.php';
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;

$registro = WGA_Azioni::registro();
$tool = 0; $azioni = 0; $errori = 0;
$mute = [];      // action che non rispondono niente
$senza_testo = []; // action documentate ma senza una descrizione

$rotte_mute = [];   // tool che non rispondono alla documentazione intera
$senza_invito = []; // rispondono ma non dicono come scendere al dettaglio

foreach ($registro as $nome => $t) {
    if (empty($t['azioni'])) continue;
    $tool++;

    // PRIMO LIVELLO: action uguale a documentazione, senza suffisso. Deve dare
    // tutta la rotta, a pagine, e invitare a scendere sulla singola operazione.
    $intera = WGA_Azioni::documentazione_rotta($nome, 1, 8);
    if (!is_array($intera) || empty($intera['action_di_questa_pagina'])) {
        $rotte_mute[] = $nome;
    } elseif (empty($intera['per_approfondire_una_operazione'])
              || false === strpos((string) $intera['per_approfondire_una_operazione'],
                                  WGA_Azioni::PREFISSO_DOC)) {
        $senza_invito[] = $nome;
    }

    foreach ($t['azioni'] as $azione => $def) {
        $azioni++;

        // La stessa domanda che farebbe un modello: la rotta, con
        // action=documentazione_<nomeaction>.
        $risposta = WGA_Azioni::documentazione_di($nome, $azione);

        if (!is_array($risposta) || !$risposta) {
            $mute[] = "$nome / $azione";
            continue;
        }

        // Deve almeno dire cosa fa: una scheda senza quella frase e' un guscio.
        $testo = '';
        foreach (['cosa_fa', 'descrizione'] as $c) {
            if (!empty($risposta[$c])) { $testo = (string) $risposta[$c]; break; }
        }
        if ('' === trim($testo)) $senza_testo[] = "$nome / $azione";
    }
}

echo "$tool tool, $azioni action\n";

if ($rotte_mute) {
    echo "\nFALLITO: " . count($rotte_mute) . " tool non rispondono ad action=documentazione:\n";
    foreach (array_slice($rotte_mute, 0, 10) as $x) echo "       $x\n";
    $errori++;
} else {
    echo "ok   tutti i $tool tool rispondono ad action=documentazione, a pagine\n";
}

if ($senza_invito) {
    echo "\nFALLITO: " . count($senza_invito) . " tool non dicono come scendere al dettaglio:\n";
    foreach (array_slice($senza_invito, 0, 10) as $x) echo "       $x\n";
    $errori++;
} else {
    echo "ok   tutti invitano a chiamare documentazione_<nomeaction>\n";
}

if ($mute) {
    echo "\nFALLITO: " . count($mute) . " action non rispondono alla loro documentazione:\n";
    foreach (array_slice($mute, 0, 10) as $x) echo "       $x\n";
    $errori++;
} else {
    echo "ok   tutte le $azioni action rispondono a documentazione_<nomeaction>\n";
}

if ($senza_testo) {
    echo "\nFALLITO: " . count($senza_testo) . " action rispondono ma non dicono cosa fanno:\n";
    foreach (array_slice($senza_testo, 0, 10) as $x) echo "       $x\n";
    $errori++;
} else {
    echo "ok   tutte dicono cosa fanno\n";
}

echo $errori ? "\n$errori CONTROLLI FALLITI\n" : "\nla documentazione per action c'e' su tutti i tool\n";
exit($errori ? 1 : 0);
