<?php

namespace Drupal\tmgmt_textmaster\Plugin\views\field;

use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator;
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
   * Translation plugin TextmasterTranslator.
   *
   * @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator
   */
  public $translatorPlugin;

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {

    /** @var \Drupal\tmgmt\JobInterface $entity */
    if (!empty($entity = $values->_entity) && $entity instanceof JobInterface) {
      if (empty($price = $entity->getSetting('project_price'))) {
        $price = $this->getProjectPriceForJob($entity);
        if (empty($price)) {
          return '';
        }
        $this->setProjectPriceForJob($entity, $price);
      }
      return $price;
    }
    return '';
  }

  /**
   * Get TextMaster Project price.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job.
   *
   * @return string|false
   *   Price if exists in  TextMaster project data.
   */
  public function getProjectPriceForJob(JobInterface $job) {
    if (empty($this->getTranslatorPlugin($job))) {
      return FALSE;
    }
    $project_id = tmgmt_textmaster_get_project_by_job_id($job->id());
    $project_info = $this->translatorPlugin->getTmProject($project_id);
    if (isset($project_info['cost_in_currency']) && !empty($cost = $project_info['cost_in_currency'])) {
      return round($cost['amount'], 2) . ' ' . $cost['currency'];
    }
    if (isset($project_info['total_costs'][0]['amount']) && !empty($project_info['total_costs'][0]['amount'])) {
      return round($project_info['total_costs'][0]['amount'], 2) . ' ' . $project_info['total_costs'][0]['currency'];
    }
    return FALSE;
  }

  /**
   * Set TextMaster Project price for job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job.
   * @param string $price
   *   TextMaster Project price.
   *
   * @return bool
   *   TRUE if success.
   */
  public function setProjectPriceForJob(JobInterface $job, $price) {
    if (empty($this->getTranslatorPlugin($job))) {
      return FALSE;
    }
    $settings = $job->settings->getValue();
    $settings[0]['project_price'] = $price;
    $job->settings->setValue($settings);
    $job->save();
    return TRUE;
  }

  /**
   * Helper to get job translator plugin.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job entity.
   *
   * @return \Drupal\tmgmt\TranslatorPluginInterface
   *   Returns the TranslatorPluginInterface.
   */
  protected function getTranslatorPlugin(JobInterface $job) {
    if (empty($this->translatorPlugin)) {
      /** @var \Drupal\tmgmt\TranslatorPluginInterface $translator_plugin */
      if (!$job->hasTranslator()) {
        return $this->translatorPlugin;
      }
      $translator_plugin = $job->getTranslator()->getPlugin();
      if ($translator_plugin instanceof TextmasterTranslator) {
        $translator_plugin->setTranslator($job->getTranslator());
        $this->translatorPlugin = $translator_plugin;
      }
    }
    return $this->translatorPlugin;
  }

}
