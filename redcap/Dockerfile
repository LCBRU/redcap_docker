FROM php:7.4-apache

LABEL maintainer="rabramley@gmail.com"

ARG smtp_server
ARG redcap_version
ARG redcap_directory
ARG secure_cookies

RUN apt-get update -qq && \
    apt-get -yq --no-install-recommends install \
    msmtp \
    supervisor \
    cron \
    libpng-dev \
    libzip-dev \
    libldap2-dev \
    curl \
    vim \
    default-mysql-client \
    && docker-php-ext-install gd zip mysqli \
    && rm -r /var/lib/apt/lists/*

RUN docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu
RUN docker-php-ext-install ldap

# Copy in REDCap files
ADD redcap /var/www/html/$redcap_directory/
ADD database.php /var/www/html/$redcap_directory/
ADD html /html/
RUN mv /html/* /var/www/html/$redcap_directory/
RUN ln -s /var/www/html/$redcap_directory/hook_functions.php /var/www/html/

# Upload folder
RUN mkdir /edocs/

# Configure temp directory
RUN chmod 777 -R /var/www/html/$redcap_directory/temp
                                                                                                                                  
# Suggested PHP config changes
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN sed -i.bak 's/upload_max_filesize = 2M/upload_max_filesize = 32M/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/post_max_size = 8M/post_max_size = 32M/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/;max_input_vars = 1000/max_input_vars = 100000/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/;error_log = php_errors.log/error_log = php_errors.log/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/;session.cookie_secure =/session.cookie_secure = On/g' $PHP_INI_DIR/php.ini

# Mail Configuration
RUN echo 'sendmail_path = "/usr/bin/msmtp -t -i"' > /usr/local/etc/php/conf.d/mail.ini

RUN echo "defaults" > /etc/msmtprc && \
  echo "account default" >> /etc/msmtprc && \
  echo "host $smtp_server" >> /etc/msmtprc && \
  echo "port 25" >> /etc/msmtprc && \
  echo "logfile /var/log/msmtp.log" >> /etc/msmtprc && \
  echo "auto_from on" >> /etc/msmtprc && \
  echo "maildomain uhl-tr.nhs.uk" >> /etc/msmtprc

RUN chown -fR www-data:www-data /var/www/html/$redcap_directory/modules/ || exit 0
RUN chown -R www-data:www-data /edocs/

# Run Redcap cronjob
RUN echo "*/15 8-18 * * * curl http://localhost/$redcap_directory/cron.php >> /cron.log 2>&1" | crontab - 

RUN echo "2020-08-21 A"

COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf

ADD redcap-logo-large.png /var/www/html/$redcap_directory/redcap_v$redcap_version/Resources/images/redcap-logo-large.png
ADD redcap-logo-medium.png /var/www/html/$redcap_directory/redcap_v$redcap_version/Resources/images/redcap-logo-medium.png
ADD ldap_config.php /var/www/html/$redcap_directory/webtools2/ldap/ldap_config.php
RUN chown www-data /var/www/html/$redcap_directory/webtools2/ldap/ldap_config.php

ADD fixes/redcap_v10/DataEntry/index.php /var/www/html/$redcap_directory/redcap_v$redcap_version/DataEntry/index.php
ADD fixes/redcap_v10/Surveys/index.php /var/www/html/$redcap_directory/redcap_v$redcap_version/Surveys/index.php

CMD /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
