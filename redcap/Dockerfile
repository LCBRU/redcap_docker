############################################################
# Dockerfile to build Redcap
# Based on Ubuntu
############################################################

# Set the base image to Ubuntu
FROM ubuntu:16.04

ARG redcap_version
ARG smtp_server
ENV redcap_version $redcap_version

RUN echo $redcap_version

MAINTAINER Richard Bramley <rabramley@gmail.com>

################## BEGIN INSTALLATION ######################


RUN apt-get update && \
    apt-get -y upgrade && \
    DEBIAN_FRONTEND=noninteractive apt-get -y install \
        apache2 \
        php7.0  \
        php7.0-mysql  \
        php7.0-curl  \
        php7.0-mcrypt  \
        php7.0-json \
        php7.0-mbstring \
        libapache2-mod-php7.0  \
        php7.0-zip \
        php7.0-gd \
        php-pear \
        php-auth \
        libpcre3-dev \
        unzip \
        cron \
        supervisor && \
    apt-get clean && \
    update-rc.d apache2 defaults 

# Install Postfix.
RUN echo "postfix postfix/main_mailer_type string Internet site" > preseed.txt
RUN echo "postfix postfix/mailname string smtp.xuhl-tr.nhs.uk" >> preseed.txt
# Use Mailbox format.
RUN debconf-set-selections preseed.txt
RUN DEBIAN_FRONTEND=noninteractive apt-get install -q -y postfix

RUN postconf -e myhostname=uhlbriccsapp01.xuhl-tr.nhs.uk
RUN postconf -e mydestination="$smtp_server, uhlbriccsapp01.xuhl-tr.nhs.uk, localhost.xuhl-tr.nhs.uk, localhost"
RUN postconf -e mail_spool_directory="/var/spool/mail/"
RUN postconf -e mailbox_command=""
RUN postconf -e relayhost="$smtp_server"

##################### INSTALLATION END #####################

# Manually set up the apache environment variables
RUN mkdir -p /var/lock/apache2 /var/run/apache2 /etc/supervisor/conf.d/
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data
ENV APACHE_LOG_DIR /var/log/apache2
ENV APACHE_LOCK_DIR /var/lock/apache2
ENV APACHE_PID_FILE /var/run/apache2.pid

# Expose apache.
EXPOSE 80

# Make a directory
RUN mkdir -p /var/www/site
RUN mkdir -p /share/edocs
RUN chown www-data:www-data /share/edocs

ADD redcap$redcap_version.zip /home
RUN unzip /home/redcap$redcap_version.zip -d /home

# Move latest version
RUN mv /home/redcap/ /var/www/site/
ADD database.php /var/www/site/redcap

# Add Redcap cronjob
RUN echo "* * * * * /usr/bin/php /var/www/site/redcap/cron.php >> /var/log/cron.log 2>&1" | crontab - 

# supervisord config file
COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Add test images
ADD redcaplogo_test.gif /var/www/site/redcap/redcap_v$redcap_version/Resources/images/redcaplogo.gif
ADD redcaplogo_small_test.gif /var/www/site/redcap/redcap_v$redcap_version/Resources/images/redcaplogo_small.gif

# Add optimised for chrome image
ADD chrome.png /var/www/site/redcap/
RUN sed -i.bkp '/print "<\/form>";/a print "<img src='\''/redcap/chrome.png'\''>";' /var/www/site/redcap/redcap_v$redcap_version/Config/init_functions.php

# house keeping
RUN chmod 777 -R /var/www/site/redcap/temp
RUN mkdir /usr/local/redcap-edocs
RUN chmod 777 -R /usr/local/redcap-edocs

# php changes
RUN sed -i.bak 's/upload_max_filesize = 2M/upload_max_filesize = 32M/g' /etc/php/7.0/apache2/php.ini
RUN sed -i.bak 's/post_max_size = 8M/post_max_size = 32M/g' /etc/php/7.0/apache2/php.ini
RUN sed -i.bak 's/; max_input_vars = 1000/max_input_vars = 10000/g' /etc/php/7.0/apache2/php.ini

# Update the default apache site with the config we created.
ADD apache-config.conf /etc/apache2/sites-enabled/000-default.conf

# Setup supervisord
CMD /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
