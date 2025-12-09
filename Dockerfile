# Basis-Image mit PHP
FROM php:8.2-apache

# Kopiere alle Dateien ins Webroot
COPY . /var/www/html/

# Stelle sicher, dass index.php als Startseite genutzt wird
RUN echo "DirectoryIndex products.php" >> /etc/apache2/apache2.conf

# Port 10000 wird von Render genutzt
EXPOSE 10000

# Apache starten
CMD ["apache2-foreground"]
