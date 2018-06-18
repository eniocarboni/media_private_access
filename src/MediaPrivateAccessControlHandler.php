<?php

namespace Drupal\media_private_access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaAccessControlHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an access control handler for media items.
 */
class MediaPrivateAccessControlHandler extends MediaAccessControlHandler implements EntityHandlerInterface {

  /**
   * Default access mode (no specific action).
   *
   * @var string
   */
  const MEDIA_PRIVATE_ACCESS_DEFAULT = 'default';

  /**
   * Permission-based access mode.
   *
   * @var string
   */
  const MEDIA_PRIVATE_ACCESS_PERMISSION = 'permission';

  /**
   * Inherited from top level route access mode.
   *
   * @var string
   */
  const MEDIA_PRIVATE_ACCESS_INHERITED_FROM_ROUTE = 'route';

  /**
   * Inherited from immediate parent access mode.
   *
   * @var string
   */
  const MEDIA_PRIVATE_ACCESS_INHERITED_FROM_PARENT = 'parent';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * MediaPrivateAccessControlHandler constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    parent::__construct($entity_type);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('media_private_access')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    // Administrators don't need to go through all this.
    if ($account->hasPermission('administer media')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Update and delete operations are managed by the original handler.
    if ($operation != 'view') {
      return parent::checkAccess($entity, $operation, $account);
    }

    // The owner can always view their own entities.
    $is_owner = ($account->id() && $account->id() === $entity->getOwnerId());
    if ($is_owner) {
      return AccessResult::allowed();
    }

    $modes = media_private_access_get_modes();
    $type = $entity->bundle();
    // If a type was not configured, default to the original handler check.
    if (!isset($modes[$type])) {
      return parent::checkAccess($entity, $operation, $account);
    }

    switch ($modes[$type]) {
      case self::MEDIA_PRIVATE_ACCESS_PERMISSION:
        return AccessResult::allowedIf($account->hasPermission('view ' . $type . ' media'))
          ->cachePerPermissions()
          ->addCacheableDependency($entity);

      case self::MEDIA_PRIVATE_ACCESS_INHERITED_FROM_ROUTE:
        $route_entity = media_private_access_get_route_entity();
        if ($route_entity) {
          // We don't want a recursive loop when visiting the media canonical
          // route. If the user got here they don't have administer permissions
          // neither are owners, so we just deny access.
          if ($route_entity->getEntityTypeId() == 'media' && $route_entity->id() == $entity->id()) {
            return AccessResult::forbidden('Access to this media standalone is only granted to administrators and owners.');
          }
          return $route_entity->access($operation, $account, TRUE);
        }
        // In all non-entity routes, access would already have been granted
        // above for admins and owners, so here we don't allow anything else.
        return AccessResult::forbidden('Access to this media is only granted to administrators and owners.');

      case self::MEDIA_PRIVATE_ACCESS_INHERITED_FROM_PARENT:
        // If entity_usage is not available or not the correct version, log an
        // error message and deny access.
        $tracking_available = FALSE;
        $usage_service = NULL;
        if ($this->moduleHandler->moduleExists('entity_usage')) {
          $usage_service = \Drupal::service('entity_usage.usage');
          if (method_exists($usage_service, 'listSources')) {
            $tracking_available = TRUE;
          }
        }
        if (!$tracking_available) {
          $this->logger->error('Trying to use access mode <em>Inherited from immediate parent</em> but the Entity Usage module was not found or not correct version.');
          return AccessResult::forbidden('Could not detect the parent(s) of this media asset, please contact your system administrator.');
        }
        $sources = $usage_service->listSources($entity);
        if (empty($sources)) {
          return AccessResult::forbidden('Access to media assets with no usages are only granted to administrators and owners.');
        }

        // Access will be granted if at least one source grants access on its
        // default revision.
        foreach ($sources as $source_type_id => $usages) {
          foreach ($usages as $source_id => $records) {
            $source = $this->entityTypeManager->getStorage($source_type_id)->load($source_id);
            if ($source && ($source instanceof RevisionableInterface)) {
              $used_in_default_revision = FALSE;
              foreach ($records as $record) {
                if ($record['source_vid'] == $source->getRevisionId()) {
                  $used_in_default_revision = TRUE;
                  break;
                }
              }
              if ($used_in_default_revision && $source->access($operation, $account)) {
                // @todo is it worth caching this?
                return AccessResult::allowed();
              }
            }
            elseif ($source && $source->access($operation, $account)) {
              // @todo is it worth caching this?
              return AccessResult::allowed();
            }
          }
        }
        return AccessResult::forbidden('Access to this media asset is restricted to administrators and owners.');

      default:
        return parent::checkAccess($entity, $operation, $account);
    }
  }

}
