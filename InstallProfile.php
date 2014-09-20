<?php
/**
 * @file InstallProfile.php
 * Provides InstallProfile interface.
 */
interface InstallProfile {
  public function getDependencies();
  public function alterDependencies();
  public function getInstallTasks();
  public function alterInstallTasks();
  public function install();
  public function alterInstallConfigureForm();
  public function submitInstallConfigureForm();
}
