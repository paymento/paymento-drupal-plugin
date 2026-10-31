FROM drupal:11.4.11-php8.4-apache-bookworm@sha256:1b09ff37090560ee75811de5947075244614d2308be827713d3ee3aeae4cfce9
RUN docker-php-ext-install bcmath
COPY fixture/ /paymento-fixture/
RUN set -eux; composer config repositories.drupal composer https://packages.drupal.org/8; composer config allow-plugins.composer/installers true; \
    if [ -f /paymento-fixture/composer.lock ]; then cp /paymento-fixture/composer.json /paymento-fixture/composer.lock /opt/drupal/; composer install --no-interaction --prefer-dist; \
    else composer require --no-interaction --with-all-dependencies drupal/commerce:3.3.13 drush/drush:13.8.0; fi; \
    composer check-platform-reqs
COPY fixture/settings.php /opt/drupal/web/sites/default/settings.php
RUN mkdir -p /opt/drupal/web/sites/default/files /opt/drupal/private && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private
