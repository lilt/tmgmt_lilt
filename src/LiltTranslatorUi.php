<?php

namespace Drupal\tmgmt_lilt;

use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Datetime\DrupalDateTime;
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
   * Base URL for Lilt web app.
   *
   * @var string
   */
  const LILT_APP_URL = 'https://lilt.com/app/';

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
        ':api_key_url' => Url::fromUri(LiltTranslatorUi::LILT_APP_URL . 'organizations/apiaccess')->toString(),
      ]),
      '#required' => TRUE,

    ];
    $form['lilt_log_api'] = [
      '#type' => 'checkbox',
      '#title' => t('Lilt API logging'),
      '#default_value' => $translator->getSetting('lilt_log_api') ?: FALSE,
      '#description' => t('Enables Watchdog logging of Lilt API requests.'),
      '#required' => FALSE,
    ];
    $form += parent::addConnectButton();

    return $form;
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
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    if ($form_state->isRebuilding() && $form_state->getTriggeringElement()['#value'] == 'lilt') {
      \Drupal::messenger()->addWarning(t('Please note that Drupal word count may differ from Lilt.'));
    }

    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
    $translator_plugin = $this->getTranslatorPluginForJob($job);

    $settings['due_date'] = [
      '#title' => t('Due Date'),
      '#type' => 'datetime',
      '#default_value' => is_null($job->getSetting('due_date')) ? DrupalDateTime::createFromTimestamp(time()) : $job->getSetting('due_date'),
      '#description' => t('The date on which the translation job is due.'),
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
    ];
    $settings['memory_id'] = [
      '#title' => t('Translation Memory'),
      '#type' => 'select',
      '#default_value' => $job->getSetting('memory_id'),
      '#description' => t('The translation memory to use for the job.'),
      '#options' => $translator_plugin->getTranslationMemories(),
    ];

    return $settings;
  }

  /**
   * Get Translator plugin for job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job Entity.
   *
   * @return \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator
   *   Lilt Translator plugin.
   */
  public function getTranslatorPluginForJob(JobInterface $job) {
    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    return $translator_plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function reviewFormSubmit(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator */
    $translator = $item->getTranslator();
    if ($translator->getPluginId() != 'lilt') {
      return;
    }
    if (empty($form['actions']['accept']) || $form_state->getTriggeringElement()['#value'] != $form['actions']['accept']['#value']) {
      return;
    }

    // Log message.
    $item->getJob()->addMessage('Lilt Document "@document_id" was completed', [
        '@document_id' => $document_id,
      ]);
    \Drupal::messenger()->addMessage(t('Lilt Document "@document_id" was completed', [
      '@document_id' => $document_id,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function reviewFormValidate(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    $translator = $item->getTranslator();
    if ($translator->getPluginId() != 'lilt') {
      return;
    }
    if ($form_state->getTriggeringElement()['#value'] == $form['actions']['save']['#value']) {
      return;
    }

    // Nothing to validate.
  }

  /**
   * Submit callback to pull translations form Lilt.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->fetchTranslatedFiles($job);
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
