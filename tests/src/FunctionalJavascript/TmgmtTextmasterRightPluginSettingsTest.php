<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

/**
 * Test for tmgmt_textmaster translator plugin.
 *
 * @group tmgmt_textmaster
 */
class TmgmtTextmasterRightPluginSettingsTest extends TmgmtTextmasterTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Test the TextMaster authentication with right API key and secret.
   */
  public function testTextmasterProviderWithRightCredentials() {
    parent::baseTestSteps();

    // Configure TextMaster Provider with right credentials and remote mapping.
    $this->configureTextmasterProvider();
  }

}
