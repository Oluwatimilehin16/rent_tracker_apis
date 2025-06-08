# Use an official PHP + Apache image
FROM php:8.2-apache

# Copy all files to Apache's web directory
COPY . /var/www/html/

# Enable Apache mod_rewrite (for friendly URLs if needed)
RUN a2enmod rewrite

# Set recommended PHP config
RUN docker-php-ext-install mysqli
