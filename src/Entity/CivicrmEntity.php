<?php

namespace Drupal\civicrm_entity\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;

/**
 * Class for representing CiviCRM entities.
 *
 * @todo Document how this is used in _entity_type_build().
 *
 * @see civicrm_entity_entity_type_build().
 */
class CivicrmEntity extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    $civicrm_fields = \Drupal::service('civicrm_entity.api')->getFields($entity_type->get('civicrm_entity'));
    foreach ($civicrm_fields as $civicrm_field) {
      $fields[$civicrm_field['name']] = self::createBaseFieldDefinition($civicrm_field, $entity_type->get('civicrm_entity'));
    }

    return $fields;
  }

  protected static function createBaseFieldDefinition(array $civicrm_field, $civicrm_entity_id) {
//    dpm($civicrm_field);
    if ($civicrm_field['name'] == 'id') {
      $field = BaseFieldDefinition::create('integer')
        ->setReadOnly(TRUE)
        ->setSetting('unsigned', TRUE);
    }
    elseif (empty($civicrm_field['type'])) {
      $field = BaseFieldDefinition::create('string')
        ->setDisplayOptions('view', [
          'label' => 'hidden',
          'type' => 'string',
        ])
        ->setDisplayOptions('form', [
          'type' => 'string_textfield',
        ]);
    }
    else {
      switch ($civicrm_field['type']) {
        case \CRM_Utils_Type::T_INT:
          // If this field has `pseudoconstant` it is a reference to values in
          // civicrm_option_value.
          if (!empty($civicrm_field['pseudoconstant'])) {
            // @todo this should be in a value callback, not set on generation.
            $options = \Drupal::getContainer()->get('civicrm_entity.api')->getOptions($civicrm_entity_id, $civicrm_field['name']);
            $field = BaseFieldDefinition::create('list_integer')
              ->setSetting('allowed_values', $options)
              ->setDisplayOptions('view', [
                'label' => 'hidden',
                'type' => 'integer',
              ])
              ->setDisplayOptions('form', [
                'type' => 'options_select',
                'weight' => 0,
              ]);
          }
          // Otherwise it is just a regular integer field.
          else {
            $field = BaseFieldDefinition::create('integer')
              ->setDisplayOptions('view', [
                'label' => 'hidden',
                'type' => 'integer',
              ])
              ->setDisplayOptions('form', [
                'type' => 'number',
                'weight' => 0,
              ]);
          }

          break;

        case \CRM_Utils_Type::T_BOOLEAN:
          $field = BaseFieldDefinition::create('boolean')
            ->setDisplayOptions('form', [
              'type' => 'boolean_checkbox',
              'weight' => 0,
              'settings' => [
                'display_label' => TRUE,
              ],
            ])
            ->setDisplayConfigurable('form', TRUE);
          break;

        case \CRM_Utils_Type::T_MONEY:
        case \CRM_Utils_Type::T_FLOAT:
          $field = BaseFieldDefinition::create('float');
          break;

        case \CRM_Utils_Type::T_STRING:
        case \CRM_Utils_Type::T_TEXT:
        case \CRM_Utils_Type::T_CCNUM:
        $field = BaseFieldDefinition::create('string')
          ->setDisplayOptions('view', [
            'type' => 'text_default',
          ])
          ->setDisplayConfigurable('view', TRUE)
          ->setDisplayOptions('form', [
            'type' => 'string_textfield',
            'weight' => 0,
          ])
          ->setDisplayConfigurable('form', TRUE);
        break;

        case \CRM_Utils_Type::T_LONGTEXT:
          $field = BaseFieldDefinition::create('text_long')
            ->setDisplayOptions('view', [
              'type' => 'text_default',
            ])
            ->setDisplayConfigurable('view', TRUE)
            ->setDisplayOptions('form', [
              'type' => 'text_textfield',
              'weight' => 0,
            ])
            ->setDisplayConfigurable('form', TRUE);
          break;

        case \CRM_Utils_Type::T_EMAIL:
          $field = BaseFieldDefinition::create('email')
            ->setSetting('max_length', 255)
            ->setDisplayOptions('view', [
              'label' => 'above',
              'type' => 'string',
              'weight' => 0,
            ])
            ->setDisplayOptions('form', [
              'type' => 'text_textfield',
              'weight' => 0,
            ])
            ->setDisplayConfigurable('form', TRUE)
            ->setDisplayConfigurable('view', TRUE);
          break;

        case \CRM_Utils_Type::T_URL:
          $field = BaseFieldDefinition::create('uri')
            ->setDisplayOptions('form', [
              'type' => 'uri',
              'weight' => 0,
            ])
            ->setDisplayConfigurable('form', TRUE);
          break;

        case \CRM_Utils_Type::T_DATE:
          $field = BaseFieldDefinition::create('datetime')
            ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATE)
            ->setDisplayOptions('form', [
              'type' => 'datetime_default',
              'weight' => 0,
            ]);
          break;
        case \CRM_Utils_Type::T_TIME:
        case (\CRM_Utils_Type::T_DATE + \CRM_Utils_Type::T_TIME):
          $field = BaseFieldDefinition::create('datetime')
            ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
            ->setDisplayOptions('form', [
              'type' => 'datetime_default',
              'weight' => 0,
            ]);
          break;

        case \CRM_Utils_Type::T_ENUM:
          $field = BaseFieldDefinition::create('map');
          break;

        case \CRM_Utils_Type::T_TIMESTAMP:
          $field = BaseFieldDefinition::create('timestamp');
          break;

        default:
          $field = BaseFieldDefinition::create('any');
          break;
      }
    }

    $field
      ->setLabel($civicrm_field['title'])
      ->setDescription(isset($civicrm_field['description']) ? $civicrm_field['description'] : '');

    if ($field->getType() != 'boolean') {
      $field->setRequired(isset($civicrm_field['required']) && (bool) $civicrm_field['required']);
    }

    return $field;
  }
}
