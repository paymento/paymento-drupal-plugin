<?php
$databases['default']['default'] = [
  'database' => 'drupal', 'username' => 'drupal', 'password' => 'synthetic-local-database',
  'prefix' => '', 'host' => 'db', 'port' => '3306', 'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql', 'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];
$settings['hash_salt'] = 'synthetic-local-drupal-fixture-hash-salt';
$settings['trusted_host_patterns'] = ['^localhost$', '^drupal$', '^127\\.0\\.0\\.1$'];
$settings['config_sync_directory'] = '/opt/drupal/private/config';
$settings['file_private_path'] = '/opt/drupal/private/files';
$settings['commerce_paymento_test_mode'] = TRUE;
$settings['commerce_paymento_api_base'] = 'http://mock:3000';
$settings['commerce_paymento_checkout_base'] = 'http://localhost:18101/gateway';
$settings['commerce_paymento_credentials']['fixture'] = ['api_key' => getenv('PAYMENTO_DRUPAL_API_KEY'), 'ipn_secret' => getenv('PAYMENTO_DRUPAL_IPN_SECRET')];
$config['system.logging']['error_level'] = 'hide';
$config['system.mail']['interface']['default'] = 'test_mail_collector';
