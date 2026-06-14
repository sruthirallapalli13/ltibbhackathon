FROM php:7.2-apache
RUN yum install -y mysql


RUN docker-php-ext-install mysqli
COPY . /var/www/html/

