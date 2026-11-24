FROM php:8.2-apache

# PDO MySQL driver so PHP can talk to the database.
RUN docker-php-ext-install pdo pdo_mysql

# Match Nginx's 25 MB upload limit.
RUN printf "upload_max_filesize = 25M\npost_max_size = 25M\n" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
