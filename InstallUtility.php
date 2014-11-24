<?php
/**
 * @file InstallUtility.php
 * Provides InstallUtility class.
 */

/**
 * InstallUtility invokes hooks in included profiles as appropriate during installation.
 */
class InstallUtility {
  // Each hook should only be invoked once per install state or form state,
  // otherwise it's easy to get trapped in a loop. Keep track here.
  private $hook_invocations;

  private $profile;

  public function __construct(Profile $profile) {
    $this->profile = $profile;
    $this->hook_invocations = array();
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
   * @param string $hook
   *   Drupal hook to be invoked.
   *
   * @param array $state
   *   Install state or form state.
   */
  public function invokeHookForState($hook, $state) {
    $results = array();

    // Keep track of state. Only invoke hooks once per state, so we don't get
    // trapped in a loop.
    if ($this->isNewStateForHookInvocations($state, $hook)) {
      $this->setUpNewHookInvocationsForState($hook, $state);
    }

    // Give included profiles an opportunity to add tasks.
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

  private static function getKeyForArray(array $array) {
    return md5(serialize($array));
  }

  // ---------------------------------------------------------------------------

  /**
   * Invokes hooks before commands like module_invoke are available.
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
          $results = array_merge($results, $result);
        }

      }
    }

    return $results;
  }

}