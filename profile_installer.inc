<?php
/**
 * @file
 *
 */
require_once DRUPAL_ROOT . '/profiles/profile_installer/ProfileInstaller.php';

/**
 * @param string $profile_name
 *
 * @return array
 *   Array of install tasks, @see hook_install_tasks.
 */
function profile_installer_get_install_tasks($profile_name) {
  $installer = ProfileInstaller::getInstallerForProfile($profile_name);
  return $installer->getInstallTasks();
}

/**
 * Callback for task to install profiles' dependencies.
 *
 * Core's hook_install_tasks does not support methods of objects, this has to be
 * a standalone function.
 *
 * The core logic here is copied from install_profile_modules() in install.core.inc.
 * We just merge our own list of modules from profiles' info files to populate
 * the modules list.
 */
function profile_installer_install_modules(&$install_state) {
  $installer = ProfileInstaller::getInstallerForProfile();
  $modules = $installer->getInstallProfileModules();

  // Get modules core requires and stores in install_profile_modules variable.
  $more_modules = variable_get('install_profile_modules', array());
  $modules = array_unique(array_merge($modules, $more_modules));
  variable_del('install_profile_modules');

  $files = system_rebuild_module_data();
  variable_del('profile_installer_install_modules');

  // Always install required modules first. Respect the dependencies between
  // the modules.
  $required = array();
  $non_required = array();
  // Although the profile module is marked as required, it needs to go after
  // every dependency, including non-required ones. So clear its required
  // flag for now to allow it to install late.
  $files[$install_state['parameters']['profile']]->info['required'] = FALSE;
  // Add modules that other modules depend on.
  foreach ($modules as $module) {
    if ($files[$module]->requires) {
      $modules = array_merge($modules, array_keys($files[$module]->requires));
    }
  }
  $modules = array_unique($modules);
  foreach ($modules as $module) {
    if (!empty($files[$module]->info['required'])) {
      $required[$module] = $files[$module]->sort;
    }
    else {
      $non_required[$module] = $files[$module]->sort;
    }
  }
  arsort($required);
  arsort($non_required);

  $operations = array();
  foreach ($required + $non_required as $module => $weight) {
    $operations[] = array('_install_module_batch', array($module, $files[$module]->info['name']));
  }
  $batch = array(
    'operations' => $operations,
    'title' => st('Installing @drupal', array('@drupal' => drupal_install_profile_distribution_name())),
    'error_message' => st('The installation has encountered an error.'),
    'finished' => '_install_profile_modules_finished',
  );
  return $batch;
}

function profile_installer_install_profiles(&$install_state) {
  $installer = ProfileInstaller::getInstallerForProfile();
  $installer->install();
}
