<?php
/**
 * @file HookUtility.php
 * Provides HookUtility class.
 */

/**
 * HookUtility extends Drupal hook system to included profiles.
 *
 * "Out of the box" Drupal core only acknowledges a single profile at a time.
 * HookUtility detects hooks in additional included profiles and gives
 * ProfileInstaller the ability to invoke them.
 */
class HookUtility {
  // Top-level profile being installed.
  private $profile;

  // Each hook should only be invoked once per install state or form state,
  // otherwise it's easy to get trapped in a loop. Keep track here.
  private $hook_invocations;

  // Included profiles' install hooks are organized here. Install profiles can
  // examine, reorder, and modify this list of callbacks as needed.
  private $install_callbacks;

  public function __construct(Profile $profile) {
    $this->profile = $profile;
    $this->hook_invocations = array();
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
   * Custom handler for install hook invocation.
   *
   * By default this just calls all included install profile's install hooks.
   * But it also provides additional options for more complex use cases, which
   * is why we're not calling a generic handler like HookUtility::invokeHook()
   * for hook_install.
   *
   * Higher level profiles may need to manually handle database collisions among
   * included, lower level, profiles' install hooks. (For example, see
   * standard2.) Giving profiles access to get and set install callbacks before
   * they fire provides a simple way to accommodate situations like these.
   */
  public function invokeHookInstall() {
    foreach ($this->getInstallCallbacks() as $callback => $path) {
      include_once $path;
      call_user_func($callback);
    }
  }

  /**
   * Returns install callbacks. Defaults to included profiles' install hooks.
   *
   * @return array
   *   Array of files containing install callbacks keyed by funciton name.
   */
  public function getInstallCallbacks() {
    if (empty($this->install_callbacks)) {
      $this->setInstallCallbacks();
    }

    return $this->install_callbacks;
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
  public function invokeAlterOnDataForState($hook, &$data, $state) {
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
   * Invoke hook_install_tasks for included profiles.
   *
   * NOTE: Drupal passes &$install_state to implementers of this hook by
   * reference. This makes the implementation here less generic than the basic
   * hook invocation methods (invokeHook, invokeHookWithParams,
   * invokeHookWithParamsForState). So, to keep things simple, this is
   * specifically for hook_install_tasks.
   *
   * @param array $state
   *   Install state .
   *
   * @return array
   *   Array of tasks.
   *   @see hook_install_tasks
   */
  public function invokeHookInstallTasks(&$state) {
    $hook = 'hook_install_tasks';
    $results = array();

    $invocations = $this->getHookInvocationsForState($hook, $state);

    foreach ($invocations as $implementation_info) {
      if ($this->hookImplementationHasNotBeenInvoked($implementation_info)) {
        $function = $this->getHookImplementation($implementation_info);
        $file = $this->getFileWithHookImplementation($implementation_info);

        $this->updateHookImplementationStatusToInvoked($implementation_info);

        include_once $file;
        $more_results = $function($state);
        $results = array_merge($results, $more_results);
      }
    }

    return $results;
  }

  /**
   * Get hook implementations to be invoked for designated state.
   *
   * hook_invocations keeps track of hooks invoked per install state and form
   * state so we don't invoke the same hook implementation multiple times per
   * state. Otherwise, it's easy to get trapped in a loop.
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
    // Keep track of state. Only invoke hooks once per state, so we don't get
    // trapped in a loop.
    if ($this->isNewStateForHookInvocations($state, $hook)) {
      $this->setUpNewHookInvocationsForState($hook, $state);
    }

    $key = $this->getKeyForState($state);
    $invocations = !empty($this->hook_invocations[$hook][$key]) ? $this->hook_invocations[$hook][$key] : array();

    return $invocations;
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
    $key = $this->getKeyForState($state);
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
    if (empty($this->profile->included_hook_implementations[$hook])) {
      // $hook is not implemented by included profiles.
      return;
    }

    $implementations = $this->profile->included_hook_implementations[$hook];
    $key = $this->getKeyForState($state);

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

  private function getHookImplementation(array $implementation_info) {
    return $implementation_info['function'];
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
   * Provides a hash key for an arbitrary array.
   *
   * @param array $array
   *   Any array.
   *
   * @return string
   *   MD5 hash.
   */
  private static function getKeyForArray(array $array) {
    return md5(serialize($array));
  }

  /**
   * Provides a hash key for form state and install state.
   * @see HookUtility::getKeyForArray().
   */
  private static function getKeyForState(array $array) {
    return self::getKeyForArray($array);
  }

  /*****************************************************************************
   * As of 11/24/14, utilities for invoking hooks below are not actually used
   * anywhere right now. But they seem potentially very useful if this utility
   * needs to provide support for more hooks. So, don't remove them yet.
   *
   * Here's a summary of how we're currently handling supported hooks (@see
   * ProfileUtility::getSupportedHooks().
   *
   * Supported alter hooks use HookUtility::invokeAlterOnDataForState:
   *
   * - hook_install_tasks_alter
   * - hook_form_install_configure_form_alter
   *
   * These hooks have custom handling:
   *
   * - hook_install_tasks, generic solution below doesn't work with
   *   install_state passed by reference
   * - hook_install, generic solution below doesn't enable parent profiles to
   *   anticipate and resolve conflicts between included profiles' install hooks
   *   (for example see standard2)
   ****************************************************************************/

  /**
   * Invokes hooks before commands like module_invoke are available.
   *
   * @param $hook
   *   Hook being invoked.
   *
   * @param $params
   *   (Optional) Params to pass to hook invocation.
   *
   * @param $state
   *   (Optional) Install state or form state, if available. Hooks will only be invoked once
   *   per state (or once at all if no state is provided).
   *
   * @return array
   *   Results, if any are returned.
   *
   * @throws Exception
   */
  public function invokeHookWithParamsForState($hook, $params = array(), $state = array()) {
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
          $results = array_merge($results, $result);
        }

      }
    }

    return $results;
  }

  /**
   * Invokes Drupal hook in included profiles with specified params.
   * @see HookUtility::invokeHookWithParamsForState()
   */
  public function invokeHookWithParams($hook, $params) {
    return $this->invokeHookWithParamsForState($hook, $params);
  }

  /**
   * Invokes Drupal hook in included profiles.
   * @see HookUtility::invokeHookWithParamsForState()
   */
  public function invokeHook($hook) {
    return $this->invokeHookWithParamsForState($hook);
  }

}
