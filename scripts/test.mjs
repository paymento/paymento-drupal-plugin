import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const cwd=fileURLToPath(new URL('../',import.meta.url));
process.env.COMPOSE_FILE=fileURLToPath(new URL('../compose.yaml',import.meta.url));
process.env.COMPOSE_PROJECT_NAME='paymento-drupal';
function run(command,args){const r=spawnSync(command,args,{cwd,stdio:'inherit',windowsHide:true});if(r.error)throw r.error;if(r.status!==0)process.exit(r.status??1);}
const dc=(...args)=>run('docker',['compose','--project-name','paymento-drupal',...args]);
// Only this dedicated fixture project's declared volumes are removed.
dc('down','--volumes','--remove-orphans');
dc('up','--detach','--build','--wait','--wait-timeout','180');
const drush=(...args)=>dc('exec','-T','drupal','vendor/bin/drush',...args);
drush('site:install','standard','--db-url=mysql://drupal:synthetic-local-database@db/drupal','--site-name=Paymento Drupal Fixture','--account-name=paymento-test-admin','--account-pass=synthetic-local-admin-password','--account-mail=admin@example.invalid','--yes');
drush('en','commerce_product','commerce_cart','commerce_checkout','commerce_payment','commerce_paymento','--yes');
drush('updatedb','--yes');drush('cr');
drush('php:script','/paymento-tests/bootstrap.php');
drush('php:script','/paymento-tests/integration.php');
run(process.execPath,['tests/http-checkout.mjs']);
run(process.execPath,['tests/concurrency.mjs']);
console.log('PASS complete isolated Drupal cold-start suite');
