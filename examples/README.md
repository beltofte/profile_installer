Profile Installer Examples
==========================


Overview
--------

Examples included here are all working install profiles.

1. Download the profile_installer project and place it inside your profiles
   directory.
 
1.  Copy or symlink examples into your profiles directory.

1. Install.


Standard2
---------

  - This profile extends Drupal core's Standard profile (standard2.info:
    `profiles[] = standard`)
  - It disables dblog, but besides that, keeps all the same basic setup that
    comes with Standard (standard2.info: `remove_dependencies[] = dblog`)
  - Inside `standard2_install_tasks()` is an example of an advanced usage, where
    standard2 adds its own custom callback to ProfileInstaller's list of install
    callbacks (by default, callbacks here are simply a list of all included
    profiles' install hooks, but this list can be examined or modified as needed
    by install profiles)


Standard3
---------

  - Extends Standard2 (standard3.info: `profiles[] = standard2`)
  - Detects profiles included in Standard2 (Standard) and includes those too
  - Adds its own additional dependencies (standard3.info: `dependencies[] =
    contact`)
