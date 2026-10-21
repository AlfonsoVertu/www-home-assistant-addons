# Vaultwarden (Bitwarden)

La cassaforte delle password sul tuo Home Assistant. [Vaultwarden](https://github.com/dani-garcia/vaultwarden) è un server indipendente compatibile con le app e l'estensione Bitwarden. **Le password del browser le gestisce Bitwarden**: l'add-on ospita il server, non importa automaticamente le password del browser.

## Primo utilizzo e HTTPS

1. Installa l'add-on e avvialo. La porta HTTP locale è `8097`, modificabile nella scheda Rete. Il pulsante «Apri interfaccia web» porta a questo indirizzo locale, ma per usare la cassaforte configura prima HTTPS.
2. Installa [Nginx Proxy Manager dello stesso repository](../nginx_proxy_manager/DOCS.md). Crea un **Proxy Host** per un tuo nome, per esempio `vault.example.com`: schema di inoltro `http`, IP LAN di Home Assistant e porta `8097`. Non usare `localhost`: nel proxy indicherebbe il container del proxy stesso.
3. Abilita **Websockets Support**. Nella scheda SSL richiedi/assegna un certificato valido per quel nome e abilita **Force SSL**. Le notifiche WebSocket passano sulla stessa porta HTTP; non occorre una porta 3012 separata. Configura DNS e accesso alla porta HTTPS del proxy; per Let's Encrypt scegli la verifica HTTP o DNS adatta alla tua rete. Non esporre direttamente `8097` su Internet.
4. Nelle opzioni di Vaultwarden imposta `domain: "https://vault.example.com"` e riavvia. Usa un dominio dedicato, senza `/admin` o `/api`. Il nome deve risolversi ed essere raggiungibile anche dai dispositivi in LAN (DNS locale o NAT loopback, se necessario).
5. Per creare il primo account imposta **temporaneamente** `signups_allowed: true`, salva e riavvia. Apri l'indirizzo HTTPS, registra l'account, poi rimetti **`false`**, salva e riavvia. La chiusura delle registrazioni **non è automatica** dopo il primo account: il valore iniziale è già `false` e il primo utente non diventa amministratore del server.

HTTPS con certificato attendibile serve per la crittografia della cassaforte web e per WebAuthn. Il dominio stabile deve coincidere con quello usato nell'estensione; cambiarlo può richiedere di registrare nuovamente le chiavi WebAuthn. L'eccezione dei browser per `http://localhost` è utile solo in prove locali, non sostituisce HTTPS per i dispositivi di casa. Non usare l'ingress di Home Assistant come indirizzo server dell'estensione.

## Opzioni

| Opzione | A cosa serve |
|---|---|
| `signups_allowed` | Booleano, inizialmente `false`. Abilita le registrazioni pubbliche, anche del primo utente. Spegnilo dopo la registrazione; gli account esistenti continuano a funzionare. Gli inviti amministrativi possono consentire nuove iscrizioni anche con questa opzione disattivata. |
| `admin_token` | Password opzionale, inizialmente vuota: senza token il pannello `/admin` è disabilitato su una nuova installazione. Usa un segreto casuale lungo, distinto dalla password principale dell'utente; è accettato anche un hash Argon2 PHC generato come descritto nella [guida ufficiale](https://github.com/dani-garcia/vaultwarden/wiki/Enabling-admin-page). Incolla i `$` letterali nelle opzioni HA. Con l'hash, accedi a `/admin` usando il segreto originale. |
| `domain` | Stringa opzionale, inizialmente vuota. Indirizzo HTTPS pubblico completo della cassaforte, es. `https://vault.example.com`. Impostalo prima di usare WebAuthn e i client Bitwarden. Vuoto non configura né certificati né proxy e lascia il valore predefinito di Vaultwarden. |

Dopo ogni modifica salva e riavvia. Le impostazioni salvate dal pannello `/admin` in **`/data/config.json` prevalgono sulle variabili d'ambiente**, compresi registrazioni, dominio e token. Se hai usato quel pannello, correggi/rimuovi lì le corrispondenti personalizzazioni; non aspettarti che un token vuoto nell'add-on disabiliti un token già salvato in `config.json`. Lo script avvisa della presenza del file senza stamparne i contenuti.

## Collegare l'estensione Bitwarden

1. Installa l'estensione ufficiale **Bitwarden** nel browser.
2. Nella schermata di accesso apri **Impostazioni › self-hosted › indirizzo** (a seconda della versione: selettore «Accesso a» / «Self-hosted»).
3. Inserisci **`https://vault.example.com`**, lo stesso valore di `domain`, e salva. Non aggiungere `/api` o `/admin`.
4. Accedi con l'account creato su questo Vaultwarden. Un account su `bitwarden.com` è distinto: non viene trasferito automaticamente.

La compilazione automatica e il salvataggio delle password sono funzioni dell'estensione. La pagina `aww://bitwarden` aiuta a trovare il server e ad aprire la cassaforte, ma non legge password né configura automaticamente l'estensione. Vedi anche le [istruzioni Bitwarden per un server self-hosted](https://bitwarden.com/help/change-client-environment/).

## Dati e backup

`DATA_FOLDER=/data`: database SQLite, allegati, chiavi e configurazione restano nella cartella persistente privata dell'add-on, montata automaticamente dal Supervisor e inclusa nei backup che selezionano questo add-on. Non serve aggiungere una voce `map` per `/data`. Conserva backup protetti e verifica il ripristino; per una copia manuale coerente dei file SQLite arresta prima l'add-on. Non eliminare l'add-on o i suoi dati per aggiornarlo.

## Immagine e avvio

L'add-on usa **`vaultwarden/server:1.37.4`**, tag verificato il 22/10/2026 tramite i [metadati Docker Hub](https://hub.docker.com/v2/repositories/vaultwarden/server/tags/1.37.4), con piattaforme `linux/amd64` e `linux/arm64` (Home Assistant: `aarch64`). Digest dell'indice osservato: `sha256:efb3cde962015fcc036b2ea625242248611943b27212ebf4392841fb30fad055`. Il riferimento di build è fissato al tag, non al digest.

Nel [Dockerfile upstream della stessa versione](https://github.com/dani-garcia/vaultwarden/blob/1.37.4/docker/Dockerfile.debian) il comando è `CMD ["/start.sh"]`; [lo script](https://github.com/dani-garcia/vaultwarden/blob/1.37.4/docker/start.sh) esegue `/vaultwarden`. Il wrapper dell'add-on legge `/data/options.json` in shell POSIX (il runtime non installa Python o jq), preserva gli escape JSON e non esegue il contenuto delle opzioni. Le opzioni devono rispettare lo schema; JSON malformato interrompe l'avvio.

## Licenza

Vaultwarden è distribuito con licenza AGPL-3.0. Questo add-on aggiunge il collegamento alle opzioni di Home Assistant all'immagine ufficiale del progetto Vaultwarden; non è un prodotto ufficiale Bitwarden.
