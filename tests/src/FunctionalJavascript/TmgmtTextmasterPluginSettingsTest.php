<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

/**
 * Test for tmgmt_textmaster translator plugin.
 *
 * @group tmgmt_textmaster
 */
class TmgmtTextmasterPluginSettingsTest extends TmgmtTextmasterTestBase {

  /**
   * Tests the configuration form of translator.
   */
  public function testTextmasterTranslatorConfigurationForm() {
    parent::baseTestSteps();
    // Visit a Textmaster Translator configuration page that requires login.
    $this->drupalGet('admin/tmgmt/translators/manage/textmaster');
    $this->assertSession()->statusCodeEquals(200);
    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'translator.png');
    $this->assertSession()->pageTextContains(t('TEXTMASTER PLUGIN SETTINGS'));

    // Try to enter wrong Api key and secret.
    $this->changeField('#edit-settings-textmaster-api-key', 'wrong key');
    $this->changeField('#edit-settings-textmaster-api-secret', 'wrong secret');
    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'wrong_credentials.png');
    $this->click('input[id^="edit-settings-connect"]');

    // Check if user has permission 'administer tmgmt'.
    $user = $this->container->get('current_user');
    $permissions = $user->hasPermission('administer tmgmt');
    $this->assertTrue($permissions);

//    $result = $this->getSession()->getDriver()->wait(2000, "jQuery('#frfr').is(':visible');");

//    $this->waitUntilVisible('');
    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'wrong_credentials_ajax.png');
    $this->assertSession()->pageTextContains(t('Authentication failed. Please check the API key and secret.'));

  }

}