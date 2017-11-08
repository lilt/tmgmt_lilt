<?php

namespace Drupal\tmgmt_textmaster;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Link;

/**
 * TextMaster translator UI.
 */
class TextmasterTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

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
      '#description' => t('Please enter the TextMaster API key.'),
      '#required' => TRUE,
    ];
    $form['textmaster_api_secret'] = [
      '#type' => 'textfield',
      '#title' => t('TextMaster API secret'),
      '#default_value' => $translator->getSetting('textmaster_api_secret') ?: '',
      '#description' => t('Please enter your TextMaster API secret.'),
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
    $templates_json = $translator_plugin->sendApiRequest('/v1/clients/api_templates');

    // Get languages from select fields.
    $sourceLang = $job->getRemoteSourceLanguage();
    $targetLang = $job->getRemoteTargetLanguage();

    $templates = [];

    foreach ($templates_json['api_templates'] as $template) {
      // Display only templates which match the selected source & target langs.
      if ($template['language_from'] === $sourceLang && $targetLang === $template['language_to']) {
        $templates[$template['id']] = $template['name'];
      }
    }
    if (empty($templates)) {

      $tm_templates_url = Url::fromUri('https://www.app.textmaster.com/clients/project_templates/api_templates');
      $settings['add_template'] = [
        '#type' => 'item',
        '#title' => t('No project template?'),
        '#markup' => 'The project template is required. You can create one ' . Link::fromTextAndUrl(t('here'), $tm_templates_url)->toString(),
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
    $tm_categories_json = $translator_plugin->sendApiRequest('/v1/public/categories');
    $categories = [];
    foreach ($tm_categories_json['categories'] as $category) {
      $categories[$category['code']] = $category['value'];
    }
    $settings['category'] = [
      '#type' => 'select',
      '#title' => t('Project category'),
      '#options' => $categories,
      '#description' => t('Select a TextMaster project category.'),
      '#required' => TRUE,
      '#default_value' => $job->getSetting('category'),
    ];
    $settings['deadline'] = [
      '#type' => 'date',
      '#title' => t('Delivery date'),
      '#date_date_format' => 'Y-m-d',
      '#description' => t('Please note that TextMaster cannot guarantee this deadline; however, they will do their best to meet it.'),
      '#default_value' => $job->getSetting('deadline'),
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
    // TODO: needs work.
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
        $job->addMessage('Fetched translations for @translated job items.', ['@translated' => $translated]);
      }
      elseif ($translated == 0) {
        drupal_set_message('No job item has been translated yet.');
      }
      else {
        $job->addMessage('Fetched translations for @translated job items, @untranslated are not translated yet.', [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]);
      }
    }
    tmgmt_write_request_messages($job);
  }

}
