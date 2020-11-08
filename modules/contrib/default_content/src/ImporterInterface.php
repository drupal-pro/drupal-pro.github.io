<?php

namespace Drupal\default_content;

/**
 * An interface defining a default content importer.
 */
interface ImporterInterface {

  /**
   * Imports default content from a given module.
   *
   * @param string $module
   *   The module to create the default content from.
   * @param bool $update_existing
   *   Whether to update existing entities or ignore them.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of created entities keyed by their UUIDs.
   */
  public function importContent($module, $update_existing = FALSE);

}
