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
 * @see http://php.net/spl
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
  const INSTALLER_GET_SUBPROFILES               = 'getSubprofiles';
  const INSTALLER_ALTER_SUBPROFILES             = 'alterSubprofiles';
  const INSTALLER_GET_DEPENDENCIES              = 'getDependencies';
  const INSTALLER_ALTER_DEPENDENCIES            = 'alterDependencies';
  const INSTALLER_GET_INSTALL_TASKS             = 'getInstallTasks';
  const INSTALLER_ALTER_INSTALL_TASKS           = 'alterInstallTasks';
  const INSTALLER_INSTALL                       = 'install';
  const INSTALLER_ALTER_INSTALL_CONFIGURE_FORM  = 'alterInstallConfigureForm';
  const INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM = 'submitInstallConfigureForm';

  // Store a list of subprofiles as an array keyed by name, value pointing to
  // file containing subprofile class.
  private $subprofiles;

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

  // Instance of Installer.
  private $instance;

  /**
   * Constructor is private. Instantiate via Installer::get.
   */
  private function __construct() {
    // Use PHP SPL storage for managing attached subprofiles/observers.
    $this->storage = new SplObjectStorage();
    // Instantiate and attach subprofiles as observers, to be notified as
    // different install events fire.
    $this->attachSubprofiles();
    $this->alterSubprofiles();
    $this->getDependencies();
    $this->alterDependencies();
  }

  /**
   * Attach subprofiles/observers.
   */
  function attachSubprofiles() {
    $this->setHook(self::INSTALLER_GET_SUBPROFILES);
    foreach ($this->getSubprofiles() as $name => $properties) {
      require_once $properties['path']; // @todo  Review/Revise.   CONTINUE HERE.
      $this->attach(new $properties['class_name'])
    }
    $this->notify();
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
  public function attach( SplObserver $observer ) {
    $this->storage->attach( $observer );
  }

  public function detach( SplObserver $observer ) {
    $this->storage->detach( $observer );
  }

  public function notify() {
    foreach ( $this->storage as $obs ) {
      $obs->update( $this );
    }
  }

  /**
   * InstallProfile interface. =================================================
   */
  public function getSubprofiles() {
    if (empty($this->subprofiles)) {
      // Find subprofiles. First check install profile's info file.
      $subprofile_names = $this->getSubprofilesFromInfoFile();

      // Next check subprofile settings from settings.php.
      if ($settings_subprofiles = variable_get('subprofiles', FALSE)) {
        $subprofile_names = array_merge($subprofile_names, $settings_subprofiles);
      }

      // Get path and class names.
      $subprofiles = array();
      foreach ($subprofile_names as $name) {

        // CONTINUE HERE. Finish implementing this...

        $subprofiles[$name]['name'] = $name;
        $subprofiles[$name]['path'] = ''; // @todo
        $subprofiles[$name]['class_name'] = ''; // @todo
      }
    }

    return $this->subprofiles;
  }

  public function alterSubprofiles() {
    // @todo
  }
  public function getDependencies() {
    // Only retrieve dependencies from subprofiles once.
    if (empty($this->dependencies)) {
      $this->setHook(INSTALLER_GET_DEPENDENCIES);
      $this->notify();
    }
    return $this->dependencies;
  }

  public function alterDependencies() {
    $this->setHook(INSTALLER_ALTER_DEPENDENCIES);
    $this->notify();
    return $this->getDependencies();
  }

  public function getInstallTasks($install_state) {
    // Only retreive install tasks from subprofiles once. If $tasks have been
    // populated, this has already been executed.
    if (empty($this->tasks)) {
      $this->setHook(INSTALLER_GET_INSTALL_TASKS);
      $this->setInstallState($install_state);
      $this->notify();

      // CONTINUE HERE
        // Add tasks for installing dependencies.

        // Fix anti pattern?
         // getInstallTasks
         // getTasks [REMOVE]
         // setTasks -> setInstallTasks
         // $tasks -> installTasks   (?)
    }
    return $this->getTasks();
  }

  public function alterInstallTasks($tasks, $install_state) {
    $this->setHook(INSTALLER_ALTER_INSTALL_TASKS);
    $this->setInstallState($install_state);
    $this->notify();
    return $this->getTasks();
  }

  public function install() {
    $this->setHook(INSTALLER_INSTALL);
    $this->notify();
  }

  public function alterInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHook(INSTALLER_ALTER_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  public function submitInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHook(INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  /**
   * Getters and setters. ======================================================
   */
  public function getHook() {
    return $this->hook;
  }

  public function setHook( $hook ) {
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

  public function getInstallState() {
    $this->_checkGetter('install_state', array(
      self::INSTALLER_GET_INSTALL_TASKS,
      self::INSTALLER_ALTER_INSTALL_TASKS
    ));
    return $this->install_state;
  }

  public function setInstallState($install_state) {
    $this->_checkSetter('install_state', 'set', array(self::INSTALLER_GET_INSTALL_TASKS));
    $this->install_state = $install_state;
  }

  public function setDependencies(array $dependencies) {
    $this->_checkSetter('dependencies', 'set', array(
      self::INSTALLER_GET_DEPENDENCIES,
      self::INSTALLER_ALTER_DEPENDENCIES,
    ));
    $this->dependencies = $dependencies;
  }

  public function addDependencies(array $dependencies) {
    $this->_checkSetter('dependencies', 'added', array(
      self::INSTALLER_GET_DEPENDENCIES,
      self::INSTALLER_ALTER_DEPENDENCIES,
    ));
    $this->dependencies = array_merge($$this->dependencies, $dependencies);
  }

  public function getTasks() {
    return $this->tasks;
  }

  public function setTasks(array $tasks) {
    $this->_checkSetter('tasks', 'set', array(
      self::INSTALLER_GET_INSTALL_TASKS,
      self::INSTALLER_ALTER_INSTALL_TASKS,
    ));
    $this->tasks = $tasks;
  }

  public function addTasks(array $tasks) {
    $this->_checkSetter('tasks', 'added', array(
      self::INSTALLER_GET_INSTALL_TASKS,
      self::INSTALLER_ALTER_INSTALL_TASKS,
    ));
    $this->tasks = array_merge($this->tasks, $tasks);
  }

  public function getForm() {
    $this->_checkGetter('form', array(
      self::INSTALLER_ALTER_INSTALL_CONFIGURE_FORM,
      self::INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->form;
  }

  public function setForm($form) {
    $this->_checkSetter('form', 'set', array(self::INSTALLER_ALTER_INSTALL_CONFIGURE_FORM));
    $this->form = $form;
  }

  public function getFormState() {
    $this->_checkGetter('form', array(
      self::INSTALLER_ALTER_INSTALL_CONFIGURE_FORM,
      self::INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->form_state;
  }

  public function setFormState($form_state) {
    $this->_checkSetter('form_state', 'set', array(
      self::INSTALLER_ALTER_INSTALL_CONFIGURE_FORM,
      self::INSTALLER_SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    $this->form_state = $form_state;
  }


    /**
     * @param string $path
     * @return array
     */
    public static function getSubprofilesFromInfoFile($path = '') {
    // If no path was passed in, assume this is being called from inside
    // parent/base install profile. Check the profile's info file.
    if (!$path) {
      $profile_path = dirname(__FILE__);
      $profile_name = basename($profile_path);
      $path = "{$profile_path}/{$profile_name}.info";
    }

    $info = drupal_parse_info_file($path);
    if (isset($info['subprofiles'])) {
      return $info['subprofiles'];
    }
    else {
      return array();
    }
  }

   /**
    * Validate setter functions. Throws exception if invalid.
    *
    * This is to prevent people from attempting to hook into the install process
    * in the wrong way at the wrong state.
    *
    * @param string $nouns
    *   Some property, e.g. tasks, dependencies.
    *
    * @param string $verbed
    *   Some verbe, e.g. added, set.
    *
    * @param array $hooks
    *   "hooks"/methods during which the property being changed is available to
    *   be changed.
    *
    * @throws Exception
    *   Notify user about how to correct their mistake when an exception is thrown.
    */
  private function _checkSetter(string $nouns, string $verbed, array $hooks) {
    if (!in_array($this->getHook(), $hooks)) {
      throw new Exception("{$nouns} can only be {$verbed} via " . implode(', ', $hooks));
    }
  }

    /**
     * Validate getter functions. Throws an exception if invalid.
     *
     * Some properties, like install_state, are only available during certain
     * "hook" invocations, to prevent confusion.
     *
     * @param string $property
     *   Name of property being requested.
     *
     * @param array $hooks
     *   Hook invocations in which the request is valid.
     *
     * @throws Exception
     *   Notify user about how to correct their mistake when exception is thrown.
     */
    private function _checkGetter(string $property, array $hooks){
    if (!in_array($this->getHook(), $hooks)) {
      throw new Exception("{$property} only available via " . implode(', ', $hooks));
    }
  }

}
