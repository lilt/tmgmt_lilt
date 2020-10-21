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
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }


  /**
   * Checks that Lilt authentication works.
   *
   * @return bool
   *   A success or failure.
   */
  public function checkLiltAuth() {
    try {
      if ($this->getLanguages()) {
        return TRUE;
      }
    }
    catch (TMGMTException $ex) {
      \Drupal::logger('tmgmt_lilt')->warning('Unable to log in to Lilt API: ' . $ex->getMessage());
    }
    return FALSE;
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
    \Drupal::logger('tmgmt_lilt')->error('@method Request to @url:<br>
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

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
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
      \Drupal::logger('tmgmt_lilt')->warning('Attempt to call Lilt API when api_key is not set: ' . $path);
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

}
