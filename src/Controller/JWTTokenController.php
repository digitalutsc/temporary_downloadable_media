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

class JWTTokenController extends ControllerBase {

  /**
   * Generates a JWT token for a specific service account user ID without
   * programmatically logging in or out.
   */
  public function getJWTTokenReponse(): JsonResponse {
    $config = \Drupal::config('temporary_downloadable_media.settings');

    // 1. Define the service account User ID.
    $service_account_uid = $config->get("user_select"); // Replace with your actual service account user ID

    // 2. Load the user entity directly.
    $account = User::load($service_account_uid);

    if (!$account) {
      // Handle the case where the user doesn't exist.
      return new JsonResponse(['error' => 'Service account not found.'], 404);
    }

    // 3. Get the necessary services.
    /** @var \Drupal\jwt\Transcoder\JwtTranscoderInterface $transcoder */
    $transcoder = \Drupal::service('jwt.transcoder');

    // 4. Create the claims array.
    $expired_hours = $config->get("token_expired_duration");
    $now = time();
    $claims = [
      // Standard claims: Issued At (iat) and Expiration (exp).
      'iat' => $now,
      'exp' => $now + 120, // Token valid for 120 seconds (2 minutes).

      // Drupal-specific claim: User UUID. This is essential for authentication.
      'drupal.uuid' => $account->uuid(),
    ];

    // 5. Encode the claims into a token object and IMMEDIATELY cast it to a string.
    $token_object = $transcoder->encode($claims);

    // Cast the token object to a string. This avoids the need for the specific 'use' statement.
    $token_string = (string) $token_object; 

    // 6. Return the token in a JSON response.
    $data = [
      'jwt-token' => $token_string,
    ];
    return new JsonResponse($data);
  }

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
	 * Can Handle big file size
	**/
	public function serveMedia(MediaInterface $media) {
	  $jwt_token = $this->getJWTToken(); // keep your auth logic
	
	  if (!$media->hasField('field_media_document') || $media->get('field_media_document')->isEmpty()) {
		throw new NotFoundHttpException();
	  }
	
	  /** @var \Drupal\file\FileInterface $file */
	  $file = $media->get('field_media_document')->entity;
	  if (!$file) {
		throw new NotFoundHttpException();
	  }
	
	  // Optional: do your own access check with JWT here
	  // e.g. if (!$this->jwtAllowsAccess($jwt_token, $file)) { throw new AccessDeniedHttpException(); }
	
	  $uri = $file->getFileUri();           // 'private://something.pdf'
	  $filename = $file->getFilename();
	  $mime = $file->getMimeType() ?: 'application/octet-stream';
	
	  // Important headers
	  header('Content-Type: ' . $mime);
	  header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
	  header('Content-Length: ' . $file->getSize());
	  header('Cache-Control: private, no-transform, no-store, must-revalidate');
	  header('Expires: 0');
	
	  // Stream without loading whole file
	  if ($stream = fopen($uri, 'rb')) {
		fpassthru($stream);
		fclose($stream);
	  } else {
		// Fallback or error
		header('HTTP/1.1 500 Internal Server Error');
		echo 'Cannot open file stream.';
	  }
	
	  exit;
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
   * Check access control and grand temporary access
   */
  public function accessGrant(String $submission_token, String $nodeinfo, $submitted) {
    $config = \Drupal::config('temporary_downloadable_media.settings');
    $expired_hours = $config->get("token_expired_duration");

    $parts = explode(", ", $nodeinfo);
    $nid = $parts[count($parts) - 1];
    
    // Load the webform submission by token
    $submission = \Drupal::entityTypeManager()
        ->getStorage('webform_submission')
        ->loadByProperties(['token' => $submission_token]);

    // Since loadByProperties returns an array, get the first element
    $submission = reset($submission);

    // validate the link is still within 1 day 
    $end_time = $submitted + ($expired_hours * 60 * 60);

    $current_time = time();
    if ($submission && ($current_time >= $submitted && $current_time <= $end_time)) {
      $media = $this->getMedias($nid);
      if ($media) {
        $this->serveMedia($media);
      }
      else {
        header('HTTP/1.1 404 Not found');
	      header("Location: https://ark.digital.utsc.utoronto.ca/404.php");
        exit;
      }
    }   
    else {
      header('HTTP/1.1 403 Forbidden');
      header("Location: https://ark.digital.utsc.utoronto.ca/403.php");
      exit;
    }
  }
}
