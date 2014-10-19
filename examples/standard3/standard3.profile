<?php
/**
 * @file
 * Enables modules and site configuration for a standard3 site installation.
 */

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form().
 *
 * Allows the profile to alter the site configuration form.
 */
function standard3_form_install_configure_form_alter(&$form, $form_state) {
  $installer = ProfileInstaller::getInstallerForProfile('standard3');
  $form = $installer->alterInstallConfigureForm($form, $form_state);
}
