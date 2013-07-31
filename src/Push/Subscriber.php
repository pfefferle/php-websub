<?php
// a PHP client library for pubsubhubbub
// as defined at http://code.google.com/p/pubsubhubbub/
// written by Josh Fraser | joshfraser.com | josh@eventvue.com
// modified by Matthias Pfefferle | notizblog.org | matthias@pfefferle.org
// Released under Apache License 2.0

namespace Push;

use Exception;

/**
 * a pubsubhubbub subscriber
 *
 * @author Matthias Pfefferle
 */
class Subscriber {
  protected $hub_urls = array();
  protected $topic_url;
  protected $callback_url;
  protected $credentials;
  // accepted values are "async" and "sync"
  protected $verify = "async";
  protected $verify_token;
  protected $lease_seconds;

  /**
   * create a new Subscriber (credentials added for SuperFeedr support)
   *
   * @param string $callback_url
   * @param string $hub_urls
   * @param string $credentials
   * @throws Exception
   */
  public function __construct( $callback_url, $hub_url = null, $credentials = false ) {
    if ($hub_url && !preg_match("|^https?://|i",$hub_url))
      throw new Exception('The specified hub url does not appear to be valid: '.$hub_url);

    if (!isset($callback_url))
      throw new Exception('Please specify a callback');

    $this->hub_urls = array($hub_url);
    $this->callback_url = $callback_url;
    $this->credentials = $credentials;
  }
  
  /**
   * subscribe to updates of the topic url
   *
   * @param string $topic_url
   * @throws Exception
   */
  public function subscribe($topic_url) {
    // check if url is valid
    if ($topic_url && !preg_match("|^https?://|i",$topic_url))
      throw new Exception('The specified topic url does not appear to be valid: '.$topic_url);
    
    return $this->change_subscription("subscribe", $topic_url);
  }
  
  /**
   * unsubscribe from updates of the topic url
   *
   * @param string $topic_url
   * @throws Exception
   */
  public function unsubscribe($topic_url) {
    // check if url is valid
    if ($topic_url && !preg_match("|^https?://|i",$topic_url))
      throw new Exception('The specified topic url does not appear to be valid: '.$topic_url);


    return $this->change_subscription("unsubscribe", $topic_url);
  }

  /**
   * helper function since sub/unsub are handled the same way
   *
   * @param string $mode can be "subscribe" or "unsubscribe"
   * @param string $topic_url the url of the topic
   * @return string|boolean returns the subscribed hub or false
   */
  private function change_subscription($mode, $topic_url) {
    if (!isset($topic_url))
      throw new Exception('Please specify a topic url');

    // lightweight check that we're actually working w/ a valid url
    if (!preg_match("/^https?:\/\//i",$topic_url))
      throw new Exception('The specified topic url does not appear to be valid: '.$topic_url);
    
    if (!$this->hub_urls)
      $this->find_hubs($topic_url);

    if (!$this->hub_urls)
      throw new Exception('This topic doesn\'t reference a hub url');
    
    // set the mode subscribe/unsubscribe
    $post_string = "hub.mode=".$mode;
    $post_string .= "&hub.callback=".urlencode($this->callback_url);
    $post_string .= "&hub.verify=".$this->verify;
    $post_string .= "&hub.verify_token=".$this->verify_token;
    $post_string .= "&hub.lease_seconds=".$this->lease_seconds;

    // append the topic url parameters
    $post_string .= "&hub.topic=".urlencode($topic_url);

    foreach ($this->hub_urls as $hub_url) {
      if (false !== $this->http($hub_url, $post_string)) {
        return $hub_url;
      }
    }
    
    return false;
  }

  /**
   * discover the hub url
   *
   * @param string $topic_url
   * @return string|false
   */
  public function discover_hubs($topic_url) {
    // search the link header first
    $headers = $this->http_header($topic_url);
    
    $result = self::discover_hubs_in_header($headers);
    
    if (isset($result['topic_url'])) {
      $this->topic_url = $result['topic_url'];
    }
    
    if (isset($result['hub_urls'])) {
      $this->hub_urls = $result['hub_urls'];
      
      return $this->hub_urls;
    }
    
    // if no headers were found, try to find
    // some <link />s in the body
    $body = $this->http($topic_url);
    
    $result = self::discover_hubs_in_body($body);

    if (isset($result['topic_url'])) {
      $this->topic_url = $result['topic_url'];
    }
    
    if (isset($result['hub_urls'])) {
      $this->hub_urls = $result['hub_urls'];
      
      return $this->hub_urls;
    }

    return false;
  }
  
