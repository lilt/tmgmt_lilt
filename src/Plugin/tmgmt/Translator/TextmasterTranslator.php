<?php

namespace Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator;

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
 * TextMaster translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "textmaster",
 *   label = @Translation("TextMaster"),
 *   description = @Translation("TextMaster translation service."),
 *   ui = "Drupal\tmgmt_textmaster\TextmasterTranslatorUi",
 * )
 */
class TextmasterTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {

  /**
   * The translator.
   *
   * @var \Drupal\tmgmt\TranslatorInterface
   */
  private $translator;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Constructs a TextmasterTranslator object.
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
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $supported_remote_languages = [];
    $this->setTranslator($translator);
    try {
      $supported_languages = $this->sendApiRequest('v1/public/languages');
      if (!$supported_languages) {
        return $supported_remote_languages;
      }
      foreach ($supported_languages['languages'] as $language) {
        if (!preg_match('/[-]/', $language['code'])) {
          continue;
        }
        $supported_remote_languages[$language['code']] = $language['value']
          . ' ('
          . $language['code']
          . ')';
      }
    }
    catch (\Exception $e) {
      $message = t('Exception occurred while getting remote languages: @error.', [
        '@error' => $e,
      ]);
      \Drupal::logger('tmgmt_textmaster')->error($message);
    }
    asort($supported_remote_languages);
    return $supported_remote_languages;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    $this->setTranslator($translator);
    if ($this->checkTextmasterAuthentication()) {
      return AvailableResult::yes();
    }
    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $this->requestJobItemsTranslation($job->getItems());
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    if ($job->isRejected()) {
      // Change the status to Unprocessed to allow submit again.
      $job->setState(Job::STATE_UNPROCESSED);
    }
    $this->setTranslator($job->getTranslator());
    try {
      $project_id = $this->createTmProject($job);
      $job->addMessage('Created a new Project in TextMaster with the id: @id', ['@id' => $project_id], 'debug');

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        $operations[] = [
          [static::class, 'createDocumentForJobItemBatchProcess'],
          [$job_item, $job, $project_id],
        ];
      }
      $batch = [
        'title' => t('Creating TextMaster Documents'),
        'operations' => $operations,
        'finished' => [static::class , 'createDocumentForJobItemBatchFinish'],
        'init_message' => t('Creating TextMaster Documents batch is starting.'),
        'progress_message' => t('Processed @current out of @total Job Items.'),
      ];

