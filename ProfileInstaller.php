<?php

/**
 * @file ProfileInstaller.php
 * Provides BaseProfile class.
 */

class ProfileInstaller {

  private static $instance;

  private $baseprofile_name;
  private $baseprofile_path;
  private $included_profiles;
  // Note: 'install_profile_modules' is the variable name used by Drupal core.
  // It's reused here for consistency with core, even though
  // install_profile_dependencies may seem more intuitive and consistent.
  private $install_profile_modules;
  private $install_profile_dependency_removals;
  private $install_callbacks;
  private $install_tasks_alter_implementations;
  private $install_tasks_alters_status;

  private function __construct($baseprofile_name) {
    $this->setBaseProfileName($baseprofile_name);
    $this->setBaseProfilePath();
    $this->setIncludedProfiles();
    $this->setInstallProfileModules();
    $this->setInstallCallbacks();
  }

  public static function getInstallerForProfile($profile_name = '') {
    if (empty(self::$instance) && !empty($profile_name)) {
      self::$instance = new self($profile_name);
    }
    elseif (empty(self::$instance) && empty($profile_name)) {
      throw new Exception('Installer must be instantiated or a particular install profile. Please pass in name as an argument.');
    }

    return self::$instance;
  }

  public static function installProfilesIncludedByProfile($profile) {
    $installer = new self($profile);
    $installer->install();
  }

  /**
   * Run install script (invoke hook_install) for included profiles.
   */
  public function install() {
    foreach ($this->install_callbacks as $callback => $path) {
      include_once $path;
      call_user_func($callback);
    }
  }

  public static function profileExists($profile_name, $raise_exception = FALSE) {
    $path = self::getPathToProfile($profile_name);
    $exists = is_dir($path);

    return $exists;
  }

  public function getInstallTasks() {
    return array(
      'profile_installer_install_profiles' => array(
        'display_name' => st('Install profiles'),
        'type' => 'normal',
      ),
    );
  }

  public function alterInstallTasks($tasks, $install_state) {
    // Use our own handler for installing profile's modules.
    $tasks['install_profile_modules']['function'] = 'profile_installer_install_modules';

    // Keep track of hook invocations.
    if ($this->isNewInstallState($install_state)) {
      $this->setUpNewInstallTaskAlterImplementationsForInstallState($install_state);
    }

    // Give included profiles an opportunity to alter tasks (once per install
    // state so we don't get trapped in a loop).
    foreach ($this->getInstallTasksAlterImplementationsForInstallState($install_state) as $function => $info) {
      if (!$info['invoked']) {
        $this->updateInvocationStatusToInvokedForInstallState($function, $install_state);
        include_once $info['file'];
        $function($tasks, $install_state);
      }
    }

    return $tasks;
  }

  private function isNewInstallState($install_state) {
    $key = $this->getKeyForInstallState($install_state);
    $is_new = !isset($this->install_tasks_alters_status[$key]);
    return $is_new;
  }

  private function setUpNewInstallTaskAlterImplementationsForInstallState(array $install_state) {
    $implementations = $this->getInstallTasksAlterImplementations();
    $key = $this->getKeyForInstallState($install_state);
    foreach ($implementations as $function => $file) {
      $this->install_tasks_alters_status[$key][$function]['function'] = $function;
      $this->install_tasks_alters_status[$key][$function]['file'] = $file;
      $this->install_tasks_alters_status[$key][$function]['invoked'] = FALSE;
    }
  }

  private function updateInvocationStatusToInvokedForInstallState($function, $install_state) {
    $key = $this->getKeyForInstallState($install_state);
    $this->install_tasks_alters_status[$key][$function]['invoked'] = TRUE;
  }

  private function getInstallTasksAlterImplementationsForInstallState($install_state) {
    $key = $this->getKeyForInstallState($install_state);
    return $this->install_tasks_alters_status[$key];
  }

  private function getInstallTasksAlterImplementations() {
    if (empty($this->install_tasks_alter_implementations)) {
      $this->setInstallTasksAlterImplementations();
    }

    return $this->install_tasks_alter_implementations;
  }

  private function setInstallTasksAlterImplementations() {
    $this->install_tasks_alter_implementations = array();
    foreach ($this->getIncludedProfiles() as $profile_name) {
      $function = "{$profile_name}_install_tasks_alter";
      $path = $this->getPathToProfile($profile_name);
      $install_file = "{$path}/{$profile_name}.install";
      $profile_file = "{$path}/{$profile_name}.profile";

      include_once $install_file;
      if (function_exists($function)) {
        $this->install_tasks_alter_implementations[$function] = $install_file;
      }

      include_once $profile_file;
      if (function_exists($function)) {
        $this->install_tasks_alter_implementations[$function] = $profile_file;
      }
      else {
        // If function doesn't exist in .install or .profile, skip.
        continue;
      }
    }
  }

  private static function getKeyForInstallState($install_state) {
    return md5(serialize($install_state));
  }

  function alterInstallConfigureForm($form, $form_state) {
    // Give included profiles an opportunity to alter install_configure_form.
    foreach ($this->getIncludedProfiles() as $profile_name) {
      $path = $this->getPathToProfile($profile_name);
      $install_file = "{$path}/{$profile_name}.install";
      $profile_file = "{$path}/{$profile_name}.profile";
      include_once $install_file;
      include_once $profile_file;
      $function = "{$profile_name}_form_install_configure_form_alter";
      if (function_exists($function)) {
        $function($form, $form_state);
      }
    }

    return $form;
  }

