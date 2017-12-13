<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

/**
 * Test for tmgmt_textmaster translator plugin.
 *
 * @group tmgmt_textmaster
 */
class TmgmtTextmasterWrongPluginSettingsTest extends TmgmtTextmasterTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Test the TextMaster authentication with wrong API key and secret.
   */
  public function testTextmasterProviderWithWrongCredentials() {
    parent::baseTestSteps();

    // Configure TextMaster Provider with wrong credentials.
    $this->setTextmasterCredentials(FALSE);

    $this->createScreenshot('config_wrong_credentials_ajax.png');
    $this->assertSession()->pageTextContains(t('Authentication failed. Please check the API key and secret.'));
  }

}
