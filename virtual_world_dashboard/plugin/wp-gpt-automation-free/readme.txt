=== WP GPT Automation Pro ===
Contributors: alfonsovertucci
Tags: gpt, ai, automation, seo, rankmath, multisite, api
Requires at least: 5.5
Tested up to: 6.3
Stable tag: 1.3.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin per gestire WordPress tramite GPT con API sicure. Compatibile con multisite e localizzato.

== Description ==

WP GPT Automation Pro è un plugin avanzato che integra un modello GPT tramite OpenAI per automatizzare diverse attività, come la gestione dei post, l'ottimizzazione SEO con RankMath, l'invio di email e la gestione delle immagini. Tutto è gestito tramite API REST sicure protette da token Bearer e nonce.
Il plugin consente inoltre di aggiungere paragrafi e incorporare video in punti specifici degli articoli tramite parametri dedicati.

== Gestione Contact Form 7 via API ==

Il plugin espone la rotta `POST /wp-json/wp-gpt/v1/contact-form7`, protetta da token Bearer e `user_code`, per creare e modificare i moduli del plugin Contact Form 7 senza accedere alla bacheca. Le azioni disponibili (`create_form`, `update_form`, `get_form`, `list_forms`) permettono di:

* Generare un modulo partendo da una descrizione ad alto livello dei campi (testo, email, select, checkbox, radio, acceptance, quiz, file, hidden, submit, recaptcha) oppure fornendo direttamente il template completo.
* Configurare mail principale e secondaria, messaggi di risposta personalizzati, impostazioni aggiuntive e lingua del form.
* Recuperare in ogni risposta lo shortcode reale (`[contact-form-7 id="…" title="…"]`) insieme a un invito esplicito a incollarlo in una pagina o articolo.
* Elencare tutti i moduli già esistenti, con ID, titolo, shortcode e link rapido alla schermata di modifica di Contact Form 7.

Esempio JSON minimo per creare un form:

```
{
  "user_code": "123456",
  "action": "create_form",
  "title": "Richiedi informazioni",
  "form_fields": [
    {"type": "text", "name": "your-name", "label": "Il tuo nome", "required": true},
    {"type": "email", "name": "your-email", "label": "La tua email", "required": true},
    {"type": "textarea", "name": "your-message", "label": "Messaggio"},
    {"type": "submit", "label": "Invia"}
  ]
}
```

== Installation ==

1. Scarica il plugin e caricalo nella directory `/wp-content/plugins/`.
2. Attiva il plugin tramite la schermata "Plugin" in WordPress.
3. Configura le impostazioni nella pagina "GPT Automation Pro" sotto il menu "Impostazioni".

== Frequently Asked Questions ==

= Come posso generare un nuovo token API? =

Puoi generare un nuovo token nella pagina delle impostazioni del plugin sotto il menu "GPT Automation Pro".

== Screenshots ==

1. Impostazioni del plugin
2. Gestione della coda

== Changelog ==

= 2.2.4 =
* Incremento di versione
* Documentata la disponibilità dei parametri opzionali `private_key` e `private_key_passphrase` per le rotte SSH.

= 1.0.0 =
* Prima versione

== Upgrade Notice ==

= 2.2.4 =
Incremento di versione.

= 1.0.0 =
Prima versione del plugin.



== REQUSITI INIZIALI ==

- Creare e modificare i post.
- Inviare email.
- Utilizzare RankMath SEO per l'ottimizzazione SEO.
- Impostare lo slug.
- Impostare il titolo SEO.
- Impostare la meta description.
- Impostare la categoria dell'articolo.
- Impostare la focus keyword.
- Impostare i tag dell'articolo.
- Inserire il title in tutti i link presenti nei post.
- Aggiungere l'immagine di copertina ed impostarle l'alt text.
- Fare ricerche sugli articoli già presenti nel sito.
- iL PLUGIN OFFRE LA POSSIBILITà DI CONTATTARE TRAMITE API IL SERVIZIO https://bypass.hix.ai/humanize-ai per umanizzare il test.
- L'umanizzazione tramite il servizio hix è utilizzabile anche tramite api,
- Nella pagina di amminsitrazione del plugin sono presenti le stesse informazioni presenti nel widget presetni nella pagina di creazione degli articoli.
- I parametri del servizio hix sono: Chiave API hix, Endpoint, Modalità (veloce, quilibrato o aggressivo ) e Limite Parole per Richiesta,
- Cercare all'interno dei titoli di un determinato articolo.
- Cercare all'interno dei paragrafi di un determinato capitolo.
- Inserire le immagini in un determinato articolo.
- Deve essere presente una pagina di amminsitrazione che i servirà da guida per configurare il nuovo gpts con lo schema corretto se non sai cos'è la schema del gpts cerca online cos'è un gpts e come si usano gli schemi.

Queste operazioni dovranno avere delle API su WordPress protette da un token Bearer e da un nonce in modo che attraverso lo schema di un GPT sia possibile utilizzarle.

Il plugin deve essere compatibile con Multisite ed essere ottimizzato per lo store di WordPress.

