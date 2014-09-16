<?php
/**
 * @file Installer.php
 * Provides Installer abstract class.
 */

/**
 * Provides an installer for Drupal install profiles which subprofiles can extend.
 *
 * Implements Observer design pattern using the Standard PHP Library's (SPL)
 * SplSubject interface and SplObjectStorage class. Installer is the subject.
 * Subprofile (extending the Subprofile class) are observers.
 *
 * Implements Singleton design pattern. Enables a single instance of Installer to
 * control the installation process. A base profile makes itself subprofile-able by
 * instantiating Installer, implementing standard Drupal hooks for install profiles,
 * and handing off control to the installer.
 *
 * @see http://php.net/manual/en/class.splsubject.php
 * @see http://php.net/manual/en/class.splobjectstorage.php
 */
abstract class Installer implements SplSubject, InstallProfile {

  /**
   * These constants represent different states of the installation process
   * where install profiles hook in. Installer enables subprofiles to extend by
   * by hooking into the same places.
   *
   * Constants map to sensible method names to make things easy for Subprofile
   * implementers who think of these as hooks.
   *
   * @see Subprofile::update
   */
  const INSTALLER_GET_DEPENDENCIES              = 'getDependencies';
  const INSTALLER_ALTER_DEPENDENCIES            = 'alterDependencies';
  const INSTALLER_GET_INSTALL_TASKS             = 'getInstallTasks';
  const INSTALLER_ALTER_INSTALL_TASKS           = 'alterInstallTasks';
  const INSTALLER_INSTALL                       = 'install';
  const INSTALLER_ALTER_INSTALL_CONFIGURE_FORM  = 'alterInstallConfigureForm';
  const INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM = 'submitInstallConfigureForm';

  // Store attached subprofiles ("observers") here.
  private $storage;

  // Keep track of which "hook" is being "invoked", that is, which install method
  // has been called by the base profile.
  private $hook;

  // Drupal install state passed by reference during hook_install_tasks, and as
  // context via hook_install_tasks_alter.
  private $install_state;

  // Array of tasks to be returned by base profile via hook_install_tasks or
  // modified via hook_install_tasks_alter.
  private $tasks;

  // Modules declared as dependencies by subprofiles, to be installed by Installer.
  private $dependencies;

  // Dependencies successfully installed.
  private $installed;

  // Variables available via hook_form_FORM_ID_alter and submit handler for
  // install_configure_form.
  private $form;
  private $form_state;

  /**
   * Constructor is private. Instantiate via Installer::get.
   */
  private function __construct() {
    $this->storage = new SplObjectStorage();  
  }

  /**
   * Installer is a singleton. This method provides a public function to instantiate Installer.
   *
   * @return obj
   *   Installer instance.
   */
  public static function get() {
    if (empty( self::$instance )) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * SplObserver interface. ====================================================
   */
  function attach( SplObserver $observer ) {
    $this->storage->attach( $observer );
  }

  function detach( SplObserver $observer ) {
    $this->storage->detach( $observer );
  }

  function notify() {
    foreach ( $this->storage as $obs ) {
      $obs->update( $this );
    }
  }

  /**
   * InstallProfile interface. =================================================
   */
  function getDependencies() {
    $this->setHook(INSTALLER_GET_DEPENDENCIES);
    $this->notify();
    return $this->dependencies;
  }

  function alterDependencies() {
    $this->setHook(INSTALLER_ALTER_DEPENDENCIES);
    $this->notify();
  }

  function getInstallTasks($install_state) {
    $this->setHook(INSTALLER_GET_INSTALL_TASKS);
    $this->setInstallState($install_state);
    $this->notify();
    return $this->tasks;
  }

  function alterInstallTasks($tasks, $install_state) {
    $this->setHook(INSTALLER_ALTER_INSTALL_TASKS);
    $this->setInstallState($install_state);
    $this->notify();
    return $this->tasks;
  }

  function install() {
    $this->setHook(INSTALLER_INSTALL);
    $this->notify();
  }

  function alterInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHook(INSTALLER_ALTER_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  function submitInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHook(INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  /**
   * Getters and setters. ======================================================
   */
  function getHook() {
    return $this->hook;
  }

  function setHook( $hook ) {
    if (!$this->isValidHook($hook)) {
      throw new Exception("Cannot set an invalid state: {$hook}");
    }
    $this->hook= $hook;
  }

  /**
   * Validate $hook is a valid hook name.
   *
   * @param $hook
   * @return bool
   */
  function isValidHook( $hook ) {
    $valid = array(
      self::INSTALLER_GET_DEPENDENCIES,
      self::INSTALLER_ALTER_DEPENDENCIES,
      self::INSTALLER_GET_INSTALL_TASKS,
      self::INSTALLER_ALTER_INSTALL_TASKS,
      self::INSTALLER_INSTALL,
      self::INSTALLER_ALTER_INSTALL_CONFIGURE_FORM,
      self::INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM,
    );
    return in_array($hook, $valid);
  }

  function getInstallState() {
    $available = array(
      self::INSTALLER_GET_INSTALL_TASKS,
      self::INSTALLER_ALTER_INSTALL_TASKS
    );
    if (!in_array($this->getHook(), $available)) {
      throw new Exception("install_state is not available");
    }

    return $this->install_state;
  }

  function setInstallState($install_state) {
    $this->install_state = $install_state;
  }

  function setDependencies(array $dependencies) {
    $this->dependencies = $dependencies;
  }

  function addDependencies(array $dependencies) {
    $this->dependencies = array_merge($$this->dependencies, $dependencies);
  }

}
