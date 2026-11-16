<?php 

namespace Drupal\temporary_downloadable_media\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\media\MediaInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\views\Views;
use Drupal\media\Entity\Media;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\User;
use Drupal\jwt\JsonWebToken\JsonWebToken;
use Drupal\jwt\Transcoder\JwtTranscoderInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class JWTTokenController extends ControllerBase {

	/** 
   * Generates a JWT token for the service account user (UID 1) without * initiating an active login session. 
   * @return string * The encoded JWT token string. */

	public function getJWTToken(): string {
	  $config = \Drupal::config('temporary_downloadable_media.settings');

	  // 1. Load the user entity directly.
	  $service_account_uid = $config->get("user_select");
	  $account = User::load($service_account_uid);

	  if (!$account) {
	    // Handle the case where the user is not found.
	    return '';
	  }

	  // 2. Instantiate the Drupal JsonWebToken object.
	  // This object implements JsonWebTokenInterface, which the transcoder expects.
	  $jwt = new JsonWebToken();
	  $now = time();
          $expired_hours = $config->get("token_expired_duration");
	  // 3. Set the claims on the JsonWebToken object.
	  $jwt->setClaim('iat', $now);
	  $jwt->setClaim('exp', ($now + 120));


	  // The 'drupal.uuid' claim is set using the array notation for nested claims.
	  $jwt->setClaim(['drupal', 'uuid'], $account->uuid()); 

	  // 4. Get the JWT Transcoder service.
	  /** @var JwtTranscoderInterface $transcoder */
	  $transcoder = \Drupal::service('jwt.transcoder');

	  // 5. Encode the JsonWebToken object.
	  // The transcoder now receives the expected object type.
	  $token_object = $transcoder->encode($jwt);
	  
	  // 6. Cast the token object to a string.
	  $token = (string) $token_object;

	  return $token;
	}

  /**
   * Streams the media's document file as a download.
   *
   * The caller (accessGrant) has already decided that access is allowed.
   */
  public function serveMedia(MediaInterface $media): BinaryFileResponse {
    if (!$media->hasField('field_media_document') || $media->get('field_media_document')->isEmpty()) {
      throw new NotFoundHttpException();
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $media->get('field_media_document')->entity;
    if (!$file || !is_file($file->getFileUri())) {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($file->getFileUri(), 200, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'private, no-store, must-revalidate',
    ], FALSE);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFilename());
    return $response;
  }

  /**
   *  Only work for small file
   **/
  public function serveMediaNotScaled(MediaInterface $media) { 
    $jwt_token = $this->getJWTToken();

    if ($media->hasField('field_media_document') && !$media->get('field_media_document')->isEmpty()) {
       $file = $media->get('field_media_document')->entity;
       if ($file) {
        $file_uri = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
       }
    }
    $curl = curl_init();
    $options = array(
      CURLOPT_URL => $file_uri,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => array(
        'Authorization: Bearer '. $jwt_token
      ),
    );
    //drupal_log(json_encode($options));
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    //drupal_log(json_encode($response));
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($http_code === 200) {
        $filename = basename($file_uri);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($response));
        header('Connection: close');
        echo $response;
        exit;
    }
    else if ($http_code === 403) {
        header('HTTP/1.1 403 Forbidden');
        echo 'serveMedia: Access forbidden';
        exit;
    }
    else {
        // Set the response to 400 Bad Request
        header('HTTP/1.1 400 Bad Request');
        echo 'serveMedia: Invalid request';
        exit;
    }
  }

  /**
   * Query the media based on Node
   */
  public function getMedias($nid) {
    // Load the node
    $node = Node::load($nid);

    if ($node) {
        // Check if the node has the field_islandora_object_media field
        if ($node->hasField('field_islandora_object_media') && !$node->get('field_islandora_object_media')->isEmpty()) {
            // Get the media entity references
            $media_references = $node->get('field_islandora_object_media')->referencedEntities();

            // Iterate through the media references
            foreach ($media_references as $media) {
                if ($media instanceof Media) {
                    //drupal_log($media->id());
                    $media_name = $media->getName();
                    // Check if the media entity has the field_media_use field
                    if ($media->hasField('field_media_use') && !$media->get('field_media_use')->isEmpty()) {
                        // Get the referenced taxonomy terms
                        $terms = $media->get('field_media_use')->referencedEntities();

                        // Iterate through the referenced terms
                        foreach ($terms as $term) {
                            // Check if the term name is "Temporary Downloadable"
                            if ($term->getName() === 'Temporary Downloadable') {
                                return $media;
                            }
                        }
                    } else {
                        return false; 
                    }
                }
            }
        } else {
            return false;
        }
    } else {
        return false;
    }

  }

  /**
   * Serves the requested item's temporary-download file for a valid link.
   *
   * The link is /request-access/{submission_token}/{nodeinfo}/{submitted}.
   * Only the token is trusted. The item and the start of the validity window
   * are read from the stored webform submission, never from the URL:
   * {nodeinfo} must match the submission's source node and {submitted} is
   * ignored (it is kept only so links already mailed out keep their shape).
   */
  public function accessGrant(string $submission_token, string $nodeinfo, $submitted) {
    $config = $this->config('temporary_downloadable_media.settings');
    $valid_hours = (int) ($config->get('token_expired_duration') ?: 24);
    $webform_id = $config->get('webform_id') ?: 'request_access';

    // 1. The token must belong to a submission of the request-access form.
    $submissions = $this->entityTypeManager()
      ->getStorage('webform_submission')
      ->loadByProperties(['token' => $submission_token, 'webform_id' => $webform_id]);
    /** @var \Drupal\webform\WebformSubmissionInterface|false $submission */
    $submission = reset($submissions);
    if (!$submission || $submission->isDraft()) {
      throw new AccessDeniedHttpException();
    }

    // 2. The validity window starts when the form was submitted (server side).
    $created = (int) $submission->getCreatedTime();
    $now = \Drupal::time()->getRequestTime();
    if ($now < $created || $now > $created + $valid_hours * 3600) {
      throw new AccessDeniedHttpException('This link has expired.');
    }

    // 3. The link is only good for the item the form was submitted on.
    $node = $submission->getSourceEntity();
    $parts = explode(', ', $nodeinfo);
    $requested_nid = end($parts);
    if (!$node instanceof NodeInterface || (string) $node->id() !== (string) $requested_nid) {
      throw new AccessDeniedHttpException();
    }

    // 4. The item itself must be visible to the visitor (published, not in a
    // group that hides it). Only the file is unlocked by the link.
    if (!$node->access('view')) {
      throw new AccessDeniedHttpException();
    }

    $media = $this->getMedias($node->id());
    if (!$media) {
      throw new NotFoundHttpException();
    }
    return $this->serveMedia($media);
  }

}
