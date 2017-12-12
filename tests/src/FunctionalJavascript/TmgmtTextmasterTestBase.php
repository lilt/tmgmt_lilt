<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JavascriptTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

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
   * Flag indicating whether screenshots should be created.
   *
   * Defaults to FALSE.
   *
   * @var bool
   */
  protected $createScreenshots = FALSE;

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
    'key' => 'LxgLQpmVJiU',
    'secret' => 'p_PDvxf7uMM',
  ];

  /**
   * TextMaster ID for template with autolaunch setting enabled.
   */
  const AUTOLAUNCH_TEMPLATE_ID = 'cfcafa05-c430-42d5-baf0-685aafbf1929';

  /**
   * TextMaster ID for template with autolaunch setting disabled.
   */
  const SIMPLE_TEMPLATE_ID = 'd92680c2-17ed-4eda-8a50-301a3139d247';

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
    'tmgmt_content',
    'language',
    'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Determine whether screenshots should be created.
    if (!empty(getenv('CREATE_TEST_SCREENSHOTS'))) {
      $this->createScreenshots = getenv('CREATE_TEST_SCREENSHOTS');
    }

    if ($this->createScreenshots) {
      // Check path for screenshots.
      $this->checkScreenshotPathExist();
    }

    // Add new language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

  }

  /**
   * {@inheritdoc}
   */
  protected function createScreenshot($filename, $set_background_color = TRUE) {
    // Create screenshots only if CREATE_TEST_SCREENSHOTS=1 is set for
    // environment.
    if (!empty($this->createScreenshots)) {
      parent::createScreenshot($filename, $set_background_color);
    }
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
   * Base steps for all tmgmt_textmaster javascript tests.
   */
  protected function baseTestSteps() {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    $admin_account = $this->drupalCreateUser([
      'administer tmgmt',
      'access site reports',
    ]);
    $this->drupalLogin($admin_account);
  }

  /**
   * Configure TextMaster Provider with right credentials and remote mapping.
   */
  protected function configureTextmasterProvider() {
    // Configure TextMaster Provider with right credentials.
    $this->setTextmasterCredentials(TRUE);

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'config_right_credentials_ajax.png');
    $this->assertSession()->pageTextContains(t('Successfully connected!'));

    // Change Remote languages mappings and save settings.
    $this->changeField('select[id^="edit-remote-languages-mappings-en"]', static::LANG_MAPPING['en']);
    $this->changeField('select[id^="edit-remote-languages-mappings-fr"]', static::LANG_MAPPING['fr']);
    $this->clickButton('input[id^="edit-submit"]');

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'configuration_updated.png');
    $this->assertSession()->pageTextContains(t('TextMaster configuration has been updated.'));
  }

  /**
   * Set API key and secret for TextMaster Provider.
   *
   * @param bool $right_credentials
   *   Flag whether the right credentials should be set.
   */
  protected function setTextmasterCredentials($right_credentials = TRUE) {

    // Visit Textmaster Provider configuration page that requires login.
    $this->visitTextmasterProviderSettingsPage();

    $api_key = $right_credentials ? static::API_CREDENTIALS['key'] : 'wrong_key';
    $api_secret = $right_credentials ? static::API_CREDENTIALS['secret'] : 'wrong_secret';

    // Enter Api key and secret.
    $this->changeField('input[id^="edit-settings-textmaster-api-key"]', $api_key);
    $this->changeField('input[id^="edit-settings-textmaster-api-secret"]', $api_secret);
    $this->click('input[id^="edit-settings-connect"]');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Visit configuration page for TextMaster Provider.
   */
  protected function visitTextmasterProviderSettingsPage() {
    $this->drupalGet('admin/tmgmt/translators/manage/textmaster');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(t('TEXTMASTER PLUGIN SETTINGS'));
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
    $page = $this->getSession()->getPage();
    $field = $page->find('css', $selector);
    $this->assertNotEmpty($field);
    $field->setValue($value);
  }

  /**
   * Helper to click button.
   *
   * @param string $selector
   *   jQuery selector for field.
   */
  protected function clickButton($selector) {
    $page = $this->getSession()->getPage();
    $button = $page->find('css', $selector);
    $this->assertNotEmpty($button);
    $button->click();
  }

  /**
   * Creates a node of a given bundle.
   *
   * It uses $this->field_names to populate content of attached fields.
   *
   * @param string $bundle
   *   Node type name.
   * @param string $sourcelang
   *   Source lang of the node to be created.
   * @param string $title
   *   Node title.
   *
   * @return \Drupal\node\NodeInterface
   *   Newly created node object.
   */
  protected function createTranslatableNode($bundle, $sourcelang = 'en', $title = '') {
    $node = [
      'type' => $bundle,
      'langcode' => $sourcelang,
    ];

    if (!empty($title)) {
      $node['title'] = $title;
    }

    if ($field_storage_config = FieldStorageConfig::loadByName('node', 'body')) {
      $cardinality = $field_storage_config->getCardinality() == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED ? 1 : $field_storage_config->getCardinality();

      // Create two deltas for each field.
      for ($delta = 0; $delta <= $cardinality; $delta++) {
        $node['body'][$delta]['value'] = $this->randomMachineName(20);
        $node['body'][$delta]['format'] = 'plain_text';
        if ($field_storage_config->getType() == 'text_with_summary') {
          $node['body'][$delta]['summary'] = $this->randomMachineName(10);
        }
      }
    }

    return $this->drupalCreateNode($node);
  }

  /**
   * Creates node type with body text field.
   *
   * @param string $machine_name
   *   Machine name of the node type.
   * @param string $human_name
   *   Human readable name of the node type.
   * @param bool $translation
   *   TRUE if translation for this entity type should be enabled.
   */
  protected function createNodeType($machine_name, $human_name, $translation = FALSE) {
    $this->drupalCreateContentType(['type' => $machine_name, 'name' => $human_name]);

    if (\Drupal::hasService('content_translation.manager') && $translation) {
      $content_translation_manager = \Drupal::service('content_translation.manager');
      $content_translation_manager->setEnabled('node', $machine_name, TRUE);
    }

    drupal_static_reset();
    \Drupal::entityManager()->clearCachedDefinitions();
    \Drupal::service('router.builder')->rebuild();
    \Drupal::service('entity.definition_update_manager')->applyUpdates();

    // Change body field to be translatable.
    $body = FieldConfig::loadByName('node', $machine_name, 'body');
    $body->setTranslatable(TRUE);
    $body->save();
  }

}
