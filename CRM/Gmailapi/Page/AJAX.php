<?php

class CRM_Gmailapi_Page_AJAX {
  static function init() {
    if (!self::authenticate()) {
      $output = array('is_error' => 1, 'message' => 'Could not authenticate with given token (perhaps the token has expired?)');
      CRM_Utils_JSON::output($output);
      CRM_Utils_System::civiExit();
    }
  }
  static function authenticate() {
    $output = array();
    CRM_Core_Error::debug_var('$_REQUEST', $_REQUEST);

    // $result = self::authorizationCodeRequest('token');
    // //$this->assertEqual($result->code, 302, 'The implicit flow request completed successfully');
    // $redirect_url_parts = explode('#', $result->redirect_url);
    // $response = drupal_get_query_array($redirect_url_parts[1]);
    // //$this->assertTokenResponse($response, FALSE);

    $verification_url = url('oauth2/tokens/' . $_REQUEST['oauth_token'], array('absolute' => TRUE));//, 'query' => array('scope' => 'gmail_extension')));
    CRM_Core_Error::debug_var('$verification_url', $verification_url);
    $result = self::httpRequest($verification_url);
    CRM_Core_Error::debug_var('$result', $result);
    $verification_response = json_decode($result->data);
    CRM_Core_Error::debug_var('$verification_response', $verification_response);

    if ($result->code == '200' && !empty($verification_response->user_id) && ($verification_response->access_token == $_REQUEST['oauth_token'])) {
      // consider logged IN
      return TRUE;

      // DS: to programmatically force user login. It also initializes civi
      // $form_state = array();
      // $form_state['uid'] = $verification_response->user_id;
      // CRM_Core_Error::debug_var('$form_state', $form_state);
      // user_login_submit(array(), $form_state);
    }
    //CRM_Core_Error::fatal('Not Authorized.');
    return FALSE;
  }

  /**
   * Performs a drupal_http_request() with additional parameters.
   *
   * Passes along all cookies. This ensures that the test user has access
   * to the oauth2 endpoints.
   *
   * @param $url
   *   $url: A string containing a fully qualified URI.
   * @param $options
   *   The options array passed along to drupal_http_request().
   *
   * @return
   *   The result object as returned by drupal_http_request().
   */
  static function httpRequest($url, array $options = array()) {
    // Forward cookies.
    $cookie_string = '';

    $options['headers']['Cookie'] = $cookie_string;

    // Set other general options.
    $options += array(
      'max_redirects' => 0,
    );

    return drupal_http_request($url, $options);
  }

  // Function that retrieves a list of CiviCRM groups
  static function getGroups(){
    self::init();

    $result = array(
      'is_error' => 0,
      'message' => '',
      'groups' => array(),
    );
    try {
      $resultGroups = civicrm_api3('Group', 'get', array(
        'sequential' => 1,
        'is_active' => 1,
        'is_hidden' => 0,
      ));
      if (!empty($resultGroups['values'])) {
        foreach ($resultGroups['values'] as $zzz => $groupDetails) {
          $grp = array();
          $grp['id'] = $groupDetails['id'];
          $grp['title'] = $groupDetails['title'];
          array_push($result['groups'], $grp);
        }
      }
    } catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message($error);
      $result['is_error'] = 1;
      $result['message'] = "Error calling CiviCRM Group.get api: $error";
    }

