<?php

namespace Drupal\Tests\tmgmt_textmaster\FunctionalJavascript;

/**
 * Test for tmgmt_textmaster translator plugin.
 *
 * @group tmgmt_textmaster
 */
class TmgmtTextmasterJobCreationTest extends TmgmtTextmasterTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->createNodeType('textmaster_page', 'TextMaster Test Page', TRUE);
    $this->createTranslatableNode('textmaster_page', 'en', 'Page for test translation En');
  }

  /**
   * Test the creation of Translation job with TextMaster Provider.
   */
  public function testTextmasterTranslationJobCreation() {
    parent::baseTestSteps();

    // Configure TextMaster Provider with right credentials and remote mapping.
    $this->configureTextmasterProvider();

    // Visit Source page to create new translation Job.
    $this->drupalGet('admin/tmgmt/sources');
    $this->assertSession()->statusCodeEquals(200);

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'translation_sources.png');
    $this->assertSession()->pageTextContains(t('Page for test translation En'));

    // Select created node and request translation.
    $this->changeField('input[id^="edit-items-1"]', 1);
    $this->clickButton('input[id^="edit-submit"]');

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'new_job_creation_page.png');
    $this->assertSession()->pageTextContains(t('Configure provider'));

    // Set TextMaster Translator.
    $this->changeField('select[id^="edit-translator"]', 'textmaster');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains(t('Buy credits on TextMaster'));
    $this->assertSession()->pageTextContains(t('Select a TextMaster project template'));

    // Set TextMaster Template.
    $this->changeField('select[id^="edit-settings-templates-wrapper-project-template"]', parent::SIMPLE_TEMPLATE_ID);
    $this->assertSession()->pageTextContains(t('Test Template EN(GB)_FR(FR) -- NO autolaunch'));

    // Update templates.
    $this->clickButton('input[id^="edit-settings-update-template-list"]');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'new_job_creation_filled_form.png');
    $this->assertSession()->pageTextContains(t('Test Template EN(GB)_FR(FR) -- NO autolaunch'));

    // Submit job to TextMaster.
    $this->clickButton('input[id^="edit-submit"]');
    $this->assertSession()->pageTextContains(t('1 document(s) was(were) created in TextMaster for Job'));

    // Visit job overview page. Check Price column and Job state.
    $this->drupalGet('admin/tmgmt/jobs');
    $this->assertSession()->pageTextContains(t('Project Price'));
    $this->assertSession()->pageTextContains(t('Unprocessed'));

    // Check "View on TextMaster" and "Launch" link.
    $page = $this->getSession()->getPage();
    $button = $page->find('css', 'li.dropbutton-toggle button');
    $this->assertNotEmpty($button);
    $button->click();

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'job_actions.png');
    $this->assertSession()->pageTextContains(t('View on TextMaster'));
    $this->assertSession()->pageTextContains(t('Launch'));

    // Visit Job page and check job messages.
    $this->clickLink(t('Submit'));

    $this->createScreenshot(\Drupal::root() . $this->screenshotPath . 'job_page.png');
    $this->assertSession()->pageTextContains(t('Please note that Drupal word count may differ from TextMaster'));
    $this->assertSession()->pageTextContains(t('Created a new Document in TextMaster with the id'));
    $this->assertSession()->pageTextContains(t('Created a new Project in TextMaster with the id'));
    $this->assertSession()->pageTextContains(t('was finalized'));
  }

}
