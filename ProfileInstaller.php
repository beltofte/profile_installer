<?php

/**
 * @file ProfileInstaller.php
 * Provides ProfileInstaller class.
 */

class ProfileInstaller {

  // ProfileInstaller is a singleton. ::getInstallerForProfile stores instance here.
  private static $instance;

  // First profile to instantiate ProfileInstaller (the one selected via Drupal
  // GUI or specified by `drush site-install <profile>` command) is the "baseprofile".
  private $baseprofile_name;
  private $baseprofile_path;

  // Keep track of profiles included by baseprofile or included profiles.
  private $included_profiles;

  // Keep track of which supported hooks have been implemented by included profiles.
  private $hook_implementations;

  // Each hook should only be invoked once per install state or form state,
  // otherwise it's easy to get trapped in a loop. Keep track here.
  private $hook_invocations;

  // 'install_profile_modules' is the variable name used by Drupal core to keep
  // track of an install profile's dependencies and then install them. The same
  // name is reused here for consistency.
  private $install_profile_modules;

  // Dependencies to be removed, specified by included profiles.
  private $install_profile_dependency_removals;

  // Included profiles' install hooks are organized here. Install profiles can
  // examine, reorder, and modify this list of callbacks as needed.
  private $install_callbacks;

  // Stores and returns install state for implementers of hook_install_tasks.
  // Install state is NOT tracked or updated throughout install process here.
  private $install_state;

  /**
   * ProfileInstaller is a singleton. Instantiate it here.
   *
   * @param string $profile_name
   *   Parent profile which includes other profiles via info file and
   *   instantiates installer.
   *
   * @return ProfileInstaller
   *
   * @throws Exception
   */
  public static function getInstallerForProfile($profile_name = '') {
    if (empty(self::$instance) && !empty($profile_name)) {
      self::$instance = new self($profile_name);
    }
    elseif (empty(self::$instance) && empty($profile_name)) {
      throw new Exception('Installer must be instantiated or a particular install profile. Please pass in name as an argument.');
    }

    return self::$instance;
  }

  /**
   * ProfileInstaller is a singleton. Instantiate via ::getInstallerForProfile.
   *
   * @param $baseprofile_name
   *   Parent profile which includes other profiles via info file and
   *   instantiates installer.
   */
  private function __construct($baseprofile_name) {
    $this->setBaseProfileName($baseprofile_name);
    $this->setBaseProfilePath();
    $this->setIncludedProfiles();
    $this->setInstallProfileModules();
    $this->setInstallCallbacks();
  }

  public function setBaseProfileName($baseprofile_name) {
    if (self::profileExists($baseprofile_name, TRUE)) {
      $this->baseprofile_name = $baseprofile_name;
    }
  }

  public function setBaseProfilePath() {
    if (empty($this->baseprofile_name)) {
      throw new Exception("Cannot set baseprofile_path if baseprofile_name is empty.");
    }
    $this->baseprofile_path = $this->getPathToProfile($this->baseprofile_name);
  }

  private function setIncludedProfiles() {
    $profiles = $this->getIncludedProfiles();
    // @todo Add sorting (alpha, weight, etc.).
    $this->included_profiles = $profiles;
  }

  public function setInstallProfileModules($modules = array()) {
    if (empty($modules) || empty($this->install_profile_modules)) {
      $dependencies = $this->getAllDependenciesForProfile( $this->getBaseProfileName() );
      $removals = $this->getAllDependencyRemovalsForProfile( $this->getBaseProfileName() );
      $modules = $this->removeNeedlesFromHaystack($removals, $dependencies);
    }
    $this->install_profile_modules = $modules;
  }

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

  public static function installProfilesIncludedByProfile($profile) {
    $installer = new self($profile);
    $installer->install();
  }

  /**
   * Run install scripts.
   *
   * This runs after the baseprofile's install hook has already run and after
   * modules have been installed.
   *
   * By default, this simply invokes hook_install for included profiles.
   * For more advanced uses install profiles can modify the list of callbacks
   * invoked here.
   *
   * @see ProfileInstaller::getInstallTasks
   * @see profile_installer_install_profiles()
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

  /**
   * Invokes hook_install_tasks for profiles included by baseprofile.
   *
   * Note: Invoking this hook is a little weird. It's not an alter, but it
   * passes $install_state by reference. It also returns a result. It adds a lot
   * of complexity to run this through a generic method like
   * PetitionsInstaller::invokeHookWithParamsForState. Just handle all its
   * business here.
   *
   * @return array
   * @see hook_install_tasks
   */
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

