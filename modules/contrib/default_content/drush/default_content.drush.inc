<?php

/**
 * @file
 * Drush integration for the default_content module.
 */

/**
 * Implements hook_drush_command().
 */
function default_content_drush_command() {
  $items['default-content-export'] = [
    'description' => dt('Exports a single entity'),
    'arguments' => [
      'entity_type' => dt('The entity type to export.'),
      'entity_id' => dt('The ID of the entity to export.'),
    ],
    'options' => [
      'file' => dt('Write out the exported content to a file instead of stdout'),
    ],
    'aliases' => ['dce'],
    'required-arguments' => 2,
  ];
  $items['default-content-export-references'] = [
    'description' => dt('Exports an entity and all its referenced entities.'),
    'arguments' => [
      'entity_type' => dt('The entity type to export.'),
      'property_name' => dt('The property name of the entity to match.'),
      'property_value' => dt('The property value of the entity to match.'),
    ],
    'options' => [
      'folder' => dt('Folder to export to, entities are grouped by entity type into directories.'),
    ],
    'aliases' => ['dcer'],
    'required-arguments' => 1,
  ];
  $items['default-content-export-module'] = [
    'description' => dt('Exports all the content defined in a module info file.'),
    'arguments' => [
      'module' => dt('The name of the module.'),
    ],
    'aliases' => ['dcem'],
    'required-arguments' => 1,
  ];

  return $items;
}

/**
 * Exports a piece of content into the stdout or into a file.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 * @param mixed $entity_id
 *   The entity ID to export.
 */
function drush_default_content_export($entity_type_id, $entity_id) {
  /** @var \Drupal\default_content\DefaultContentExporterInterface $exporter */
  $exporter = \Drupal::service('default_content.exporter');
  $export = $exporter->exportContent($entity_type_id, $entity_id);

  if ($file = drush_get_option('file')) {
    file_put_contents($file, $export);
  }
  else {
    drush_print($export);
  }
}

/**
 * Exports a piece of content and all its referenced entities.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 * @param string $property_name
 *   (Optional) The property name to match, or all entities will be exported.
 * @param mixed $property_value
 *   (Optional) The property value to match, if $property_name is given.
 */
function drush_default_content_export_references($entity_type_id, $property_name = NULL, $property_value = NULL) {
  /** @var \Drupal\default_content\DefaultContentExporterInterface $exporter */
  $exporter = \Drupal::service('default_content.exporter');

  $folder = drush_get_option('folder', '.');
  $properties = $property_name ? [$property_name => $property_value] : [];

  $entities = \Drupal::entityManager()->getStorage($entity_type_id)->loadByProperties($properties);
  // @todo Add paging.
  foreach ($entities as $entity) {
    if ($entity_id = $entity->id()) {
      $serialized_by_type = $exporter->exportContentWithReferences($entity_type_id, $entity_id);
      $exporter->writeDefaultContent($serialized_by_type, $folder);
    }
  }
}

/**
 * Exports all of the content for a given module.
 *
 * @param string $module_name
 *   The module name to export.
 */
function drush_default_content_export_module($module_name) {
  /** @var \Drupal\default_content\DefaultContentExporterInterface $exporter */
  $exporter = \Drupal::service('default_content.exporter');
  $serialized_by_type = $exporter->exportModuleContent($module_name);
  $module_folder = \Drupal::moduleHandler()
    ->getModule($module_name)
    ->getPath() . '/content';
  $exporter->writeDefaultContent($serialized_by_type, $module_folder);
}
