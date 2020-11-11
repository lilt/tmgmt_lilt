<?php

namespace Drupal\tmgmt_lilt;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
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

    $app_url = UrlHelper::isValid($translator->getSetting('lilt_app_url')) ? $translator->getSetting('lilt_app_url') : 'https://lilt.com/app/';
    $service_url = UrlHelper::isValid($translator->getSetting('lilt_service_url')) ? $translator->getSetting('lilt_service_url') : 'https://lilt.com/2';

    $form['lilt_api_key'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt API key'),
      '#default_value' => $translator->getSetting('lilt_api_key') ?: '',
      '#description' => t("Please enter the Lilt API key. You can find it <a href=:api_key_url  target='_blank'>here</a>", [
        ':api_key_url' => Url::fromUri($app_url . 'organizations/apiaccess')->toString(),
      ]),
      '#required' => TRUE,

    ];
    $form['lilt_service_url'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt API URL'),
      '#default_value' => $service_url,
      '#description' => t('Please enter the Lilt API base URL.'),
      '#required' => TRUE,
    ];
    $form['lilt_app_url'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt App URL'),
      '#default_value' => $app_url,
      '#description' => t('Please enter the Lilt App base url.'),
      '#required' => TRUE,
    ];
    $form['lilt_log_api'] = [
      '#type' => 'checkbox',
      '#title' => t('Lilt API logging'),
      '#default_value' => $translator->getSetting('lilt_log_api') ?: FALSE,
      '#description' => t('Enables Watchdog logging of Lilt API requests.'),
      '#required' => FALSE,
    ];
    $form['lilt_pretranslation'] = [
      '#type' => 'select',
      '#title' => t('Lilt Pretranslation'),
      '#default_value' => $translator->getSetting('lilt_pretranslation'),
      '#description' => t('The optional pre-translation option to use for uploaded documents.'),
      '#options' => [
        '' => t('Null (none)'),
        'tm' => t('Translation Memory'),
        'tm+mt' => t('Translation Memory & Machine Translation'),
      ],
      '#required' => FALSE,
    ];
    $form['lilt_auto_accept'] = [
      '#type' => 'checkbox',
      '#title' => t('Lilt Auto-accept'),
      '#default_value' => $translator->getSetting('lilt_auto_accept') ?: FALSE,
      '#description' => t('Auto-accept pre-matched translations.'),
      '#required' => FALSE,
    ];
    $form['lilt_config_id'] = [
      '#type' => 'textfield',
      '#title' => t('Lilt Config ID'),
      '#default_value' => $translator->getSetting('lilt_config_id'),
      '#description' => t('An optional pararameter to specify an import configuration to be applied when extracting translatable content from the document.'),
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
      $project_id = LiltTranslator::getJobProjectId($job);
      $translator_plugin = $this->getTranslatorPluginForJob($job);
      $project_info = $translator_plugin->getLiltProject($project_id);

      $ready_translation = ($project_info['state'] == 'done');
      $project_url = Url::fromUri(LiltTranslator::getLiltAppUrl($job->getTranslator()) . 'projects/details/' . $project_id)->toString();
      if (!$ready_translation) {
        \Drupal::messenger()->addWarning(t("The <a href=:project_url  target='_blank'>Lilt translation project</a> for this job hasn't completed yet.", [
          ':project_url' => $project_url,
        ]));
      }
      else {
        \Drupal::messenger()->addMessage(t("The <a href=:project_url  target='_blank'>Lilt translation project</a> for this job has completed. The <strong>Pull translations</strong> button will retrieve the translations.", [
          ':project_url' => $project_url,
        ]));
      }

      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => [[$this, 'submitPullTranslations']],
        '#weight' => -10,
        '#disabled' => !$ready_translation,
      ];
    }
    else {
      if ($project_id = LiltTranslator::getJobProjectId($job)) {
        $project_url = Url::fromUri(LiltTranslator::getLiltAppUrl($job->getTranslator()) . 'projects/details/' . $project_id)->toString();
        $form['lilt_status'] = [
          '#markup' => t("<span>The <a href=:project_url  target='_blank'>Lilt translation project</a> has been archived.</span>", [
            ':project_url' => $project_url,
          ]),
          '#weight' => -10,
        ];
      }
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

    $tm_filter = $job->getTargetLangcode();
    $tm_filter = (strpos($tm_filter, '-') !== FALSE) ? explode('-', $tm_filter)[0] : $tm_filter;

    $default_date = isset($_SESSION['tmgmt_lilt']['last_job_due_date']) ? $_SESSION['tmgmt_lilt']['last_job_due_date'] : DrupalDateTime::createFromTimestamp(time());
    $settings['due_date'] = [
      '#title' => t('Due Date'),
      '#type' => 'datetime',
      '#default_value' => is_null($job->getSetting('due_date')) ? $default_date : $job->getSetting('due_date'),
      '#description' => t('The date on which the translation job is due.'),
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
    ];
    $settings['memory_id'] = [
      '#title' => t('Translation Memory'),
      '#type' => 'select',
      '#default_value' => $job->getSetting('memory_id'),
      '#description' => t('The translation memory to use for the job.'),
      '#options' => $translator_plugin->getTranslationMemories($tm_filter),
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
    $remote = LiltTranslator::getJobItemMapping($item);
    list('module_name' => $module_name, 'document_id' => $document_id, 'project_id' => $project_id) = $remote;
    if ($module_name != 'tmgmt_lilt') {
      return;
    }
    if (empty($form['actions']['accept']) || $form_state->getTriggeringElement()['#value'] != $form['actions']['accept']['#value']) {
      return;
    }

    // Log message.
    $item->getJob()->addMessage('Lilt Document @document_id for Project @project_id was completed', [
      '@document_id' => $document_id,
      '@project_id' => $project_id,
    ]);
    \Drupal::messenger()->addMessage(t('Lilt Document @document_id for Project @project_id was completed', [
      '@document_id' => $document_id,
      '@project_id' => $project_id,
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

    // Remove destination override so we can redirect to job admin page.
    \Drupal::request()->query->remove('destination');

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
      $form_state->setErrorByName('settings][lilt_api_key', t('Authentication failed. Please check the API key or API URL.'));
      $form_state->setErrorByName('settings][lilt_service_url', t('Authentication failed. Please check the API key or API URL.'));
    }
  }

}
