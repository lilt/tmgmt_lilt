<?php

namespace Drupal\tmgmt_lilt\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\Entity\Job;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes Lilt translation jobs to automatically pull and publish translations.
 *
 * @QueueWorker(
 *   id = "tmgmt_lilt_publish_translations",
 *   title = @Translation("Publish Lilt Translations"),
 *   cron = {"time" = 60}
 * )
 */
class PublishTranslations extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a PublishTranslations Queue Worker.
   *
   * @param array $configuration
   *   A configuration array.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger')
    );
  }

  /**
   * Validates input job before processing it
   *
   * @param array $data
   *   The queue item data.
   *
   * @return \Drupal\tmgmt\Entity\Job|null
   *   The job if everything is successful, null if validation fails
   */
  protected function validateJob($data) {
    if (empty($data['job_id'])) {
      return;
    }

    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = Job::load($data['job_id']);
    if (!$job) {
      // no job found
      return;
    }

    // Process only Lilt translation jobs
    if (!$job->hasTranslator() || $job->getTranslatorId() !== 'lilt') {
      return;
    }

    // If job is not active anymore, stop processing 
    if (! $job->isActive()) {
      return ;
    }

    return $job;
  }

    /**
   * For a given job, verifies whether associated Lilt projects still exists
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   The job to be processed
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE in case of failure.
   */
  protected function validateLiltProject($job) {

    // Retrieve the Lilt project_id given the current job
    $project_id = \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator::getJobProjectId($job);
    if (!$project_id) {
      // No project found
      return;
    }

    // Configure the translation plugin

    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    // Retrieve the remote project info.
    $project_info = $translator_plugin->getLiltProject($project_id);

    if (!$project_info ||  !isset($project_info) || empty($project_info)){
      // No project info retrieved
      return;
    }

    return $project_info;
  }

  /**
   * Process one queue item.
   *
   * The data is expected to contain a key 'job_id', representing a job in ACTIVE state.
   *
   * @param array $data
   *   The queue item data.
   */
  public function processItem($data) {
    
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $this->validateJob($data);

    if(empty($job)) {
      // the job cannot be processed
      \Drupal::messenger()->addMessage(t('Fetched queue job @data cannot be processed.', ['@data' => $data['job_id']]));
      return ;
    }

    $project_info = $this->validateLiltProject($job);

    if (empty($project_info)) {
      // the lilt project couldn't be fetched
      $job->addMessage(t('The Lilt Project could not be fetched'));
      return ;
    }

    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    // valid Lilt Project, ready to be published

    if (isset($project_info['state']) && $project_info['state'] === 'done') {
      // process single project if not array returned;
      $translator_plugin->fetchAsyncTranslatedFiles($job);
      // Accept the translations and mark the job finished
      $job->acceptTranslation();
      $job->finished();
    } 
    else {
      $job->addMessage(t('The Lilt project is in state: @state. Could not fetch translations', ['@state' => $project_info['state']]));
    }
  }

}
