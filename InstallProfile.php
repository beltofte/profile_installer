<?php
/**
 * @file InstallProfile.php
 * Provides InstallProfile interface.
 */
interface InstallProfile {
  // @todo Decide what to do about params in interface below.
  public function getDependencies();
  public function alterDependencies();
  public function getInstallTasks();
  public function alterInstallTasks();
  public function install();
  public function alterInstallConfigureForm();
  public function submitInstallConfigureForm();
  // public function updateN(); @todo
}
