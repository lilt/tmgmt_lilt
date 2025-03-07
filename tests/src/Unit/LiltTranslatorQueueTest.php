<?php

namespace Drupal\Tests\tmgmt_lilt\Unit;
namespace Drupal\tmgmt_lilt\Plugin\tmgmt\Translator;

use Drupal\tmgmt\Entity\Job;
use Drupal\Tests\UnitTestCase;
use Drupal\tmgmt_lilt\Plugin\QueueWorker\PublishTranslations;

// Stubs for unimplemented functions in Translator namespace

if (!function_exists('Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\t')) {
  /**
   * Stub for t()
   *
   * @param string $string
   *   The string to translate.
   * @param array $args
   *   Replacement arguments.
   *
   * @return string
   *   The formatted string.
   */
  function t($string, array $args = []) {
    return strtr($string, $args);
  }
}

if(!function_exists('Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\tmgmt_write_request_messages()')){
   /**
   * Stub for tmgmt_write_request_messages()
   *
   * @param string $job
   *   The current job
   * @return string
   *   Dummy return value
   */
  function tmgmt_write_request_messages($job) {
    return "";
  }
}

class LiltTranslatorQueueTest extends UnitTestCase {

  /**
   * The instance of PublishTranslations to test.
   *
   * @var \Drupal\tmgmt_lilt\Plugin\QueueWorker\PublishTranslations
   */
  protected $queueWorker;

  /**
   * Dummy entity storage for 'tmgmt_job'.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $dummyStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // setUp the test case
  
    // create a dummy container.
    $container = new \Symfony\Component\DependencyInjection\Container();
  
    // create a dummy messenger service.
    $dummyMessenger = $this->getMockBuilder('Drupal\Core\Messenger\MessengerInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $container->set('messenger', $dummyMessenger);
  
    // create a dummy entity_type.repository service.
    $dummyEntityTypeRepository = $this->getMockBuilder('Drupal\Core\Entity\EntityTypeRepositoryInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $container->set('entity_type.repository', $dummyEntityTypeRepository);
  
    // create a dummy entity storage for the 'tmgmt_job' entity.
    $dummyStorage = $this->getMockBuilder('Drupal\Core\Entity\EntityStorageInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $this->dummyStorage = $dummyStorage;
  
    // create a dummy entity type manager.
    $dummyEntityTypeManager = $this->getMockBuilder('Drupal\Core\Entity\EntityTypeManagerInterface')
      ->disableOriginalConstructor()
      ->getMock();
    
    // relax the expectation to accept any parameter.
    $dummyEntityTypeManager->expects($this->any())
      ->method('getStorage')
      ->willReturn($dummyStorage);
    $container->set('entity_type.manager', $dummyEntityTypeManager);
  
    // set the container in Drupal.
    \Drupal::setContainer($container);
  
    // instantiate the queue worker with the dummy messenger.
    $this->queueWorker = new PublishTranslations([], 'tmgmt_lilt_publish_translations', [], $dummyMessenger);
  }
  

  /**
   * Helper method to call the protected validateJob().
   *
   * @param array $data
   *   The data array to validate.
   *
   * @return \Drupal\tmgmt\Entity\Job|null
   *   The job object or null.
   */
  protected function callValidateJob(array $data) {
    $reflection = new \ReflectionClass(get_class($this->queueWorker));
    $method = $reflection->getMethod('validateJob');
    $method->setAccessible(true);
    return $method->invokeArgs($this->queueWorker, [$data]);
  }

   /**
   * Helper to call the protected validateLiltProject().
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   The job to validate.
   *
   * @return mixed
   *   The project info or null.
   */
  protected function callValidateLiltProject($job) {
    $reflection = new \ReflectionClass(get_class($this->queueWorker));
    $method = $reflection->getMethod('validateLiltProject');
    $method->setAccessible(true);
    return $method->invoke($this->queueWorker, $job);
  }

  /**
   * validateJob() returns null when no job_id is provided.
   */
  public function testValidateJobWithoutJobId() {
    $data = [];
    $job = $this->callValidateJob($data);
    $this->assertNull($job, 'No job_id provided should return null.');
  }

  /**
   * validateJob() returns null when job cannot be loaded.
   */
  public function testValidateJobWithNonExistentJob() {
    $data = ['job_id' => 999];

    $this->dummyStorage->expects($this->any())
      ->method('load')
      ->with(999)
      ->willReturn(null);

    $job = $this->callValidateJob($data);
    $this->assertNull($job, 'Non-existent job should return null.');
  }

