FROM php:{%version%}-cli-stretch

ENV PHP_MEMORY_LIMIT 2G
ENV MAGENTO_ROOT /app
ENV DEBUG false
ENV MAGENTO_RUN_MODE production
ENV COMPOSER_ALLOW_SUPERUSER 1
{%env_php_extensions%}

# Install dependencies
RUN apt-get update \
  && apt-get upgrade -y \
  && apt-get install -y --no-install-recommends \
  {%packages%} \
  && rm -rf /var/lib/apt/lists/*

# Install PyYAML
RUN pip3 install --upgrade setuptools \
    && pip3 install pyyaml

# Configure the gd library
{%docker-php-ext-configure%}

# Install required PHP extensions
{%docker-php-ext-install%}

{%php-pecl-extensions%}

{%installation_scripts%}

{%docker-php-ext-enable%}

ADD etc/php-cli.ini /usr/local/etc/php/conf.d/zz-magento.ini
ADD etc/php-xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug-settings.ini
ADD etc/mail.ini /usr/local/etc/php/conf.d/zz-mail.ini

RUN groupadd -g 1000 www && useradd -g 1000 -u 1000 -d ${MAGENTO_ROOT} -s /bin/bash www

RUN chown -R www:www /etc /usr/local/etc /var

ADD docker-entrypoint.sh /docker-entrypoint.sh
RUN ["chmod", "+x", "/docker-entrypoint.sh"]
ENTRYPOINT ["/docker-entrypoint.sh"]

ENV COMPOSER_ALLOW_SUPERUSER 1

VOLUME /root/.composer/cache

ADD etc/php-cli.ini /usr/local/etc/php/conf.d/zz-magento.ini

# Get composer installed to /usr/local/bin/composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ADD bin/* /usr/local/bin/

RUN ["chmod", "+x", "/usr/local/bin/magento-installer"]
RUN ["chmod", "+x", "/usr/local/bin/magento-command"]
RUN ["chmod", "+x", "/usr/local/bin/ece-command"]
RUN ["chmod", "+x", "/usr/local/bin/cloud-build"]
RUN ["chmod", "+x", "/usr/local/bin/cloud-deploy"]
RUN ["chmod", "+x", "/usr/local/bin/run-cron"]
RUN ["chmod", "+x", "/usr/local/bin/run-hooks"]

RUN mkdir ${MAGENTO_ROOT} && chown www:www ${MAGENTO_ROOT}
RUN mkdir ${MAGENTO_ROOT}/vendor && chown www:www ${MAGENTO_ROOT}/vendor
RUN mkdir ${MAGENTO_ROOT}/generated && chown www:www ${MAGENTO_ROOT}/generated
RUN mkdir ${MAGENTO_ROOT}/var && chown www:www ${MAGENTO_ROOT}/var
RUN mkdir -p ${MAGENTO_ROOT}/app/etc && chown www:www ${MAGENTO_ROOT}/app/etc
RUN mkdir -p ${MAGENTO_ROOT}/pub/static && chown www:www ${MAGENTO_ROOT}/pub/static
RUN mkdir -p ${MAGENTO_ROOT}/pub/media && chown www:www ${MAGENTO_ROOT}/pub/media

VOLUME ${MAGENTO_ROOT}
VOLUME ${MAGENTO_ROOT}/vendor
VOLUME ${MAGENTO_ROOT}/generated
VOLUME ${MAGENTO_ROOT}/var
VOLUME ${MAGENTO_ROOT}/app/etc
VOLUME ${MAGENTO_ROOT}/pub/static
VOLUME ${MAGENTO_ROOT}/pub/media

USER www:www

WORKDIR ${MAGENTO_ROOT}

CMD ["bash"]
