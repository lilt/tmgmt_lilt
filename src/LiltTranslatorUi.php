<?php

namespace Drupal\tmgmt_lilt;

use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator;

/**
 * Lilt translator UI.
 */
class LiltTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['lilt_service_url'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt API url'),
      '#default_value' => $translator->getSetting('lilt_service_url') ?: 'https://lilt.com/2',
      '#description' => t('Please enter the Lilt API base url.'),
      '#required' => TRUE,
    ];
    $form['lilt_api_key'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt API key'),
      '#default_value' => $translator->getSetting('lilt_api_key') ?: '',
      '#description' => t("Please enter the Lilt API key. You can find it <a href=:api_key_url  target='_blank'>here</a>", [
        ':api_key_url' => Url::fromUri('https://lilt.com/app/organizations/apiaccess')->toString(),
      ]),
      '#required' => TRUE,

    ];
    $form += parent::addConnectButton();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    if (!$plugin->checkLiltAuth()) {
      $form_state->setErrorByName('settings][service_url', t('Authentication failed. Please check the API key and secret.'));
    }
  }
}
