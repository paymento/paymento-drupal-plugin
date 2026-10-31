<?php
namespace Drupal\commerce_paymento;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Database\Connection;
final class UninstallValidator implements ModuleUninstallValidatorInterface {
  public function __construct(private Connection $database) {}
  public function validate($module) {
    if ($module === 'commerce_paymento' && $this->database->schema()->tableExists('commerce_paymento_attempt') && $this->database->select('commerce_paymento_attempt', 'a')->countQuery()->execute()->fetchField()) {
      return ['Paymento financial history exists. Disable its payment gateway instead; export and reconcile records before any supervised removal.'];
    }
    return [];
  }
}
