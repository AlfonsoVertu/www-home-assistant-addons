# Immagine ufficiale: /start.sh avvia /vaultwarden. Le opzioni HA sono lette in sh puro.
ARG BUILD_FROM
FROM $BUILD_FROM
LABEL io.hass.name="Vaultwarden (Bitwarden)" io.hass.type="addon" io.hass.version="1.37.4"
COPY run.sh /run-addon.sh
RUN chmod +x /run-addon.sh && sed -i 's/\r$//' /run-addon.sh
ENTRYPOINT []
CMD ["/run-addon.sh"]
