<?php
/**
 * @file Profile.php
 * Provides Profile class.
 */

/**
 * Profile is a data transfer object with info about the profile being installed.
 */
class Profile {
  // Name of profile being installed. (The first to instantiate installer.)
  public $profile_name;

  // Path to profile being installed.
  public $profile_path;

  // Utility for finding info about profile.
  private $profile_utility;

  // Keep track of profiles included by baseprofile or included profiles.
  public $included_profiles;

  // Keep track of supported hooks implemented by included profiles. (That is,
  // hooks NOT implemented by profile being installed, only its included profiles.)
  public $included_hook_implementations;

  // 'install_profile_modules' is the variable name used by Drupal core to keep
  // track of an install profile's dependencies and then install them. The same
  // name is reused here for consistency.
  public $install_profile_modules;

  public function __construct($profile_name, ProfileUtility $profile_utility) {
    $this->profile_utility = $profile_utility;
    $this->setProfileName($profile_name);
    $this->setProfilePath();
    $this->setIncludedProfiles();
    $this->setInstallProfileModules();
    $this->setHookImplementations();
  }

  /**
   * Keeps track of parent profile, the one instantiating ProfileInstaller.
   *
   * @param string $profile_name
   */
  private function setProfileName($profile_name) {
    if ($this->profile_utility->profileExists($profile_name, TRUE)) {
      $this->profile_name = $profile_name;
    }
  }

  /**
   * Stores absolute path to baseprofile.
   *
   * @throws Exception
   */
  private function setProfilePath() {
    if (empty($this->profile_name)) {
      throw new Exception("Cannot set profile_path if profile_name is empty.");
    }
    $this->profile_path = $this->profile_utility->getPathToProfile($this->profile_name);
  }

  /**
   * Detects profiles included by baseprofile and sets included_profiles property.
   */
  private function setIncludedProfiles() {
    $profiles = $this->profile_utility->getIncludedProfiles($this->profile_name);
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
      $dependencies = $this->profile_utility->getDependenciesForProfile( $this->profile_name );
      $removals = $this->profile_utility->getDependencyRemovalsForProfile( $this->profile_name );
      $modules = $this->profile_utility->removeNeedlesFromHaystack($removals, $dependencies);
    }
    $this->install_profile_modules = $modules;
  }

  private function setHookImplementations() {
    $profile_name = $this->profile_name;
    $included_hook_implementations = $this->profile_utility->findHookImplementationsInIncludedProfiles($profile_name);

    $this->included_hook_implementations = $included_hook_implementations;
  }

}
