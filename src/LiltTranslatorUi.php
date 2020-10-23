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
   * Set a value in form_state to rebuild the form and fill with data.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public static function askForRevisionValidate(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    $form_state->set('ask_for_revision', TRUE);
    $form_state->clearErrors();
    $form_state->setValidationComplete();
  }

  /**
   * Ajax callback to show revision field.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax Response.
   */
  public static function askForRevisionCallback(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('.ask-for-revision-button', $form['actions']['lilt_revision']));
    $response->addCommand(new ReplaceCommand('#revision-message-wrapper', $form['revision_message_wrapper']));

    return $response;
  }

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
   * Ajax callback to send revision message.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax Response.
   */
  public static function sendRevisionRequestCallback(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if (isset($form['revision_message_wrapper']['revision_message']) && $form_state->getError($form['revision_message_wrapper']['revision_message'])) {
      $form['revision_message_wrapper']['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => 5,
      ];
      $response->addCommand(new HtmlCommand('#revision-message-wrapper', $form['revision_message_wrapper']));
      return $response;
    }

    $form['revision_message_wrapper']['status_messages'] = [
      '#type' => 'status_messages',
    ];
    unset($form['revision_message_wrapper']['request_revision'], $form['revision_message_wrapper']['revision_message']);
    $response->addCommand(new ReplaceCommand('#revision-message-wrapper', $form['revision_message_wrapper']));
    $response->addCommand(new RemoveCommand('div.messages--warning'));

    return $response;
  }

  /**
   * Validation of revision message field.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public static function sendRevisionRequestValidate(array &$form, FormStateInterface $form_state) {
    if (empty(trim($form_state->getValue('revision_message')))) {
      $form_state->setErrorByName($form['revision_message_wrapper']['revision_message']['#name'], t('Please enter revision message'));
      $form_state->setRebuild();
    }
  }

  /**
   * Submit for revision message field.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public static function sendRevisionRequestSubmit(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\JobItemInterface $job_item */
    $job_item = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_lilt\Plugin\tmgmt\Translator\LiltTranslator $plugin */
    $plugin = $job_item->getTranslatorPlugin();
    $plugin->setTranslator($job_item->getTranslator());

    $remote = LiltTranslator::getJobItemMapping($job_item);
    list('document_id' => $document_id, 'project_id' => $project_id) = $remote;
    $message = $form_state->getValue('revision_message');
    $result = $plugin->createLiltSupportMessage($project_id, $document_id, $message);

    if (!empty($result)) {
      \Drupal::messenger()->addMessage(t('Revision message was sent for Job item "@item_label".', [
        '@item_label' => $job_item->label(),
      ]));
      $job_item->getJob()
        ->addMessage('Revision message was sent for Document "@document_id".', [
          '@document_id' => $document_id,
        ]);
      $form_state->set('revision_message_sent', TRUE);
    }
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
