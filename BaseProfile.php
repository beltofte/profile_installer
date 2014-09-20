<?php
/**
 * @file BaseProfile.php
 * Provides BaseProfile class.
 */

/**
 * Provides BaseProfile class to manage installation for Drupal install profiles.
 *
 * Install profiles using BaseProfile to manage installation are "base profiles".
 * Base profiles can be extended by sub profiles.
 *
 * Implements Observer design pattern using the Standard PHP Library's (SPL)
 * SplSubject interface and SplObjectStorage class. BaseProfile is the subject.
 * SubProfiles are observers.
 *
 * Implements Singleton design pattern. Enables a single instance of BaseProfile to
 * control the installation process. A Drupal install profile makes itself subprofile-able by
 * instantiating BaseProfile, implementing standard Drupal hooks for install profiles,
 * and handing off control to BaseProfile.
 *
 * @see http://php.net/spl
 * @see http://php.net/manual/en/class.splsubject.php
 * @see http://php.net/manual/en/class.splobjectstorage.php
 */
class BaseProfile implements SplSubject, InstallProfile {

  /**
   * These constants represent different stages of the installation process
   * where Drupal enables install profiles to hook in. BaseProfile notifies
   * SubProfiles about these events ane enables them to hook in here too, along
   * with the base profile.
   *
   * Constants map to sensible method names to make things easy for SubProfile
   * implementers.
   *
   * @see SubProfile::update
   */
  const GET_DEPENDENCIES              = 'getDependencies';
  const ALTER_DEPENDENCIES            = 'alterDependencies';
  const GET_INSTALL_TASKS             = 'getInstallTasks';
  const ALTER_INSTALL_TASKS           = 'alterInstallTasks';
  const INSTALL                       = 'install';
  const ALTER_INSTALL_CONFIGURE_FORM  = 'alterInstallConfigureForm';
  const SUBMIT_INSTALL_CONFIGURE_FORM = 'submitInstallConfigureForm';

  private $subprofile_storage;
  private $hook_invoked;

  // Drupal install state passed by reference during hook_install_tasks, and as
  // context via hook_install_tasks_alter.
  private $install_state;

  // Array of tasks to be returned by base profile via hook_install_tasks or
  // modified via hook_install_tasks_alter.
  private $tasks;

  // Projects declared as dependencies by sub profiles.
  private $dependencies;

  // Dependencies successfully installed.
  private $installed;

  // Variables available via hook_form_FORM_ID_alter and submit handler for
  // install_configure_form.
  private $form;
  private $form_state;

  // Instance of BaseProfile.
  private $instance;

  /**
   * Constructor is private. Instantiate via BaseProfile::get.
   */
  private function __construct() {
    // Use PHP SPL storage for managing attached subprofiles/observers.
    $this->subprofile_storage= new SplObjectStorage();
  }

  /**
   * BaseProfile is a singleton. This method provides a public function to instantiate BaseProfile.
   *
   * @return obj
   *   BaseProfile instance.
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
    $this->subprofile_storage->attach( $observer );
  }

  public function detach( SplObserver $observer ) {
    $this->subprofile_storage->detach( $observer );
  }

  public function notify() {
    foreach ( $this->subprofile_storage as $obs ) {
      $obs->update( $this );
    }
  }

  /**
   * InstallProfile interface. =================================================
   */
  public function getDependencies() {
    // Only retrieve dependencies from subprofiles once.
    if (empty($this->dependencies)) {
      $this->setHookInvoked(GET_DEPENDENCIES);
      $this->notify();
    }
    return $this->dependencies;
  }

  public function alterDependencies() {
    $this->setHookInvoked(ALTER_DEPENDENCIES);
    $this->notify();
    return $this->getDependencies();
  }

  public function getInstallTasks($install_state) {
    // Only retreive install tasks from subprofiles once. If $tasks have been
    // populated, this has already been executed.
    if (empty($this->tasks)) {
      $this->setHookInvoked(GET_INSTALL_TASKS);
      $this->setInstallState($install_state);
      $this->notify();

      // CONTINUE HERE
        // Add tasks for installing dependencies.
    }
    return $this->tasks();
  }

  public function alterInstallTasks($tasks, $install_state) {
    $this->setHookInvoked(ALTER_INSTALL_TASKS);
    $this->setInstallState($install_state);
    $this->notify();
    return $this->getInstallTasks();
  }

  public function install() {
    $this->setHookInvoked(INSTALL);
    $this->notify();
  }

  public function alterInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHookInvoked(ALTER_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  public function submitInstallConfigureForm($form, $form_state) {
    $this->form = $form;
    $this->form_state = $form_state;
    $this->setHookInvoked(SUBMIT_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  /**
   * Getters and setters. ======================================================
   */
  public function getHookInvoked() {
    return $this->hook_invoked;
  }

  public function setHookInvoked( $hook_invoked ) {
    if (!$this->isValidHook($hook_invoked)) {
      throw new Exception("Cannot set an invalid state: {$hook_invoked}");
    }
    $this->hook_invoked= $hook_invoked;
  }

  /**
   * Validate $hook_invoked is a valid hook name.
   *
   * @param $hook_invoked
   * @return bool
   */
  function isValidHook( $hook_invoked ) {
    $valid = array(
      self::GET_DEPENDENCIES,
      self::ALTER_DEPENDENCIES,
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS,
      self::INSTALL,
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    );
    return in_array($hook_invoked, $valid);
  }

  public function getInstallState() {
    $this->_checkGetter('install_state', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS
    ));
    return $this->install_state;
  }

  public function setInstallState($install_state) {
    $this->_checkSetter('install_state', 'set', array(self::GET_INSTALL_TASKS));
    $this->install_state = $install_state;
  }

  public function setDependencies(array $dependencies) {
    $this->_checkSetter('dependencies', 'set', array(
      self::GET_DEPENDENCIES,
      self::ALTER_DEPENDENCIES,
    ));
    $this->dependencies = $dependencies;
  }

  public function addDependencies(array $dependencies) {
    $this->_checkSetter('dependencies', 'added', array(
      self::GET_DEPENDENCIES,
      self::ALTER_DEPENDENCIES,
    ));
    $this->dependencies = array_merge($$this->dependencies, $dependencies);
  }

  public function setInstallTasks(array $tasks) {
    $this->_checkSetter('tasks', 'set', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS,
    ));
    $this->tasks = $tasks;
  }

  public function addInstallTasks(array $tasks) {
    $this->_checkSetter('tasks', 'added', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS,
    ));
    $this->tasks = array_merge($this->tasks, $tasks);
  }

  public function getForm() {
    $this->_checkGetter('form', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->form;
  }

  public function setForm($form) {
    $this->_checkSetter('form', 'set', array(self::ALTER_INSTALL_CONFIGURE_FORM));
    $this->form = $form;
  }

  public function getFormState() {
    $this->_checkGetter('form', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->form_state;
  }

  public function setFormState($form_state) {
    $this->_checkSetter('form_state', 'set', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    $this->form_state = $form_state;
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
    if (!in_array($this->getHookInvoked(), $hooks)) {
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
    if (!in_array($this->getHookInvoked(), $hooks)) {
      throw new Exception("{$property} only available via " . implode(', ', $hooks));
    }
  }

}
