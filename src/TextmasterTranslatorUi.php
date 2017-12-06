<?php

namespace Drupal\tmgmt_textmaster;

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
use Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator;

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
      '#description' => t("Please enter the TextMaster API key. You can find it <a href=:api_key_url  target='_blank'>here</a>", [
        ':api_key_url' => $tm_api_key_url,
      ]),
      '#required' => TRUE,
    ];
    $form['textmaster_api_secret'] = [
      '#type' => 'textfield',
      '#title' => t('TextMaster API secret'),
      '#default_value' => $translator->getSetting('textmaster_api_secret') ?: '',
      '#description' => t("Please enter your TextMaster API secret. You can find it <a href=:api_key_url target='_blank'>here</a>", [
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
    if (!$form_state->isRebuilding()) {
      drupal_set_message(t('Please note that Drupal word count may differ from TextMaster.'), 'warning');
    }

    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $this->getTranslatorPluginForJob($job);

    // Account Credits.
    $account_info = $translator_plugin->getTmAccountInfo();
    if (!empty($account_info['wallet'])) {
      $buy_credits_url = Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/payment_requests/new', [
        'attributes' => [
          'target' => '_blank',
        ],
      ]);
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

    // Project Price.
    $settings['project_price'] = [
      '#access' => FALSE,
      '#type' => 'textfield',
      '#title' => t('Project Price'),
      '#description' => t('TextMaster Project price.'),
      '#default_value' => $job->getSetting('project_price'),
    ];

    // Project Templates.
    $settings['templates_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'templates-wrapper'],
    ];
    // Project templates list.
    if ($form_state->isRebuilding() && $form_state->get('update_templates')) {
      // If this is an AJAX-Request, clear templates cache.
      Cache::invalidateTags(['tmgmt_textmaster']);
    }
    $templates = $this->getTemplatesList($translator_plugin, $job);
    $settings['templates_wrapper']['project_template'] = [
      '#type' => 'select',
      '#title' => t('Project template'),
      '#options' => $templates,
      '#description' => t('Select a TextMaster project template.'),
      '#required' => TRUE,
      '#default_value' => $job->settings->templates_wrapper['project_template'],
    ];
    // Add template link.
    $settings['templates_wrapper']['add_template'] = [
      '#type' => 'item',
      '#title' => t('Want to add project template?'),
      '#markup' => t('You can create it <a href=:template_url target="_blank">here</a>', [
        ':template_url' => Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/project_templates/api_templates')
          ->toString(),
      ]),
    ];
    // Update templates button.
    $settings['update_template_list'] = [
      '#type' => 'submit',
      '#value' => t('Update templates'),
      '#description' => t('If you added new template in TextMaster click on this button to update the list in Drupal.'),
      '#validate' => [[$this, 'updateTemplatesValidate']],
      '#ajax' => [
        'callback' => [$this, 'updateTemplatesSelectlist'],
        'wrapper' => 'templates-wrapper',
        'method' => 'replace',
      ],
      '#weight' => 10,
    ];

    // Xliff converter setting to allow html tags.
    $settings['xliff_cdata'] = [
      '#type' => 'checkbox',
      '#title' => t('XLIFF CDATA'),
      '#value' => TRUE,
      '#description' => t('Check to use CDATA for import/export.'),
      '#default_value' => $job->getSetting('xliff_cdata'),
      '#access' => FALSE,
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
   * {@inheritdoc}
   */
  public function reviewForm(array $form, FormStateInterface $form_state, JobItemInterface $item) {

    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $plugin */
    $translator = $item->getTranslator();
    if ($translator->getPluginId() != 'textmaster') {
      return $form;
    }
    if ($item->isState(JobItemInterface::STATE_REVIEW) && !$form_state->isRebuilding()) {
      drupal_set_message(t('If content is not accepted within 7 days, it will be automatically validated by TextMaster with no possible revision request.'), 'warning');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function reviewFormValidate(array $form, FormStateInterface $form_state, JobItemInterface $item) {

    $translator = $item->getTranslator();
    if ($translator->getPluginId() != 'textmaster') {
      return;
    }
    if ($form_state->getTriggeringElement()['#value'] == $form['actions']['save']['#value']) {
      // Allow 'Save' action for job item.
      return;
    }
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    $remote = tmgmt_textmaster_get_job_item_remote($item);
    $document_id = $remote['document_id'];
    $project_id = $remote['project_id'];
    $tm_document_data = $plugin->getTmDocument($project_id, $document_id);
    if (empty($tm_document_data)) {
      $form_state->setError($form, t('Could not get the TextMaster Document "@document_id" to complete it.', ['@document_id' => $document_id]));
    }
    if (!array_key_exists('status', $tm_document_data)
      || !$plugin->isRemoteTranslationCompleted($tm_document_data['status'])
    ) {
      $form_state->setError($form, t('The translation for this job item can not be accepted as the TextMaster document "@document_id" status is "@status".', [
        '@document_id' => $document_id,
        '@status' => $tm_document_data['status'],
      ]));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function reviewFormSubmit(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $plugin */
    $translator = $item->getTranslator();
    if ($translator->getPluginId() != 'textmaster') {
      return;
    }
    // Check if the user clicked on 'Save as completed'.
    if (empty($form['actions']['accept']) || $form_state->getTriggeringElement()['#value'] != $form['actions']['accept']['#value']) {
      return;
    }
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    // Get the mapping only for the last created Project.
    $remote = tmgmt_textmaster_get_job_item_remote($item);
    $document_id = $remote['document_id'];
    $project_id = $remote['project_id'];
    // Check document status. Only "in_review" documents can be completed.
    $tm_document_data = $plugin->getTmDocument($project_id, $document_id);
    if (!array_key_exists('status', $tm_document_data)
      || $tm_document_data['status'] != 'in_review'
    ) {
      // This Document must be already completed as Job item passed validation.
      $message = t('Could not complete TextMaster document "@document_id" with status "@status"', [
        '@document_id' => $document_id,
        '@status' => $tm_document_data['status'],
      ]);
      drupal_set_message($message);
      $item->getJob()->addMessage('Could not complete TextMaster document "@document_id" with status "@status"', [
        '@document_id' => $document_id,
        '@status' => $tm_document_data['status'],
      ]);
      return;
    }
    // Complete document in TextMaster.
    $result = $plugin->completeTmDocument($project_id, $document_id);
    // Show the result messages.
    if (!empty($result)) {
      // Success.
      $item->getJob()
        ->addMessage('TextMaster Document "@document_id" was completed', [
          '@document_id' => $document_id,
        ]);
      drupal_set_message(t('TextMaster Document "@document_id" was completed', [
        '@document_id' => $document_id,
      ]));
    }
    else {
      // Inform about failure.
      $item->getJob()
        ->addMessage('Could not complete TextMaster Document "@document_id"', [
          '@document_id' => $document_id,
        ], 'error');
      drupal_set_message(t('Could not complete TextMaster Document "@document_id"', [
        '@document_id' => $document_id,
      ]), 'error');
    }
  }

  /**
   * Submit callback to pull translations form TextMaster.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->fetchTranslatedFiles($job);
  }

  /**
   * Set a value in form_state to rebuild the form and fill with data.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   */
  public function updateTemplatesValidate(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    $form_state->set('update_templates', TRUE);
    // Clear errors to allow form rebuild.
    $form_state->clearErrors();
    $form_state->setValidationComplete();
  }

  /**
   * Ajax callback to update TextMaster templates list.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   *
   * @return array
   *   Form element that replaces templates_wrapper with the new one.
   */
  public function updateTemplatesSelectlist(array $form, FormStateInterface $form_state) {
    return $form['translator_wrapper']['settings']['templates_wrapper'];
  }

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
    // Clear errors to allow form rebuild.
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
    // Hide "Ask for revision button".
    $response->addCommand(new ReplaceCommand('.ask-for-revision-button', $form['actions']['ask_revision_in_tm']));
    // Show revision message field.
    $response->addCommand(new ReplaceCommand('#revision-message-wrapper', $form['revision_message_wrapper']));

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
    // Validation passed. Send revision request.
    /** @var \Drupal\tmgmt\JobItemInterface $job_item */
    $job_item = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $plugin */
    $plugin = $job_item->getTranslatorPlugin();
    $plugin->setTranslator($job_item->getTranslator());
    $remote = tmgmt_textmaster_get_job_item_remote($job_item);
    $document_id = $remote['document_id'];
    $project_id = $remote['project_id'];
    $message = $form_state->getValue('revision_message');
    $result = $plugin->createTmSupportMessage($project_id, $document_id, $message);

    if (!empty($result)) {
      // Add messages.
      drupal_set_message(t('Revision message was sent for Job item "@item_label".', [
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
    // Check erorrs.
    if (isset($form['revision_message_wrapper']['revision_message'])
      && $form_state->getError($form['revision_message_wrapper']['revision_message'])) {
      // If validation failed add error to response.
      $response = new AjaxResponse();
      $form['revision_message_wrapper']['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => 5,
      ];
      $response->addCommand(new HtmlCommand('#revision-message-wrapper', $form['revision_message_wrapper']));

      return $response;
    }
    // Validation passed. Rebuild the whole form as job item state was changed.
    $response = new AjaxResponse();
    // Remove previous warning message about 7 days validation.
    $response->addCommand(new RemoveCommand('div.messages--warning'));
    // Show new messages in form.
    $form['status_messages'] = [
      '#type' => 'status_messages',
    ];
    $response->addCommand(new ReplaceCommand('#tmgmt-textmaster-job-item-form-wrapper', $form));

    return $response;

  }

  /**
   * Get Translator plufin for job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job Entity.
   *
   * @return \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator
   *   TextMaster Translator plugin.
   */
  public function getTranslatorPluginForJob(JobInterface $job) {
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    return $translator_plugin;
  }

  /**
   * Get TextMaster templates list filtered by Job source and target language.
   *
   * @param \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin
   *   TextMaster Translator plugin.
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job Entity.
   *
   * @return array
   *   Filtered array of templates.
   */
  public function getTemplatesList(TextmasterTranslator $translator_plugin, JobInterface $job) {
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
    return $templates;
  }

}
