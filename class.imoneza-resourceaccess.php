<?php

/**
 * Class iMonezaResourceAccess
 *
 * Resource Access API implementation. Has methods for determining whether
 * a user has access to a given resource.
 */
class iMonezaResourceAccess extends iMonezaApi
{

  protected $cookieExpiration;

  /**
   * Constructor
   */
  public function __construct() {
    $options = variable_get('imoneza_options');
    parent::__construct($options,
      $options['imoneza_ra_api_key_access'],
      $options['imoneza_ra_api_key_secret'],
      IMONEZA__RA_API_URL);

    // 14 days
    $this->cookieExpiration = 60 * 60 * 24 * 14;
  }

  /**
   * Either allows access to a resource or forwards to iMoneza for access
   * control.
   * @param $external_key
   * @param $resource_url
   * @throws Exception
   */
  public function getResourceAccess($external_key, $resource_url) {
    try {
      $user_token = '';

      // Check for excluded user agents.
      if (isset($this->options['access_control_excluded_user_agents'])
        &&
        $this->options['access_control_excluded_user_agents'] != ''
      ) {
        foreach (explode("\n",
          $this->options['access_control_excluded_user_agents'])
                 as $user_agent) {

          if ($user_agent == $_SERVER['HTTP_USER_AGENT'])
            return;
        }
      }

      if (isset($_REQUEST['iMonezaTUT'])) {
        // The user just authenticated at iMoneza, and
        // iMoneza is sending the temporary user token back to us.
        $temporary_user_token = $_REQUEST['iMonezaTUT'];
        $resource_access_data =
          $this->getResourceAccessDataFromTemporaryUserToken(
            $external_key, $resource_url, $temporary_user_token);
      } else {
        if (isset($_COOKIE['iMonezaUT'])) {
          $user_token = $_COOKIE['iMonezaUT'];
        };
        $resource_access_data =
          $this->getResourceAccessDataFromExternalKey(
            $external_key, $resource_url, $user_token);
      }
      $user_token = $resource_access_data['UserToken'];
      setcookie('iMonezaUT', $user_token, time() + $this->cookieExpiration);

      if ($resource_access_data['AccessActionURL']
        && strlen($resource_access_data['AccessActionURL']) > 0
      ) {
        $url = $resource_access_data['AccessActionURL'];
        $url = $url . '&OriginalURL=' . rawurlencode($resource_url);
        drupal_goto($url);
        exit;
      }
    } catch (Exception $e) {
      // Default to open access if there's some sort of exception.
      error_log(print_r($e, TRUE));
      if (IMONEZA__DEBUG)
        throw $e;
    }
  }

  /**
   * Returns access decision from iMoneza based on external key.
   * @param $external_key
   * @param $resource_url
   * @param $user_token
   * @return mixed
   * @throws Exception
   */
  public function getResourceAccessDataFromExternalKey(
    $external_key, $resource_url, $user_token) {
    $request = new IMonezaRestfulRequest($this);
    $request->method = 'GET';
    $request->uri = '/api/Resource/' . $this->accessKey . '/' . $external_key;
    $request->getParameters['ResourceURL'] = $resource_url;
    $request->getParameters['UserToken'] = $user_token;

    $response = $request->getResponse();

    if ($response->code == '404') {
      throw new Exception('An error occurred with the Resource Access '
        . 'API key. Make sure you have valid Access Management API keys'
        . ' set in the iMoneza plugin settings.');
    } else {
      return json_decode($response->data, TRUE);
    }
  }

  /**
   * Returns data from iMoneza based on tempoary token.
   * @param $external_key
   * @param $resource_url
   * @param $temporary_user_token
   * @return mixed
   * @throws Exception
   */
  public function getResourceAccessDataFromTemporaryUserToken(
    $external_key, $resource_url, $temporary_user_token) {
    $request = new IMonezaRestfulRequest($this);
    $request->method = 'GET';
    $request->uri = '/api/TemporaryUserToken/'
      . $this->accessKey . '/' . $temporary_user_token;
    $request->getParameters['ResourceKey'] = $external_key;
    $request->getParameters['ResourceURL'] = $resource_url;

    $response = $request->getResponse();

    if ($response->code == '404') {
      throw new Exception('An error occurred with the Resource Access '
        . 'API key. Make sure you have valid Access Management API '
        . 'keys set in the iMoneza plugin settings.');
    } else {
      return json_decode($response->data, TRUE);
    }
  }
}
