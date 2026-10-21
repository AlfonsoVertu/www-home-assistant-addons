# Changelog

## 1.0.4
- Il token di Home Assistant messo dalle impostazioni del sito non viene piu'
  svuotato a ogni avvio: le opzioni dell'add-on comandano solo se il token li' c'e'.

## 1.0.3
- Plugin e tema stanno nell'immagine fuori da `/var/www/html` e vengono rimessi a
  ogni avvio: un contenitore ricreato non perde piu' il tema ne' la rotta della casa.
- WP GPT Automation Free (1.3.2) viaggia con l'immagine; non si installa piu' da uno
  zip in `/share`.
- I caricamenti del sito stanno in `/data/uploads` e restano fra un avvio e l'altro.
- Codice utente e token si scrivono solo se nelle opzioni dell'add-on ci sono.
- L'immagine la costruisce il flusso di GitHub del repository da cui Home Assistant
  prende l'add-on (WorkingWithEuroOffice), a ogni push.
