<?php
/**
 * @file ProfileUtility.php
 * Provides ProfileUtility class.
 */

/**
 * ProfileUtility provides info about Drupal install profiles.
 */
class ProfileUtility {

  /**
   * Verifies profile is included in Drupal code base.
   *
   * @param string $profile_name
   *
   * @return bool
   */
  public static function profileExists($profile_name) {
    $path = self::getPathToProfile($profile_name);
    $exists = is_dir($path);

    return $exists;
  }

  public static function getPathToProfile($profile_name) {
    return DRUPAL_ROOT . "/profiles/{$profile_name}";
  }

  /**
   * Get profiles included in baseprofile's info file.
   *
   * @TOOD Add recursion, included_profiles property should include profiles included by included profiles (see getAllDependenciesForProfile).
   *
   * @return array
   */
  public static function getIncludedProfiles($profile_name) {
    $info_file = self::getInfoFileForProfile($profile_name);
    $included_profiles = self::getProfileNamesFromInfoFile($info_file);

    return $included_profiles;
  }

  public static function getInfoFileForProfile($profile_name) {
    $path = self::getPathToProfile($profile_name);

    return "{$path}/{$profile_name}.info";
  }

  public static function getProfileNamesFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    $profile_names = (isset($info['profiles'])) ? $info['profiles'] : array();

    return $profile_names;
  }

  public static function getAllDependenciesForProfile($profile_name) {
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

  public static function getDependenciesFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    return isset($info['dependencies']) ? $info['dependencies'] : array();
  }

  public static function getAllDependencyRemovalsForProfile($profile_name) {
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

  public static function getDependencyRemovalsFromInfoFile($info_file) {
    $info = drupal_parse_info_file($info_file);
    return isset($info['remove_dependencies']) ? $info['remove_dependencies'] : array();
  }

  public static function removeNeedlesFromHaystack(array $needles, array $haystack) {
    foreach ($needles as $needle) {
      $key = array_search($needle, $haystack);
      unset($haystack[$key]);
    }

    return $haystack;
  }

  /**
   * Returns a list of hooks supported by Profile Installer compatible profiles.
   *
   * @return array
   */
  public static function getSupportedHooks() {
    return array(
      'hook_install_tasks',
      'hook_install_tasks_alter',
      'hook_form_install_configure_form_alter',
    );
  }

  /**
   * Generates name of hook for a profile.
   *
   * @param string $hook
   * @param string $profile_name
   * @return string
   */
  public static function getHookImplementationForProfile($hook, $profile_name) {
    $suffix = substr($hook, 5);
    return "{$profile_name}_{$suffix}";
  }

  public static function findFunctionInProfile($function, $profile_name) {
    $install_file = self::getPathToProfileInstallFile($profile_name);
    $profile_file = self::getPathToProfileProfileFile($profile_name);

    include_once $install_file;
    if (function_exists($function)) {
      return $install_file;
    }

    include_once $profile_file;
    if (function_exists($function)) {
      return $profile_file;
    }

    return FALSE;
  }

  public static function getPathToProfileInstallFile($profile_name) {
    return self::getPathToProfile($profile_name) . "/{$profile_name}.install";
  }

  public static function getPathToProfileProfileFile($profile_name) {
    return self::getPathToProfile($profile_name) . "/{$profile_name}.profile";
  }


  /**
   * Finds implementations for any supported hook included in profile.
   *
   * @param $profile_name
   *
   * @return array
   *   Returns an associative array of hooks implementations like this:
   *   $hook_implementations[$hook][$function] = $file;
   */
  public static function findHookImplementationsInProfile($profile_name) {
    $hook_implementations = array();

    $supported_hooks = self::getSupportedHooks();
    $profile_names = self::getIncludedProfiles($profile_name);

    foreach ($supported_hooks as $hook) {
      foreach ($profile_names as $profile_name) {
        $function = self::getHookImplementationForProfile($hook, $profile_name);
        if ($file = self::findFunctionInProfile($function, $profile_name)) {
          $hook_implementations[$hook][$function] = $file;
        }
      }
    }

    return $hook_implementations;
  }

  /**
   * Get a list of function names implementing specified hook.
   *
   * @param string $hook
   *   Hook we're interested in.
   *
   * @return array
   *   Associative array of files with functions, keyed by function name.
   */
  public function getHookImplementationsInProfile($hook, Profile $profile) {
    $implementations = array();
    $hook_implementations = $profile->hook_implementations;

    if (!empty($hook_implementations[$hook])) {
      foreach ($hook_implementations[$hook] as $function => $file) {
        $implementations[$function] = $file;
      }
    }

    return $implementations;
  }

}