<?php

namespace Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\tmgmt\Entity\Job;

/**
 * TextMaster translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "textmaster",
 *   label = @Translation("TextMaster"),
 *   description = @Translation("TextMaster translator service."),
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
      foreach ($supported_languages['languages'] as $language) {
        $supported_remote_languages[$language['code']] = $language['value']
          . ' ('
          . $language['code']
          . ')';
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
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
    $job = $this->requestJobItemsTranslation($job->getItems());
    if ($job->isRejected()) {
      return;
    }
    $job_remote_data = end($job->getRemoteMappings());
    $auto_launch = $job_remote_data->remote_data->TemplateAutoLaunch;
    if ($auto_launch) {
      $job->submitted();
    }
    else {
      $job->setState(Job::STATE_UNPROCESSED, 'The translation job has been submitted.');
    }
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
    $due_date = $job->getSetting('deadline');
    try {
      $project_id = $this->createTmProject($job, $due_date);
      $job->addMessage('Created a new project in TextMaster with the id: @id', ['@id' => $project_id], 'debug');

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        $document_id = $this->sendFiles($job_item, $project_id);

        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
        $remote_mapping = RemoteMapping::create([
          'tjid' => $job->id(),
          'tjiid' => $job_item->id(),
          'remote_identifier_1' => 'tmgmt_textmaster',
          'remote_identifier_2' => $project_id,
          'remote_identifier_3' => $document_id,
          'remote_data' => [
            'FileStateVersion' => 1,
            'TMState' => 'in_creation',
            'RequiredBy' => $due_date,
            'TemplateAutoLaunch' => $this->isTemplateAutoLaunch($job->getSetting('project_template')),
          ],
        ]);
        $remote_mapping->save();

        if ($job_item->getJob()->isContinuous()) {
          $job_item->active();
        }
      }
      // TODO: add this step after fixing.
      $this->finalizeTmProject($project_id);
    }
    catch (TMGMTException $e) {
      $job->rejected('Job has been rejected with following error: @error',
        ['@error' => $e->getMessage()], 'error');
      if (isset($remote_mapping)) {
        $remote_mapping->delete();
      }
    }
    return $job;
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
      $config = \Drupal::configFactory()->get('tmgmt_textmaster.settings');
      if ($config->get('debug')) {
        \Drupal::logger('tmgmt_textmaster')
          ->warning('Unable to log in to TextMaster API: ' . $ex->getMessage());
      }
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
   * Sends a request to the TextMaster API and refreshes the token if necessary.
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
    $config = \Drupal::configFactory()->get('tmgmt_textmaster.settings');

    if (!$service_url) {
      if ($config->get('debug')) {
        \Drupal::logger('tmgmt_textmaster')
          ->warning('Attempt to call TextMaster API when service_url is not set: ' . $path);
      }
      return [];
    }
    $url = $service_url . '/' . $path;
    if ($body) {
      $options['body'] = $body;
    }
    else {
      if ($method == 'GET') {
        $options['query'] = $params;
      }
      else {
        $options['json'] = $params;
      }
    }

    // Default headers for TextMaster Api requests.
    $options['headers'] = [
      'Apikey' => $this->translator->getSetting('textmaster_api_key'),
      'Date' => $date = $this->utcDate(),
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
      if ($config->get('debug')) {
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
      }
      if ($code) {
        return $response->getStatusCode();
      }
      throw new TMGMTException('Unable to connect to TextMaster API due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }
    $received_data = $response->getBody()->getContents();
    if ($config->get('debug')) {
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
    }
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
   * @param string $due_date
   *   The date by when the translation is required.
   *
   * @return int
   *   TextMaster Project ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function createTmProject(JobInterface $job, $due_date) {
    // Prepare parameters for Project API.
    $name = $job->get('label')->value ?: 'Drupal TMGMT project ' . $job->id();
    $params = [
      'project' => [
        'name' => $name,
        'activity_name' => 'translation',
        'api_template_id' => $job->getSetting('project_template'),
        'category' => $job->getSetting('category'),
        'deadline' => $due_date,
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
   * @param array $previous_pages_result
   *   The array with previous pages values.
   *
   * @return array|int|null
   *   Result of the API request.
   */
  public function allPagesResult($request_path, $result_key, array $previous_pages_result = []) {
    $result = $this->sendApiRequest($request_path);
    $all_pages_list = array_merge($result[$result_key], $previous_pages_result);
    if (isset($result['next_page'])) {
      return $this->allPagesResult($result['next_page'], $result_key, $all_pages_list);
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
      $result_with_cost = $this->getTmProject($project_id);
      if (!empty($currency = $result_with_cost['total_costs'][0]['currency']) && !empty($amount = $result_with_cost['total_costs'][0]['amount'])) {
        // TODO: Set the project cost here.
        $stop = [];
      }
      return $result;
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not get the TextMaster Project: @error', ['@error' => $e->getMessage()]);
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
   * Send the files to TextMaster.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   * @param int $project_id
   *   TextMaster Project id.
   *
   * @return string
   *   TextMaster Document Id.
   */
  private function sendFiles(JobItemInterface $job_item, $project_id) {
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
    $params = [
      'document' => [
        'title' => $document_title,
        'remote_file_url' => $remote_file_url,
        'deliver_work_as_file' => 'true',
        'perform_word_count' => 'true',
      ],
    ];
    $result = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents', 'POST', $params);

    return $result['id'];
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
   *
   * @return array
   *   Array containing a containing the number of items translated and the
   *   number that has not been translated yet.
   */
  public function fetchTranslatedFiles(JobInterface $job) {
    $this->setTranslator($job->getTranslator());
    $translated = 0;
    $errors = [];

    try {
      /** @var \Drupal\tmgmt\JobItemInterface $job_item */
      foreach ($job->getItems() as $job_item) {
        $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
        foreach ($mappings as $mapping) {
          // Prepare parameters for Job API (to get the job status).
          $document_id = $mapping->getRemoteIdentifier3();
          $project_id = $mapping->getRemoteIdentifier2();
          $old_state = $mapping->getRemoteData('TMState');
          $info = [];
          try {
            $info = $this->sendApiRequest('v1/clients/projects/' . $project_id . '/documents/' . $document_id, 'GET');
          }
          catch (TMGMTException $e) {
            $job->addMessage('Error fetching the job item: @job_item. TextMaster document @document_id not found.',
              [
                '@job_item' => $job_item->label(),
                '@document_id' => $document_id,
              ], 'error');
            $errors[] = 'TextMaster job ' . $document_id . ' not found, it was probably deleted.';
          }

          if (array_key_exists('status', $info)) {
            if ($this->remoteTranslationCompleted($info['status'])) {
              try {
                $this->addTranslationToJob($job, $info['status'], $project_id, $document_id, $info['author_work']);
                $translated++;
              }
              catch (TMGMTException $e) {
                $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
                continue;
              }
            }
          }
        }
      }
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_textmaster')
        ->error('Could not pull translation resources: @error', ['@error' => $e->getMessage()]);
    }
    return [
      'translated' => $translated,
      'untranslated' => count($job->getItems()) - $translated,
      'errors' => $errors,
    ];
  }

  /**
   * Retrieve all the updates for all the job items in a translator.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to get the translation.
   *
   * @return int
   *   The number of updated job items.
   */
  public function pullRemoteTranslation(JobItemInterface $job_item) {
    // TODO: correct this method or remove it.
    $job = $job_item->getJob();
    $this->setTranslator($job->getTranslator());
    $remotes = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
    $remote = reset($remotes);
    $params = [
      'document' => $remote->getRemoteIdentifier3(),
    ];
    $info = $this->sendApiRequest('v8/job/get', 'GET', $params);
    $old_state = $remote->getRemoteData('TmsState');
    if ($this->remoteTranslationCompleted($info['status'])) {
      try {
        $this->addTranslationToJob($job, $info['status'], $remote->getRemoteIdentifier2(), $remote->getRemoteIdentifier3());
        return 1;
      }
      catch (TMGMTException $e) {
        $job->addMessage('Error fetching the job item: @job_item.', [
          '@job_item' => $remote->getJobItem()
            ->label(),
        ], 'error');
      }
    }
    return 0;
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
  public function remoteTranslationCompleted($status) {
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
   * @param string $file_id
   *   The file ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function addTranslationToJob(JobInterface $job, $document_state, $project_id, $document_id, $remote_file_url) {
    $translated_file_response = $this->client->request('GET', $remote_file_url);
    $translated_file_content = $translated_file_response->getBody()
      ->getContents();
    $file_data = $this->parseTranslationData($translated_file_content);
    if ($this->remoteTranslationCompleted($document_state)) {
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
    $mapping->addRemoteData('TMState', $document_state);
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
   *    Date gmt/utc in format 'Y-m-d H:i:s'.
   */
  public function utcDate() {
    return gmdate('Y-m-d H:i:s');
  }

  /**
   * Checks if the string is not empty.
   *
   * @param string $string
   *   String.
   *
   * @return bool
   *   True if not empty.
   */
  public function containsText($string) {
    return $string != NULL && $string != "" && !ctype_space(preg_replace("/(&nbsp;)/", "", $string));
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
