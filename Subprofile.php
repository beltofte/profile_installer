<?php
/**
 * @file Subprofile.php
 * Provides Subprofile abstract class.
 */
require_once DRUPAL_ROOT . '/profiles/profileinstaller/InstallProfile.php';

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

  function __construct($name, ProfileInstaller $installer ) {
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
      $dependencies = $this->getDependenciesFromInfoFile();
      $this->setDependencies($dependencies);
    }
    $installer->addDependencies($this->dependencies);
    return $this->dependencies;
  }

  public function alterDependencies() {
    /*
    $dependencies = $this->installer->getDependencies();
    // Make changes to $dependencies here...
    $this->installer->setDependencies($dependencies);
    // */
  }

  public function getInstallTasks() {
    /*
    // Add your own custom tasks here...
    $my_tasks = array( ... );
    $this->installer->addInstallTasks($my_tasks);

    $install_state = $installer->getDrupalInstallState();
    // Modify install_state here...
    $this->installer->setDrupalInstallState($install_state);

    // Return value is not actually necessary for ProfileInstaller. But who
    // knows who else might call this? The function begins with the verb "get",
    // so return what was requested.
    return $my_tasks;
    // */
  }

  public function alterInstallTasks() {
    /*
    $tasks = $this->installer->getInstallTasks();
    // Make changes to $tasks here...
    $this->installer->setInstallTasks($tasks);
    // */
  }

  public function install() {
    // Execute custom install code here. This runs after install tasks complete.
    // Anything you might put in hook_install in a base profile can go here.
    // For example:
    //   variable_set('site_name', 'Hello World!');
  }

  public function alterInstallConfigureForm() {
    /*
    $form = $this->installer->getInstallConfigureForm();
    $form_state = $this->installer->getInstallConfigureFormState();
    // Do anything you'd do in hook_form_alter here....
    $this->installer->setInstallConfigureForm($form);
    // */
  }

  public function submitInstallConfigureForm() {
    /*
    $form = $this->installer->getInstallConfigureForm();
    $form_state = $this->installer->getInstallConfigureFormState();
    // Make changes to form_state or do custom form submission handling here...
    $this->installer->setInstallConfigureFormState($form_state);
    // */
  }

  /**
   * Getters and setters. ======================================================
   */

  public function setDependencies($dependencies) {
    $this->dependencies = $dependencies;
  }

  public function getDependenciesFromInfoFile() {
    $installer = $this->installer;
    $info_file = $installer->getSubprofileInfoFile($this->name);
    $info = drupal_parse_info_file($info_file);
    return $info['dependencies'];
  }

}
