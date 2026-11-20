FROM debian:trixie-slim AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates php8.4-cli php8.4-cgi php8.4-mbstring php8.4-sqlite3 php8.4-mysql libnode-dev \
    && rm -rf /var/lib/apt/lists/*

FROM base AS extension
ARG V8JS_COMMIT=8a39efa3cf3b275e402ddf3c4f6b611a5f69a499
RUN apt-get update && apt-get install -y --no-install-recommends git php8.4-dev build-essential \
    && git init /tmp/v8js && cd /tmp/v8js \
    && git remote add origin https://github.com/phpv8/v8js.git \
    && git fetch --depth 1 origin "$V8JS_COMMIT" && git checkout FETCH_HEAD \
    && phpize && ./configure --with-v8js=/usr LDFLAGS="-lstdc++" \
    && make -j2 && cp modules/v8js.so /tmp/v8js.so

FROM debian:trixie-slim AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates python3 php8.4-cli php8.4-cgi php8.4-mbstring php8.4-sqlite3 php8.4-mysql php8.4-gd libbrotli1 libcares2 libicu76 libnghttp2-14 libssl3t64 libuv1t64 libstdc++6 libzstd1 zlib1g \
    && rm -rf /var/lib/apt/lists/*
COPY --from=base /usr/lib/*-linux-gnu/libnode.so.115 /usr/local/lib/
RUN ldconfig
COPY --from=extension /tmp/v8js.so /usr/lib/php/20240924/v8js.so
RUN echo 'extension=v8js.so' > /etc/php/8.4/mods-available/v8js.ini \
    && phpenmod v8js && php --ri v8js && ! command -v node
WORKDIR /app

FROM node:24-bookworm-slim AS node
FROM runtime AS test
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && apt-get update && apt-get install -y --no-install-recommends composer unzip python3-venv \
    && rm -rf /var/lib/apt/lists/*
COPY requirements-dev.txt /tmp/requirements-dev.txt
RUN python3 -m venv /opt/venv && /opt/venv/bin/pip install -r /tmp/requirements-dev.txt \
    && /opt/venv/bin/python -m playwright install --with-deps chromium
ENV PATH="/opt/venv/bin:${PATH}"
