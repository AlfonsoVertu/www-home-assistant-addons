# Open WebUI (https://github.com/open-webui/open-webui): l'immagine ufficiale, coi dati in /data (persistenti e nel backup di
# Home Assistant) e le opzioni dell'add-on passate come variabili d'ambiente (run.sh)
ARG BUILD_FROM
FROM $BUILD_FROM
LABEL io.hass.name="Open WebUI" io.hass.type="addon" io.hass.version="0.12.3"
COPY run.sh /run-addon.sh
RUN chmod +x /run-addon.sh && sed -i 's/\r$//' /run-addon.sh
ENTRYPOINT []
CMD ["/run-addon.sh"]
