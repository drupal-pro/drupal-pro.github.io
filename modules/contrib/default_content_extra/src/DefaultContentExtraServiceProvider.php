<?php

namespace Drupal\default_content_extra;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds customized normalizer to handle taxonomy hierarchy.
 */
class DefaultContentExtraServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    // @todo Get rid of after https://www.drupal.org/node/2543726
    if (isset($modules['taxonomy'])) {
      // Add a normalizer service for term entities.
      $service_definition = new Definition('Drupal\default_content_extra\Normalizer\TermEntityNormalizer', [
        new Reference('hal.link_manager'),
        new Reference('entity.manager'),
        new Reference('module_handler'),
        new Reference('config.factory'),
      ]);
      // The priority must be higher than that of
      // serializer.normalizer.entity.hal in hal.services.yml.
      $service_definition->addTag('normalizer', ['priority' => 50]);
      $container->setDefinition('default_content_extra.normalizer.taxonomy_term.halt', $service_definition);
    }
  }

}
