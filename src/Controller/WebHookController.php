<?php

namespace Drupal\tmgmt_textmaster\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\TMGMTException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route controller of the remote callbacks for the tmgmt_textmaster module.
 */
class WebHookController extends ControllerBase {

  /**
   * Handles the change of TextMaster document state to "in_review".
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function inReviewCallback(Request $request) {
    $logger = $this->getLogger('tmgmt_textmaster');
    try {
      $logger->debug('Request received @request.', ['@request' => $request]);
      $logger->debug('Request payload: ' . $request->getContent());

      $json_content = json_decode($request->getContent());
      $document_id = $json_content->id;
      $project_id = $json_content->project_id;
      $status = $json_content->status;
      $remote_file_url = $json_content->author_work;

      if (!isset($project_id) || !isset($document_id) || !isset($status) || !isset($remote_file_url)) {
        // Nothing to do here.
        $logger->warning('Could not find TextMaster project id, document id, status or translated file url in callback request.');
        return new Response('Could not get TextMaster project id, document id, status or translated file url from request.');
      }

      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
      $remote = $this->getJobItemRemoteByTmData($project_id, $document_id);
      if (empty($remote)) {
        // Didn't find JobItem with this Document ID. Probably it was deleted.
        $logger->warning('Job Item with TextMaster Document id "@document_id" and Project id "@project_id" not found.', [
          '@document_id' => $document_id,
          '@project_id' => $project_id,
        ]);
        return new Response(new FormattableMarkup('Document @id not found.', ['@id' => $document_id]), 404);
      }

      $job = $remote->getJob();
      /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
      $translator_plugin = $job->getTranslator()->getPlugin();
      $translator_plugin->setTranslator($job->getTranslator());
      if (!$translator_plugin->isRemoteTranslationCompleted($status)) {
        $logger->warning('Invalid document status @status: project @project_id, document @document_id',
          [
            '@status' => $status,
            '@project_id' => $project_id,
            '@document_id' => $document_id,
          ]);
        return new Response(new FormattableMarkup('Unknown status for the Document @id.', ['@id' => $document_id]), 400);
      }

      try {
        // Download translated document from the given URL.
        $translator_plugin->addTranslationToJob($job, $status, $project_id, $document_id, $remote_file_url);
      }
      catch (TMGMTException $e) {
        $job_item = $remote->getJobItem();
        $job->addMessage('Exception occurred while downloading translation for the job item @job_item: @error', [
          '@job_item' => $job_item->label(),
          '@error' => $e->getMessage(),
        ], 'error');
      }
    }
    catch (\Exception $e) {
      $logger->error($e->getMessage());
    }
    return new Response('OK');
  }

  /**
   * Handles the finalization of documents word count to finalize the project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function wordCountFinishedCallback(Request $request) {
    $logger = $this->getLogger('tmgmt_textmaster');
    try {
      $logger->debug('Request received @request.', ['@request' => $request]);
      $logger->debug('Request payload: ' . $request->getContent());

      $json_content = json_decode($request->getContent());
      $document_id = $json_content->id;
      $project_id = $json_content->project_id;
      $word_count = $json_content->word_count;

      if (!isset($project_id) || !isset($document_id) || !isset($word_count)) {
        // Nothing to do here.
        $logger->warning('Could not find TextMaster project id, document id or word count in callback request.');
        return new Response('Could not find TextMaster project id, document id or word count in callback request.');
      }

      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
      $remote = $this->getJobItemRemoteByTmData($project_id, $document_id);
      if (empty($remote)) {
        // Didn't find JobItem with this Document ID. Probably it was deleted.
        $logger->warning('Job Item with TextMaster Document id "@document_id" and Project id "@project_id" not found.', [
          '@document_id' => $document_id,
          '@project_id' => $project_id,
        ]);
        return new Response(new FormattableMarkup('Document @id not found.', ['@id' => $document_id]), 404);
      }

      // Set WordCountFinished in JobItem remote data.
      $remote_data = $remote->remote_data->getValue();
      $remote_data[0]['WordCountFinished'] = TRUE;
      $remote->remote_data->setValue($remote_data);
      $remote->save();
      $job = $remote->getJob();

      // Check if word count was finished for all Job Items of this Job.
      if ($this->isWordCountFinishedForJob($job)) {

        // Finalize the project.
        /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
        $translator_plugin = $job->getTranslator()->getPlugin();
        $translator_plugin->setTranslator($job->getTranslator());
        $translator_plugin->finalizeTmProject($project_id, $job);
      }
    }
    catch (\Exception $e) {
      $logger->error($e->getMessage());
    }

    return new Response('OK');
  }

  /**
   * Handles the finalization of TextMaster Project to set the correct price.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function projectFinalizedCallback(Request $request) {
    $logger = $this->getLogger('tmgmt_textmaster');
    try {
      $logger->debug('Request received @request.', ['@request' => $request]);
      $logger->debug('Request payload: ' . $request->getContent());

      $json_content = json_decode($request->getContent());
      $project_id = $json_content->id;
      $total_costs = $json_content->total_costs;

      if (!isset($project_id) || !isset($total_costs)) {
        // Nothing to do here.
        $logger->warning('Could not find TextMaster project id or total costs in callback request.');
        return new Response('Could not find TextMaster project id or total costs in callback request.');
      }

      // Get mappings between the job and project id.
      $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_textmaster', $project_id);
      if (empty($remotes)) {
        // Didn't find a Job with this Project ID. Probably it was deleted.
        $logger->warning('Job with TextMaster Project id "@id" not found.', ['@id' => $project_id]);
        return new Response(new FormattableMarkup('Project @id not found.', ['@id' => $project_id]), 404);
      }

      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
      $remote = reset($remotes);
      $job = $remote->getJob();
      $cost_in_currency = reset($total_costs);
      $price = round($cost_in_currency->amount, 2) . ' ' . $cost_in_currency->currency;

      // Set the project price.
      $this->setProjectPriceForJob($job, $price);
    }
    catch (\Exception $e) {
      $logger->error($e->getMessage());
    }
    return new Response('OK');
  }

  /**
   * Set TextMaster Project price for job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job.
   * @param string $price
   *   TextMaster Project price.
   *
   * @return bool
   *   TRUE if success.
   */
  public function setProjectPriceForJob(JobInterface $job, $price) {
    $settings = $job->settings->getValue();
    $settings[0]['project_price'] = $price;
    $job->settings->setValue($settings);
    $job->save();
    return TRUE;
  }

  /**
   * Get TMGMT RemoteMapping by TM project and document ids.
   *
   * @param string $project_id
   *   TextMaster project id.
   * @param string $document_id
   *   TextMaster document id.
   *
   * @return array|\Drupal\tmgmt\Entity\RemoteMapping
   *   Remote mapping.
   */
  public function getJobItemRemoteByTmData($project_id, $document_id) {
    // Get mappings between the job items and project Document IDs.
    $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_textmaster', $project_id, $document_id);
    $logger = $this->getLogger('tmgmt_textmaster');
    if (empty($remotes)) {
      // Didn't find JobItem with this Document ID and Project ID.
      // Probably it was deleted.
      $logger->warning('Job Item with TextMaster Document id "@id" not found.', ['@id' => $document_id]);
      return [];
    }

    return reset($remotes);
  }

  /**
   * Check if word count was finished for all Job Items of this Job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   TMGMT Job.
   *
   * @return bool
   *   TRUE if was finished.
   */
  public function isWordCountFinishedForJob(JobInterface $job) {
    foreach ($job->getItems() as $item) {
      // Check WordCountFinished in remote data.
      $mappings = $item->getRemoteMappings();
      $remote = end($mappings);
      if (!$remote->remote_data->WordCountFinished) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
