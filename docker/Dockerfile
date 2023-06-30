FROM php:8.2-cli-alpine3.18 AS runtime-cli

ARG VERSION=latest

# updated sha512sum
RUN set -eux ; \
  apk add --no-cache --virtual .kahu-cli-rundeps \
    coreutils

# install https://github.com/mlocati/docker-php-extension-installer
RUN set -eux ; \
  curl \
    --silent \
    --fail \
    --location \
    --retry 3 \
    --output /usr/local/bin/install-php-extensions \
    --url https://github.com/mlocati/docker-php-extension-installer/releases/download/2.1.35/install-php-extensions \
  ; \
  echo f6ceaad399bad5951668a9d93a6821ddef9fe6b5de6dcf1228f664962b634facebd1c41b9a9bcb7e5cb5f81b3ae66f38291fe92eb5c216ec6b0a54ef363b5d45 /usr/local/bin/install-php-extensions | sha512sum --strict --check ; \
  chmod +x /usr/local/bin/install-php-extensions ; \
  install-php-extensions json pcntl filter openssl

RUN apk del .kahu-cli-rundeps && \
  rm /usr/src/php.tar.xz /usr/src/php.tar.xz.asc

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    rm "$PHP_INI_DIR/php.ini-development" && \
    echo "date.timezone = UTC"                > /usr/local/etc/php/conf.d/timezone.ini && \
    echo "memory_limit = -1"                  > /usr/local/etc/php/conf.d/memory.ini && \
    echo "variables_order = EGPCS"            > /usr/local/etc/php/conf.d/variables_order.ini && \
    echo "zend.assertions = -1"               > /usr/local/etc/php/conf.d/zend.ini && \
    echo "allow_url_fopen = Off"              > /usr/local/etc/php/conf.d/security.ini && \
    echo "allow_url_include = Off"            >> /usr/local/etc/php/conf.d/security.ini

WORKDIR /app

COPY bin/kahu-cli.phar /usr/bin/kahu-cli
CMD ["/usr/bin/kahu-cli"]

LABEL maintainer="Kahu.app <contact@kahu.app>" \
      org.opencontainers.image.authors="contact@kahu.app" \
      org.opencontainers.image.base.name="ghcr.io/kahu-app/cli:${VERSION}" \
      org.opencontainers.image.source="https://github.com/kahu-app/cli" \
      org.opencontainers.image.title="Kahu: Command-line Interface" \
      org.opencontainers.image.description="Monitor software dependencies for malicious code before they reach you" \
      org.opencontainers.image.url="https://github.com/kahu-app/cli" \
      org.opencontainers.image.vendor="Kahu.app" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.licenses="MIT"

FROM scratch AS standalone-cli

ARG VERSION=latest

COPY --from=runtime-cli /usr/bin/kahu-cli /kahu-cli

LABEL maintainer="Kahu.app <contact@kahu.app>" \
      org.opencontainers.image.authors="contact@kahu.app" \
      org.opencontainers.image.base.name="ghcr.io/kahu-app/cli:${VERSION}-standalone" \
      org.opencontainers.image.source="https://github.com/kahu-app/cli" \
      org.opencontainers.image.title="Kahu: Command-line Interface (standalone)" \
      org.opencontainers.image.description="Monitor software dependencies for malicious code before they reach you" \
      org.opencontainers.image.url="https://github.com/kahu-app/cli" \
      org.opencontainers.image.vendor="Kahu.app" \
      org.opencontainers.image.version="${VERSION}-standalone" \
      org.opencontainers.image.licenses="MIT"
