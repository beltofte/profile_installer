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
  private $included_profiles_dependencies;
  private $install_profile_modules;
  private $install_callbacks;

  private function __construct($baseprofile_name) {
    $this->setBaseProfileName($baseprofile_name);
    $this->setBaseProfilePath();
    $this->setIncludedProfiles();
    $this->setIncludedProfilesDependencies();
    $this->setInstallProfileModules( $this->getIncludedProfilesDependencies() );
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

  public static function alterTasksForProfile($profile_name, $install_state) {
    // @TODO
  }

  public function removeInstallProfileModules(array $modules) {
    $dependencies = $this->getInstallProfileModules();
    foreach ($modules as $module) {
      $key = array_search($module, $dependencies);
      unset($dependencies[$key]);
    }
    $this->setInstallProfileModules($dependencies);
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

  public function getIncludedProfilesDependencies() {
    if (empty($this->included_profiles_dependencies)) {
      $this->setIncludedProfilesDependencies();
    }

    return $this->included_profiles_dependencies;
  }

  private function setIncludedProfilesDependencies() {
    $dependencies = array();
    foreach ($this->included_profiles as $profile_name) {
      $additional_dependencies = self::getAllDependenciesForProfile($profile_name);
      $dependencies = array_unique(array_merge($dependencies, $additional_dependencies));
    }
    $this->included_profiles_dependencies = $dependencies;
  }

  public function setInstallProfileModules($modules) {
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

  public static function getInfoFileForProfile($profile_name) {
    $path = self::getPathToProfile($profile_name);

    return "{$path}/{$profile_name}.info";
  }

  public static function getDependenciesFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);

    return $info['dependencies'];
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
