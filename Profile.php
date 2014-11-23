<?php
/**
 * @file Profile.php
 * Provides Profile class.
 */
require_once __DIR__ . '/ProfileUtility.php';

/**
 * Profile is a data transfer object with info about the profile being installed.
 */
class Profile {
  //
  public $profile_utility;

  // First profile to instantiate ProfileInstaller (the one selected via Drupal
  // GUI or specified by `drush site-install <profile>` command) is the "baseprofile".
  public $baseprofile_name;
  public $baseprofile_path;

  // Keep track of profiles included by baseprofile or included profiles.
  public $included_profiles;

  // Keep track of which supported hooks have been implemented by included profiles.
  public $hook_implementations;

  // 'install_profile_modules' is the variable name used by Drupal core to keep
  // track of an install profile's dependencies and then install them. The same
  // name is reused here for consistency.
  public $install_profile_modules;

  public function __construct($baseprofile_name, ProfileUtility $profile_utility) {
    $this->profile_utility = $profile_utility;
    $this->setBaseProfileName($baseprofile_name);
    $this->setBaseProfilePath();
    $this->setIncludedProfiles();
    $this->setInstallProfileModules();
    $this->setHookImplementations();
  }

  /**
   * Keeps track of parent profile, the one instantiating ProfileInstaller.
   *
   * @param string $baseprofile_name
   */
  private function setBaseProfileName($baseprofile_name) {
    if ($this->profile_utility->profileExists($baseprofile_name, TRUE)) {
      $this->baseprofile_name = $baseprofile_name;
    }
  }

  /**
   * Stores absolute path to baseprofile.
   *
   * @throws Exception
   */
  private function setBaseProfilePath() {
    if (empty($this->baseprofile_name)) {
      throw new Exception("Cannot set baseprofile_path if baseprofile_name is empty.");
    }
    $this->baseprofile_path = $this->profile_utility->getPathToProfile($this->baseprofile_name);
  }

  /**
   * Detects profiles included by baseprofile and sets included_profiles property.
   */
  private function setIncludedProfiles() {
    $profiles = $this->profile_utility->getIncludedProfiles($this->baseprofile_name);
    // @todo Add sorting (alpha, weight, etc.).
    $this->included_profiles = $profiles;
  }

  /**
   * Set modules to be enabled during installation of baseprofile.
   *
   * Defaults to detecting all module dependencies declared by baseprofile and
   * included profiles.
   *
   * @param array $modules
   */
  private function setInstallProfileModules($modules = array()) {
    if (empty($modules) || empty($this->install_profile_modules)) {
      $dependencies = $this->profile_utility->getDependenciesForProfile( $this->baseprofile_name );
      $removals = $this->profile_utility->getDependencyRemovalsForProfile( $this->baseprofile_name );
      $modules = $this->profile_utility->removeNeedlesFromHaystack($removals, $dependencies);
    }
    $this->install_profile_modules = $modules;
  }

  private function setHookImplementations() {
    $profile_name = $this->baseprofile_name;
    $hook_implementations = $this->profile_utility->findHookImplementationsInProfile($profile_name);

    $this->hook_implementations = $hook_implementations;
  }


}
