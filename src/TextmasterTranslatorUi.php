<?php

namespace Drupal\tmgmt_textmaster;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * TextMaster translator UI.
 */
class TextmasterTranslatorUi extends TranslatorPluginUiBase {

  const TEXTMASTER_APPLICATION_URL = 'https://www.app.sandbox.textmaster.com';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $tm_api_key_url = Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/api_info')->toString();

    $form['textmaster_service_url'] = [
      '#type' => 'textfield',
      '#title' => t('TextMaster API url'),
      '#default_value' => $translator->getSetting('textmaster_service_url') ?: 'http://api.textmaster.com',
      '#description' => t('Please enter the TextMaster API base url.'),
      '#required' => TRUE,
    ];
    $form['textmaster_api_key'] = [
      '#type' => 'textfield',
      '#title' => t('TextMaster API key'),
      '#default_value' => $translator->getSetting('textmaster_api_key') ?: '',
      '#description' => t("Please enter the TextMaster API key. You can find it <a href=:api_key_url>here</a>", [
        ':api_key_url' => $tm_api_key_url,
      ]),
      '#required' => TRUE,
    ];
    $form['textmaster_api_secret'] = [
      '#type' => 'textfield',
      '#title' => t('TextMaster API secret'),
      '#default_value' => $translator->getSetting('textmaster_api_secret') ?: '',
      '#description' => t("Please enter your TextMaster API secret. You can find it <a href=:api_key_url>here</a>", [
        ':api_key_url' => $tm_api_key_url,
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
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    $result = $plugin->checkTextmasterAuthentication();
    if ($result) {
      // Authentication OK.
    }
    else {
      $form_state->setErrorByName('settings][service_url', t('Authentication failed. Please check the API key and secret.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    // Account Credits.
    $account_info = $translator_plugin->getTmAccountInfo();
    if (!empty($account_info['wallet'])) {
      $buy_credits_url = Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/payment_requests/new');
      $settings['account_credits'] = [
        '#type' => 'item',
        '#title' => t('Available credits: @current_money @currency_code', [
          '@current_money' => $account_info['wallet']['current_money'],
          '@currency_code' => $account_info['wallet']['currency_code'],
        ]),
        '#markup' => Link::fromTextAndUrl(t('Buy credits on TextMaster'), $buy_credits_url)
          ->toString(),
      ];
    }

    // Project Templates.
    $templates_json = $translator_plugin->getTmApiTemplates();
    $sourceLang = $job->getRemoteSourceLanguage();
    $targetLang = $job->getRemoteTargetLanguage();
    $templates = [];
    foreach ($templates_json as $template) {
      // Display only templates which match the selected source & target langs.
      if ($template['language_from'] === $sourceLang && $targetLang === $template['language_to']) {
        $templates[$template['id']] = $template['name'];
      }
    }
    if (empty($templates)) {
      $settings['add_template'] = [
        '#type' => 'item',
        '#title' => t('No project template?'),
        '#markup' => t('The project template is required. You can create one <a href=:template_url>here</a>', [
          ':template_url' => Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/project_templates/api_templates')
            ->toString(),
        ]),
      ];
      return $settings;
    }
    $settings['project_template'] = [
      '#type' => 'select',
      '#title' => t('Project template'),
      '#options' => $templates,
      '#description' => t('Select a TextMaster project template.'),
      '#required' => TRUE,
      '#default_value' => $job->getSetting('project_template'),
    ];

    // Project Price.
    $settings['project_price'] = [
      '#access' => FALSE,
      '#type' => 'textfield',
      '#title' => t('Project Price'),
      '#description' => t('TextMaster Project price.'),
      '#default_value' => $job->getSetting('project_price'),
    ];

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = [];

    if ($job->isActive()) {
      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => [[$this, 'submitPullTranslations']],
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * Submit callback to pull translations form TextMaster.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $result = $translator_plugin->fetchTranslatedFiles($job);
    $translated = $result['translated'];
    $untranslated = $result['untranslated'];
    $errors = $result['errors'];
    if (count($errors) == 0) {
      if ($untranslated == 0 && $translated != 0) {
        $job->addMessage(t('Fetched translations for @translated job items.', ['@translated' => $translated]));
      }
      elseif ($translated == 0) {
        drupal_set_message(t('No job item has been translated yet.'));
      }
      else {
        $job->addMessage(t('Fetched translations for @translated job items, @untranslated are not translated yet.', [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]));
      }
    }
    tmgmt_write_request_messages($job);
  }

  /**
   * {@inheritdoc}
   */
  public function reviewFormSubmit(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    // Nothing to do here by default.
  }

}
