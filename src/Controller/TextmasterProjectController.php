<?php

namespace Drupal\tmgmt_textmaster\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator;
use Drupal\tmgmt\Entity\Job;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Route controller to perform project launch.
 */
class TextmasterProjectController extends ControllerBase {

  /**
   * Initialize the TextMaster Project project launch.
   *
   * @param string $tm_job_id
   *   The translation job id.
   * @param string $tm_project_id
   *   The TextMaster Project to launch.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function launchProject(string $tm_job_id, string $tm_project_id) {
    $job = Job::load($tm_job_id);
    /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    if (!$translator_plugin instanceof TextmasterTranslator) {
      $message = $this->t('Could not launch the job with Translation plugin different from TextMaster');
      return $this->redirectToJobsList($message, 'warning');
    }
    $translator_plugin->setTranslator($job->getTranslator());
    // Check the status of the projectin TextMaster.
    $tm_project_data = $translator_plugin->getTmProject($tm_project_id);
    if (!isset($tm_project_data['status'])) {
      $message = $this->t('Could not get the TextMaster Project status');
      return $this->redirectToJobsList($message, 'error');
    }
    if ($tm_project_data['status'] != 'in_creation') {
      $message = $this->t('Could not launch the TextMaster Project wit status: @status', ['@status' => $tm_project_data['status']]);
      return $this->redirectToJobsList($message, 'error');
    }
    $translator_plugin->sendApiRequest('/v1/clients/projects/' . $tm_project_id . '/launch', 'PUT');
    $message = $this->t('The TextMaster project "@project_label" was successfully launched', ['@project_label' => $job->label()]);
    $job->setState(Job::STATE_ACTIVE, $message);
    return $this->redirectToJobsList($message);
  }

  /**
   * Redirects to jobs listing page with a message(optional).
   *
   * @param string $message
   *   The message to show.
   * @param string $type
   *   The message type for log.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function redirectToJobsList($message = '', $type = 'status') {
    if (!empty($message)) {
      drupal_set_message($message, $type);
    }
    $jobs_list_url = Url::fromRoute('view.tmgmt_job_overview.page_1')
      ->toString();
    return new RedirectResponse($jobs_list_url);
  }

}
