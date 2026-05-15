FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates skopeo \
    && rm -rf /var/lib/apt/lists/*

COPY public/ /var/www/html/
