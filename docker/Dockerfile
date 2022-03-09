FROM php:7.4-cli

ARG BUILD_ID=1
ARG VERSION=7.4

ENV BUILD_ID=$BUILD_ID \
    APP_VERSION=$VERSION \
    PHP_VERSION=7.4 \
    OPENSSL_CONF=/etc/ssl/

LABEL build_id="$BUILD_ID" \
      version="$VERSION" \
      vendor="MIT" \
      maintainer="MIT LICENSE" \
      description="php7 with extensions"

WORKDIR /home

RUN echo "\
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye-updates main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye-backports main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian-security bullseye-security main contrib non-free\n \
    " > /etc/apt/sources.list

RUN apt-get update  \
    && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libevent-dev \
        libxslt1.1 libxslt1-dev \
        libfontconfig1 libfontconfig1-dev \
        openssl libssl-dev \
        libzip-dev \
        procps \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install sockets \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install exif \
    && docker-php-ext-install shmop \
    && docker-php-ext-install sysvmsg \
    && docker-php-ext-install sysvsem \
    && docker-php-ext-install sysvshm \
    && docker-php-ext-install zip \
    && docker-php-ext-install xsl \
    && docker-php-ext-install calendar \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install event \
    && docker-php-ext-enable event \
    && pecl install msgpack \
    && docker-php-ext-enable msgpack \
    && apt-get autoremove -y \
    && apt-get autoclean -y \
    && apt-get clean -y \
    && find /var/cache/apt/archives /var/lib/apt/lists /var/cache \
       -not -name lock \
       -type f \
       -delete \
    && find /var/log -type f | while read f; do echo -n '' > ${f}; done

RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini \
    && echo "cgi.fix_pathinfo=0" > /usr/local/etc/php/conf.d/local.ini

CMD ["php","-a"]