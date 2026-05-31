<?php

namespace Drupal\Tests\tmgmt_lilt\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the X-Lilt-Connector-Version header on outbound Lilt API requests.
 *
 * @coversDefaultClass \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator
 *
 * @group tmgmt_lilt
 */
class LiltTranslatorConnectorVersionTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Provide a minimal container so any \Drupal::logger() call is harmless.
    $container = new \Symfony\Component\DependencyInjection\Container();
    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a translator mock returning the given settings.
   *
   * @param array $settings
   *   Keyed settings returned by getSetting().
   *
   * @return \Drupal\tmgmt\TranslatorInterface
   *   The translator mock.
   */
  protected function mockTranslator(array $settings): TranslatorInterface {
    $translator = $this->getMockBuilder(TranslatorInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $translator->method('getSetting')
      ->willReturnCallback(function ($key) use ($settings) {
        return $settings[$key] ?? '';
      });
    return $translator;
  }

  /**
   * Builds a 200 response whose body returns the given contents.
   *
   * @param string $contents
   *   The response body contents.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response mock.
   */
  protected function mockResponse(string $contents): ResponseInterface {
    $stream = $this->createMock(StreamInterface::class);
    // request() reads the body via getContents(); createLiltRemoteFile() casts
    // the stream to a string, so stub both.
    $stream->method('getContents')->willReturn($contents);
    $stream->method('__toString')->willReturn($contents);
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getBody')->willReturn($stream);
    return $response;
  }

  /**
   * Matches a Guzzle options array carrying the connector version header.
   *
   * @return \PHPUnit\Framework\Constraint\Callback
   *   The constraint.
   */
  protected function hasConnectorVersionHeader() {
    return $this->callback(function ($options) {
      return isset($options['headers']['X-Lilt-Connector-Version'])
        && $options['headers']['X-Lilt-Connector-Version'] === 'drupal/' . LiltTranslator::CONNECTOR_VERSION;
    });
  }

  /**
   * Requests routed through request() carry the connector version header.
   *
   * @covers ::request
   */
  public function testApiRequestSendsConnectorVersionHeader() {
    $client = $this->getMockBuilder(ClientInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $client->expects($this->once())
      ->method('request')
      ->with(
        $this->equalTo('GET'),
        $this->anything(),
        $this->hasConnectorVersionHeader()
      )
      ->willReturn($this->mockResponse('{}'));

    $translator = $this->mockTranslator([
      'lilt_service_url' => 'https://api.example.com',
      'lilt_api_key' => 'test-key',
      'lilt_log_api' => FALSE,
    ]);

    $plugin = new LiltTranslator($client, [], 'lilt', []);
    $plugin->setTranslator($translator);

    // getServiceRoot() funnels through sendApiRequest() -> request().
    $plugin->getServiceRoot();
  }

  /**
   * The file upload path carries the connector version header.
   *
   * @covers ::createLiltRemoteFile
   */
  public function testFileUploadSendsConnectorVersionHeader() {
    $client = $this->getMockBuilder(ClientInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $client->expects($this->once())
      ->method('request')
      ->with(
        $this->equalTo('POST'),
        $this->stringContains('/documents/files'),
        $this->hasConnectorVersionHeader()
      )
      ->willReturn($this->mockResponse('{"id":"doc-123"}'));

    $translator = $this->mockTranslator([
      'lilt_service_url' => 'https://api.example.com',
      'lilt_api_key' => 'test-key',
    ]);

    $plugin = new LiltTranslator($client, [], 'lilt', []);
    $plugin->setTranslator($translator);

    $document_id = $plugin->createLiltRemoteFile('<xliff/>', 'file', 1);
    $this->assertSame('doc-123', $document_id);
  }

}
