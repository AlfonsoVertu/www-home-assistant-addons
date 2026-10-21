ARG BUILD_FROM
FROM $BUILD_FROM

LABEL \
    io.hass.name="EuroOffice Document Server" \
    io.hass.description="Collaborative document editing server for Home Assistant" \
    io.hass.arch="${BUILD_ARCH}" \
    io.hass.type="addon" \
    io.hass.version="1.0.4"

# Install jq for options.json parsing, and dos2unix to fix Windows line endings
RUN apt-get update && apt-get install -y jq python3 dos2unix && rm -rf /var/lib/apt/lists/*

COPY run.sh /
RUN dos2unix /run.sh && chmod a+x /run.sh

ENTRYPOINT ["/run.sh"]