    self::returnApiResult($result);
  }

  // Function to call outlook API
  static function callOutlookApi(){
    self::init();

    $result = array();
    $email = '';
    $processAttachment= FALSE;

    // function to validate token
    // $isValidUser = self::authenticate();
    // FIXME ::: setting validuser to true & assign source contact for now
    $isValidUser = TRUE;
    // FIX ME:
    // get source contacts api key and assign it into the request
    // getApiKey();

    if ($isValidUser) {

      $params = array();
      // if email address found, assign params to call activty API
      if (isset($_REQUEST['email'])) {
        // extract valid email address from the email string : Eg: Gopi Krishna <gopi@vedaconsulting.co.uk>
        $emailParts = self::extractValidEmail($_REQUEST['email'], '<', '>');
        $params['email'] = $email = $emailParts['email'];
        if (array_key_exists('name', $emailParts)) {
          $params['name'] = $emailParts['name'];
        }
      }
      if (isset($_REQUEST['name'])) {
        $params['name'] = $_REQUEST['name'];
      }
      if (isset($_REQUEST['date_time'])) {
        $params['date_time'] = $_REQUEST['date_time'];
      }
      if (isset($_REQUEST['subject'])) {
        $params['subject'] = $_REQUEST['subject'];
      }
      if (isset($_REQUEST['email_body'])) {
        $params['email_body'] = $_REQUEST['email_body'];
      }
      if (isset($_REQUEST['group'])) {
        $params['group'] = $_REQUEST['group'];
      }
      if (isset($_REQUEST['ot_target_contact_id'])) {
        $params['ot_target_contact_id'] = $_REQUEST['ot_target_contact_id'];
      }
      // if email attachment not empty, set process attchment true
      if (isset($_REQUEST['email_attachment']) && !empty($_REQUEST['email_attachment'])) {
        $processAttachment = TRUE;
        $params['attachment'] = $_REQUEST['email_attachment'];
      }

      // FIX ME : using email activity type id for now
      $params['activity_type_id'] = 3;

    } else{
      $result['is_error'] = 1;
      $result['message'] = 'Please authenticate your Gmail with Civi';
      // return API result with error message, if not a valid user
      self::returnApiResult($result);
    }

    // if valid email address found, continue with log activity API call
    if (!empty($email) && !empty($params) ) {
      CRM_Core_Error::debug_var('Params received from ajax', $params);
      // call createactivity API in outlook API extension
      $result = civicrm_api3('CiviOutlook', 'createactivity', $params);

      // Process Attachment, if activity created and attachments found in the request
      if (isset($result['id']) && !empty($result['id']) && $processAttachment){
        $attachments = $params['attachment'];
        if(!empty($attachments)){

          // TO DO : Process attachments
          foreach ($attachments as $key => $value) {
            # code...
          }
          CRM_Core_Error::debug_var('attachment in final activity result ', $attachments);
        }
      }

      // return API response received from outlook API
      self::returnApiResult($result);

    } else{
      $result['is_error'] = 1;
      $result['message'] = 'No Valid email address found to record activity';
      // return API result with error message, if no valid email found
      self::returnApiResult($result);
    }

  }

  // Function to return the API results back to extension
  static function returnApiResult($result){
    CRM_Core_Error::debug_var('API $result', $result);
    CRM_Utils_JSON::output($result);
    CRM_Utils_System::civiExit();
  }

  static function fileAttachment() {
    self::init();

    $params = array();
    $params['activityID'] = CRM_Utils_Type::escape($_REQUEST['activityID'], 'Integer');

    //FIXME: attachment outlook api needs to improved and used here as well
    // A: pass decoded data to be written as new file 
    // B: mimetype should be taken from $_FILE 
    // C: not sure about new file name inlcuding extension name
    //$result = civicrm_api3('CiviOutlook', 'processattachments', $params);
  
    //Process the below only if there is any attachment found
    if ($params['activityID'] && CRM_Utils_Array::value("name", $_FILES['file'])) {
      $config = CRM_Core_Config::singleton();
      $directoryName = $config->customFileUploadDir;
      CRM_Utils_File::createDir($directoryName);

      $tmpName = $_FILES['file']['tmp_name'];

      $img = file_get_contents($tmpName);
      $img = str_replace('_', '/', $img);
      $img = str_replace('-', '+', $img);
      $data = base64_decode($img);

      $name = str_replace(' ', '_', $_FILES['file']['name']);
      //Replace any spaces in name with underscore

      $fileExtension = new SplFileInfo($name);
      if ($fileExtension->getExtension()) {
        $explodeName = explode(".".$fileExtension->getExtension(), $name);
        $name = $explodeName[0]."_".md5($name).".".$fileExtension->getExtension();
      }

      $_FILES['file']['uri'] = $name;
      file_put_contents("$directoryName$name", $data);
      CRM_Core_Error::debug_log_message("For Activity ID: {$params['activityID']} attachment $directoryName$name written from $tmpName > {$_FILES['file']['name']}");
      //move_uploaded_file($tmpName, "$directoryName$name");

      foreach ($_FILES['file'] as $key => $value) {
        $params[$key] = $value;
      }
      // as mime_type as type in $_FILE
      $params['mime_type'] = $params['type'];

      $result = civicrm_api3('File', 'create', $params);
      if (CRM_Utils_Array::value('id', $result)) {
        if(CRM_Utils_Array::value('activityID', $params)) {
          $lastActivityID = $params['activityID'];
        }

        $entityFileDAO = new CRM_Core_DAO_EntityFile();
        $entityFileDAO->entity_table = 'civicrm_activity';
        $entityFileDAO->entity_id = $lastActivityID;
        $entityFileDAO->file_id = $result['id'];
        $entityFileDAO->save();
      }
    }

    CRM_Utils_JSON::output($result);
    CRM_Utils_System::civiExit();
  }

  // Function to to extract valid email addres from email string
  static function extractValidEmail($string, $start, $end){
    $pos = stripos($string, $start);
    // return the actual email string, if '<' not found in the email string
    if ($pos == '') {
      return array('email' => $string);
    }
    $name = substr($string, 0, $pos);
    $str = substr($string, $pos);
    $str_two = substr($str, strlen($start));
    $second_pos = stripos($str_two, $end);
    $str_three = substr($str_two, 0, $second_pos);
    $unit = trim($str_three); // remove whitespaces
    return array('name' => $name, 'email' => $unit);
  }


}
