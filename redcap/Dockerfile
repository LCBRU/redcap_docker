FROM php:7.2-apache

LABEL maintainer="rabramley@gmail.com"

ARG smtp_server
ARG redcap_version

RUN apt-get update -qq && \
    apt-get -yq --no-install-recommends install \
    msmtp \
    supervisor \
    cron \
    libpng-dev \
    libzip-dev \
    curl \
    && docker-php-ext-install gd zip mysqli \
    && rm -r /var/lib/apt/lists/*


# Copy in REDCap files
ADD redcap /var/www/html/
ADD database.php /var/www/html/
ADD html /html/
RUN mv /html/* /var/www/html/

# Upload folder
RUN mkdir /edocs/

# Configure temp directory
RUN chmod 777 -R /var/www/html/temp

# Suggested PHP config changes
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN sed -i.bak 's/upload_max_filesize = 2M/upload_max_filesize = 32M/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/post_max_size = 8M/post_max_size = 32M/g' $PHP_INI_DIR/php.ini
RUN sed -i.bak 's/; max_input_vars = 1000/max_input_vars = 10000/g' $PHP_INI_DIR/php.ini

# Mail Configuration
RUN echo 'sendmail_path = "/usr/bin/msmtp -t -i"' > /usr/local/etc/php/conf.d/mail.ini

RUN echo "defaults" > /etc/msmtprc && \
  echo "account default" >> /etc/msmtprc && \
  echo "host $smtp_server" >> /etc/msmtprc && \
  echo "port 25" >> /etc/msmtprc && \
  echo "logfile /var/log/msmtp.log" >> /etc/msmtprc && \
  echo "auto_from on" >> /etc/msmtprc && \
  echo "maildomain uhl-tr.nhs.uk" >> /etc/msmtprc

RUN chown -R www-data:www-data /var/www/html/modules/
RUN chown -R www-data:www-data /edocs/

# Run Redcap cronjob
RUN echo "* * * * * curl http://localhost/cron.php >> /cron.log 2>&1" | crontab - 

COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf

ADD redcap-logo-large.png /var/www/html/redcap_v$redcap_version/Resources/images/redcap-logo-large.png
ADD redcap-logo-medium.png /var/www/html/redcap_v$redcap_version/Resources/images/redcap-logo-medium.png

CMD /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
