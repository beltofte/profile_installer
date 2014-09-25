<?php
/**
 * @file ProfileInstaller.php
 * Provides BaseProfile class.
 */

/**
 * Provides BaseProfile class to manage installation for Drupal install profiles.
 *
 * Install profiles using BaseProfile to manage installation are "base profiles".
 * Base profiles can be extended by subprofiles.
 *
 * Implements Observer design pattern using the Standard PHP Library's (SPL)
 * SplSubject interface and SplObjectStorage class. BaseProfile is the subject.
 * Subprofiles are observers.
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
class ProfileInstaller implements SplSubject, InstallProfile {
  /**
   * These constants represent different stages of the installation process
   * where Drupal enables install profiles to hook in. BaseProfile notifies
   * Subprofiles about these events and enables them to hook in here too, along
   * with the base profile.
   *
   * Constants map to sensible method names to make things easy for Subprofile
   * implementers.
   *
   * @see Subprofile::update
   */
  const GET_DEPENDENCIES              = 'getDependencies';
  const ALTER_DEPENDENCIES            = 'alterDependencies';
  const GET_INSTALL_TASKS             = 'getInstallTasks';
  const ALTER_INSTALL_TASKS           = 'alterInstallTasks';
  const INSTALL                       = 'install';
  const ALTER_INSTALL_CONFIGURE_FORM  = 'alterInstallConfigureForm';
  const SUBMIT_INSTALL_CONFIGURE_FORM = 'submitInstallConfigureForm';

  // Stuff passed to install profile via Drupal hooks.
  private $drupal_install_state;
  private $install_tasks;
  private $install_configure_form;
  private $install_configure_form_state;

  // ProfileInstaller internals.
  private $baseprofile_name;
  private $baseprofile_path;
  private $subprofile_storage;
  private $subprofiles_details;

  private $dependencies;
  private $hook_invoked;
  private $instance;

  /**
   * Constructor is private. Instantiate via ProfileInstaller::getInstallerForProfile.
   */
  private function __construct($baseprofile_name) {
    $this->setBaseProfileName($baseprofile_name);
    $this->setBaseProfilePath();
    $this->subprofile_storage = new SplObjectStorage();
    $this->initializeAndAttachSubprofiles();
  }

  private function initializeAndAttachSubprofiles() {
    $subprofile_names = array_merge(
      $this->getSubprofileNamesFromInfoFile(),
      $this->getSubprofileNamesFromSiteSettings(),
    );

    foreach ($subprofile_names as $subprofile_name) {
      include_once $this->getSubprofileClassFile($subprofile_name);
      $subprofile_class = $this->getSubprofileClassName($subprofile_name);
      $this->attach( new $subprofile_class($subprofile_name, $this) );
    }
  }

  /**
   * @return bool
   */
  public static function isSubprofileClassFile($uri) {
    // If extension isn't .php, it's definitely not a valid class file.
    if (substr($uri) != '.php') {
      return FALSE;
    }

    // Now check for a valid match in project name and class name.
    $project_name = basename(dirname($uri));
    $file_name = basename($uri);
    $class_name = self::removeFileExtensionFromFile('.php', $file_name);
    $match = FALSE;

    // Match is case insensitive.
    strtolower($project_name);
    strtolower($class_name);

    // Check acceptable variations of class name.
    // Names like myproject and MyProject are valid.
    if ($project_name == $class_name) {
      $match = TRUE;
    }
    // Names like myproject and MyProjectSubprofile are valid.
    if ($project_name == substr($class_name, 0, -10)) {
      $match = TRUE;
    }

    return $match;
  }

  public static function removeFileExtensionFromFile($extension, $filename) {
    $length = strlen($extension);
    return substr($filename, 0, $length * -1);
  }


  private function loadSubprofileNamesFromInfoFile() {
    // @todo
    // $info_file = ...

    $info = drupal_parse_info_file($info_file);
    $result = (isset($info['subprofiles'])) ? $info['subprofiles'] : array();
    return $result;
  }

  private function getSubprofileNamesFromSiteSettings() {
      // @todo Verify variable_get works immediately after bootstrap. Otherwise use $conf or prevent initializing until later (like getDependencies).
    return variable_get('subprofiles', array());
  }

  private function getSubprofileNamesFromInfoFile() {
    $info_file = $this->getBaseProfilePath() . '/' . $this->getBaseProfileName() . '.info';
    $info = drupal_parse_info_file($info_file);
    $subprofile_names = (isset($info['subprofiles']) ? $info['subprofiles'] : array();
    return $subprofile_names;
  }

  /**
   * ProfileInstaller is a singleton. This method provides a public function to get an instance.
   *
   * @return obj
   *   ProfileInstaller instance.
   */
  public static function getInstallerForProfile($baseprofile_name) {
    if (self::baseProfileExists($baseprofile_name, TRUE)) {
      self::_getInstallerForProfile($baseprofile_name);
    }
  }

  public static function baseProfileExists($baseprofile_name, $raise_exception = FALSE) {
    $path = self::getPathToBaseProfile($baseprofile_name);
    $exists = is_dir($path);
    if (!$exists && $raise_exception) {
      throw new Exception("Profile does not exist: {$baseprofile_name}");
    }
    return $exists;
  }

  public static function getPathToBaseProfile($baseprofile_name) {
    return DRUPAL_ROOT . "/profiles/{$baseprofile_name}";
  }

  private static function _getInstallerForProfile($baseprofile_name) {
    if (empty(self::$instance)) {
      self::$instance = new self($baseprofile_name);
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
  public function getInstallTasks() {

    // Drupal invokes hook_install_tasks several times throughout the install
    // process. When this hook is invoked after install_system_module (which
    // sets the variable install_profile_modules) and before the function
    // install_profile_modules (which installs install profile modules and then
    // deletes this variable), we insert a hook to enable subprofiles to add/alter
    // dependencies too.
    if ($baseprofile_dependencies = variable_get('install_profile_modules', FALSE)) {
      $subprofile_dependencies = $this->getDependencies();
      $dependencies = array_unique(array_merge($baseprofile_dependencies, $subprofile_dependencies));
      $this->setDependencies($dependencies);
      $this->alterDependencies();
      variable_set('install_profile_modules', $dependencies);
    }

    // Only retreive install tasks from subprofiles once. If $tasks have been
    // populated, this has already been executed.
    if (empty($this->install_tasks)) {
      $this->setHookInvoked(GET_INSTALL_TASKS);
      $this->setDrupalInstallState($drupal_install_state);
      $this->notify();

      // CONTINUE HERE
      // Add tasks for installing dependencies.
      /*
      $subprofiles = $installUtility->getSubprofiles();
      foreach ($subprofiles as $name => $properties) {
        $subprofileClass = $properties['class_name'];
        require_once "{$properties['path']}/{$subprofileClass}.php";
        $installer->attach( new $subprofileClass() );
      }
      // */
    }
    return $this->install_tasks;
  }

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


  public function alterInstallTasks($install_tasks, $drupal_install_state) {
    $this->setHookInvoked(ALTER_INSTALL_TASKS);
    $this->setDrupalInstallState($drupal_install_state);
    $this->notify();
    return $this->getInstallTasks();
  }

  public function install() {
    $this->setHookInvoked(INSTALL);
    $this->notify();
  }

  public function alterInstallConfigureForm($install_configure_form, $install_configure_form_state) {
    $this->install_configure_form = $install_configure_form;
    $this->install_configure_form_state = $install_configure_form_state;
    $this->setHookInvoked(ALTER_INSTALL_CONFIGURE_FORM);
    $this->notify();
  }

  public function submitInstallConfigureForm($install_configure_form, $install_configure_form_state) {
    $this->install_configure_form = $install_configure_form;
    $this->install_configure_form_state = $install_configure_form_state;
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

  public function getDrupalInstallState() {
    $this->_checkGetter('drupal_install_state', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS
    ));
    return $this->drupal_install_state;
  }

  private function setDrupalInstallState($drupal_install_state) {
    $this->_checkSetter('drupal_install_state', 'set', array(self::ALTER_INSTALL_TASKS));
    $this->drupal_install_state = $drupal_install_state;
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

  public function setInstallTasks(array $install_tasks) {
    $this->_checkSetter('install_tasks', 'set', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS,
    ));
    $this->install_tasks = $install_tasks;
  }

  public function addInstallTasks(array $install_tasks) {
    $this->_checkSetter('install_tasks', 'added', array(
      self::GET_INSTALL_TASKS,
      self::ALTER_INSTALL_TASKS,
    ));
    $this->install_tasks = array_merge($this->install_tasks, $install_tasks);
  }

  public function getInstallConfigureForm() {
    $this->_checkGetter('install_configure_form', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->install_configure_form;
  }

  public function setInstallConfigureForm($install_configure_form) {
    $this->_checkSetter('install_configure_form', 'set', array(self::ALTER_INSTALL_CONFIGURE_FORM));
    $this->install_configure_form = $install_configure_form;
  }

  public function getInstallConfigureFormState() {
    $this->_checkGetter('install_configure_form', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    return $this->form_state;
  }

  public function setInstallConfigureFormState($install_configure_form_state) {
    $this->_checkSetter('install_configure_form_state', 'set', array(
      self::ALTER_INSTALL_CONFIGURE_FORM,
      self::SUBMIT_INSTALL_CONFIGURE_FORM,
    ));
    $this->install_configure_form_state = $install_configure_form_state;
  }

  public function getBaseProfileName() {
    return $this->baseprofile_name;
  }

  public function setBaseProfileName($baseprofile_name) {
    if (self::baseProfileExists($baseprofile_name, TRUE)) {
      $this->baseprofile_name = $baseprofile_name;
    }
  }

  public function getBaseProfilePath() {
    return $this->baseprofile_path;
  }

  public function setBaseProfilePath() {
    if (empty($this->baseprofile_name)) {
      throw new Exception("Cannot set baseprofile_path if baseprofile_name is empty.");
    }
    $this->baseprofile_path = $this->getPathToBaseProfile($this->baseprofile_name);
  }

  public function getSubprofileDetails($subprofile_name) {
    if (empty($this->subprofiles_details[$subprofile_name])) {
      $this->setSubprofileDetails($subprofile_name);
    }
    return $this->subprofiles_details[$subprofile_name];
  }

  public function setSubprofileDetails($subprofile_name) {
    $class_info = $this->getSubprofileClassInfo($subprofile_name);

    $this->setSubprofilePath($subprofile_name);
    $this->setSubprofileName($subprofile_name);
    $this->setSubprofileInfoFile($subprofile_name);
    $this->setSubprofileClassName($subprofile_name, $class_info['class_name']);
    $this->setSubprofileClassFile($subprofile_name, $class_info['class_file']);
  }

  public function getSubprofilePath($subprofile_name, $raise_exception = FALSE) {
    if (empty($this->subprofiles_details[$subprofile_name]['subprofile_path'])) {
      $subprofile_path = $this->_getSubprofilePath($subprofile_name, $raise_exception);
      $this->setSubprofilePath($subprofile_path, $subprofile_name)
    }
    return $this->subprofiles_details[$subprofile_name]['subprofile_path'];
  }

  private function _getSubprofilePath($subprofile_name, $raise_exception) {
    $included_subprofile_path = DRUPAL_ROOT . "/profiles/{$this->baseprofile_name}/subprofiles/{$subprofile_name}";
    $add_on_subprofile_path = DRUPAL_ROOT . "/profiles/subprofiles/{$subprofile_name}";

    if (is_dir($included_subprofile_path)) {
      $path = $included_subprofile_path;
    }
    else if (is_dir($add_on_subprofile_path)) {
      $path = $add_on_subprofile_path;
    }
    else {
      $path = NULL;
    }

    if (!$path && $raise_exception) {
      throw new Exception("Sub profile not found: {$subprofile_name}");
    }

    return $path;
  }

  public function setSubprofilePath($subprofile_name) {
    $subprofile_path = $this->getSubprofilePath($subprofile_name, TRUE);
    $this->subprofiles_details[$subprofile_name]['subprofile_path'] = $subprofile_path;
  }

  public function setSubprofileName($subprofile_name) {
    $this->subprofiles_details[$subprofile_name]['subprofile_name'] = $subprofile_name;
  }

  public function getSubprofileClassInfo($subprofile_name, $raise_exception = FALSE) {
    if (!empty($this->subprofiles_details[$subprofile_name]['class_file'])) {
      $info['class_name'] = $this->getSubprofileClassName($subprofile_name);
      $info['class_file'] = $this->getSubprofileClassFile($subprofile_name);
    }
    else {
      $info = array();
      $subprofile_path = $this->getSubprofilePath($subprofile_name, TRUE);

      foreach(file_scan_directory($subprofile_path, '/.*/') as $file) {
        if (self::isSubprofileClassFile($file->uri)) {
          $info['class_name'] = self::removeFileExtensionFromFile('.php', $file->name);
          $info['class_file'] = $file->uri;
        }
      }

      if (empty($info['class_file']) && $raise_exception) {
        throw new Exception("Invalid subprofile, no valid class file found here: {$subprofile_path}");
      }
    }

    return $info;
  }

  public function getSubprofileClassFile($subprofile_name) {
    return $this->subprofiles_details[$subprofile_name]['class_file'];
  }

  public function setSubprofileClassFile($subprofile_name, $file) {
    if (!file_exists($file)) {
      throw new Exception("File does not exist: {$file}");
    }
    $this->subprofiles_details[$subprofile_name]['class_file'] = $file;
  }

  public function getSubprofileClassName($subprofile_name) {
    return $this->subprofiles_details[$subprofile_name]['class_name'];
  }

  public function setSubprofileClassName($subprofile_name, $class_name) {
    $this->subprofiles_details[$subprofile_name]['class_name'] = $class_name;
  }

  public function getSubprofileInfoFile($subprofile_name) {
    return $this->subprofiles_details[$subprofile_name]['info_file'];
  }

  public function setSubprofileInfoFile($subprofile_name) {
    $subprofile_path = $this->getSubprofilePath($subprofile_name, TRUE);
    $subprofile_info_file = "{$subprofile_path}/{$subprofile_name}.info";

    if (!file_exists($subprofile_info_file)) {
      throw new Exception("Invalid subprofile, no valid info file found here: {$subprofile_info_file}");
    }

    $this->subprofiles_details[$subprofile_name]['info_file'] = $subprofile_info_file;
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
