<?php
/**
 * @file
 * Enables modules and site configuration for a standard2 site installation.
 */

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form().
 *
 * Allows the profile to alter the site configuration form.
 */
/*
function standard2_form_install_configure_form_alter(&$form, $form_state) {
  $installer = ProfileInstaller::getInstallerForProfile('standard2');
  $installer->setInstallConfigureForm($form);
  $installer->setInstallConfigureFormState($form_state);
  $installer->alterInstallConfigureForm();

  $form = $installer->getInstallConfigureForm();
}
// */
