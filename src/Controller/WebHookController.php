<?php

namespace Drupal\tmgmt_textmaster\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route controller of the remote callbacks for the tmgmt_textmaster module.
 */
class WebHookController extends ControllerBase {

  /**
   * Handles the notifications of changes in the files states.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function callback(Request $request) {
    // TODO:: Change this method according to API.
    $config = \Drupal::configFactory()->get('tmgmt_textmaster.settings');
    if ($config->get('debug')) {
      \Drupal::logger('tmgmt_textmaster')
        ->debug('Request received %request.', ['%request' => $request]);
      \Drupal::logger('tmgmt_textmaster')
        ->debug('Request payload: ' . $request->getContent());
    }
    $json_content = json_decode($request->getContent());
    $jobParts = $json_content->jobParts;
    foreach ($jobParts as $jobPart) {
      $project_id = $jobPart->project->id;
      $job_part_id = $jobPart->id;
      $status = $jobPart->status;
      $workflow_level = $jobPart->workflowLevel;
      $last_workflow_level = $jobPart->project->lastWorkflowLevel;
      if (isset($project_id) && isset($job_part_id) && isset($status)) {
        // Get mappings between the job items and the file IDs, for the project.
        $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_textmaster', $project_id);
        if (empty($remotes)) {
          \Drupal::logger('tmgmt_textmaster')
            ->warning('Project %id not found.', ['%id' => $project_id]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 404);
        }
        $remote = NULL;
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_candidate */
        foreach ($remotes as $remote_candidate) {
          if ($remote_candidate->getRemoteIdentifier3() == $job_part_id) {
            $remote = $remote_candidate;
          }
        }
        if (!$remote) {
          \Drupal::logger('tmgmt_textmaster')
            ->warning('File %id not found.', ['%id' => $job_part_id]);
          return new Response(new FormattableMarkup('File %id not found.', ['%id' => $job_part_id]), 404);
        }
        if ($workflow_level != $last_workflow_level) {
          \Drupal::logger('tmgmt_textmaster')
            ->warning('Workflow level %workflow_level is not the last workflow level %last_workflow_level: project %project_id, job part %job_part_id',
              [
                '%workflow_level' => $workflow_level,
                '%last_workflow_level' => $last_workflow_level,
                '%project_id' => $project_id,
                '%job_part_id' => $job_part_id,
              ]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 400);
        }
        /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
        $translator_plugin = $remote->getJob()->getTranslator()->getPlugin();
        $translator_plugin->setTranslator($remote->getJob()->getTranslator());
        if (!$translator_plugin->remoteTranslationCompleted($status)) {
          \Drupal::logger('tmgmt_textmaster')
            ->warning('Invalid job part status %status: project %project_id, job part %job_part_id',
              [
                '%status' => $status,
                '%project_id' => $project_id,
                '%job_part_id' => $job_part_id,
              ]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 400);
        }

        $job = $remote->getJob();
        $job_item = $remote->getJobItem();
        try {
          $translator_plugin->addFileDataToJob($remote->getJob(), $status, $project_id, $job_part_id);
        }
        catch (TMGMTException $e) {
          $restart_point = $status == 'TranslatableReviewPreview' ? 'RestartPoint01' : 'RestartPoint02';
          $translator_plugin->sendFileError($restart_point, $project_id, $job_part_id, $job_item->getJob(), $remote->getRemoteData('RequiredBy'), $e->getMessage(), TRUE);
          $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
        }
      }
    }
    return new Response();
  }

  /**
   * Pull all remote translations.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function pullAllRemoteTranslations(Request $request) {
    $translators = Translator::loadMultiple();
    $items = [];
    $limit = 50;
    $operations = [];

    /** @var \Drupal\tmgmt\Entity\Translator $translator */
    foreach ($translators as $translator) {
      $translator_plugin = $translator->getPlugin();
      if ($translator_plugin instanceof TextmasterTranslator) {
        $query = \Drupal::entityQuery('tmgmt_job')
          ->condition('translator', $translator->id());
        $jobs = $query->execute();
        $query = \Drupal::entityQuery('tmgmt_job_item')
          ->condition('tjid', $jobs, 'IN');
        $or = $query->orConditionGroup()
          ->condition('state', JobItemInterface::STATE_ACTIVE)
          ->condition('state', JobItemInterface::STATE_REVIEW);
        $query->condition($or);
        $items = array_merge($query->execute(), $items);
      }
    }

    $chunks = array_chunk($items, $limit);

    foreach ($chunks as $chunk) {
      $operations[] = [
        [self::class, 'pullRemoteTranslations'],
        [$chunk],
      ];
    }
    $batch = [
      'title' => t('Pulling translations'),
      'operations' => $operations,
      'finished' => 'tmgmt_textmaster_pull_translations_batch_finished',
    ];
    batch_set($batch);
    return batch_process(Url::fromRoute('view.tmgmt_translation_all_job_items.page_1'));
  }

  /**
   * Creates continuous job items for entity.
   *
   * Batch callback function.
   */
  public static function pullRemoteTranslations(array $items, &$context) {
    if (!isset($context['results']['translated'])) {
      $context['results']['translated'] = 0;
    }
    $translated = $context['results']['translated'];
    /** @var \Drupal\tmgmt\JobItemInterface[] $job_items */
    $job_items = JobItem::loadMultiple($items);
    foreach ($job_items as $item) {
      /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
      $translator_plugin = $item->getJob()->getTranslatorPlugin();
      $translated += $translator_plugin->pullRemoteTranslation($item);
    }
    $context['results']['translated'] = $translated;
  }

}
