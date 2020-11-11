<?php

namespace Drupal\Tests\tmgmt_lilt\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tmgmt_lilt\LiltTranslatorUi;

/**
 * Tests Lilt translator UI class.
 *
 * @coversDefaultClass \Drupal\tmgmt_lilt\LiltTranslatorUi
 *
 * @group tmgmt_lilt
 */
class LiltTranslatorUiTest extends UnitTestCase {

  /**
   * Test utility plugin.
   *
   * @see LiltTranslatorUi::getTranslatorPluginForJob()
   */
  public function testGetTranslatorPluginForJob() {
    $lilt_ui = new LiltTranslatorUi([], 'tmgmt_lilt_ui', NULL);

    $tmgmt_job = $this->getMockBuilder('Drupal\tmgmt\JobInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $tmgmt_translator = $this->getMockBuilder('\Drupal\tmgmt\Entity\Translator')
      ->disableOriginalConstructor()
      ->getMock();
    $lilt_translator = $this->getMockBuilder('\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator')
      ->disableOriginalConstructor()
      ->getMock();

    $tmgmt_job->method('getTranslator')
      ->willReturn($tmgmt_translator);
    $tmgmt_translator->method('getPlugin')
      ->willReturn($lilt_translator);

    $lilt_translator->expects($this->once())
      ->method('setTranslator');

    $translator_plugin = $lilt_ui->getTranslatorPluginForJob($tmgmt_job);
    $this->assertInstanceOf('\Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator', $translator_plugin);
  }

}