  public function removeInstallProfileModules(array $modules) {
    $dependencies = $this->getInstallProfileModules();
    $dependencies = $this->removeNeedlesFromHaystack($modules, $dependencies);
    $this->setInstallProfileModules($dependencies);
  }

  public function removeNeedlesFromHaystack(array $needles, array $haystack) {
    foreach ($needles as $needle) {
      $key = array_search($needle, $haystack);
      unset($haystack[$key]);
    }
    return $haystack;
  }


  /**
   * Getters and setters. ======================================================
   */

  public function setInstallCallbacks($callbacks = array()) {
    if (empty($callbacks)) {
      $included_profiles = $this->getIncludedProfiles();
      foreach ($included_profiles as $profile_name) {
        $path = $this->getPathToProfile($profile_name);
        $path = "{$path}/{$profile_name}.install";
        $func = "{$profile_name}_install";
        $callbacks[$func] = $path;
      }
    }
    $this->install_callbacks = $callbacks;
  }

  public function getInstallCallbacks() {
    if (empty($this->install_callbacks)) {
      $this->setInstallCallbacks();
    }

    return $this->install_callbacks;
  }

  public static function getDependenciesForProfilesIncludedByProfile($baseprofile_name) {
    $installer = new self($baseprofile_name);
    return $installer->getIncludedProfilesDependencies();
  }

  function getInstallProfileDependencyRemovals() {
    if (empty($this->install_profile_dependency_removals)) {
      $this->setInstallProfileDependencyRemovals();
    }
    return $this->install_profile_dependency_removals;
  }

  function setInstallProfileDependencyRemovals() {
    $removals = $this->getAllDependencyRemovalsForProfile($this->getBaseProfileName());
    $this->install_profile_dependency_removals = $removals;
  }

  public function setInstallProfileModules($modules = array()) {
    if (empty($modules) || empty($this->install_profile_modules)) {
      $dependencies = $this->getAllDependenciesForProfile( $this->getBaseProfileName() );
      $removals = $this->getAllDependencyRemovalsForProfile( $this->getBaseProfileName() );
      $modules = $this->removeNeedlesFromHaystack($removals, $dependencies);
    }
    $this->install_profile_modules = $modules;
  }

  public function getInstallProfileModules() {
    return $this->install_profile_modules;
  }

  private static function getAllDependenciesForProfile($profile_name) {
    // Get top-level dependencies for profile.
    $info_file = self::getInfoFileForProfile($profile_name);
    $dependencies = self::getDependenciesFromInfoFile($info_file);

    // Recurse. Detect included profiles, and get their dependencies.
    $profile_names = self::getProfileNamesFromInfoFile($info_file);
    foreach ($profile_names as $profile_name) {
      $additional_dependencies = self::getAllDependenciesForProfile($profile_name);
      $dependencies = array_unique(array_merge($dependencies, $additional_dependencies));
    }

    return $dependencies;
  }

  private static function getAllDependencyRemovalsForProfile($profile_name) {
    // Get top-level removals for profile.
    $info_file = self::getInfoFileForProfile($profile_name);
    $removals = self::getDependencyRemovalsFromInfoFile($info_file);

    // Recurse. Detect included profiles, and get their dependencies.
    $profile_names = self::getProfileNamesFromInfoFile($info_file);
    foreach ($profile_names as $profile_name) {
      $additional_removals = self::getAllDependencyRemovalsForProfile($profile_name);
      $removals = array_unique(array_merge($removals, $additional_removals));
    }

    return $removals;
  }

  public static function getInfoFileForProfile($profile_name) {
    $path = self::getPathToProfile($profile_name);

    return "{$path}/{$profile_name}.info";
  }

  public static function getDependenciesFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    return isset($info['dependencies']) ? $info['dependencies'] : array();
  }

  public static function getDependencyRemovalsFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    return isset($info['remove_dependencies']) ? $info['remove_dependencies'] : array();
  }

  private function setIncludedProfiles() {
    $profiles = $this->getIncludedProfiles();
    // @todo Add sorting (alpha, weight, etc.).
    $this->included_profiles = $profiles;
  }

  public static function getProfileNamesFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    $profile_names = (isset($info['profiles'])) ? $info['profiles'] : array();

    return $profile_names;
  }

  private function getIncludedProfiles() {
    if (empty($this->included_profiles)) {
      $profile_name = $this->getBaseProfileName();
      $profile_path = $this->getBaseProfilePath();
      $info_file = self::getInfoFileForProfile($this->baseprofile_name);
      $this->included_profiles = self::getProfileNamesFromInfoFile($info_file);
    }

    return $this->included_profiles;
  }

  public static function getPathToProfile($profile_name) {
    return DRUPAL_ROOT . "/profiles/{$profile_name}";
  }

  public function getBaseProfileName() {
    return $this->baseprofile_name;
  }

  public function setBaseProfileName($baseprofile_name) {
    if (self::profileExists($baseprofile_name, TRUE)) {
      $this->baseprofile_name = $baseprofile_name;
    }
  }

  public function getBaseProfilePath() {
    return $this->baseprofile_path;
  }

  public function setBaseProfilePath() {
    if (empty($this->baseprofile_name)) {
      throw new Exception("Cannot set baseprofile_path if baseprofile_name is empty.");
    }
    $this->baseprofile_path = $this->getPathToProfile($this->baseprofile_name);
  }
}