    foreach ($invocations as $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $function = $this->getHookImplementation($implementation_info);
        $file = $this->getFileWithHookImplementation($implementation_info);

        $this->updateHookImplementationStatusToInvoked($implementation_info);

        include_once $file;
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
   * Provides install_state in case it was altered by hook_install_tasks.
   *
   * $install_state is passed by reference to hook_install_tasks.
   * If/When install_state is updated by any implementers of hook_install_tasks
   * it's stored in ProfileInstaller::$install_state and publicly accessible
   * via the getter here.
   *
   * WARNING: This should only be called by hook_install_tasks. That's the only
   * place we're tracking current install_state.
   *
   * @return array
   *   $install_state, for profiles implementing hook_install_tasks
   */
  public function getInstallState() {
    return $this->install_state;
  }

  /**
   * Invokes hook_install_tasks_alter() for included profiles.
   *
   * @param array $tasks
   *   @see hook_install_tasks_alter
   *
   * @param $install_state
   *   @see hook_install_tasks_alter
   *
   * @return array
   *   Altered tasks.
   */
  public function alterInstallTasks($tasks, $install_state) {
    // Use our own handler for installing profile's modules.
    $tasks['install_profile_modules']['function'] = 'profile_installer_install_modules';

    // Invoke included profiles' alters.
    $tasks = $this->invokeAlterOnDataForState('hook_install_tasks_alter', $tasks, $install_state);

    return $tasks;
  }

  /**
   * Invokes alter hooks for included profiles.
   *
   * @param $hook
   *   Drupal alter hook to be invoked.
   *   - hook_install_task_alter
   *   - hook_form_install_configure_form_alter
   *
   * @param $data
   *   Data to alter, e.g. $tasks, $form
   *
   * @param $state
   *   install state or form state
   *
   * @return mixed
   *   Altered data
   *
   * @throws Exception
   */
  private function invokeAlterOnDataForState($hook, &$data, $state) {
    // Hooks should only be invoked once per install state or form state,
    // otherwise it's easy to get trapped in a loop.
    if ($this->isNewStateForHookInvocations($state, $hook)) {
      $this->setUpNewHookInvocationsForState($hook, $state);
    }

    // Get invocations which should be called for current state.
    $invocations = $this->getHookInvocationsForState($hook, $state);

    // Loop through invocations. Only call functions not yet invoked.
    foreach ($invocations as $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $file = $this->getFileWithHookImplementation($implementation_info);
        $function = $this->getHookImplementation($implementation_info);

        // Update status to invoked before actually invoking in case invocation
        // calls the same alter function. Otherwise, we get trapped.
        $this->updateHookImplementationStatusToInvoked($implementation_info);

        // This is how Drupal core (install.core.inc) invokes
        // hook_install_tasks_alter (presumably drupal_alter isn't available yet).
        include_once $file;
        $function($data, $state);
      }
    }

    return $data;
  }

  /**
   * Invokes hooks before commands like module_invoke are available.
   *
   * NOTE: As of 10/24/14, this NOT actually used anywhere. Alter hooks--
   * hook_install_tasks_alter and hook_form_install_configure_form_alter--are
   * both routed through a simple generic function similart to this one. But
   * hook_install_tasks and hook_install both have their own special uses that
   * make this approach impractical for them.
   *
   * @todo If nobody uses this soon, phase it out or refactor it to make it relevant.
   *
   * @param $hook
   *   Hook being invoked.
   *
   * @param $params
   *   Params to pass to hook invocation.
   *
   * @param $state
   *   Install state or form state, if available. Hooks will only be invoked once
   *   per state (or once at all if no state is provided).
   *
   * @return array
   *   Results, if any are returned.
   *
   * @throws Exception
   */
  private function invokeHookWithParamsForState($hook, $params, $state = array()) {
    // Hooks should only be invoked once per install state or form state,
    // otherwise it's easy to get trapped in a loop.
    if ($this->isNewStateForHookInvocations($state, $hook)) {
      $this->setUpNewHookInvocationsForState($hook, $state);
    }

    $invocations = $this->getHookInvocationsForState($hook, $state);

    $results = array();
    foreach ($invocations as $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $file = $this->getFileWithHookImplementation($implementation_info);
        $function = $this->getHookImplementation($implementation_info);

        $this->updateHookImplementationStatusToInvoked($implementation_info);

        include_once $file;
        $result = call_user_func($function, $params);

        if (is_array($result)) {
          array_merge($results, $result);
        }

      }
    }

