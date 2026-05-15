FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates skopeo \
    && rm -rf /var/lib/apt/lists/*

COPY apache-ssl.conf /etc/apache2/sites-available/hub-self-ssl.conf
COPY public/ /var/www/html/

RUN a2enmod ssl headers \
    && a2ensite hub-self-ssl
