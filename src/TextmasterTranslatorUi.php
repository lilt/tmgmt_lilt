<?php

namespace Drupal\tmgmt_textmaster;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\tmgmt\JobInterface;
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
    $translator_plugin = $this->getTranslatorPluginForJob($job);
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
    $templates = $this->getTemplatesList($translator_plugin, $job);
    // Project templates list.
    $settings['templates_wrapper']['project_template'] = [
      '#type' => 'select',
      '#title' => t('Project template'),
      '#options' => $templates,
      '#description' => t('Select a TextMaster project template.'),
      '#required' => TRUE,
      '#default_value' => $job->getSetting('project_template'),
    ];
    // Add template link.
    $settings['templates_wrapper']['add_template'] = [
      '#type' => 'item',
      '#title' => t('Want to add project template?'),
      '#markup' => t('You can create it <a href=:template_url>here</a>', [
        ':template_url' => Url::fromUri(static::TEXTMASTER_APPLICATION_URL . '/clients/project_templates/api_templates')
          ->toString(),
      ]),
    ];
    // Update templates button.
    $settings['update_template_list'] = [
      '#type' => 'button',
      '#value' => t('Update templates'),
      '#description' => t('If you added new template in TextMaster click on this button to update the list in Drupal.'),
      '#ajax' => [
        'callback' => [$this, 'updateTemplatesSelectlist'],
      ],
      '#weight' => 10,
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
    $translator_plugin->fetchTranslatedFiles($job);
  }

  /**
   * Ajax callback to update TextMaster templates list.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   FormState.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Response that replaces selectlist with the new one.
   */
  public function updateTemplatesSelectlist(array $form, FormStateInterface $form_state) {
    // Invalidate templates cache.
    Cache::invalidateTags(['tmgmt_textmaster']);

    // Set new values.
    $response = new AjaxResponse();
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $this->getTranslatorPluginForJob($job);
    $htmlId = '#templates-wrapper';
    $wrapper = $form['translator_wrapper']['settings']['templates_wrapper'];
    $templates = $this->getTemplatesList($translator_plugin, $job);
    // Add Empty value as selectlist has already been processed.
    $empty_option = ['' => t('-- Select --')];
    $wrapper['project_template']['#options'] = $empty_option + $templates;
    // Clear errors.
    unset($wrapper['project_template']['#errors']);
    $form_state->clearErrors();

    $response->addCommand(new HtmlCommand($htmlId, $wrapper));

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