  /**
   * discover link header
   *
   * @param string $header the http-header
   * @return array
   */
  public static function discover_hubs_in_header($header) {
    $header = self::parse_header($header);
    
    if (!array_key_exists('Link', $header))
      return false;

    if (is_array($header['Link'])) {
      $link_header = implode($header['Link'], ", ");
    } else {
      $link_header = $header['Link'];
    }
    
    $result = array();
    
    if (preg_match_all('/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?hub\/?[\"\']?/i', $link_header, $match)) {
      $result['hub_urls'] = $match[1];
    }
    if (preg_match('/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?self\/?[\"\']?/i', $link_header, $match)) {
      $result['topic_url'] = $match[1];
    }
    
    return $result;
  }

  /**
   * to be backwards compatible with <= spec 0.3
   *
   * @param string $body html body of the website
   * @return array
   */
  public static function discover_hubs_in_body($body) {
    $result = array();

    // get hub urls
    if(preg_match_all('/<([a-zA-Z^:]+:)?link\s+href=[\"\']([^"\']+)[\"\']\s+rel=[\"\']hub[\"\']\s*\/?>/i', $body, $match)) {
      $result['hub_urls'] = $match[2];
    }
    if (preg_match_all('/<([a-zA-Z^:]+:)?link\s+rel=[\"\']hub[\"\']\s+href=[\"\']([^"\']+)[\"\']\s*\/?>/i', $body, $match)) {
      $result['hub_urls'] = array_merge($result['hub_urls'], $match[2]);
    }
    
    // get self url
    if(preg_match('/<([a-zA-Z^:]+:)?link\s+href=[\"\']([^"\']+)[\"\']\s+rel=[\"\']self[\"\']\s*\/?>/i', $body, $match)) {
      $result['topic_url'] = $match[2];
    }
    if (preg_match('/<([a-zA-Z^:]+:)?link\s+rel=[\"\']self[\"\']\s+href=[\"\']([^"\']+)[\"\']\s*\/?>/i', $body, $match)) {
      $result['topic_url'] = $match[2];
    }
    
    return $result;
  }
  
  // default http function that uses curl to post to the hub endpoint
  private function http($url, $post_string = null) {

    // add any additional curl options here
    $options = array(CURLOPT_URL => $url,
                     CURLOPT_USERAGENT => "PubSubHubbub-Subscriber-PHP/1.0",
                     CURLOPT_RETURNTRANSFER => true,
                     CURLOPT_FOLLOWLOCATION => true);

    if ($post_string) {
      $options[CURLOPT_POST] = true;
      $options[CURLOPT_POSTFIELDS] = $post_string;
    }

    if ($this->credentials)
      $options[CURLOPT_USERPWD] = $this->credentials;

    $ch = curl_init();
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    
    $info = curl_getinfo($ch);
    
    // all good -- anything in the 200 range
    if (substr($info['http_code'],0,1) == "2") {
      return $response;
    }

    return false;
  }
  
  private function http_header($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "PubSubHubbub-Subscriber-PHP/1.0");
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    
    $response = curl_exec($ch);
    
    $info = curl_getinfo($ch);
    
    return $response;
  }
  
  /**
   * convert header into an array
   *
   * @param string $header the HTTP-Header
   * @return array
   */
  private static function parse_header($header) {
    $ret_val = array();
    $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
    foreach( $fields as $field ) {
      if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
        $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
        if( isset($ret_val[$match[1]]) ) {
          if (!is_array($ret_val[$match[1]])) {
            $ret_val[$match[1]] = array($ret_val[$match[1]]);
          }
          $ret_val[$match[1]][] = $match[2];
        } else {
          $ret_val[$match[1]] = trim($match[2]);
        }
      }
    }
    
    return $ret_val;
  }
  
  // setter
  public function set_topic_url($topic_url) {
    $this->topic_url = $topic_url;
  }
  
  // getter
  public function get_topic_url() {
    return $this->topic_url;
  }
  
  public function get_hub_urls() {
    return $this->hub_urls;
  }
  
  public function get_last_response() {
    return $this->last_response;
  }
  
  public function get_last_response_header() {
    return $this->last_response_header;
  }
}