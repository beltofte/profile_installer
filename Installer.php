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
abstract class Installer implements SplSubject {

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
  // Keep track of which "hook" is being "invoked".
  private $hook;
  // Drupal install state.
  private $install_state;
  // Array of tasks to be returned to base profiles'
  private $tasks;
  private $dependencies;
  private $form;
  private $form_state;

  /**
   * Constructor is private. Instantiate via Installer::get.
   */
  private function __construct() {
    $this->storage = new SplObjectStorage();  
  }

  /**
   * Provides public function to instantiate Installer.
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
   * SplObserver interface.
   */
  function attach( Subprofile $observer ) {
    $this->storage->attach( $observer );
  }

  function detach( Subprofile $observer ) {
    $this->storage->detach( $observer );
  }

  function notify() {
    foreach ( $this->storage as $obs ) {
      $obs->update( $this );
    }
  }

  /**
   * CONTINUE HERE. implements hooks for notify/install subprofiling.
   */
    /*
  function getDependencies() {}
  function alterDependencies() {}
  function getInstallTasks() {}
  function alterInstallTasks() {}
  function install() {}
  function alterInstallConfigureForm() {}
  function submitInstallConfigureForm() {}
    */

    /**
    * Getters and setters.
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

}
