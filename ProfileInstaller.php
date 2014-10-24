<?php

/**
 * @file ProfileInstaller.php
 * Provides BaseProfile class.
 */

class ProfileInstaller {

  private static $instance;

  private $baseprofile_name;
  private $baseprofile_path;
  private $hook_implementations;
  private $hook_invocations;
  private $included_profiles;
  // Note: 'install_profile_modules' is the variable name used by Drupal core.
  // It's reused here for consistency with core, even though
  // install_profile_dependencies may seem more intuitive and consistent.
  private $install_profile_modules;
  private $install_profile_dependency_removals;
  private $install_callbacks;
  private $install_state;
  private $install_tasks_alter_implementations; // @todo Remove.
  private $install_tasks_alters_status; // @todo Remove.
  private $install_configure_form_alter_implementations; // @todo remove.
  private $install_configure_form_alters_status;  // @todo remove.

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

  public function getInstallTasks(array $install_state) {
    $tasks = array(
      'profile_installer_install_profiles' => array(
        'display_name' => st('Install profiles'),
        'type' => 'normal',
      ),
    );

    // Keep track of state. Only invoke hooks once per state, so we don't get
    // trapped in a loop.
    $hook = 'hook_install_tasks';
    if ($this->isNewStateForHookInvocations($install_state, $hook)) {
      $this->setUpNewHookInvocationsForState($hook, $install_state);
    }

    // Give included profiles an opportunity to add tasks.
    $invocations = $this->getHookInvocationsForState($hook, $install_state);
    foreach ($invocations as $invocation) {
      if ($this->hookInvocationHasNotBeenCalled($invocation)) {
        $this->updateInvocationToInvoked($invocation);
        include_once $this->getFileWithHookImplementation($invocation);
        $function = $invocation['function'];
        $more_tasks = $function($install_state);
        $tasks = array_merge($tasks, $more_tasks);
      }
    }

    // Store this so it can be returned in case anyone alters it, when passed by
    // reference in hook_install_tasks;
    $this->install_state = $install_state;

    return $tasks;
  }

  /**
   * WARNING: This should only be called by hook_install_tasks. That's the only
   * place we're tracking current install_state.
   *
   * @return array
   */
  public function getInstallState() {
    return $this->install_state;
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
    $implementations = $this->getInstallTasksAlterImplementationsForInstallState($install_state);
    foreach ($implementations as $function => $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $this->updateHookImplementationStatusToInvoked($implementation_info);
        include_once $this->getFileWithHookImplementation($implementation_info);
        $function($tasks, $install_state);
      }
    }

