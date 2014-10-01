<?php
/**
 * @file SubProfile.php
 * Provides SubProfile abstract class.
 */

/**
 * Provides SubProfile class to extend Drupal install profiles.
 *
 * Implements Observer design pattern using the Standard PHP Library's (SPL)
 * SplObserver interface. Installer is the subject. SubProfiles (classes
 * extending the SubProfile class) are observers.
 *
 * @see http://php.net/spl
 * @see http://php.net/manual/en/class.splobserver.php
 *
 * @param SplSubject $subject
 */
abstract class SubProfile implements SplObserver, InstallProfile {
  private $installer;

  function __construct( SplSubject $installer ) {
    $this->installer = $installer;
    $installer->attach( $this );
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
    $dependencies = $this->getDependenciesFromInfoFile();
    $this->installer->addDependencies($dependencies);
  }

  public function getDependenciesFromInfoFile() {

    // @todo get local info file.
    // $info_file = ... ;

    $info = drupal_parse_info_file($info_file);
    return $info['dependencies'];
  }

  /*
  public function alterDependencies();
  public function getInstallTasks();
  public function alterInstallTasks();
  public function install();
  public function alterInstallConfigureForm();
  public function submitInstallConfigureForm();
  // */

}
