<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JavascriptTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Base setup for TmgmtTextmaster tests.
 */
abstract class TmgmtTextmasterTestBase extends JavascriptTestBase {

  /**
   * Path to create screenshots.
   *
   * @var string
   */
  protected $screenshotPath = '/sites/simpletest/tmgmt_textmaster/';

  /**
   * A tmgmt_translator.
   *
   * @var TranslatorInterface
   */
  protected $translator;

  /**
   * TextMaster API URL.
   */
  const API_URL = 'http://api.textmaster.com';

  /**
   * TextMaster API credantials.
   */
  const API_CREDENTIALS = [
    'textmaster_api_key' => 'LxgLQpmVJiU',
    'textmaster_api_secret' => 'p_PDvxf7uMM',
  ];

  /**
   * TextMaster ID for template with autolaunch setting enabled.
   */
  const AUTOLAUNCH_TEMPLATE_ID = '31e2516a-5ed5-4508-aa96-ffb88f687b4a';

  /**
   * TextMaster ID for template with autolaunch setting disabled.
   */
  const SIMPLE_TEMPLATE_ID = 'e4362191-c91d-4c04-9064-d9c4f2d970fb';

  /**
   * Translator mapping for remote languages.
   */
  const LANG_MAPPING = [
    'en' => 'en-gb',
    'fr' => 'fr-fr',
  ];

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'tmgmt',
    'tmgmt_textmaster',
    'tmgmt_file',
    'language',
    'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Check path for screenshots.
    $this->checkScreenshotPathExist();

    // Add new language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

  }

  /**
   * Check does screenshot path exist and create if it's necessary.
   */
  private function checkScreenshotPathExist() {
    if (file_exists(\Drupal::root() . $this->screenshotPath)) {
      return;
    }
    $this->verbose(\Drupal::root() . $this->screenshotPath);
    mkdir(\Drupal::root() . $this->screenshotPath, 0777, TRUE);
  }

  /**
   * Base steps for all javascript tests.
   */
  protected function baseTestSteps() {
    $admin_account = $this->drupalCreateUser([
      'administer tmgmt',
    ]);
    $this->drupalLogin($admin_account);
  }

  /**
   * Helper to change Field value with Javascript.
   *
   * @param string $selector
   *   jQuery selector for field.
   * @param string $value
   *   Field value.
   */
  protected function changeField($selector, $value = '') {
    $this->getSession()
      ->executeScript("jQuery('" . $selector . "').val('" . $value . "').trigger('keyup').trigger('change');");
  }

  /**
   * Waits and asserts that a given element is visible.
   *
   * @param string $selector
   *   The CSS selector.
   * @param int $timeout
   *   (Optional) Timeout in milliseconds, defaults to 1000.
   * @param string $message
   *   (Optional) Message to pass to assertJsCondition().
   */
  protected function waitUntilVisible($selector, $timeout = 1000, $message = '') {
    $condition = "jQuery('{$selector}').is(':visible');";
    $this->assertJsCondition($condition, $timeout, $message);
  }

}