<?php
// In construct make sure to invoke initialize
namespace Drupal\civicrm_entity;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\Plugin\DataType\DateTimeIso8601;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines entity class for external CiviCRM entities.
 */
class CiviEntityStorage extends SqlContentEntityStorage {

  /**
   * @var \Drupal\civicrm_entity\CiviCrmApi
   */
  protected $civicrmApi;


  /**
   * Constructs a SqlContentEntityStorage object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend to be used.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(EntityTypeInterface $entity_type, Connection $database, EntityManagerInterface $entity_manager, CacheBackendInterface $cache, LanguageManagerInterface $language_manager, CiviCrmApi $civicrm_api) {
    parent::__construct($entity_type, $database, $entity_manager, $cache, $language_manager);
    $this->civicrmApi = $civicrm_api;
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('database'),
      $container->get('entity.manager'),
      $container->get('cache.entity'),
      $container->get('language_manager'),
      $container->get('civicrm_entity.api')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @todo Need to work in \Drupal\Core\Entity\Sql\SqlContentEntityStorage::mapFromStorageRecords
   */
  protected function doLoadMultiple(array $ids = NULL) {
    $entities = [];

    if ($ids === NULL) {
      $civicrm_entities = $this->civicrmApi->get($this->entityType->get('civicrm_entity'));
      foreach ($civicrm_entities as $civicrm_entity) {
        $civicrm_entity = reset($civicrm_entity);
        /** @var \Drupal\civicrm_entity\Entity\CivicrmEntity $entity */
        $entity = new $this->entityClass($civicrm_entity, $this->entityTypeId);
        $entities[$entity->id()] = $entity;
      }
    }
    else {
      foreach ($ids as $id) {
        $civicrm_entity = $this->civicrmApi->get($this->entityType->get('civicrm_entity'), ['id' => $id]);
        $civicrm_entity = reset($civicrm_entity);
        /** @var \Drupal\civicrm_entity\Entity\CivicrmEntity $entity */
        $entity = new $this->entityClass($civicrm_entity, $this->entityTypeId);
        $entities[$entity->id()] = $entity;
      }
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  protected function has($id, EntityInterface $entity) {
    return !$entity->isNew();
  }

  /**
   * {@inheritdoc}
   */
  protected function getQueryServiceName() {
    return 'entity.query.civicrm_entity';
  }

  /**
   * {@inheritdoc}
   */
  public function countFieldData($storage_definition, $as_bool = FALSE) {
    return $as_bool ? 0 : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasData() {
    // @todo query API and get actual count.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function doSaveFieldItems(ContentEntityInterface $entity, array $names = []) {
    parent::doSaveFieldItems($entity, $names);
    $params = [];
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    foreach ($entity->getFields() as $field_name => $items) {
      $storage_definition = $items->getFieldDefinition()->getFieldStorageDefinition();

      if ($storage_definition->hasCustomStorage()) {
        continue;
      }

      $items->filterEmptyItems();
      if ($items->isEmpty()) {
        continue;
      }

      $main_property_name = $storage_definition->getMainPropertyName();
      $list = [];
      /** @var \Drupal\Core\Field\FieldItemInterface $item */
      foreach ($items as $delta => $item) {
        $main_property = $item->get($main_property_name);
        if ($main_property instanceof DateTimeIso8601) {
          $value = $main_property->getDateTime()->format(DATETIME_DATETIME_STORAGE_FORMAT);
        }
        else {
          $value = $main_property->getValue();
        }
        $list[$delta] = $value;
      }

      // Remove the wrapping array if the field is single-valued.
      if ($storage_definition->getCardinality() === 1) {
        $list = reset($list);
      }
      if (!empty($list)) {
        $params[$field_name] = $list;
      }
    }

    $this->civicrmApi->save($this->entityType->get('civicrm_entity'), $params);
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeleteFieldItems($entities) {
    parent::doDeleteFieldItems($entities);
    /** @var EntityInterface $entity */
    foreach ($entities as $entity) {
      try {
        $params['id'] = $entity->id();
        $this->civicrmApi->delete($this->entityType->get('civicrm_entity'), $params);
      }
      catch (\Exception $e) {
        throw $e;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Provide any additional processing of values from CiviCRM API.
   */
  protected function initFieldValues(ContentEntityInterface $entity, array $values = [], array $field_names = []) {
    parent::initFieldValues($entity, $values, $field_names);
    foreach ($entity->getFieldDefinitions() as $definition) {
      $items = $entity->get($definition->getName());
      if ($items->isEmpty()) {
        continue;
      }
      $main_property_name = $definition->getMainPropertyName();

      // Fix DateTime values for Drupal format.
      if ($definition->getType() == 'datetime') {
        $item_values = $items->getValue();
        foreach ($item_values as $delta => $item) {
          // Handle if the value provided is a timestamp.
          // @note: This only occurred during test migrations.
          if (is_numeric($item[$main_property_name])) {
            $item_values[$delta][$main_property_name] = (new \DateTime())->setTimestamp($item[$main_property_name])->format(DATETIME_DATETIME_STORAGE_FORMAT);
          }
          // Date time formats from CiviCRM do not match the storage
          // format for Drupal's date time fields. Add in missing "T" marker.
          else {
            $item_values[$delta][$main_property_name] = str_replace(' ', 'T', $item[$main_property_name]);
          }
        }
        $items->setValue($item_values);
      }
    }
  }


}
