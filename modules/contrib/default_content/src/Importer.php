<?php

namespace Drupal\default_content;

use Drupal\Component\Graph\Graph;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\default_content\Event\DefaultContentEvents;
use Drupal\default_content\Event\ImportEvent;
use Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList;
use Drupal\hal\LinkManager\LinkManagerInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * A service for handling import of default content.
 *
 * @todo throw useful exceptions
 */
class Importer implements ImporterInterface {

  /**
   * Defines relation domain URI for entity links.
   *
   * @var string
   */
  protected $linkDomain;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A list of vertex objects keyed by their link.
   *
   * @var array
   */
  protected $vertexes = [];

  /**
   * The graph entries.
   *
   * @var array
   */
  protected $graph = [];

  /**
   * The link manager service.
   *
   * @var \Drupal\hal\LinkManager\LinkManagerInterface
   */
  protected $linkManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The file system scanner.
   *
   * @var \Drupal\default_content\ScannerInterface
   */
  protected $scanner;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * Constructs the default content manager.
   *
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\hal\LinkManager\LinkManagerInterface $link_manager
   *   The link manager service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\default_content\ScannerInterface $scanner
   *   The file scanner.
   * @param string $link_domain
   *   Defines relation domain URI for entity links.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The account switcher.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, LinkManagerInterface $link_manager, EventDispatcherInterface $event_dispatcher, ScannerInterface $scanner, $link_domain, AccountSwitcherInterface $account_switcher) {
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
    $this->linkManager = $link_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->scanner = $scanner;
    $this->linkDomain = $link_domain;
    $this->accountSwitcher = $account_switcher;
  }

  /**
   * {@inheritdoc}
   */
  public function importContent($module, $update_existing = FALSE) {
    $created = [];
    $updated = [];
    $revision_links = [];
    $folder = drupal_get_path('module', $module) . "/content";

    if (file_exists($folder)) {
      $root_user = $this->entityTypeManager->getStorage('user')->load(1);
      $this->accountSwitcher->switchTo($root_user);
      $file_map = [];
      $definitions = $this->entityTypeManager->getDefinitions();
      foreach ($definitions as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface(ConfigEntityInterface::class)) {
          continue;
        }
        if (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = $this->scanner->scan($folder . '/' . $entity_type_id);
        // Default content uses drupal.org as domain.
        // @todo Make this use a uri like default-content:.
        $this->linkManager->setLinkDomain($this->linkDomain);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          $contents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');
          // Get the link to this entity.
          $item_uuid = $decoded['uuid'][0]['value'];

          // Throw an exception when this UUID already exists.
          if (isset($file_map[$item_uuid])) {
            // Reset link domain.
            $this->linkManager->setLinkDomain(FALSE);
            throw new \Exception(sprintf('Default content with uuid "%s" exists twice: "%s" "%s"', $item_uuid, $file_map[$item_uuid]->uri, $file->uri));
          }

          // Store the entity type with the file.
          $file->entity_type_id = $entity_type_id;
          // Store the file in the file map.
          $file_map[$item_uuid] = $file;
          // Create a vertex for the graph.
          $vertex = $this->getVertex($item_uuid);
          $this->graph[$vertex->id]['edges'] = [];
          if (empty($decoded['_embedded'])) {
            // No dependencies to resolve.
            continue;
          }
          // Here we need to resolve our dependencies:
          foreach ($decoded['_embedded'] as $embedded) {
            foreach ($embedded as $item) {
              $uuid = $item['uuid'][0]['value'];
              $edge = $this->getVertex($uuid);
              $this->graph[$vertex->id]['edges'][$edge->id] = TRUE;
            }
          }
        }
      }

      // @todo what if no dependencies?
      $sorted = $this->sortTree($this->graph);
      foreach ($sorted as $link => $details) {
        if (!empty($file_map[$link])) {
          $file = $file_map[$link];
          $entity_type_id = $file->entity_type_id;
          /* @var $entity_type \Drupal\Core\Entity\EntityTypeInterface */
          $entity_type = $definitions[$entity_type_id];
          $contents = $this->parseFile($file);

          /* @var $entity \Drupal\Core\Entity\EntityInterface */
          $entity = $this->serializer->deserialize($contents, $entity_type->getClass(), 'hal_json', ['request_method' => 'POST']);

          // Ensure we use the proper target_revision_id for edges.
          if (!empty($details['edges']) && !empty($revision_links)) {
            foreach ($details['edges'] as $uuid => $bool) {
              foreach ($entity as $data) {
                // If this is a field that requires the revision id ensure it
                // has the one assigned during the import and not the one stored
                // in the export.
                if ($data instanceof EntityReferenceRevisionsFieldItemList) {
                  foreach ($data as $item) {
                    if ($target_entity = $item->getProperties(TRUE)['entity']) {
                      if (isset($revision_links[$target_entity->getTargetDefinition()->getEntityTypeId()][$target_entity->getTargetIdentifier()])) {
                        $target_entity->setValue([
                          'target_id' => $target_entity->getTargetIdentifier(),
                          'target_revision_id' => $revision_links[$target_entity->getTargetDefinition()->getEntityTypeId()][$target_entity->getTargetIdentifier()],
                        ]);
                      }
                    }
                  }
                }
              }
            }
          }

          $is_new = TRUE;

          $old_entity = $this->lookupEntity($entity, $entity_type);

          if ($old_entity && $update_existing) {
            // All unique keys need to match the old entity.
            $entity->{$entity_type->getKey('uuid')} = $old_entity->uuid();
            $entity->{$entity_type->getKey('id')} = $old_entity->id();
            $is_new = FALSE;
            if ($this->isRevisionableEntity($entity)) {
              $entity->{$entity_type->getKey('revision')} = $old_entity->getRevisionId();
            }
          }
          elseif (!$old_entity) {
            // Don't import site level IDs if they are used.
            if ($this->existEntityId($entity, $entity_type)) {
              $entity->{$entity_type->getKey('id')} = NULL;
            }
            $entity->{$entity_type->getKey('revision')} = NULL;
          }

          !$is_new && $old_entity ? $entity->setOriginalId($old_entity->id()) : $entity->enforceIsNew($is_new);
          if ($this->isRevisionableEntity($entity)) {
            $entity->setNewRevision($is_new);
          }

          // Ensure that the entity is not owned by the anonymous user.
          if ($entity instanceof EntityOwnerInterface && empty($entity->getOwnerId())) {
            $entity->setOwner($root_user);
          }

          if ($old_entity && $update_existing) {
            $updated[$entity->uuid()] = $entity;
            $entity->save();
            if ($this->isRevisionableEntity($entity)) {
              $revision_links[$entity->getEntityTypeId()][$entity->id()] = $entity->{$entity_type->getKey('revision')}->value;
            }
          }
          elseif (!$old_entity) {
            $created[$entity->uuid()] = $entity;
            $entity->save();
            if ($this->isRevisionableEntity($entity)) {
              $revision_links[$entity->getEntityTypeId()][$entity->id()] = $entity->{$entity_type->getKey('revision')}->value;
            }
          }
        }
      }
      if (!empty($created)) {
        $this->eventDispatcher->dispatch(DefaultContentEvents::IMPORT, new ImportEvent($created, $module));
      }
      if (!empty($updated)) {
        $this->eventDispatcher->dispatch(DefaultContentEvents::UPDATE, new ImportEvent($updated, $module));
      }
      $this->accountSwitcher->switchBack();
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);
    return $created;
  }

  /**
   * Lookup whether an entity already exists.
   *
   * For most typical entities this is done by uuid.
   * For core user 1 this is done by id.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that will be imported.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type for this entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The old entity, or NULL if no entity.
   */
  public function lookupEntity(EntityInterface $entity, EntityTypeInterface $entity_type) {
    $entity_storage = $this->entityTypeManager->getStorage($entity_type->id());

    $lookup_properties = [$entity_type->getKey('uuid') => $entity->uuid()];
    // Alter the lookup properties for known core irregularities.
    if ($entity_type->id() === 'user' && $entity->id() == 1) {
      $lookup_properties = [$entity_type->getKey('id') => $entity->id()];
    }

    $entity_query = $entity_storage->getQuery()->accessCheck(FALSE);
    foreach ($lookup_properties as $key => $value) {
      // Cast scalars to array so we can consistently use an IN condition.
      $entity_query->condition($key, (array) $value, 'IN');
    }
    $result = $entity_query->execute();

    $old_entity = $result ? $entity_storage->load(current($result)) : [];

    return $old_entity;
  }

  /**
   * Check if an imported entity id already exists.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that will be imported.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type of this entity.
   *
   * @return bool
   *   TRUE if current entity's id exists.
   */
  public function existEntityId(EntityInterface $entity, EntityTypeInterface $entity_type) {
    if ($entity->id()) {
      $entity_storage = $this->entityTypeManager->getStorage($entity_type->id());
      $entity_query = $entity_storage->getQuery()->accessCheck(FALSE);
      $entity_query->condition($entity_type->getKey('id'), (array) $entity->id(), 'IN');
      $result = $entity_query->execute();
      return !empty($result);
    }
  }

  /**
   * Parses content files.
   *
   * @param object $file
   *   The scanned file.
   *
   * @return string
   *   Contents of the file.
   */
  protected function parseFile($file) {
    return file_get_contents($file->uri);
  }

  /**
   * Resets tree properties.
   */
  protected function resetTree() {
    $this->graph = [];
    $this->vertexes = [];
  }

  /**
   * Sorts dependencies tree.
   *
   * @param array $graph
   *   Array of dependencies.
   *
   * @return array
   *   Array of sorted dependencies.
   */
  protected function sortTree(array $graph) {
    $graph_object = new Graph($graph);
    $sorted = $graph_object->searchAndSort();
    uasort($sorted, 'Drupal\Component\Utility\SortArray::sortByWeightElement');
    return array_reverse($sorted);
  }

  /**
   * Returns a vertex object for a given item link.
   *
   * Ensures that the same object is returned for the same item link.
   *
   * @param string $item_link
   *   The item link as a string.
   *
   * @return object
   *   The vertex object.
   */
  protected function getVertex($item_link) {
    if (!isset($this->vertexes[$item_link])) {
      $this->vertexes[$item_link] = (object) ['id' => $item_link];
    }
    return $this->vertexes[$item_link];
  }

  /**
   * Checks a given entity for revision support.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A typical drupal entity object.
   *
   * @return bool
   *   Whether this entity supports revisions.
   */
  protected function isRevisionableEntity(EntityInterface $entity) {
    return $entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable();
  }

}
