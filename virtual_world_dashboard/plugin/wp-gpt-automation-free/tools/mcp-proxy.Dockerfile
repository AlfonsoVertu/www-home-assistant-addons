# syntax=docker/dockerfile:1.6
FROM python:3.11-slim

WORKDIR /app

# Install system deps for TLS and pip packages
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY tools/mcp_ws_proxy.py ./mcp_ws_proxy.py

RUN pip install --no-cache-dir \
        requests==2.31.0 \
        websockets==12.0

# Environment defaults can be overridden at runtime
ENV WP_GPT_WS_HOST=0.0.0.0 \
    WP_GPT_WS_PORT=8901 \
    WP_GPT_WS_PATH=/mcp

EXPOSE 8901

ENTRYPOINT ["python", "/app/mcp_ws_proxy.py"]
