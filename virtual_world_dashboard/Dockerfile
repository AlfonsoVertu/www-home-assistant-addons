FROM wordpress:6.6.2-php8.2-apache

# Install dependencies for HA environment and WP-CLI
RUN apt-get update && apt-get install -y \
    jq \
    curl \
    mariadb-client \
    unzip \
    dos2unix \
    && rm -rf /var/lib/apt/lists/*

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# PLUGIN E TEMA STANNO FUORI DA /var/www/html, e li' li rimette run.sh a ogni avvio.
#
# /var/www/html e' un volume: e' del contenitore, e l'add-on lo ricrea ogni volta
# che il contenitore rinasce. Quello che ci si copia qui dentro dipende da come
# viene riempito quel volume, e quello che ci si mette a mano dopo sparisce al
# primo riavvio. Il 2 ottobre 2026 e' successo cosi': tema sparito, plugin
# tornato a una copia di mesi prima, e l'app senza piu' la rotta della casa.
# Tenendoli qui, l'immagine e' l'unica verita' e l'avvio la applica sempre.
COPY plugin/virtual-world-gate-key /usr/src/vwg/plugins/virtual-world-gate-key/
COPY plugin/vwgk-security /usr/src/vwg/plugins/vwgk-security/
# WP GPT Automation Free (quello per la casa: rotta di Home Assistant e manifesto).
# Prima non era nell'immagine: arrivava da uno zip in /share, se c'era.
COPY plugin/wp-gpt-automation-free /usr/src/vwg/plugins/wp-gpt-automation-free/
COPY theme/vwg-dashboard /usr/src/vwg/themes/vwg-dashboard/

# Copy entrypoint script
COPY run.sh /run.sh
RUN dos2unix /run.sh && chmod +x /run.sh

ENTRYPOINT ["/run.sh"]
