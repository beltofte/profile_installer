<?php

/**
 * @file ProfileInstaller.php
 * Provides ProfileInstaller class.
 */
require_once __DIR__ . '/Profile.php';
require_once __DIR__ . '/ProfileUtility.php';

/**
 * ProfileInstaller installs profiles included by the top-level profile.
 *
 * This is as close as we can get to a controller class without refactoring core.
 * It's not really accurate to describe this as the controller. The controller
 * is really the top-level profile which instantiates ProfileInstaller. It works
 * like this: As Drupal invokes various install hooks, the top-level profile gives
 * ProfileInstaller a chance to include other profiles. The basic
 * flow (which may vary depending on tasks and alters added by profiles) is this:
 *
 * 1. myprofile_install_tasks calls ProfileInstaller::getInstallTasks, registers
 *    profile_installer_install_profiles and any other tasks provided by included
 *    profiles
 *
 * 2. myprofile_install_tasks_alter calls ProfileInstaller::alterInstallTasks,
 *    replaces core profile_install_modules with profile_installer_install_modules
 *    and gives included profiles an opportunit to alter tasks
 *
 * 3. profile_installer_install_modules installs dependencies
 *
 * 4. myprofile_install, top-level profile's install hook runs
 *
 * 5. myprofile_form_install_configure_form_alter calls
 *    ProfileInstaller::alterInstallConfigureForm and gives included profiles an
 *    opportunity to alter install_configure_form
 *
 * 6. profile_installer_install_profiles runs included profiles' install hooks
 */
class ProfileInstaller {

  // ProfileInstaller is a singleton. ::getInstallerForProfile stores instance here.
  private static $instance;

  // Data transfer object. Corresponds to the profile being installed.
  public $profile;

  // Each hook should only be invoked once per install state or form state,
  // otherwise it's easy to get trapped in a loop. Keep track here.
  private $hook_invocations;

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
   *   Parent profile, or "baseprofile" which includes other profiles via info file and
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
  private function __construct($profile_name) {
    $this->profile = new Profile($profile_name, new ProfileUtility());
    $this->setInstallCallbacks();
  }

  /**
   * Set install callbacks to run during installation.
   *
   * These callbacks run after modules have been installed and after
   * the top-level profile's install script has run.
   *
   * Defaults to using all included profiles' install hooks. This list of
   * callbacks can be inspected, modified, and overridden by any profile that
   * instantiates ProfileInstaller.
   *
   * @param array $callbacks
   *   Associative array of files containing callbacks, keyed by function name.
   */
  public function setInstallCallbacks($callbacks = array()) {
    if (empty($callbacks)) {
      $included_hook_implementations = $this->profile->included_hook_implementations;
      foreach ($included_hook_implementations['hook_install'] as $function => $file) {
        $callbacks[$function] = $file;
      }
    }

    $this->install_callbacks = $callbacks;
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

  /**
   * Invokes hook_install_tasks and adds task to install included profiles.
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
   * Invokes hook_install_tasks_alter and adds our own handler for module installation.
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
   * Enables included profiles to alter install_configure_form.
   *
   * @param array $form
   * @param array $form_state
   * @return mixed
   */
  function alterInstallConfigureForm($form, $form_state) {
    $form = $this->invokeAlterOnDataForState('hook_form_install_configure_form_alter', $form, $form_state);
    return $form;
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

  private static function getKeyForArray(array $array) {
    return md5(serialize($array));
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
    if (empty($this->profile->included_hook_implementations[$hook])) {
      // $hook is not implemented by included profiles.
      return;
    }

    $implementations = $this->profile->included_hook_implementations[$hook];
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

  public function removeInstallProfileModules(array $modules) {
    $dependencies = $this->getInstallProfileModules();
    $dependencies = $this->removeNeedlesFromHaystack($modules, $dependencies);
    $this->setInstallProfileModules($dependencies);
  }

  public function getInstallCallbacks() {
    if (empty($this->install_callbacks)) {
      $this->setInstallCallbacks();
    }

    return $this->install_callbacks;
  }

}
