<?php
/**
 * @file example.profile
 * Example of a subprofile-enabled install profile.
 *
 * Technically a .profile file can do just about anything a .module file can do.
 * But to keep things manageable, maintainable, and extendable, we only actually
 * implement the following hooks:
 *
 * - hook_form_install_configure_form_alter: Customize Drupal's default installation configuration form
 * - hook_permission: Enable profiles to declare generic, application-wide permissions
 *
 * An install profile that wants to accomplish anything else should do so as:
 *
 * - part of the install script (see example_install(), Installer::install, and Subprofile::update)
 * - an installation task (see example_install_tasks, Installer::install_tasks, and Subprofile::update)
 * - inside a required module (see example.info, Installer::getDependencies, and Subprofile::update)
 */
