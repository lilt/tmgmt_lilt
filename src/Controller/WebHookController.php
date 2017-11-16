<?php

namespace Drupal\tmgmt_textmaster\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
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
  public function callback(Request $request) {
    $logger = $this->getLogger('tmgmt_textmaster');
    try {
      $logger->debug('Request received %request.', ['%request' => $request]);
      $logger->debug('Request payload: ' . $request->getContent());
      $json_content = json_decode($request->getContent());
      $document_id = $json_content->id;
      $project_id = $json_content->project_id;
      $status = $json_content->status;
      $remote_file_url = $json_content->author_work;
      if (isset($project_id) && isset($document_id) && isset($status)) {
        // Get mappings between the job items and project Document IDs.
        $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_textmaster', $project_id);
        if (empty($remotes)) {
          $logger->warning('Job with TextMaster Project id "%id" not found.', ['%id' => $project_id]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 404);
        }
        $remote = NULL;
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_candidate */
        foreach ($remotes as $remote_candidate) {
          if ($remote_candidate->getRemoteIdentifier3() == $document_id) {
            $remote = $remote_candidate;
          }
        }
        if (!$remote) {
          $logger->warning('Job Item with TextMaster Document id "%id" not found.', ['%id' => $document_id]);
          return new Response(new FormattableMarkup('Document %id not found.', ['%id' => $document_id]), 404);
        }
        $job = $remote->getJob();
        /** @var \Drupal\tmgmt_textmaster\Plugin\tmgmt\Translator\TextmasterTranslator $translator_plugin */
        $translator_plugin = $job->getTranslator()->getPlugin();
        $translator_plugin->setTranslator($job->getTranslator());
        if (!$translator_plugin->remoteTranslationCompleted($status)) {
          $logger->warning('Invalid document status %status: project %project_id, document %document_id',
            [
              '%status' => $status,
              '%project_id' => $project_id,
              '%document_id' => $document_id,
            ]);
          return new Response(new FormattableMarkup('Invalid Document %id status.', ['%id' => $document_id]), 400);
        }
        $job_item = $remote->getJobItem();
        try {
          $translator_plugin->addTranslationToJob($job, $status, $project_id, $document_id, $remote_file_url);
        }
        catch (TMGMTException $e) {
          $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
        }
      }
    }
    catch (\Exception $e) {
      $logger->error($e->getMessage());
    }
    return new Response();
  }

}
