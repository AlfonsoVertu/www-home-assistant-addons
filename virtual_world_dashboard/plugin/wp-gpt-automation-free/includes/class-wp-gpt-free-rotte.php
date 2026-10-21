<?php
/**
 * Quali rotte esiste questa edizione.
 *
 * COS'E' IL FREE. Due mestieri e basta: comandare l'impianto domotico e
 * ricordarsi le cose. Il resto - i file, le pagine, l'accesso SSH alle
 * macchine, i lavori programmati - e' della versione Pro, e su un sito di casa
 * non serve a nessuno. Tenerlo acceso "tanto c'e'" vuol dire offrire a chi si
 * collega degli attrezzi che non dovrebbe avere.
 *
 * PERCHE' UNA LISTA E NON SETTE FILE IN MENO. Le due rotte da togliere piu'
 * ingombranti (manage-file, manage-page-with-user-code) stanno dentro
 * class-wp-gpt-api.php, che e' anche il file dove vive la guardia usata da
 * TUTTE le rotte, manifesto compreso: togliere il file spegnerebbe anche cio'
 * che vogliamo tenere. Filtrare le rotte alla fine lascia il codice comune
 * intatto e mette in un posto solo, leggibile, la risposta alla domanda
 * "cosa fa il free?".
 *
 * SI VEDE ANCHE DA FUORI. Il manifesto elenca le rotte davvero registrate:
 * togliendole qui, spariscono anche dalla scoperta. Chi si collega vede due
 * strumenti, non dieci di cui otto che rifiutano.
 */

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) exit;

if (!class_exists(__NAMESPACE__ . '\WP_GPT_Free_Rotte')) :

class WP_GPT_Free_Rotte {

    /** Le sole rotte che questa edizione espone, oltre all'indice del namespace. */
    const TENUTE = [
        // la scoperta: senza, l'app non sa cosa questo sito sa fare
        '/wp-gpt/v1/tools-manifest',
        // Il rinnovo delle credenziali. SENZA QUESTA RIGA LA ROTTA NON ESISTE:
        // il filtro del free toglie tutto cio' che non e' elencato qui, e la
        // rotta appena aggiunta sparirebbe subito dopo essersi registrata -
        // viva nel codice, assente nel sito, senza nessun errore a dirlo.
        '/wp-gpt/v1/manage-credentials-rotation-refresh-token',

        // l'impianto domotico e le videocamere. Le due rotte di Home Assistant
        // sono state fuse in una: chi aggiorna il plugin senza aggiornare
        // questo elenco si ritrova la casa muta, perche' la rotta c'e' ma il
        // filtro la butta via.
        '/wp-gpt/v1/manage-casahomeassistant-domoticandashboard-videocam',
        // le stanze, i dispositivi e le pagine
        '/wp-gpt/v1/manage-casa-stanze-dispositivi-e-pagine',

        // le pagine: le dashboard di Home Assistant SONO pagine WordPress.
        // Senza questa rotta la casa si comanda ma non si puo' guardare.
        '/wp-gpt/v1/manage-page-with-user-code',
        // e i file, per metterci dentro le immagini
        '/wp-gpt/v1/manage-file',

        // cercare fuori: e' il mestiere per cui si tiene acceso un assistente
        // su un sito di casa, dopo la domotica
        '/wp-gpt/v1/gather-information-youtube-google-serp-keywrd-analisys-image-link',
        '/wp-gpt/v1/webpage-link-and-twitter-scraping',

        // le macchine
        '/wp-gpt/v1/ssh-connection',
        '/wp-gpt/v1/ssh-connection-advanced-command',

        // la memoria
        '/wp-gpt/v1/memory/goal',
        '/wp-gpt/v1/memory/admin',
        '/wp-gpt/v1/memory/admin/delete-goals',
        '/wp-gpt/v1/memory/admin/delete-steps',
    ];

    public static function init() {
        // priorita' alta: dopo che tutti hanno registrato le loro
        add_filter('rest_endpoints', [__CLASS__, 'filtra'], 99);
    }

    public static function filtra($rotte) {
        foreach (array_keys($rotte) as $percorso) {
            if (0 !== strpos($percorso, '/wp-gpt/')) continue;   // altri plugin non si toccano
            if ('/wp-gpt/v1' === $percorso) continue;            // l'indice del namespace resta
            if (in_array($percorso, self::TENUTE, true)) continue;
            unset($rotte[$percorso]);
        }
        return $rotte;
    }
}

endif;
