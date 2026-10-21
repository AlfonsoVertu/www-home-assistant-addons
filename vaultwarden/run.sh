#!/bin/sh
# Il runtime ufficiale Debian non include python/jq. Parser del solo oggetto
# piatto definito in config.yaml: niente eval, sed sul JSON o dipendenze aggiunte.
set -eu
export LC_ALL=C

errore() {
    printf '%s\n' "Vaultwarden: /data/options.json non valido: $1" >&2
    exit 1
}

prendi() {
    [ -n "$json" ] || errore 'JSON incompleto.'
    resto=${json#?}
    carattere=${json%"$resto"}
    json=$resto
}

spazi() {
    while [ -n "$json" ]; do
        case "$json" in
            ' '*|"$tab"*|"$cr"*|"$nl"*) prendi ;;
            *) break ;;
        esac
    done
}

attendi() {
    prendi
    [ "$carattere" = "$1" ] || errore 'sintassi JSON inattesa.'
}

esadecimale() {
    codice=0
    for cifra in 1 2 3 4; do
        prendi
        case "$carattere" in
            [0-9]) valore=$carattere ;;
            a|A) valore=10 ;; b|B) valore=11 ;; c|C) valore=12 ;;
            d|D) valore=13 ;; e|E) valore=14 ;; f|F) valore=15 ;;
            *) errore 'escape Unicode non valido.' ;;
        esac
        codice=$((codice * 16 + valore))
    done
}

byte_utf8() {
    ottale=$(printf '%03o' "$1")
    # Il suffisso preserva anche un eventuale newline finale.
    byte=$(printf "\\$ottale"; printf '.')
    stringa=$stringa${byte%.}
}

unicode() {
    esadecimale
    if [ "$codice" -ge 55296 ] && [ "$codice" -le 56319 ]; then
        alto=$codice
        attendi '\'; attendi u; esadecimale
        [ "$codice" -ge 56320 ] && [ "$codice" -le 57343 ] || errore 'coppia Unicode non valida.'
        codice=$((65536 + (alto - 55296) * 1024 + codice - 56320))
    elif [ "$codice" -ge 56320 ] && [ "$codice" -le 57343 ]; then
        errore 'surrogato Unicode isolato.'
    fi
    [ "$codice" -ne 0 ] || errore 'NUL non ammesso nelle variabili di ambiente.'
    if [ "$codice" -lt 128 ]; then
        byte_utf8 "$codice"
    elif [ "$codice" -lt 2048 ]; then
        byte_utf8 "$((192 + codice / 64))"
        byte_utf8 "$((128 + codice % 64))"
    elif [ "$codice" -lt 65536 ]; then
        byte_utf8 "$((224 + codice / 4096))"
        byte_utf8 "$((128 + codice / 64 % 64))"
        byte_utf8 "$((128 + codice % 64))"
    else
        byte_utf8 "$((240 + codice / 262144))"
        byte_utf8 "$((128 + codice / 4096 % 64))"
        byte_utf8 "$((128 + codice / 64 % 64))"
        byte_utf8 "$((128 + codice % 64))"
    fi
}

leggi_stringa() {
    attendi '"'
    stringa=
    while :; do
        prendi
        case "$carattere" in
            '"') break ;;
            '\')
                prendi
                case "$carattere" in
                    '"'|'\'|'/') stringa=$stringa$carattere ;;
                    b) stringa=$stringa$bs ;; f) stringa=$stringa$ff ;;
                    n) stringa=$stringa$nl ;; r) stringa=$stringa$cr ;;
                    t) stringa=$stringa$tab ;;
                    u) unicode ;;
                    *) errore 'escape JSON non valido.' ;;
                esac ;;
            [[:cntrl:]]) errore 'carattere di controllo non escapato.' ;;
            *) stringa=$stringa$carattere ;;
        esac
    done
}

tab=$(printf '\t'); cr=$(printf '\r'); bs=$(printf '\b'); ff=$(printf '\f')
nl='
'
json=
[ -r /data/options.json ] || errore 'file assente o non leggibile.'
while IFS= read -r riga || [ -n "$riga" ]; do
    json=$json$riga$nl
done < /data/options.json

SIGNUPS_ALLOWED=false
DOMAIN=
ADMIN_TOKEN=
viste='|'
spazi; attendi '{'; spazi
if [ "${json#\}}" = "$json" ]; then
    while :; do
        leggi_stringa
        chiave=$stringa
        case "$chiave" in
            signups_allowed|admin_token|domain) ;;
            *) errore 'opzione sconosciuta.' ;;
        esac
        case "$viste" in *"|$chiave|"*) errore 'opzione duplicata.' ;; esac
        viste=$viste$chiave'|'
        spazi; attendi ':'; spazi
        case "$chiave" in
            signups_allowed)
                case "$json" in
                    true*) SIGNUPS_ALLOWED=true; json=${json#true} ;;
                    false*) SIGNUPS_ALLOWED=false; json=${json#false} ;;
                    *) errore 'signups_allowed deve essere un booleano.' ;;
                esac ;;
            domain) leggi_stringa; DOMAIN=$stringa ;;
            admin_token) leggi_stringa; ADMIN_TOKEN=$stringa ;;
        esac
        spazi; prendi
        case "$carattere" in
            '}') break ;;
            ',') spazi ;;
            *) errore 'separatore JSON non valido.' ;;
        esac
    done
else
    attendi '}'
fi
spazi
[ -z "$json" ] || errore 'contenuto dopo la fine del JSON.'

case "$DOMAIN" in
    '') unset DOMAIN ;;
    https://?*) export DOMAIN ;;
    *) errore 'domain deve essere vuoto oppure un indirizzo https pubblico.' ;;
esac
if [ -n "$ADMIN_TOKEN" ]; then export ADMIN_TOKEN; else unset ADMIN_TOKEN; fi
export SIGNUPS_ALLOWED
export DATA_FOLDER=/data
export ROCKET_PORT=80
# Il file salvato da /admin ha precedenza sulle variabili (vedi DOCS.md).
if [ -f /data/config.json ]; then
    printf '%s\n' 'Vaultwarden: /data/config.json può prevalere sulle opzioni dell’add-on; verificare /admin.' >&2
fi
unset json riga stringa chiave byte 2>/dev/null || :
exec /start.sh
