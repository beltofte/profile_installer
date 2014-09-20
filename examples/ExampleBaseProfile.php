<?php
/**
 * @file ExampleBaseProfile.php
 * Provides custom BaseProfile for Example install profile.
 */

class ExampleBaseProfile extends BaseProfile {
  function install() {
    variable_set('site_name', 'Example Profile');
  }
}
