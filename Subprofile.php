<?php
/**
 * @file
 */

/**
 * - Observer pattern
 * - observer
 * - SplObserver
 *
 * @param SplSubject $subject
 */
abstract class Subprofile implements SplObserver {
  private $installer;

  function __construct( Installer $installer ) {
    $this->installer = $installer;
    $installer->attach( $this );
  }

  function update( SplSubject $subject ) {
    $installer = $this->installer;
    // Make sure update is being invoked by installer.
    if (!$subject === $installer) {
      return;
    }
    // If the subprofile implemented the "hook", invoke it.
    $hook = $installer->getHook();
    if (method_exists($this, $hook) {
      $this->$hook();
    }
  }

}