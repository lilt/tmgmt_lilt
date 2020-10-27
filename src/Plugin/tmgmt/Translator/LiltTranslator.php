<?php

namespace Drupal\tmgmt_lilt\Plugin\tmgmt\Translator;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use function GuzzleHttp\Psr7\parse_query;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Lilt translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "lilt",
 *   label = @Translation("Lilt"),
 *   description = @Translation("Lilt translation service."),
 *   ui = "Drupal\tmgmt_lilt\LiltTranslatorUi",
 * )
 */
class LiltTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * TMGMT translator.
   *
   * @var \Drupal\tmgmt\TranslatorInterface
   */
  private $translator;

  /**
   * Constructs a LiltTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \GuzzleHttp\ClientInterface $client */
    $client = $container->get('http_client');
    return new static(
      $client,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function abortTranslation(JobInterface $job) {
    if (!$this->translator) {
      $this->setTranslator($job->getTranslator());
    }
    $mappings = $job->getRemoteMappings();
    $mapping = end($mappings);
    $project_id = $mapping->remote_identifier_2->value;
    $project_info = $this->getLiltProject($project_id);
    if (in_array($project_info['status'], ['inProgress', 'inReview', 'inQA'])) {
      $job->addMessage('Could not cancel the project "@job_title" with status at "@status"', [
        '@status' => $project_info['status'],
        '@job_title' => $job->label(),
      ]);
      return FALSE;
    }
    $this->archiveLiltProject($project_id);
    $job->aborted();
    return TRUE;
  }

  /**
   * Retrieve the data of a file in a state.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job to which will be added the data.
   * @param string $document_state
   *   The state of the file.
   * @param int $project_id
   *   The project ID.
   * @param string $document_id
   *   The Document ID.
   * @param string $remote_file_url
   *   Translated file url.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */

  public function addTranslationToJob(JobInterface $job, $document_state, $project_id, $document_id, $remote_file_url) {
    $translated_file = $this->request('documents/files?is_xliff=false&id=' . $document_id, 'GET', [], TRUE);
    $file_data = $this->parseTranslationData($translated_file);
    $status = TMGMT_DATA_ITEM_STATE_TRANSLATED;
    $job->addTranslatedData($file_data, [], $status);
    $mappings = RemoteMapping::loadByRemoteIdentifier('tmgmt_lilt', $project_id, $document_id);
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = reset($mappings);
    $mapping->removeRemoteData('LiltState');
    $mapping->addRemoteData('LiltState', $status);
    $mapping->save();
  }

  /**
   * Archive Lilt project.
   *
   * @param string $project_id
   *   Lilt project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function archiveLiltProject($project_id) {
    try {
      $project_info = $this->getLiltProject($project_id);
      $project_info['archived'] = TRUE;
      // The Lilt API enforces a range on this property. Unset from PUT body in case property is out range.
      // @see https://lilt.com/docs/api#operation--projects-put
      unset($project_info['sample_review_percentage']);
      $result = $this->sendApiRequest('projects', 'PUT', [], FALSE, FALSE, json_encode($project_info));
      return $result;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_lilt')->error('Could not archive the Lilt Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    $this->setTranslator($translator);
    if ($this->checkLiltAuth()) {
      return AvailableResult::yes();
    }
    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * Checks that Lilt authentication works.
   *
   * @return bool
   *   A success or failure.
   */
  public function checkLiltAuth() {
    try {
      if ($this->getServiceRoot()) {
        return TRUE;
      }
    }
    catch (TMGMTException $ex) {
      \Drupal::logger('tmgmt_lilt')->warning('Unable to log in to Lilt API: ' . $ex->getMessage());
    }
    return FALSE;
  }

  /**
   * Batch callback for Document creation process.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   Job Item.
   * @param \Drupal\tmgmt\JobInterface $job
   *   Drupal tmgmt Job.
   * @param string $project_id
   *   Project in Lilt for this job.
   * @param array $context
   *   An array that will contain information about the
   *   status of the batch. The values in $context will retain their
   *   values as the batch progresses.
   */
  public static function createDocumentForJobItemBatchProcess(JobItemInterface $job_item, JobInterface $job, $project_id, array &$context) {

    // Init:
    if (empty($context['results'])) {
      $context['results']['job_id'] = $job_item->getJobId();
      $context['results']['project_id'] = $project_id;
      $context['results']['created'] = 0;
      $context['results']['errors'] = [];
    }

    // Create Document:
    try {
      /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
      $translator_plugin = $job->getTranslator()->getPlugin();
      $translator_plugin->setTranslator($job->getTranslator());
      $document_id = $translator_plugin->sendFiles($job_item, $project_id);

      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
      $remote_mapping = RemoteMapping::create([
        'tjid' => $job->id(),
        'tjiid' => $job_item->id(),
        'remote_identifier_1' => 'tmgmt_lilt',
        'remote_identifier_2' => $project_id,
        'remote_identifier_3' => $document_id,
        'remote_data' => [
          'FileStateVersion' => 1,
          'LiltState' => TMGMT_DATA_ITEM_STATE_PRELIMINARY,
          'WordCountFinished' => FALSE,
        ],
      ]);
      $remote_mapping->save();
      $job->addMessage('Created a new Document in Lilt with the id: @id for Job Item: @item_label', [
        '@id' => $document_id,
        '@item_label' => $job_item->label(),
      ], 'debug');

      if ($job_item->getJob()->isContinuous()) {
        $job_item->active();
      }

      $context['results']['created']++;
    }
    // Fail:
    catch (\Exception $e) {
      if (isset($remote_mapping)) {
        $remote_mapping->delete();
      }
      $message = t('Exception occurred while creating a Document for the job item "@job_item": @error.', [
        '@job_item' => $job_item->label(),
        '@error' => $e->getMessage(),
      ]);
      $job->addMessage($message->render(), [], 'debug');

      $context['results']['errors'][] = $message;
    }

    $context['finished'] = 1;
  }

  /**
   * Batch 'finished' callback for Creating Lilt documents process.
   *
   * @param bool $success
   *   Batch success.
   * @param array $results
   *   Results.
   * @param array $operations
   *   Operations.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|false
   *   Redirects to jobs overview page if success.
   */
  public static function createDocumentForJobItemBatchFinish($success, array $results, array $operations) {

    if (!$success) {
      return FALSE;
    }
    $errors = $results['errors'];
    $created = $results['created'];
    $job = Job::load($results['job_id']);
    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    // Succeed:
    if (count($errors) == 0 && !empty($created)) {
      if (!$job->isRejected()) {
        $job->setState(Job::STATE_ACTIVE, 'The translation job has been submitted.');
      }
      \Drupal::messenger()->addMessage(t('@created document(s) was(were) created in Lilt for Job "@job_label".', [
        '@created' => $created,
        '@job_label' => $job->label(),
      ]));
      $jobs_list_url = Url::fromRoute('view.tmgmt_job_overview.page_1')->toString();
      return new RedirectResponse($jobs_list_url);
    }
    // Fail:
    elseif (!empty($created)) {
      $message = t('Project for job @job_label was not finalized. @created documents were created in Lilt. @errors_count error(s) occurred during Document creation: @error', [
        '@job_label' => $job->label(),
        '@created' => $created,
        '@errors_count' => count($errors),
        '@error' => implode('; ', $errors),
      ]);
      \Drupal::messenger()->addMessage($message->render());
    }
    else {
      $message = t('Project for job @job_label was not finalized. Error(s) occurred during Document creation: @error', [
        '@job_label' => $job->label(),
        '@error' => implode('; ', $errors),
      ]);
      \Drupal::messenger()->addMessage($message->render());
    }
  }

  /**
   * Creates new translation project at Lilt.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   *
   * @return int
   *   Lilt Project ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function createLiltProject(JobInterface $job) {
    // Prepare parameters for Project API.
    $name = $job->get('label')->value ?: 'Drupal Lilt project ' . $job->id();
    $params = [
        'name' => $name,
        'memory_id' => $job->getSetting('memory_id'),
        'due_date' => $job->getSetting('due_date')->format('U'),
        'metadata' => [
          'connectorType' => 'drupal',
          'notes' => 'Drupal Lilt project ' . $job->id(),
        ]
    ];
    $result = $this->sendApiRequest('projects', 'POST', [], FALSE, FALSE, json_encode($params));

    return $result['id'];
  }

  /**
   * Creates a file resource at Lilt.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param string $name
   *   File name of the .XLIFF file without extension.
   *
   * @return string
   *   The URL of uploaded file.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function createLiltRemoteFile($xliff, $name, $project_id) {
    $file_name = $name . '.xliff';
    $service_url = $this->translator->getSetting('lilt_service_url');
    $api_key = $this->translator->getSetting('lilt_api_key');
    if (!$service_url || !$api_key) {
      throw new TMGMTException('Could not call Lilt API when API key or URL is not set.');
    }

    // Set parameters to request for upload properties from Lilt API.
    $params = [
      'name' => $file_name,
      'project_id' => $project_id,
    ];

    // Set headers and body for file PUT request.
    $options['headers']['Authorization'] = 'Basic ' . base64_encode($api_key . ':' . $api_key);
    $options['headers']['Content-Type'] = 'application/octet-stream';
    $options['headers']['LILT-API'] = json_encode($params);
    $options['body'] = $xliff;

    // We don't need apiRequest here just common request.
    $file_response = $this->client->request('POST', $service_url . '/documents/files', $options);
    if ($file_response->getStatusCode() != 200) {
      throw new TMGMTException('Could not Upload the file ' . $file_name . ' to Lilt.');
    }

    $res = json_decode($file_response->getBody(), true);
    return $res['id'];
  }

  /**
   * Delete Lilt project.
   *
   * @param string $project_id
   *   Lilt project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function deleteLiltProject($project_id) {
    try {
      $project_info = $this->getLiltProject($project_id);
      if (!isset($project_info['id'])) {
        throw new TMGMTException('Could not delete Lilt Project @id', ['@id' => $project_id]);
      }

      return $this->sendApiRequest('projects', 'DELETE', ['id' => $project_info['id']]);
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_lilt')->error('Could not delete the Lilt Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Batch 'finished' callback for pull Job translations process.
   *
   * @param bool $success
   *   Batch success.
   * @param array $results
   *   Results.
   * @param array $operations
   *   Operations.
   */
  public static function fetchTranslationsBatchFinish($success, array $results, array $operations) {
    if (!$success) {
      return;
    }
    $translated = $results['translated'];
    $untranslated = $results['untranslated'];
    $errors = $results['errors'];
    $job = Job::load($results['job_id']);
    if (count($errors) == 0) {
      if ($untranslated == 0 && $translated != 0) {
        $job->addMessage(t('Fetched translations for @translated job item(s).', ['@translated' => $translated]));
      }
      elseif ($translated == 0) {
        \Drupal::messenger()->addMessage(t('No job item has been translated yet.'));
      }
      else {
        $job->addMessage(t('Fetched translations for @translated job item(s), @untranslated are not translated yet.', [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('Error(s) occurred during fetching translations for Job: @error', ['@error' => implode('; ', $errors)]));
    }

    tmgmt_write_request_messages($job);
  }

  /**
   * Batch callback for pull Job translations process.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   Job Item.
   * @param array $context
   *   An array that will contain information about the
   *   status of the batch. The values in $context will retain their
   *   values as the batch progresses.
   */
  public static function fetchTranslationsBatchProcess(JobItemInterface $job_item, array &$context) {
    // Set results:
    if (empty($context['results'])) {
      $context['results']['job_id'] = $job_item->getJobId();
      $context['results']['errors'] = [];
      $context['results']['translated'] = 0;
    }

    // Load data:
    $translated = $context['results']['translated'];
    $errors = $context['results']['errors'];
    $job = $job_item->getJob();

    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    $is_item_translated = FALSE;
    $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());

    // Fetch translation:
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    foreach ($mappings as $mapping) {
      try {
        $translator_plugin->addTranslationToJob($job, NULL, $mapping->getRemoteIdentifier2(), $mapping->getRemoteIdentifier3(), NULL);
        $is_item_translated = TRUE;
      }
      catch (TMGMTException $e) {
        $job->addMessage('Exception occurred while fetching the job item "@job_item": @error.', [
          '@job_item' => $job_item->label(),
          '@error' => $e->getMessage(),
        ], 'error');
        $errors[] = 'Exception occurred while fetching the job item ' . $job_item->label();
      }
    }
    if ($is_item_translated) {
      $translated++;
    }

    // Set results:
    $context['results']['translated'] = $translated;
    $context['results']['untranslated'] = count($job->getItems()) - $translated;
    $context['results']['errors'] = $errors;

    // Inform the batch engine that we finished the operation with this item.
    $context['finished'] = 1;
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   A job containing job items that translations will be fetched for.
   */
  public function fetchTranslatedFiles(JobInterface $job) {
    $job_items = $job->getItems();

    foreach ($job_items as $job_item) {
      $operations[] = [
        [static::class, 'fetchTranslationsBatchProcess'],
        [$job_item],
      ];
    }
    $batch = [
      'title' => t('Pulling translations'),
      'operations' => $operations,
      'finished' => [static::class , 'fetchTranslationsBatchFinish'],
      'init_message' => t('Pull Translation batch is starting.'),
      'progress_message' => t('Processed @current out of @total Job Items.'),
    ];
    batch_set($batch);
  }

  /**
   * Gets the supported Lilt languages.
   *
   * @return array|int|null
   *   Account info.
   */
  public function getLanguages() {
    return $this->sendApiRequest('languages');
  }

  /**
   * Get remote mapping for a Job Item.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   Job Item.
   *
   * @return array
   *   Associative array with basic remote data.
   */
  static function getJobItemMapping(JobItemInterface $job_item) {
    $remote_mapping = $job_item->getRemoteMappings();
    $remote_mapping = end($remote_mapping);
    return [
      'module_name' => $remote_mapping->remote_identifier_1->value,
      'project_id' => $remote_mapping->remote_identifier_2->value,
      'document_id' => $remote_mapping->remote_identifier_3->value,
    ];
  }

  /**
   * Get a Lilt project ID for a Job.
   *
   * @param \Drupal\tmgmt\Entity\Job $job_item
   *   Job.
   *
   * @return string|false
   *   The Lilt project ID or FALSE.
   */
  static function getJobProjectId(Job $job) {
    $remote_mapping = $job->getRemoteMappings();
    $remote_mapping = end($remote_mapping);
    return isset($remote_mapping->remote_identifier_2->value) ? $remote_mapping->remote_identifier_2->value : FALSE;
  }

  /**
   * Get the Lilt App base URL.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   TMGMT Translator.
   *
   * @return string
   *   The Lilt App base URL.
   */
  static function getLiltAppURL(TranslatorInterface $translator) {
    return $translator->getSetting('lilt_app_url');
  }

  /**
   * Get Lilt document.
   *
   * @param string $document_id
   *   Lilt document id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function getLiltDocument($document_id) {
    try {
      return $this->sendApiRequest('documents?id=' . $document_id, 'GET');
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_lilt')->error('Could not get the Lilt Document: @error', ['@error' => $e->getMessage()]);
    }
    return [];
  }

  /**
   * Get Lilt project.
   *
   * @param string $project_id
   *   Lilt project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function getLiltProject($project_id) {
    try {
      $projects = $this->sendApiRequest('projects?id=' . $project_id);
      return (is_array($projects) && isset($projects[0])) ? $projects[0] : $projects;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_lilt')->error('Could not get the Lilt Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Gets the Lilt translation memories.
   *
   * @return array|int|null
   *   The keyed array (ID => NAME) of objects containing all translation memories.
   */
  public function getTranslationMemories() {
    $output = [];
    $memories = $this->sendApiRequest('memories');
    if (is_array($memories)) {
      foreach ($memories as $memory) {
        $output[$memory['id']] = $memory['name'];
      }
    }
    return $output;
  }

  /**
   * Gets the service API root endpoint for any metadata.
   *
   * @return array|int|null
   *   API info.
   */
  public function getServiceRoot() {
    return $this->sendApiRequest('');
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $remote_languages = [];
    $this->setTranslator($translator);
    try {
      $supported_languages = $this->getLanguages();
      if (!$supported_languages) {
        return $remote_languages;
      }
      $remote_languages = $supported_languages['code_to_name'];
    }
    catch (\Exception $e) {
      $message = t('Exception occurred while getting remote languages: @error.', [
        '@error' => $e->getMessage(),
      ]);
      \Drupal::logger('tmgmt_lilt')->error($message);
    }
    asort($remote_languages);
    return $remote_languages;
  }

  /**
   * Logs an API request.
   *
   * @param string $method
   *   The HTTP method used.
   * @param string $url
   *   The HTTP URL used.
   * @param string $request
   *   The body contents of the HTTP request.
   * @param string $response
   *   The body contents of the HTTP response.
   */
  public function logApiRequest($method, $url, $request, $response) {
    $lilt_log_api = $this->translator->getSetting('lilt_log_api');
    if ($lilt_log_api) {
      \Drupal::logger('tmgmt_lilt')->debug('@method Request to @url:<br>
          <ul>
              <li>Request: @request</li>
              <li>Response: @response</li>
          </ul>
          ', [
            '@method' => $method,
            '@url' => $url,
            '@request' => $request,
            '@response' => $response,
          ]
      );
    }
  }

  /**
   * Parses translation from Lilt and returns unflatted data.
   *
   * @param string $data
   *   Xliff data, received from Lilt.
   *
   * @return array
   *   Unflatted data.
   */
  protected function parseTranslationData($data) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');
    // Import given data using XLIFF converter. Specify that passed content is
    // not a file.
    return $xliff_converter->import($data, FALSE);
  }

  /**
   * Does a request to Lilt API.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   (Optional) HTTP method (GET, POST...). By default uses GET method.
   * @param array $params
   *   (Optional) Form parameters to send to Lilt API.
   * @param bool $download
   *   (Optional) If we expect resource to be downloaded. FALSE by default.
   * @param bool $code
   *   (Optional) If we want to return the status code of the call. FALSE by
   *   default.
   * @param string $body
   *   (Optional) Body of the POST request. NULL by
   *   default.
   *
   * @return array|int
   *   Response array or status code.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function request($path, $method = 'GET', array $params = [], $download = FALSE, $code = FALSE, $body = NULL) {
    $options = [];
    if (!$this->translator) {
      throw new TMGMTException('There is no Translator entity. Access to the Lilt API is not possible.');
    }

    $service_url = $this->translator->getSetting('lilt_service_url');
    if (!$service_url) {
      \Drupal::logger('tmgmt_lilt')->warning('Attempt to call Lilt API when service_url is not set: ' . $path);
      return [];
    }
    $url = $service_url . '/' . $path;
    if ($body) {
      $options['body'] = $body;
    }

    $api_key = $this->translator->getSetting('lilt_api_key');
    if (!$api_key) {
      \Drupal::logger('tmgmt_lilt')->warning('Could not call Lilt API when API key or URL is not set: ' . $path);
      return [];
    }
    else {
      $options['headers'] = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_key),
      ];
    }

    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        if ($code) {
          return $e->getCode();
        }
        throw new TMGMTException('Unable to connect to Lilt API due to following error: @error', ['@error' => $e->getMessage()], $e->getCode());
      }
      $response = $e->getResponse();
      $this->logApiRequest($method, $url, $e->getRequest()->getBody()->getContents(), $response->getBody()->getContents());
      if ($code) {
        return $response->getStatusCode();
      }
      throw new TMGMTException('Unable to connect to Lilt API due to following error: @error', ['@error' => $response->getBody()->getContents()], $response->getStatusCode());
    }

    $received_data = $response->getBody()->getContents();
    $this->logApiRequest($method, $url, json_encode($options), $received_data);
    if ($code) {
      return $response->getStatusCode();
    }

    if ($response->getStatusCode() != 200) {
      throw new TMGMTException('Unable to connect to the Lilt API due to following error: @error at @url',
        ['@error' => $response->getStatusCode(), '@url' => $url]);
    }

    return ($download) ? $received_data : json_decode($received_data, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    if ($job->isRejected()) {
      $job->setState(Job::STATE_UNPROCESSED);
    }
    $this->setTranslator($job->getTranslator());
    try {
      $project_id = $this->createLiltProject($job);
      $job->addMessage('Created a new Project in Lilt with the id: @id', ['@id' => $project_id], 'debug');

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        $operations[] = [
          [static::class, 'createDocumentForJobItemBatchProcess'],
          [$job_item, $job, $project_id],
        ];
      }
      $batch = [
        'title' => t('Create Lilt Documents'),
        'operations' => $operations,
        'finished' => [static::class , 'createDocumentForJobItemBatchFinish'],
        'init_message' => t('Create Lilt Documents batch is starting.'),
        'progress_message' => t('Processed @current out of @total Job Items.'),
      ];

      batch_set($batch);
    }
    catch (TMGMTException $e) {
      $job->rejected('Job has been rejected with following error: @error', ['@error' => $e->getMessage()], 'error');
    }
    return $job;
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $this->requestJobItemsTranslation($job->getItems());
  }

  /**
   * Sends a request to the Lilt API.
   *
   * @param string $path
   *   API path.
   * @param string $method
   *   (Optional) HTTP method.
   * @param array $params
   *   (Optional) API params.
   * @param bool $download
   *   (Optional) If true, return the response body as a downloaded content.
   * @param bool $code
   *   (Optional) If true, return only the response HTTP status code.
   * @param string $body
   *   (Optional) An optional request body.
   *
   * @return array|int|null
   *   Result of the API request.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function sendApiRequest($path, $method = 'GET', array $params = [], $download = FALSE, $code = FALSE, $body = NULL) {
    $result = NULL;
    try {
      $result = $this->request($path, $method, $params, $download, $code, $body);
    }
    catch (TMGMTException $e) {
      switch ($e->getCode()) {
        // Re-try:
        case 401:
          $result = $this->request($path, $method, $params, $download, $code, $body);
        default:
          throw $e;
      }
    }
    return $result;
  }

  /**
   * Send files to Lilt.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   * @param int $project_id
   *   Lilt Project id.
   *
   * @return string
   *   Lilt Document Id.
   */
  public function sendFiles(JobItemInterface $job_item, $project_id) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

    $job_item_id = $job_item->id();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $conditions = ['tjiid' => ['value' => $job_item_id]];
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_item->getJob()->id()}_JobItemID_{$job_item_id}_{$job_item->getJob()->getSourceLangcode()}_{$target_language}";

    $document_id = $this->createLiltRemoteFile($xliff, $name, $project_id);

    return $document_id;
  }

  /**
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }

}
