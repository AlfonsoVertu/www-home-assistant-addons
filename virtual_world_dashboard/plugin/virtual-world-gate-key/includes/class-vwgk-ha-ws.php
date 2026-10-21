<?php
/**
 * Il filo diretto con Home Assistant, per le cose che l'API REST non fa.
 *
 * PERCHE' ESISTE. Le aree - le "stanze" di Home Assistant - non si creano dal
 * REST: /api/config/area_registry risponde 404, verificato sul campo. Vivono
 * nel registro, e il registro parla solo WebSocket. Senza questo file, "crea
 * la stanza che manca" resta una cosa da fare a mano nell'interfaccia di HA,
 * una per una, e l'automazione si ferma proprio dove servirebbe.
 *
 * PERCHE' SCRITTO A MANO E SENZA LIBRERIE. Un client WebSocket completo non
 * serve: qui si apre, ci si autentica, si mandano due o tre comandi e si
 * chiude. Tirarsi dietro una dipendenza per questo vorrebbe dire legare il
 * plugin alle sue versioni e ai suoi aggiornamenti, su un'installazione
 * WordPress dove nessuno fa composer install.
 *
 * COSA NON FA, ed e' voluto: non resta in ascolto, non gestisce i messaggi
 * spezzati su piu' frame in arrivo, non fa ping/pong. Le risposte del registro
 * sono piccole e arrivano intere. Se un giorno servisse ascoltare gli eventi,
 * questo file non basta e va sostituito, non allargato.
 */

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_HA_WS
{
    /** Quanto si aspetta una risposta prima di rinunciare. */
    const ATTESA = 10;

    /**
     * Apre, autentica, esegue i comandi, chiude.
     *
     * @param array $comandi elenco di array associativi senza "id": lo mette lei.
     * @return array|WP_Error le risposte, nello stesso ordine.
     */
    public static function esegui(array $comandi)
    {
        if (!$comandi) {
            return [];
        }

        $url = trim((string) get_option('vwgk_ha_url', ''));
        $token = (string) get_option('vwgk_ha_long_lived_token', '');
        if ($url === '' || $token === '') {
            return new WP_Error(
                'vwgk_ws_config',
                __('Per creare le stanze in Home Assistant servono il suo indirizzo e un token a lunga durata.', 'virtual-world-gate-key')
            );
        }

        $p = wp_parse_url($url);
        if (!$p || empty($p['host'])) {
            return new WP_Error('vwgk_ws_url', __('Indirizzo di Home Assistant non valido.', 'virtual-world-gate-key'));
        }
        $sicuro = (isset($p['scheme']) && $p['scheme'] === 'https');
        $porta = isset($p['port']) ? (int) $p['port'] : ($sicuro ? 443 : 80);
        $host = $p['host'];
        $percorso = rtrim($p['path'] ?? '', '/') . '/api/websocket';

        $trasporto = $sicuro ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            $trasporto . $host . ':' . $porta,
            $errno,
            $errstr,
            self::ATTESA,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            return new WP_Error('vwgk_ws_connect', sprintf(
                /* translators: %s: messaggio di errore della connessione */
                __('Non riesco ad aprire il canale con Home Assistant: %s', 'virtual-world-gate-key'),
                $errstr ?: (string) $errno
            ));
        }
        stream_set_timeout($sock, self::ATTESA);

        // La stretta di mano. La chiave e' casuale a ogni connessione: e' cosi'
        // che il protocollo distingue una richiesta vera da una risposta
        // riciclata da una cache lungo la strada.
        $chiave = base64_encode(random_bytes(16));
        $richiesta = "GET {$percorso} HTTP/1.1\r\n"
            . "Host: {$host}:{$porta}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$chiave}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $richiesta);

        $intestazioni = '';
        while (($riga = fgets($sock)) !== false) {
            $intestazioni .= $riga;
            if ($riga === "\r\n") {
                break;
            }
        }
        if (strpos($intestazioni, ' 101 ') === false) {
            fclose($sock);
            return new WP_Error('vwgk_ws_handshake', __('Home Assistant non ha aperto il canale.', 'virtual-world-gate-key'));
        }

        // L'autenticazione: HA parla per primo con auth_required.
        $primo = self::leggi($sock);
        if (!is_array($primo) || ($primo['type'] ?? '') !== 'auth_required') {
            fclose($sock);
            return new WP_Error('vwgk_ws_auth', __('Home Assistant non ha chiesto l\'autenticazione come previsto.', 'virtual-world-gate-key'));
        }
        self::scrivi($sock, ['type' => 'auth', 'access_token' => $token]);
        $esito = self::leggi($sock);
        if (!is_array($esito) || ($esito['type'] ?? '') !== 'auth_ok') {
            fclose($sock);
            return new WP_Error('vwgk_ws_auth', __('Token rifiutato da Home Assistant.', 'virtual-world-gate-key'));
        }

        $risposte = [];
        $id = 0;
        foreach ($comandi as $cmd) {
            $id++;
            $cmd['id'] = $id;
            self::scrivi($sock, $cmd);
            // SI SCARTA QUELLO CHE NON E' LA NOSTRA RISPOSTA. HA puo' mandare
            // eventi in mezzo: leggere il primo messaggio che arriva e
            // chiamarlo "risposta" e' il modo classico di prendere un evento
            // per un esito e credere che sia andato tutto bene.
            $mio = null;
            $fine = time() + self::ATTESA;
            while (time() < $fine) {
                $m = self::leggi($sock);
                if ($m === null) {
                    break;
                }
                if (($m['id'] ?? null) === $id) {
                    $mio = $m;
                    break;
                }
            }
            $risposte[] = $mio ?? ['success' => false, 'error' => ['message' => 'nessuna risposta']];
        }

        fclose($sock);
        return $risposte;
    }

    /** Un frame di testo, mascherato: i client DEVONO mascherare, i server no. */
    private static function scrivi($sock, array $dati): void
    {
        $carico = wp_json_encode($dati);
        $len = strlen($carico);
        $testa = chr(0x81);                       // FIN + opcode testo
        if ($len <= 125) {
            $testa .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $testa .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $testa .= chr(0x80 | 127) . pack('J', $len);
        }
        $maschera = random_bytes(4);
        $mascherato = '';
        for ($i = 0; $i < $len; $i++) {
            $mascherato .= $carico[$i] ^ $maschera[$i % 4];
        }
        fwrite($sock, $testa . $maschera . $mascherato);
    }

    /** Legge un frame e lo interpreta come JSON. Null se il canale si chiude. */
    private static function leggi($sock)
    {
        $testa = fread($sock, 2);
        if ($testa === false || strlen($testa) < 2) {
            return null;
        }
        $len = ord($testa[1]) & 0x7F;
        if ($len === 126) {
            $ext = fread($sock, 2);
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($sock, 8);
            $len = unpack('J', $ext)[1];
        }
        $carico = '';
        while (strlen($carico) < $len) {
            $pezzo = fread($sock, $len - strlen($carico));
            if ($pezzo === false || $pezzo === '') {
                break;
            }
            $carico .= $pezzo;
        }
        $d = json_decode($carico, true);
        return is_array($d) ? $d : null;
    }
}
