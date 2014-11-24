<?php

/**
 * @file ProfileInstaller.php
 * Provides ProfileInstaller class.
 */
require_once __DIR__ . '/Profile.php';
require_once __DIR__ . '/ProfileUtility.php';
require_once __DIR__ . '/InstallUtility.php';

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
    $this->install_utility = new InstallUtility($this->profile);
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
   * This runs after the top-level profile's install hook has already run and after
   * modules have been installed.
   *
   * By default, this simply invokes hook_install for included profiles.
   * For more advanced uses install profiles can modify the list of callbacks
   * invoked here (see example in standard2 profile).
   *
   * @see ProfileInstaller::getInstallTasks
   * @see profile_installer_install_profiles()
   */
  public function install() {
    foreach ($this->getInstallCallbacks() as $callback => $path) {
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

    $results = $this->install_utility->invokeHookInstallTasks($install_state);
    $tasks = array_merge($tasks, $results);

    // Store this so it can be returned in case anyone alters it, when passed by
    // reference in hook_install_tasks;
    // @todo Revisit. See if this still works after refactor. Update as necessary.
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
    $tasks = $this->install_utility->invokeAlterOnDataForState('hook_install_tasks_alter', $tasks, $install_state);

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
    $form = $this->install_utility->invokeAlterOnDataForState('hook_form_install_configure_form_alter', $form, $form_state);
    return $form;
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
