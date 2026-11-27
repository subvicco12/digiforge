FROM composer:2.8 AS composer
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client python3 git unzip subversion curl libonig-dev libxml2-dev libpng-dev libzip-dev && docker-php-ext-install mysqli mbstring dom xml xmlwriter gd zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /audit
COPY composer.json /audit/composer.json
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
COPY bin/install-wp-tests.sh /audit/install-wp-tests.sh
ENV WP_TESTS_DIR=/fixture/tests WP_CORE_DIR=/fixture/wordpress
RUN bash /audit/install-wp-tests.sh digiforge_tests root '' 'localhost:/run/mysqld/mysqld.sock' latest
COPY tests/artifact-audit /audit/tests/artifact-audit
COPY bin/artifact-audit/run.sh /audit/run.sh
ENTRYPOINT ["bash", "/audit/run.sh"]