      // Set batch process for Documents creation.
      batch_set($batch);

    }
    catch (TMGMTException $e) {
      $job->rejected('Job has been rejected with following error: @error',
        ['@error' => $e->getMessage()], 'error');
    }
    return $job;
  }

  /**
   * Batch callback for Document creation process.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   Job Item.
   * @param \Drupal\tmgmt\JobInterface $job
   *   Drupal tmgmt Job.
   * @param string $project_id
   *   Project in TextMaster for this job.
   * @param array $context
   *   An array that will contain information about the
   *   status of the batch. The values in $context will retain their
   *   values as the batch progresses.
   */
  public static function createDocumentForJobItemBatchProcess(JobItemInterface $job_item, JobInterface $job, $project_id, array &$context) {
    if (empty($context['results'])) {
      // Set initial values.
      $context['results']['job_id'] = $job_item->getJobId();
      $context['results']['project_id'] = $project_id;
      $context['results']['created'] = 0;
      $context['results']['errors'] = [];
    }

    try {
      /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
      $translator_plugin = $job->getTranslator()->getPlugin();
      $translator_plugin->setTranslator($job->getTranslator());
      $document_id = $translator_plugin->sendFiles($job_item, $project_id);

      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
      $remote_mapping = RemoteMapping::create([
        'tjid' => $job->id(),
        'tjiid' => $job_item->id(),
        'remote_identifier_1' => 'tmgmt_textmaster',
        'remote_identifier_2' => $project_id,
        'remote_identifier_3' => $document_id,
        'remote_data' => [
          'FileStateVersion' => 1,
          'TMState' => TMGMT_DATA_ITEM_STATE_PRELIMINARY,
          'TemplateAutoLaunch' => $translator_plugin->isTemplateAutoLaunch($job->settings->templates_wrapper['project_template']),
        ],
      ]);
      $remote_mapping->save();
      $job->addMessage('Created a new Document in TextMaster with the id: @id for Job Item: @item_label', [
        '@id' => $project_id,
        '@item_label' => $job_item->label(),
      ], 'debug');

      if ($job_item->getJob()->isContinuous()) {
        $job_item->active();
      }

      $context['results']['created']++;
    }
    catch (\Exception $e) {
      // Delete Remote mapping if error occurred.
      if (isset($remote_mapping)) {
        $remote_mapping->delete();
      }
      $message = t('Exception occurred while creating a Document for the job item "@job_item": @error.', [
        '@job_item' => $job_item->label(),
        '@error' => $e,
      ]);
      $job->addMessage($message->render(), [], 'debug');

      $context['results']['errors'][] = $message;
    }

    // Inform the batch engine that we finished the operation with this item.
    $context['finished'] = 1;
  }

  /**
   * Batch 'finished' callback for Creating TextMaster documents process.
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
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    if (count($errors) == 0 && !empty($created)) {

      // Everything went OK. We can Finalize the project.
      $translator_plugin->finalizeTmProject($results['project_id']);
      $job->addMessage('A TextMaster Project with the id: @id was finalized', [
        '@id' => $results['project_id'],
      ], 'debug');
      // Set Job state.
      if (!$job->isRejected()) {
        $mappings = $job->getRemoteMappings();
        $job_remote_data = end($mappings);
        $auto_launch = $job_remote_data->remote_data->TemplateAutoLaunch;
        if ($auto_launch) {
          $job->submitted();
        }
        else {
          $job->setState(Job::STATE_UNPROCESSED, 'The translation job has been submitted.');
        }
      }
      drupal_set_message(t('@created document(s) was(were) created in TextMaster for Job "@job_label".', [
        '@created' => $created,
        '@job_label' => $job->label(),
      ]));
      $jobs_list_url = Url::fromRoute('view.tmgmt_job_overview.page_1')
        ->toString();
      return new RedirectResponse($jobs_list_url);
    }
    // Some errors occurred. Show them.
    elseif (!empty($created)) {
      $message = t('Project for job @job_label was not finalized. @created documents were created in TextMaster. @errors_count error(s) occurred during Document creation: @error', [
        '@job_label' => $job->label(),
        '@created' => $created,
        '@errors_count' => count($errors),
        '@error' => implode('; ', $errors),
      ]);
      drupal_set_message($message->render());
    }
    else {
      $message = t('Project for job @job_label was not finalized. Error(s) occurred during Document creation: @error', [
        '@job_label' => $job->label(),
        '@error' => implode('; ', $errors),
      ]);
      drupal_set_message($message->render());
    }
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
    $project_info = $this->getTmProject($project_id);
    if (!in_array($project_info['status'], ['in_creation', 'in_progress'])) {
      $job->addMessage('Could not cancel the project "@job_title" with status "@status"', [
        '@status' => $project_info['status'],
        '@job_title' => $job->label(),
      ]);
      return FALSE;
    }
    if ($project_info['status'] != 'in_creation') {
      $this->pauseTmProject($project_id);
    }
    $this->cancelTmProject($project_id);
    $job->aborted();
    return TRUE;
  }

  /**
   * Checks the TextMaster account.
   *
   * @return bool
   *   A success or failure.
   */
  public function checkTextmasterAuthentication() {
    try {
      $result = $this->getTmAccountInfo();
      if ($result) {
        // Successfully Authenticated.
        return TRUE;
      }
    }
    catch (TMGMTException $ex) {
      \Drupal::logger('tmgmt_textmaster')
        ->warning('Unable to log in to TextMaster API: ' . $ex->getMessage());
    }
    return FALSE;
  }

  /**
   * Gets the TextMaster Account information.
   *
   * @return array|int|null
   *   Account info.
   */
  public function getTmAccountInfo() {
    return $this->sendApiRequest('v1/clients/users/me');
  }

  /**
   * Sends a request to the TextMaster API.
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
    catch (TMGMTException $ex) {
      if ($ex->getCode() == 401) {
        // Authentication failed , try to re-connect.
        $result = $this->request($path, $method, $params, $download, $code, $body);
      }
      else {
        throw $ex;
      }
    }
    return $result;
  }

  /**
   * Does a request to TextMaster API.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   (Optional) HTTP method (GET, POST...). By default uses GET method.
   * @param array $params
   *   (Optional) Form parameters to send to TextMaster API.
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
      throw new TMGMTException('There is no Translator entity. Access to the TextMaster API is not possible.');
    }
    $service_url = $this->translator->getSetting('textmaster_service_url');

    if (!$service_url) {
      \Drupal::logger('tmgmt_textmaster')
        ->warning('Attempt to call TextMaster API when service_url is not set: ' . $path);
      return [];
    }
    $url = $service_url . '/' . $path;
    if ($body) {
      $options['body'] = $body;
    }
    else {
      if ($method == 'GET' && isset($params['query'])) {
        $options['query'] = $params['query'];
      }
      else {
        $options['json'] = $params;
      }
    }

    // Default headers for TextMaster Api requests.
    $date = $this->utcDate();
    $options['headers'] = [
      'Apikey' => $this->translator->getSetting('textmaster_api_key'),
      'Date' => $date,
      'Signature' => $this->getTextmasterSignature($date, $this->translator->getSetting('textmaster_api_secret')),
      'Content-Type' => 'application/json',
    ];

    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        if ($code) {
          return $e->getCode();
        }
        throw new TMGMTException('Unable to connect to TextMaster API due to following error: @error', ['@error' => $e->getMessage()], $e->getCode());
      }
      $response = $e->getResponse();
      \Drupal::logger('tmgmt_textmaster')->error('%method Request to %url:<br>
          <ul>
              <li>Request: %request</li>
              <li>Response: %response</li>
          </ul>
          ', [
            '%method' => $method,
            '%url' => $url,
            '%request' => $e->getRequest()->getBody()->getContents(),
            '%response' => $response->getBody()->getContents(),
          ]
      );
      if ($code) {
        return $response->getStatusCode();
      }
      throw new TMGMTException('Unable to connect to TextMaster API due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }
    $received_data = $response->getBody()->getContents();
    \Drupal::logger('tmgmt_textmaster')->debug('%method Request to %url:<br>
          <ul>
              <li>Request: %request</li>
              <li>Response: %response</li>
          </ul>
          ', [
            '%method' => $method,
            '%url' => $url,
            '%request' => json_encode($options),
            '%response' => $received_data,
          ]
    );
    if ($code) {
      return $response->getStatusCode();
    }

    if ($response->getStatusCode() != 200) {
      throw new TMGMTException('Unable to connect to the TextMaster API due to following error: @error at @url',
        ['@error' => $response->getStatusCode(), '@url' => $url]);
    }

    // If we are expecting a download, just return received data.
    if ($download) {
      return $received_data;
    }
    $received_data = json_decode($received_data, TRUE);

    return $received_data;
  }

  /**
   * Creates new translation project at TextMaster.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   *
   * @return int
   *   TextMaster Project ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function createTmProject(JobInterface $job) {
    // Prepare parameters for Project API.
    $name = $job->get('label')->value ?: 'Drupal TMGMT project ' . $job->id();
    $params = [
      'project' => [
        'name' => $name,
        'activity_name' => 'translation',
        'api_template_id' => $job->settings->templates_wrapper['project_template'],
        'category' => 'C033',
      ],
    ];
    $result = $this->sendApiRequest('v1/clients/projects', 'POST', $params);

    return $result['id'];
  }

  /**
   * Function to check if api template "auto_launch" parameter is set TRUE.
   *
   * @param string $api_template_id
   *   The ID of TextMaster API template.
   *
   * @return bool
   *   True if template auto_launch parameter is set to true.
   */
  public function isTemplateAutoLaunch($api_template_id) {
    $templates = $this->getTmApiTemplates();
    foreach ($templates as $template) {
      if ($template['id'] === $api_template_id) {
        return $template['auto_launch'];
      }
    }
    return FALSE;
  }

  /**
   * Get TextMaster API templates.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function getTmApiTemplates() {
    $cache = \Drupal::cache()
      ->get('tmgmt_textmaster_api_templates');
    if (!empty($cache)) {
      return $cache->data;
    }

    try {
      $templates = $this->allPagesResult('v1/clients/api_templates', 'api_templates');
      \Drupal::cache()
        ->set('tmgmt_textmaster_api_templates', $templates, CacheBackendInterface::CACHE_PERMANENT, [
          'tmgmt_textmaster',
        ]);
      return $templates;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not get TextMaster API templates: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Function to get all pages result.
   *
   * @param string $request_path
   *   Path for request.
   * @param string $result_key
   *   The array key for results.
   * @param array $params
   *   Request params.
   * @param array $previous_pages_result
   *   The array with previous pages values.
   *
   * @return array|int|null
   *   Result of the API request.
   */
  public function allPagesResult($request_path, $result_key, array $params = [], array $previous_pages_result = []) {
    $result = $this->sendApiRequest($request_path, 'GET', $params);
    $all_pages_list = array_merge($result[$result_key], $previous_pages_result);
    if (isset($result['next_page'])) {
      // Prepare next page query.
      $next_page_query = parse_url($result['next_page'], PHP_URL_QUERY);
      $next_page_params = [
        'query' => parse_query($next_page_query),
      ];
      return $this->allPagesResult($request_path, $result_key, $next_page_params, $all_pages_list);
    }
    return $all_pages_list;
  }

  /**
   * Finalizes TextMaster project.
   *
   * @param string $project_id
   *   TextMaster project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function finalizeTmProject($project_id) {
    try {
      $result = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/finalize', 'PUT', []);
      return $result;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not get the TextMaster Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Cancel TextMaster project.
   *
   * @param string $project_id
   *   TextMaster project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function cancelTmProject($project_id) {
    try {
      $result = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/cancel', 'PUT', []);
      return $result;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not cancel the TextMaster Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Pause TextMaster project.
   *
   * @param string $project_id
   *   TextMaster project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function pauseTmProject($project_id) {
    try {
      $result = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/pause', 'PUT', []);
      return $result;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not pause the TextMaster Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Get TextMaster project.
   *
   * @param string $project_id
   *   TextMaster project id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function getTmProject($project_id) {
    try {
      return $this->sendApiRequest('v1/clients/projects/' . $project_id);
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not get the TextMaster Project: @error', ['@error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Send files to TextMaster.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   * @param int $project_id
   *   TextMaster Project id.
   *
   * @return string
   *   TextMaster Document Id.
   */
  public function sendFiles(JobItemInterface $job_item, $project_id) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')
      ->createInstance('xlf');

    $job_item_id = $job_item->id();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $conditions = ['tjiid' => ['value' => $job_item_id]];
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_item->getJob()->id()}_JobItemID_{$job_item_id}_{$job_item->getJob()->getSourceLangcode()}_{$target_language}";

    $remote_file_url = $this->createTmRemoteFile($xliff, $name);
    $document_id = $this->createTmDocument($project_id, $remote_file_url, $name);

    return $document_id;
  }

  /**
   * Creates a file resource at TextMaster.
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
  public function createTmRemoteFile($xliff, $name) {
    $file_name = $name . '.xliff';
    $file_hash = hash('sha256', $xliff);

    // Set Parametres to request for upload properties from TextMaster API.
    $params = [
      'file_name' => $file_name,
      'hashed_payload' => $file_hash,
    ];
    $upload_properties = $this->sendApiRequest('v1/clients/s3_upload_properties.json', 'POST', $params);
    if (!isset($upload_properties['url']) || !isset($upload_properties['headers'])) {
      throw new TMGMTException('Could not obtain upload properties from TextMaster API');
    }
    // Set headers and body for file PUT request.
    $options['headers'] = $upload_properties['headers'];
    $options['headers']['Content-Type'] = 'application/xml';
    $options['body'] = $xliff;
    // We don't need apiRequest here just common request.
    $file_response = $this->client->request('PUT', $upload_properties['url'], $options);
    if ($file_response->getStatusCode() != 200) {
      throw new TMGMTException('Could not Upload the file ' . $file_name . ' to TextMaster.');
    }

    return $upload_properties['url'];
  }

  /**
   * Get TextMaster document.
   *
   * @param string $project_id
   *   TextMaster project id.
   * @param string $document_id
   *   TextMaster document id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function getTmDocument($project_id, $document_id) {
    try {
      return $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents/' . $document_id, 'GET');
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not get the TextMaster Document: @error', ['@error' => $e->getMessage()]);
    }
    return [];
  }

  /**
   * Creates a new job at TextMaster.
   *
   * @param string $project_id
   *   Project ID.
   * @param string $remote_file_url
   *   Remote file url.
   * @param string $document_title
   *   Remote Document title.
   *
   * @return string
   *   TextMaster Document ID.
   */
  public function createTmDocument($project_id, $remote_file_url, $document_title) {
    $callback_url = Url::fromRoute('tmgmt_textmaster.callback')
      ->setAbsolute()
      ->toString();
    $params = [
      'document' => [
        'title' => $document_title,
        'remote_file_url' => $remote_file_url,
        'deliver_work_as_file' => 'true',
        'perform_word_count' => 'true',
        'callback' => [
          'in_review' => [
            "url" => $callback_url,
            "format" => "json",
          ],
        ],
      ],
    ];
    $result = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents', 'POST', $params);

    return $result['id'];
  }

  /**
   * Complete TextMaster document.
   *
   * @param string $project_id
   *   TextMaster project id.
   * @param string $document_id
   *   TextMaster document id.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function completeTmDocument($project_id, $document_id) {
    $params = [
      'satisfaction' => 'positive',
      'message' => 'Well done!',
    ];
    try {
      return $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents/' . $document_id . '/complete', 'PUT', $params);
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not complete the TextMaster Document: @error', ['@error' => $e->getMessage()]);
    }
    return [];
  }

  /**
   * Ask for document revision in TextMaster.
   *
   * @param string $project_id
   *   TextMaster project id.
   * @param string $document_id
   *   TextMaster document id.
   * @param string $message
   *   Revision message.
   *
   * @return array|int|null|false
   *   Result of the API request or FALSE.
   */
  public function createTmSupportMessage($project_id, $document_id, string $message) {
    $params = [
      'support_message' => [
        'message' => $message,
      ],
    ];
    try {
      return $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents/' . $document_id . '/support_messages', 'POST', $params);
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not create TextMaster support message: @error', ['@error' => $e->getMessage()]);
    }
    return [];
  }

  /**
   * Parses translation from TextMaster and returns unflatted data.
   *
   * @param string $data
   *   Xliff data, received from TextMaster.
   *
   * @return array
   *   Unflatted data.
   */
  protected function parseTranslationData($data) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')
      ->createInstance('xlf');
    // Import given data using XLIFF converter. Specify that passed content is
    // not a file.
    return $xliff_converter->import($data, FALSE);
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
    if (empty($context['results'])) {
      // Set initial values.
      $context['results']['job_id'] = $job_item->getJobId();
      $context['results']['errors'] = [];
      $context['results']['translated'] = 0;
    }

    $translated = $context['results']['translated'];
    $errors = $context['results']['errors'];
    $job = $job_item->getJob();
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    $is_item_translated = FALSE;
    // Get all existing mappings for this Job Item.
    $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    foreach ($mappings as $mapping) {
      // Prepare parameters for Job API (to get the job status).
      $document_id = $mapping->getRemoteIdentifier3();
      $project_id = $mapping->getRemoteIdentifier2();
      $info = $translator_plugin->getTmDocument($project_id, $document_id);
      if (empty($info)) {
        $job->addMessage('Error fetching the job item: @job_item. TextMaster document @document_id not found.',
          [
            '@job_item' => $job_item->label(),
            '@document_id' => $document_id,
          ], 'error');
        $errors[] = 'TextMaster document ' . $document_id . ' not found, it was probably deleted.';
      }

      if (!array_key_exists('status', $info)
        || !$translator_plugin->isRemoteTranslationCompleted($info['status'])
      ) {
        continue;
      }

      try {
        $translator_plugin->addTranslationToJob($job, $info['status'], $project_id, $document_id, $info['author_work']);
        $is_item_translated = TRUE;
      }
      catch (TMGMTException $e) {
        $job->addMessage('Exception occurred while fetching the job item "@job_item": @error.', [
          '@job_item' => $job_item->label(),
          '@error' => $e,
        ], 'error');
        $errors[] = 'Exception occurred while fetching the job item ' . $job_item->label();
      }
    }
    if ($is_item_translated) {
      $translated++;
    }

    // Set  results:
    $context['results']['translated'] = $translated;
    $context['results']['untranslated'] = count($job->getItems()) - $translated;
    $context['results']['errors'] = $errors;

    // Inform the batch engine that we finished the operation with this item.
    $context['finished'] = 1;
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
        drupal_set_message(t('No job item has been translated yet.'));
      }
      else {
        $job->addMessage(t('Fetched translations for @translated job item(s), @untranslated are not translated yet.', [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]));
      }
    }
    else {
      drupal_set_message(t('Error(s) occurred during fetching translations for Job: @error', ['@error' => implode('; ', $errors)]));
    }

    tmgmt_write_request_messages($job);
  }

  /**
   * Checks if the translation has one of the completed statuses.
   *
   * @param string $status
   *   Status code.
   *
   * @return bool
   *   True if completed.
   */
  public function isRemoteTranslationCompleted($status) {
    return $status == 'in_review' || $status == 'completed';
  }

  /**
   * Sends an error file to TextMaster API.
   *
   * @param string $state
   *   The state.
   * @param int $project_id
   *   The project id.
   * @param string $file_id
   *   The file id to update.
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job.
   * @param string $required_by
   *   The date by when the translation is required.
   * @param string $message
   *   (Optional) The error message.
   * @param bool $confirm
   *   (Optional) Set to TRUE if also want to send the confirmation message
   *   of this error. Otherwise will not send it.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If there is a problem with the request.
   */
  public function sendFileError($state, $project_id, $file_id, JobInterface $job, $required_by, $message = '', $confirm = FALSE) {
    // Use this function to handle the error at TextMaster side (not used now).
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
    $translated_file_response = $this->client->request('GET', $remote_file_url);
    $translated_file_content = $translated_file_response->getBody()
      ->getContents();
    $file_data = $this->parseTranslationData($translated_file_content);
    if ($this->isRemoteTranslationCompleted($document_state)) {
      $status = TMGMT_DATA_ITEM_STATE_TRANSLATED;
    }
    else {
      $status = TMGMT_DATA_ITEM_STATE_PRELIMINARY;
    }
    $job->addTranslatedData($file_data, [], $status);
    $mappings = RemoteMapping::loadByRemoteIdentifier('tmgmt_textmaster', $project_id, $document_id);
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = reset($mappings);
    $mapping->removeRemoteData('TMState');
    $mapping->addRemoteData('TMState', $status);
    $mapping->save();
  }

  /**
   * Generates TextMaster Api signature. See https://www.app.textmaster.com/api-documentation#authentication-signature-creation.
   *
   * @param string $date
   *   Date gmt/utc in format 'Y-m-d H:i:s'.
   * @param string $api_secret
   *   TextMaster Api Secret.
   *
   * @return string
   *   TextMaster Api signature.
   */
  public function getTextmasterSignature($date, $api_secret) {
    $signature = sha1($api_secret . $date);
    return $signature;
  }

  /**
   * Get gmt/utc date in format 'Y-m-d H:i:s'.
   *
   * @return string
   *   Date gmt/utc in format 'Y-m-d H:i:s'.
   */
  public function utcDate() {
    return gmdate('Y-m-d H:i:s');
  }

  /**
   * Logs a debug message.
   *
   * @param string $message
   *   Message.
   */
  public function logDebug($message) {
    \Drupal::logger('tmgmt_textmaster')->debug($message);
  }

}
