<?php

namespace Drupal\tmgmt_textmaster\Plugin\views\field;

use Drupal\tmgmt\JobInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler which shows the price of TextMaster Projects.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("tmgmt_textmaster_price")
 */
class TextMasterProjectPrice extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {

    /** @var \Drupal\tmgmt\JobInterface $entity */
    if (empty($entity = $values->_entity)
      || !$entity instanceof JobInterface
      || empty($price = $entity->getSetting('project_price'))
    ) {
      return '';
    }

    return $price;
  }

}
