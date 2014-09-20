<?php
/**
 * @file InstallUtility.php
 * Provides utilities for detecting subprofiles and wrappers for drupal functions.
 *
 * Anything requiring a Drupal bootstrap, knowledge of Drupal directory structure,
 * or logic determining where things like subprofiles live in a code base is encapsulated here.
 */
class InstallUtility {
  // Path to install profile.
  private $path;
  // Path to info file.
  private $infoFile;
  // Machine name of install profile.
  private $name;

  public function __construct($path) {
    $this->path = $dirname;
    $this->name = basename($dirname);
    $this->infoFile = "{$path}/{$name}.info";
  }

  /**
   * @param $path
   *   Path to base install profile.
   *
   * @return array $subprofiles
   *   Properties:
   *   - name, name of subprofile (matches directory name)
   *   - path, path to subprofile
   *   - class_name, name of class (e.g. mysubprofile/MySubProfile.php, MySubProfile is the name)
   */
  public static function getSubProfiles($path) {
    // Find subprofiles. First check install profile's info file.
    $subprofile_names = $this->getSubProfilesFromInfoFile();

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

    return $subprofiles;
  }


    /**
     * @param string $path
     * @return array
     */
    public static function getSubProfilesFromInfoFile($path = '') {
      if (!$path) {
        $path = $this->infoFile;
      }

      $info = drupal_parse_info_file($path);
      if (isset($info['subprofiles'])) {
        return $info['subprofiles'];
      }
      else {
        return array();
      }
    }
}