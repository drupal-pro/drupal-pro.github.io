<?php

namespace Drupal\default_content\Event;

/**
 * Defines the events for Default Content.
 *
 * @see \Drupal\default_content\Event\ImportEvent
 * @see \Drupal\default_content\Event\UpdateEvent
 * @see \Drupal\default_content\Event\ExportEvent
 */
final class DefaultContentEvents {

  /**
   * Name of the event fired when importing new default content.
   *
   * This event allows modules to perform actions after the default content has
   * been imported. The event listener receives a
   * \Drupal\default_content\Event\ImportEvent instance.
   *
   * @Event
   *
   * @see \Drupal\default_content\Event\ImportEvent
   *
   * @var string
   */
  const IMPORT = 'default_content.import';

  /**
   * Name of the event fired when updating existing default content.
   *
   * This event allows modules to perform actions after the default content has
   * been updated. The event listener receives a
   * \Drupal\default_content\Event\ImportEvent instance.
   *
   * @Event
   *
   * @see \Drupal\default_content\Event\UpdateEvent
   *
   * @var string
   */
  const UPDATE = 'default_content.update';

  /**
   * Name of the event fired when exporting default content.
   *
   * This event allows modules to perform actions after the default content has
   * been exported. The event listener receives a
   * \Drupal\default_content\Event\ExportEvent instance.
   *
   * @Event
   *
   * @see \Drupal\default_content\Event\ExportEvent
   *
   * @var string
   */
  const EXPORT = 'default_content.export';

}
