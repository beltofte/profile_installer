
==============================================================
@TODO: This is outdated. Describes subprofile approach. Update.
==============================================================

Profile Installer
=================

Provides BaseProfile class to manage installation for Drupal install profiles. Install profiles using BaseProfile to manage installation can be extended by subprofiles the way themes are extended and overridden by subthemes and the way Drupal can be extended and overridden by modules.

Installation and setup (for base profiles)
------------------------------------------

Make your install profile a base profile that supports subprofiles like this:

1. Download this project. Place it in the profiles directory (as if it were an install profile).

2. Extend the BaseProfile class with a class named MyProfileBaseProfile (see ExampleBaseProfile.php).

3. Use MyProfileBaseProfile to manage installation and other install profile
   functionality (see example.profile and example.install or example2.profile and
   example2.install).


Create a subprofile
-------------------

Subprofiles include two files:

  mysubprofile.info: Declares subprofile's dependencies (just like a standard info file for any other Drupal install profile).

  MySubprofile.php: Includes MySubprofile class. MySubprofile extends abstract Subprofile class (see SampleSubprofile.php), giving it the ability to hook into Drupal's installation process at all the same places where a standard install profile hooks in to set up an application..


Using subprofiles
-----------------

Include subprofiles in your code base in either of the following two places:

  (A) Create a subprofiles directory inside Drupal's profiles directory. Download subprofiles and place them there. For example:

    drupal/profiles/subprofiles/example_subprofile_1
    drupal/profiles/subprofiles/example_subprofile_2
    drupal/profiles/subprofiles/example_subprofile_3

  (B) Include subprofiles with a base profile by adding a subprofiles directory _inside_ the base profile. For example:

    drupal/profiles/myprofile/subprofiles/example_subprofile_a
    drupal/profiles/myprofile/subprofiles/example_subprofile_b
    drupal/profiles/myprofile/subprofiles/example_subprofile_c

For Drupal to be aware of your subprofile (or subprofiles) and allow it to extend the base profile's installer, you must declare your subprofile(s) in either of the following two places:

  (A) A base profile can include subprofiles in it's info file, alongside module dependencies like this:

    subprofiles[] = example_subprofile_1
    subprofiles[] = example_subprofile_2
    subprofiles[] = example_subprofile_3

  (B) You can also extend a subprofile-enabled base profile by declaring one or more subprofiles in settings.php like this:

    $conf['subprofiles'][] = example_subprofile_1
    $conf['subprofiles'][] = example_subprofile_2
    $conf['subprofiles'][] = example_subprofile_3


Including subprofiles in a code base via Drush Make
---------------------------------------------------

  Note: This is not supported by drupal.org. The only way to include subprofiles in an install profile on drupal.org is to include both InstallProfileObserver and subprofiles inside the install profile's project repo.

    ; Include baseprofile. It's not really an install profile. But
    ; Drush Make will put it in the right place if we pretend it is.
    projects[baseprofile][type] = profile

    ; Included subprofiles.
    projects[example_subprofile_1][type] = profile
    projects[example_subprofile_1][subdir] = subprofiles

    projects[example_subprofile_2][type] = profile
    projects[example_subprofile_2][subdir] = subprofiles
