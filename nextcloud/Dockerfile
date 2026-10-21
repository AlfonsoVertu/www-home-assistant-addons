ARG BUILD_FROM
FROM $BUILD_FROM

LABEL io.hass.name="Nextcloud + ONLYOFFICE" \
      io.hass.description="Nextcloud with ONLYOFFICE integration pre-installed" \
      io.hass.type="addon"

# Install jq for options parsing in run script
RUN apk add --no-cache jq || (apt-get update && apt-get install -y jq && rm -rf /var/lib/apt/lists/*)

# Copy our custom ONLYOFFICE setup script
COPY rootfs /
# (21/10) scripts saved on Windows: no BOM and no CRLF, or bash does not run them
RUN sed -i '1s/^ï»¿//; s/$//' /etc/cont-init.d/99-onlyoffice-setup.sh && chmod a+x /etc/cont-init.d/99-onlyoffice-setup.sh
