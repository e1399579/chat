FROM php:8.3.4-cli-bullseye

ARG BUILD_ID=1
ARG VERSION=8.3

ENV BUILD_ID=$BUILD_ID \
    APP_VERSION=$VERSION \
    PHP_VERSION=8.3 \
    OPENSSL_CONF=/etc/ssl/

LABEL build_id="$BUILD_ID" \
      version="$VERSION" \
      vendor="MIT" \
      maintainer="MIT LICENSE" \
      description="php8 with extensions"

WORKDIR /home

RUN echo "\
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye-updates main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian/ bullseye-backports main contrib non-free\n \
    deb https://mirrors.tuna.tsinghua.edu.cn/debian-security bullseye-security main contrib non-free\n \
    " > /etc/apt/sources.list

# install extensions
RUN apt-get update  \
    && apt-get install -y \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libevent-dev libxslt1-dev libfontconfig1-dev openssl libssl-dev \
        libzip-dev procps \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd sockets pcntl bcmath exif opcache\
    && docker-php-ext-install shmop sysvsem sysvmsg sysvshm zip xsl calendar 

# curl 'https://github.com/phpredis/phpredis/archive/refs/tags/6.0.2.tar.gz' -o phpredis-6.0.2.tar.gz
# curl 'https://bitbucket.org/osmanov/pecl-event/get/9099a9e890e8752b715c7d9305ef095869fabc4b.tar.gz' -o osmanov-pecl-event-9099a9e890e8.tar.gz
# curl 'https://github.com/msgpack/msgpack-php/archive/refs/tags/msgpack-2.2.0.tar.gz' -o msgpack-php-msgpack-2.2.0.tar.gz
COPY ./source/* /home/

# compile and install extra extensions
RUN cd /home \
    && tar -zxv -f phpredis-6.0.2.tar.gz \
    && cd phpredis-6.0.2 \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable redis \
    && cd /home \
    && tar -zxv -f osmanov-pecl-event-9099a9e890e8.tar.gz \
    && cd osmanov-pecl-event-9099a9e890e8 \
    && phpize && ./configure && make && make install \
    && echo "extension=sockets.so\nextension=event" > \
        /usr/local/etc/php/conf.d/docker-php-ext-event.ini \
    && rm -rf /usr/local/etc/php/conf.d/docker-php-ext-sockets.ini \
    && cd /home \
    && tar -zxv -f msgpack-php-msgpack-2.2.0.tar.gz \
    && cd msgpack-php-msgpack-2.2.0 \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable msgpack \
    && cd /home \
    && rm -rf phpredis* osmanov* msgpack* /tmp/pear \
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