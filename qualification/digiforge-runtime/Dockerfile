FROM mariadb@sha256:6e562000e74ce36a9c7cc1302e462c271e3e3a5e0e7ae0ec2ab5f71f0f586f02 AS php-build
RUN apt-get update && apt-get install -y --no-install-recommends autoconf bison re2c make gcc libc6-dev pkg-config libxml2-dev libssl-dev libcurl4-openssl-dev libonig-dev libsodium-dev libzip-dev zlib1g-dev
COPY php-src.tar.gz /tmp/php-src.tar.gz
RUN echo '2d08c1e67defb2f5f2db6c9c2c07642002dbdb6c3b0e24cf0552940f32cb3cf7  /tmp/php-src.tar.gz' | sha256sum -c - \
 && mkdir /tmp/php-src && tar -xzf /tmp/php-src.tar.gz --strip-components=1 -C /tmp/php-src
WORKDIR /tmp/php-src
RUN ./buildconf --force && ./configure --prefix=/opt/php --disable-cgi --disable-phpdbg \
  --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-curl --with-openssl --with-zlib --with-sodium --with-zip --enable-mbstring \
  && make -j8 && make install
FROM mariadb@sha256:6e562000e74ce36a9c7cc1302e462c271e3e3a5e0e7ae0ec2ab5f71f0f586f02
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl iproute2 jq unzip python3 nodejs npm libxml2 libonig5 libzip4t64 libsodium23 \
  && mkdir /opt/browser && cd /opt/browser && npm install --ignore-scripts --save-exact playwright@1.52.0 \
  && PLAYWRIGHT_BROWSERS_PATH=/opt/browsers /opt/browser/node_modules/.bin/playwright install --with-deps chromium \
  && browser=$(find /opt/browsers -path '*/chrome-linux/chrome' -print -quit) && test -x "$browser" && ln -s "$browser" /usr/bin/chromium \
  && dpkg-query -W > /opt/runtime-packages.tsv && rm -rf /var/lib/apt/lists/*
COPY --from=php-build /opt/php/bin/php /usr/local/bin/php
ENV NODE_PATH=/opt/browser/node_modules HOME=/tmp
RUN php -r 'exit(PHP_VERSION==="8.3.37" && extension_loaded("pdo_mysql") && extension_loaded("mysqli") && extension_loaded("sodium") ? 0 : 1);' \
 && mariadbd --version | grep -F 11.8.9 && chromium --version