    return $results;
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

  /**
   * Registers hooks to be invoked for a particular install state or form state.
   *
   * Keeping track of hook invocations prevents us from getting trapped in a
   * loop when more than one profile uses ProfileInstaller.
   *
   * @param $hook
   *   Hook to be invoked.
   *
   * @param array $state
   *   Form state or install state.
   *
   * @throws Exception
   */
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
      $this->hook_invocations[$hook][$key][$function]['hook'] = $hook;
    }
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

  /**
   * Determines whether hook has been invoked yet.
   *
   * @param array $implementation_info
   *   An single hook invocation from the ProfileInstaller::hook_invocations
   *   array. Keys:
   *   - function
   *   - file
   *   - invoked
   *   - key
   *   - hook
   *  @see ProfileInstaller::setUpNewHookInvocationsForState
   *
   * @return bool
   *
   * @throws Exception
   */
  private function hookImplementationHasNotBeenInvoked($implementation_info) {
    $hook = $implementation_info['hook'];
    $key = $implementation_info['key'];
    $function = $implementation_info['function'];

    if (!empty($this->hook_invocations[$hook][$key][$function])) {
      $has_been_invoked = $this->hook_invocations[$hook][$key][$function]['invoked'];
      return !$has_been_invoked;
    }

    // If we get to here, something has gone wrong.
    throw new Exception('Something went wrong. $implementation_info does not include the info needed or hook was not set up for state properly (see ProfileInstaller::setUpNewHookInvocationsForState).');
  }

  /**
   * hook_invocations keeps track of hooks invoked. Update hook "invoked".
   *
   * @param array $implementation_info
   *  @see ProfileInstaller::setUpNewHookInvocationsForState
   */
  private function updateHookImplementationStatusToInvoked(array $implementation_info) {
    $key = $implementation_info['key'];
    $function = $implementation_info['function'];
    $hook = $implementation_info['hook'];

    $this->hook_invocations[$hook][$key][$function]['invoked'] = TRUE;
  }

  /**
   * Get absolute path to file including hook implementation.
   *
   * @param array $implementation_info
   *  @see ProfileInstaller::setUpNewHookInvocationsForState
   *
   * @return string
   */
  private function getFileWithHookImplementation(array $implementation_info) {
    return $implementation_info['file'];
  }

  private function getHookImplementation(array $implementation_info) {
    return $implementation_info['function'];
  }

  /**
   * Get hook implementations to be invoked for designated state.
   *
   * hook_invocations keeps track of hooks invoked per install state and form
   * state so we don't invoke the same hook implementation multiple times per state.
   *
   * @param string $hook
   *   Hook we want info about.
   *
   * @param array $state
   *   Install state or form state.
   *
   * @return array
   *  @see ProfileInstaller::setUpNewHookInvocationsForState
   */
  private function getHookInvocationsForState($hook, array $state) {
    $key = $this->getKeyForArray($state);
    $invocations = !empty($this->hook_invocations[$hook][$key]) ? $this->hook_invocations[$hook][$key] : array();
    return $invocations;
  }

  /**
   * Get a list of function names implementing specified hook.
   *
   * @param string $hook
   *   Hook we're interested in.
   *
   * @return array
   *   List of hook implementations.
   */
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

  function alterInstallConfigureForm($form, $form_state) {
    $form = $this->invokeAlterOnDataForState('hook_form_install_configure_form_alter', $form, $form_state);
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

  private static function getSupportedHooks() {
    return array(
      'hook_install_tasks',
      'hook_install_tasks_alter',
      'hook_form_install_configure_form_alter',
    );
  }

  private static function getKeyForArray(array $array) {
    return md5(serialize($array));
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

  public function getBaseProfilePath() {
    return $this->baseprofile_path;
  }

}