  /**
   * validateJob() returns null for a job that is not a Lilt job.
   */
  public function testValidateJobWithNonLiltTranslator() {
    $data = ['job_id' => 1];

    $dummyJob = $this->getMockBuilder(Job::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->dummyStorage->expects($this->any())
      ->method('load')
      ->with(1)
      ->willReturn($dummyJob);

    $dummyJob->expects($this->any())
      ->method('hasTranslator')
      ->willReturn(TRUE);

    $dummyJob->expects($this->any())
      ->method('getTranslatorId')
      ->willReturn('some_other_translator');

    $dummyJob->expects($this->any())
      ->method('isActive')
      ->willReturn(TRUE);

    $job = $this->callValidateJob($data);
    $this->assertNull($job, 'Job with non-Lilt translator should return null.');
  }

  /**
   * validateJob() returns the job for a valid Lilt job.
   */
  public function testValidateJobValidLiltJob() {
    $data = ['job_id' => 1];

    $dummyJob = $this->getMockBuilder(Job::class)
      ->disableOriginalConstructor()
      ->getMock();

    $this->dummyStorage->expects($this->any())
      ->method('load')
      ->with(1)
      ->willReturn($dummyJob);

    $dummyJob->expects($this->any())
      ->method('hasTranslator')
      ->willReturn(TRUE);

    $dummyJob->expects($this->any())
      ->method('getTranslatorId')
      ->willReturn('lilt');

    $dummyJob->expects($this->any())
      ->method('isActive')
      ->willReturn(TRUE);

    $job = $this->callValidateJob($data);
    $this->assertSame($dummyJob, $job, 'Valid Lilt job should be returned.');
  }

  public function testValidateLiltProjectNoProjectId() {
    // dummy job that returns an empty remote mappings array.
    $dummyJob = $this->getMockBuilder(Job::class)
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getRemoteMappings')
      ->willReturn([]);
    
    $result = $this->callValidateLiltProject($dummyJob);
    $this->assertNull($result, 'No project id (empty remote mappings) should return null.');
  }

  /**
   * Tests that validateLiltProject() returns null when project info is empty.
   */
  public function testValidateLiltProjectWithEmptyProjectInfo() {
    // remote mapping object with a project ID.
    $remote_mapping = new \stdClass();
    $remote_mapping->remote_identifier_2 = (object) ['value' => '123'];
    
    // dummy job that returns the remote mapping.
    $dummyJob = $this->getMockBuilder(Job::class)
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getRemoteMappings')
      ->willReturn([$remote_mapping]);
    
    // dummy translator and set it on the job.
    $dummyTranslator = $this->getMockBuilder('Drupal\tmgmt\Entity\Translator')
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getTranslator')
      ->willReturn($dummyTranslator);

    // dummy translator plugin.
    $dummyTranslatorPlugin = $this->getMockBuilder('Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator')
      ->disableOriginalConstructor()
      ->getMock();
    $dummyTranslator->expects($this->any())
      ->method('getPlugin')
      ->willReturn($dummyTranslatorPlugin);

    // setTranslator() is called with the translator.
    $dummyTranslatorPlugin->expects($this->once())
      ->method('setTranslator')
      ->with($dummyTranslator);
      
    // getLiltProject() returns an empty value.
    $dummyTranslatorPlugin->expects($this->once())
      ->method('getLiltProject')
      ->with('123')
      ->willReturn([]);
    
    $result = $this->callValidateLiltProject($dummyJob);
    $this->assertNull($result, 'Empty project info should return null.');
  }

  /**
   * Tests that validateLiltProject() returns valid project info when available.
   */
  public function testValidateLiltProjectValid() {
    // remote mapping with a valid project ID.
    $remote_mapping = new \stdClass();
    $remote_mapping->remote_identifier_2 = (object) ['value' => '123'];
    
    // create dummy setup for job / translator / translator plugin
    $dummyJob = $this->getMockBuilder(Job::class)
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getRemoteMappings')
      ->willReturn([$remote_mapping]);
    
    $dummyTranslator = $this->getMockBuilder('Drupal\tmgmt\Entity\Translator')
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getTranslator')
      ->willReturn($dummyTranslator);

    $dummyTranslatorPlugin = $this->getMockBuilder('Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator')
      ->disableOriginalConstructor()
      ->getMock();
    $dummyTranslator->expects($this->any())
      ->method('getPlugin')
      ->willReturn($dummyTranslatorPlugin);

    // setTranslator() is called with the translator.
    $dummyTranslatorPlugin->expects($this->once())
      ->method('setTranslator')
      ->with($dummyTranslator);
    
    // getLiltProject() returns valid project info.
    $projectInfo = ['state' => 'done', 'id' => '123'];
    $dummyTranslatorPlugin->expects($this->once())
      ->method('getLiltProject')
      ->with('123')
      ->willReturn($projectInfo);
    
    $result = $this->callValidateLiltProject($dummyJob);
    $this->assertSame($projectInfo, $result, 'Valid project info should be returned.');
  }

/**
 * Tests that processItem() publishes translations when the project state is 'done'.
 */
public function testProcessItemWhenProjectStateDone() {
    $data = ['job_id' => 1];
    
    // create full setup
    $dummyJob = $this->getMockBuilder('Drupal\tmgmt\Entity\Job')
      ->disableOriginalConstructor()
      ->getMock();
  
    $dummyJob->expects($this->any())
      ->method('hasTranslator')
      ->willReturn(TRUE);
    $dummyJob->expects($this->any())
      ->method('getTranslatorId')
      ->willReturn('lilt');
    $dummyJob->expects($this->any())
      ->method('isActive')
      ->willReturn(TRUE);
  
    $remoteMapping = new \stdClass();
    $remoteMapping->remote_identifier_2 = (object) ['value' => '123'];
    $dummyJob->expects($this->any())
      ->method('getRemoteMappings')
      ->willReturn([$remoteMapping]);
  
    $dummyTranslator = $this->getMockBuilder('Drupal\tmgmt\Entity\Translator')
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getTranslator')
      ->willReturn($dummyTranslator);
  
    $dummyTranslatorPlugin = $this->getMockBuilder('Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator')
      ->disableOriginalConstructor()
      ->getMock();
  
    // getLiltProject() is called with the project ID ('123')
    // and returns a project info array with state 'done'.
    $projectInfo = ['state' => 'done'];
    $dummyTranslatorPlugin->expects($this->once())
      ->method('getLiltProject')
      ->with('123')
      ->willReturn($projectInfo);
  
    // fetchAsyncTranslatedFiles() is called with the dummy job.
    $dummyTranslatorPlugin->expects($this->once())
      ->method('fetchAsyncTranslatedFiles')
      ->with($dummyJob);
  
    // dummy translator plugin.
    $dummyTranslator->expects($this->any())
      ->method('getPlugin')
      ->willReturn($dummyTranslatorPlugin);
  
    // acceptTranslation() and finished() are called exactly once.
    $dummyJob->expects($this->once())
      ->method('acceptTranslation');
    $dummyJob->expects($this->once())
      ->method('finished');
  
    // Job::load returns our dummy job
    $this->dummyStorage->expects($this->any())
      ->method('load')
      ->with(1)
      ->willReturn($dummyJob);
  
    // entry point
    $this->queueWorker->processItem($data);
  }

  public function testReportAsyncTranslationResultsAllTranslated() {
    // dummy job for addMessage
    $dummyJob = $this->getMockBuilder(\Drupal\tmgmt\Entity\Job::class)
      ->disableOriginalConstructor()
      ->getMock();

    // addMessage is called once with the expected message.
    $dummyJob->expects($this->once())
      ->method('addMessage')
      ->with($this->equalTo('Fetched translations for all 2 job item(s).'));
  
    // 2 translated; job_id 42; 0 untranslated; no errors
    $results = [
      'job_id'      => 42,
      'translated'  => 2,
      'untranslated'=> 0,
      'errors'      => [],
    ];
  
    $translator = $this->getMockBuilder(\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator::class)
      ->disableOriginalConstructor()
      ->getMockForAbstractClass();
  
    // reflection to call the protected reportAsyncTranslationResults() method.
    $reflection = new \ReflectionClass($translator);
    $method = $reflection->getMethod('reportAsyncTranslationResults');
    $method->setAccessible(true);
    $method->invoke($translator, $dummyJob, $results);
  }

  public function testReportAsyncTranslationResultsWithUntranslated() {
    // dummy job for addMessage
    $dummyJob = $this->getMockBuilder(\Drupal\tmgmt\Entity\Job::class)
      ->disableOriginalConstructor()
      ->getMock();

    // addMessage is called once with the expected message.
    $dummyJob->expects($this->once())
      ->method('addMessage')
      ->with($this->equalTo('Fetched translations for 2 job item(s), but 2 remain untranslated.'));
  
    // 2 translated; job_id 42; 0 untranslated; no errors
    $results = [
      'job_id'      => 42,
      'translated'  => 2,
      'untranslated'=> 2,
      'errors'      => [],
    ];
  
    $translator = $this->getMockBuilder(\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator::class)
      ->disableOriginalConstructor()
      ->getMockForAbstractClass();
  
    // reflection to call the protected reportAsyncTranslationResults() method.
    $reflection = new \ReflectionClass($translator);
    $method = $reflection->getMethod('reportAsyncTranslationResults');
    $method->setAccessible(true);
    $method->invoke($translator, $dummyJob, $results);
  }

  public function testFetchAsyncTranslatedFilesReportsCorrectly() {
    // dummy setup
    $jobItem1 = $this->getMockBuilder(\Drupal\tmgmt\JobItemInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $jobItem2 = $this->getMockBuilder(\Drupal\tmgmt\JobItemInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
  
    $dummyJob = $this->getMockBuilder(\Drupal\tmgmt\Entity\Job::class)
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getItems')
      ->willReturn([$jobItem1, $jobItem2]);
    $dummyJob->expects($this->any())
      ->method('id')
      ->willReturn(42);
  
    $translator = $this->getMockBuilder(\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processJobItemTranslation', 'reportAsyncTranslationResults'])
      ->getMock();
  
    // simulate a successful translation for each job item.
    $translator->expects($this->exactly(2))
      ->method('processJobItemTranslation')
      ->willReturnCallback(function($job, $job_item, &$results) {
        $results['translated']++;
      });
  
    // expects reportAsyncTranslationResults() to be called once with the final results.
    // - job_id equal to 42,
    // - 2 translated items,
    // - 0 errors, and
    // - 0 untranslated items (since there were exactly 2 job items).
    $translator->expects($this->once())
      ->method('reportAsyncTranslationResults')
      ->with(
        $dummyJob,
        $this->callback(function($results) {
          return isset($results['job_id'], $results['translated'], $results['errors'], $results['untranslated']) &&
            $results['job_id'] === 42 &&
            $results['translated'] === 2 &&
            $results['errors'] === [] &&
            $results['untranslated'] === 0;
        })
      );
  
    $translator->fetchAsyncTranslatedFiles($dummyJob);
  }
  
  public function testFetchAsyncTranslatedFilesWithErrors() {
    // dummy job items mock.
    $jobItem1 = $this->getMockBuilder(\Drupal\tmgmt\JobItemInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $jobItem2 = $this->getMockBuilder(\Drupal\tmgmt\JobItemInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
  
    // stub() for labeling
    $jobItem1->expects($this->any())
      ->method('label')
      ->willReturn('JobItem1');
    $jobItem2->expects($this->any())
      ->method('label')
      ->willReturn('JobItem2');
  
    // dummy job
    $dummyJob = $this->getMockBuilder(\Drupal\tmgmt\Entity\Job::class)
      ->disableOriginalConstructor()
      ->getMock();
    $dummyJob->expects($this->any())
      ->method('getItems')
      ->willReturn([$jobItem1, $jobItem2]);
    $dummyJob->expects($this->any())
      ->method('id')
      ->willReturn(10);
  
    // mock processJobItemTranslation and reportAsyncTranslationResults
    $translator = $this->getMockBuilder(\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processJobItemTranslation', 'reportAsyncTranslationResults'])
      ->getMock();
  
    // processJobItemTranslation will add some errors
    $translator->expects($this->exactly(2))
      ->method('processJobItemTranslation')
      ->willReturnCallback(function($job, $job_item, &$results) {
        $results['errors'][] = 'Exception occurred while fetching the job item ' . $job_item->label();
      });
  
    // capturing the results from reportAsyncTranslationResults().
    $capturedResults = null;
    $translator->expects($this->once())
      ->method('reportAsyncTranslationResults')
      ->willReturnCallback(function($job, $results) use (&$capturedResults) {
        $capturedResults = $results;
      });
  
    $translator->fetchAsyncTranslatedFiles($dummyJob);
  
    // - 0 job items were translated,
    // - 2 job items remain untranslated,
    // - and there are 2 error messages in the results.
    $this->assertNotNull($capturedResults, 'The report method should have been called with results.');
    $this->assertEquals(0, $capturedResults['translated'], 'No job items should be translated.');
    $this->assertEquals(2, $capturedResults['untranslated'], 'There should be 2 untranslated job items.');
    $this->assertNotEmpty($capturedResults['errors'], 'The errors array should not be empty.');
    $this->assertCount(2, $capturedResults['errors'], 'There should be 2 errors reported.');
  }
}
