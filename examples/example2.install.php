<?php
// CONTINUE HERE
// @see 6.x-1.x features/includes/features.ctools.inc
require_once DRUPAL_ROOT . '/profiles/InstallProfileObserver/ProfileInstaller.php';
new $installUtility = new InstallUtility(__FILE__);
eval($installUtility->getInstallProfileCode(__FILE__));
