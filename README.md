Profile Installer
=================

 - [Overview](#Overview)
 - [Proof of concept](#Proof of concept)
 - [Usage](#Usage)
 - [Glue](#Glue)

Overview
--------

Profile Installer makes Drupal install profiles (distros) extendable like Drupal
modules and themes.

Proof of concept
----------------

This is a proof-of-concept it works. But it's under active development.
Developers will make every effort to keep the master branch and tagged 0.x.x
releases in a working state until the first release. But we make no promises.
Tests (e.g. for included example profiles) and pull requests are welcome.

Here's the checklist of features currently envisioned for a feature-complete
7.x-1.0.0 release:

Supports:

 - [x] profiles[] in info file
 - [x] remove_dependencies[] in info file
 - [x] hook_install
 - [ ] hook_install_tasks
 - [x] hook_install_tasks_alter
 - [x] hook_form_install_configure_form_alter
 - [ ] hook_update_N
 - [ ] auto-generate .install and .profile files for info-file-only profiles. Use
       Features module's trick to declare necessary hooks like this: 
       ([original](http://cgit.drupalcode.org/features/tree/includes/features.ctools.inc?id=0f77db7a&h=7.x-1.x),
       or [updated
       approach](http://cgit.drupalcode.org/features/tree/includes/features.ctools.inc?id=9f4ecc7&h=7.x-2.x)

Utilities:

 - [ ] `drush profile-installer-list-dependencies`, list all the dependencies for a profile
 - [ ] `drush profile-installer-check-dependencies`, check what's enabled/disabled
        compared to profile's dependencies, report what's amiss
 - [ ] `drush profile-installer-enforce-dependencies`, disable/enable modules to
        enforce consistent profile state with dependencies declared in code

Usage
-----

To make a "subprofile" of a "base profile" the way a subtheme extends a base
theme, add something like this your profile's info file:

        profiles[] = example_baseprofile

To make an install profile extend or depend on several other profiles the way a
module can depend on several other modules, add something like this to your
profile's info file:

        profiles[] = profile1
        profiles[] = profile2
        profiles[] = profile3

You can also override dependencies provided by an included profile. For example,
maybe your profile is a simple subprofile of some contrib distro. You just want
to override some included feature modules to customize the included views and
panels. Now your customized versions of these feature modules conflict with the
profile you're including. Just disable the included profile's feature modules
like this:

        remove_dependencies[] = feature_i_customized1
        remove_dependencies[] = feature_i_customized2
        remove_dependencies[] = feature_i_customized3

Glue
----

Profile Installer requires a little glue code. Since Drupal isn't installed
yet when it does it's magic. So it relies on you to invoke a few standard
install profile hooks, then run the installer.

Place Profile Installer in your code base as if it was an install profile here:

        docroot/profiles/profile_installer

If your included profiles are really basic, this is the only thing you need to
add to your install file. This will take care of detecting and enabling all
dependencies (and removed dependencies):

```php
  require_once DRUPAL_ROOT . '/profiles/profile_installer/profile_installer.inc';

  /**
   * Implements hook_install_tasks_alter().
   */
  function standard3_install_tasks_alter(&$tasks, $install_state) {
    $installer = ProfileInstaller::getInstallerForProfile('standard3');
    $tasks = $installer->alterInstallTasks($tasks, $install_state);
  }
```

For profiles with additional customizations to install tasks or the install
configure form, add this to your install file:

```php
  /**
   * Implements hook_install_tasks().
   */
  function example_install_tasks(&$install_state) {
    $installer = ProfileInstaller::getInstallerForProfile('standard3');
    $tasks = $installer->getInstallTasks($install_state);
    $install_state = $installer->getInstallState();
    return $tasks;
  }
```

And add this to your profile file:

```php
  require_once DRUPAL_ROOT . '/profiles/profile_installer/profile_installer.inc';

  /**
   * Implements hook_form_FORM_ID_alter() for install_configure_form().
   *
   * Allows the profile to alter the site configuration form.
   */
  function standard2_form_install_configure_form_alter(&$form, $form_state) {
    $installer = ProfileInstaller::getInstallerForProfile('standard2');
    $form = $installer->alterInstallConfigureForm($form, $form_state);
  }
```
