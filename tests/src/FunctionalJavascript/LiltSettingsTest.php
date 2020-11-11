<?php

namespace Drupal\Tests\tmgmt_lilt\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * TMGMT Lilt settings admin tests.
 *
 * @group tmgmt_lilt
 */
class LiltSettingsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'tmgmt',
    'tmgmt_file',
    'tmgmt_lilt',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'seven';

  /**
   * Ensure the Lilt settings form exists and contains the Lilt API base.
   */
  public function testSettingsForm() {
    $admin_user = $this->drupalCreateUser([], 'tmgmt_lilt_tester', TRUE);
    $session = $this->getSession();
    $this->drupalLogin($admin_user);
    $this->drupalGet(Url::fromRoute('entity.tmgmt_translator.edit_form', [
      'tmgmt_translator' => 'lilt',
    ]));

    $page = $session->getPage();
    $summary = $page->find('css', '#edit-settings-lilt-service-url');
    $this->assertEquals('https://lilt.com/2', $summary->getAttribute('value'));
  }

}
