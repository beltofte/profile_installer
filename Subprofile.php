<?php
/**
 * @file Subprofile.php
 * Provides Subprofile abstract class.
 */

/**
 * Provides Subprofile class to extend Drupal install profiles.
 *
 * Implements Observer design pattern using the Standard PHP Library's (SPL)
 * SplObserver interface. Installer is the subject. Subprofiles (classes
 * extending the Subprofile class) are observers.
 *
 * @see http://php.net/spl
 * @see http://php.net/manual/en/class.splobserver.php
 *
 * @param SplSubject $subject
 */
abstract class Subprofile implements SplObserver, InstallProfile {
  private $name;
  private $installer;
  private $dependencies;

  function __construct( string $name, ProfileInstaller $installer ) {
    $this->name = $name;
    $this->installer = $installer;
  }

  /**
   * SplSubject interface. ======================================================
   *
   * @param SplSubject $subject
   */
    function update( SplSubject $subject ) {
    $installer = $this->installer;
    // Make sure update is being invoked by installer.
    if (!$subject === $installer) {
      return;
    }
    // If the subprofile implemented the "hook", invoke it.
    $hook = $installer->getHookInvoked();
    if (method_exists($this, $hook)) {
      $result = $this->$hook();
    }

    return $result;
  }

 /**
  * InstallProfile interface. ==================================================
  */
  public function getDependencies() {
    $installer = $this->installer;

    if (empty($this->dependencies)) {
      $this->setDependencies();
    }

    if ($installer->getHookInvoked() == $installer::GET_DEPENDENCIES) {
      $installer->addDependencies($dependencies);
    }

    return $this->dependencies;
  }

  /*
  public function alterDependencies();
  public function getInstallTasks();
  public function alterInstallTasks();
  public function install();
  public function alterInstallConfigureForm();
  public function submitInstallConfigureForm();
  // */

  /**
   * Getters and setters. ======================================================
   */

  public function setDependencies() {
    $this->dependencies = $this->getDependenciesFromInfoFile();
  }

  public function getDependenciesFromInfoFile() {
    $installer = $this->installer;
    $info_file = $installer->getSubprofileInfoFile($this->name);
    $info = drupal_parse_info_file($info_file);
    return $info['dependencies'];
  }

}