    return $tasks;
  }

  /**
   * Checks whether hooks have been registered to be invoked for this state yet.
   *
   * @param array $state
   *   This can be $form_state or $install_state.
   *
   * @return bool
   */
  private function isNewStateForHookInvocations(array $state, $hook) {
    $key = $this->getKeyForArray($state);
    $is_new = !isset($this->hook_invocations[$hook][$key]);
    return $is_new;
  }

  private function isNewInstallState($install_state) {
    $key = $this->getKeyForInstallState($install_state);
    $is_new = !isset($this->install_tasks_alters_status[$key]);
    return $is_new;
  }

  private function setUpNewHookInvocationsForState($hook, array $state) {
    $implementations = $this->getHookImplementations($hook);
    $key = $this->getKeyForArray($state);

    if (isset($this->hook_invocations[$hook][$key])) {
      throw new Exception ("{$hook} invocations this install_state/form_state have already been set up.");
    }

    foreach ($implementations as $function => $file) {
      $this->hook_invocations[$hook][$key][$function]['function'] = $function;
      $this->hook_invocations[$hook][$key][$function]['file'] = $file;
      $this->hook_invocations[$hook][$key][$function]['invoked'] = FALSE;
      $this->hook_invocations[$hook][$key][$function]['key'] = $key;
      $this->hook_invocations[$hook][$key][$function]['hook'] = 'hook_install_tasks_alter';
    }
  }

  private function setUpNewInstallTaskAlterImplementationsForInstallState(array $install_state) {
    $implementations = $this->getInstallTasksAlterImplementations();
    $key = $this->getKeyForInstallState($install_state);

    if (isset($this->install_tasks_alters_status[$key])) {
      throw new Exception ('Not new. Alters for this install_state have already been set up.');
    }

    foreach ($implementations as $function => $file) {
      $this->install_tasks_alters_status[$key][$function]['function'] = $function;
      $this->install_tasks_alters_status[$key][$function]['file'] = $file;
      $this->install_tasks_alters_status[$key][$function]['invoked'] = FALSE;
      $this->install_tasks_alters_status[$key][$function]['key'] = $key;
      $this->install_tasks_alters_status[$key][$function]['hook'] = 'hook_install_tasks_alter';
    }
  }

  private function hookInvocationHasNotBeenCalled($invocation) {
    $hook = $invocation['hook'];
    $key = $invocation['key'];
    $function = $invocation['function'];
    if (!empty($this->hook_invocations[$hook][$key][$function])) {
      $has_been_invoked = $this->hook_invocations[$hook][$key][$function]['invoked'];
      return !$has_been_invoked;
    }
    else {
      throw new Exception('Something went wrong. $invocation does not include the info needed.');
    }
  }

  private function updateInvocationToInvoked(array $invocation) {
    $key = $invocation['key'];
    $function = $invocation['function'];
    $hook = $invocation['hook'];
    $this->hook_invocations[$hook][$key][$function]['invoked'] = TRUE;
  }

  private function getHookImplementationForProfile($hook, $profile_name) {
    $suffix = substr($hook, 4);
    return "{$profile_name}_{$suffix}";
  }

  private function hookImplementationHasNotBeenInvoked($implementation_info) {
    return !$implementation_info['invoked'];
  }

  private function updateHookImplementationStatusToInvoked(array $implementation_info) {
    $key = $implementation_info['key'];
    $function = $implementation_info['function'];
    $hook = $implementation_info['hook'];

    switch ($hook) {
      case 'hook_install_tasks_alter':
        $property = 'install_tasks_alters_status';
        break;

      case 'hook_form_install_configure_form_alter':
        $property = 'install_configure_form_alters_status';
        break;

      default:
        throw new Exception("No property for hook {$hook}");
    }

    if (!property_exists($this, $property)) {
      throw new Exception("Property does not exist: {$property}");
    }

    $this->{$property}[$key][$function]['invoked'] = TRUE;
  }

  private function getFileWithHookImplementation($implementation_info) {
    return $implementation_info['file'];
  }

  private function getHookInvocationsForState($hook, array $state) {
    $key = $this->getKeyForArray($state);
    $invocations = !empty($this->hook_invocations[$hook][$key]) ? $this->hook_invocations[$hook][$key] : array();
    return $invocations;
  }

  private function getInstallTasksAlterImplementationsForInstallState(array $install_state) {
    $key = $this->getKeyForInstallState($install_state);
    $implementations = isset($this->install_tasks_alters_status[$key]) ? $this->install_tasks_alters_status[$key] : array();
    return $implementations;
  }

  private function getHookImplementations($hook) {
    $implementations = array();
    if (empty($this->hook_implementations)) {
      $this->setHookImplementations();
    }

    if (!empty($this->hook_implementations[$hook])) {
      foreach ($this->hook_implementations[$hook] as $function => $file) {
        $implementations[$function] = $file;
      }
    }

    return $implementations;
  }

  private function setHookImplementations() {
    $this->hook_implementations = array();
    $supported_hooks = $this->getSupportedHooks();

    foreach ($supported_hooks as $hook) {
      foreach ($this->getIncludedProfiles() as $profile_name) {
        $function = $this->getHookImplementationForProfile($hook, $profile_name);
        if ($file = $this->findFunctionInProfile($function, $profile_name)) {
          $this->hook_implementations[$hook][$function] = $file;
        }

      }
    }
  }

  private static function getSupportedHooks() {
    return array(
      'hook_install_tasks',
      'hook_install_tasks_alter',
      'hook_form_install_configure_form_alter',
    );
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

      if ($file = $this->findFunctionInProfile($function, $profile_name)) {
        $this->install_tasks_alter_implementations[$function] = $file;
      }

    }
  }

  private static function getKeyForInstallState(array $install_state) {
    return self::getKeyForArray($install_state);
  }

  function alterInstallConfigureForm($form, $form_state) {
    // Keep track of hook invocations.
    if ($this->isNewFormState($form_state)) {
      $this->setUpNewInstallConfigureFormAlterImplementationsForFormState($form_state);
    }

    // Give included profiles an opportunity to alter install_configure_form
    // once per form state so we don't get trapped in a loop.
    $implementations = $this->getInstallConfigureFormAlterImplementationsForFormState($form_state);
    foreach ($implementations as $function => $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $this->updateHookImplementationStatusToInvoked($implementation_info);
        include_once $this->getFileWithHookImplementation($implementation_info);
        $function($form, $form_state);
      }
    }

    return $form;
  }

  private function isNewFormState(array $form_state) {
    $key = $this->getKeyForFormState($form_state);
    $is_new = !isset($this->install_configure_form_alters_status[$key]);
    return $is_new;
  }

  private function setUpNewInstallConfigureFormAlterImplementationsForFormState(array $form_state) {
    $implementations = $this->getInstallConfigureFormAlterImplementations();
    $key = $this->getKeyForFormState($form_state);

    if (isset($this->install_configure_form_alters_status[$key])) {
      throw new Exception ('Not new. Alters for this form_state have already been set up.');
    }

    foreach ($implementations as $function => $file) {
      $this->install_configure_form_alters_status[$key][$function]['function'] = $function;
      $this->install_configure_form_alters_status[$key][$function]['file'] = $file;
      $this->install_configure_form_alters_status[$key][$function]['invoked'] = FALSE;
      $this->install_configure_form_alters_status[$key][$function]['key'] = $key;
      $this->install_configure_form_alters_status[$key][$function]['hook'] = 'hook_form_install_configure_form_alter';
    }
  }

  private function getInstallConfigureFormAlterImplementations() {
    if (empty($this->install_configure_form_alter_implementations)) {
      $this->setInstallConfigureFormAlterImplementations();
    }

    return $this->install_configure_form_alter_implementations;
  }

  private function setInstallConfigureFormAlterImplementations() {
    $this->install_configure_form_alter_implementations = array();

    foreach ($this->getIncludedProfiles() as $profile_name) {
      $function = "{$profile_name}_form_install_configure_form_alter";

      if ($file = $this->findFunctionInProfile($function, $profile_name)) {
        $this->install_configure_form_alter_implementations[$function] = $file;
      }

    }
  }

  private function getInstallConfigureFormAlterImplementationsForFormState(array $form_state) {
    $key = $this->getKeyForFormState($form_state);
    $implementations = isset($this->install_configure_form_alters_status[$key]) ? $this->install_configure_form_alters_status[$key] : array();
    return $implementations;
  }

  private static function getKeyForFormState(array $form_state) {
    return self::getKeyForArray($form_state);
  }

  private static function getKeyForArray(array $array) {
    return md5(serialize($array));
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

  private function findFunctionInProfile($function, $profile_name) {
    $install_file = $this->getPathToProfileInstallFile($profile_name);
    $profile_file = $this->getPathToProfileProfileFile($profile_name);

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

  public static function getPathToProfileInstallFile($profile_name) {
    return self::getPathToProfile($profile_name) . "/{$profile_name}.install";
  }

  public static function getPathToProfileProfileFile($profile_name) {
    return self::getPathToProfile($profile_name) . "/{$profile_name}.profile";
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
