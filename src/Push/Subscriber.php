<?php
// a PHP client library for pubsubhubbub
// as defined at http://code.google.com/p/pubsubhubbub/
// written by Josh Fraser | joshfraser.com | josh@eventvue.com
// modified by Matthias Pfefferle | notizblog.org | matthias@pfefferle.org
// Released under Apache License 2.0

namespace Push;

/**
 * a pubsubhubbub subscriber
 *
 * @author Matthias Pfefferle
 */
class Subscriber
{
    protected $hubUrls = array();
    protected $topicUrl;
    protected $callbackUrl;
    protected $credentials;
    // accepted values are "async" and "sync"
    protected $verify = 'async';
    protected $verify_token;
    protected $lease_seconds;

    /**
     * create a new Subscriber (credentials added for SuperFeedr support)
     *
     * @param string $callbackUrl
     * @param string $hubUrls
     * @param string $credentials
     * @throws \Exception
     */
    public function __construct($callbackUrl, $hubUrl = null, $credentials = false)
    {
        if ($hubUrl && !preg_match('|^https?://|i', $hubUrl)) {
            throw new \Exception('The specified hub url does not appear to be valid: ' . $hubUrl);
        }

        if (!isset($callbackUrl)) {
            throw new \Exception('Please specify a callback');
        }

        $this->hubUrls = array($hubUrl);
        $this->callback_url = $callbackUrl;
        $this->credentials = $credentials;
    }

    /**
     * subscribe to updates of the topic url
     *
     * @param string $topicUrl
     * @throws \Exception
     */
    public function subscribe($topicUrl)
    {
        // check if url is valid
        if ($topicUrl && !preg_match('|^https?://|i', $topicUrl)) {
            throw new \Exception('The specified topic url does not appear to be valid: ' . $topicUrl);
        }

        return $this->changeSubscription('subscribe', $topicUrl);
    }

    /**
     * unsubscribe from updates of the topic url
     *
     * @param string $topicUrl
     * @throws \Exception
     */
    public function unsubscribe($topicUrl)
    {
        // check if url is valid
        if ($topicUrl && !preg_match('|^https?://|i', $topicUrl)) {
            throw new \Exception('The specified topic url does not appear to be valid: ' . $topicUrl);
        }

        return $this->changeSubscription('unsubscribe', $topicUrl);
    }

    /**
     * helper function since sub/unsub are handled the same way
     *
     * @param string $mode can be "subscribe" or "unsubscribe"
     * @param string $topicUrl the url of the topic
     * @return string|boolean returns the subscribed hub or false
     */
    private function changeSubscription($mode, $topicUrl)
    {
        if (!isset($topicUrl)) {
            throw new \Exception('Please specify a topic url');
        }

        // lightweight check that we're actually working w/ a valid url
        if (!preg_match('/^https?:\/\//i', $topicUrl)) {
            throw new \Exception('The specified topic url does not appear to be valid: '.$topicUrl);
        }

        if (!$this->hubUrls) {
            $this->find_hubs($topicUrl);
        }

        if (!$this->hubUrls) {
            throw new \Exception('This topic doesn\'t reference a hub url');
        }

        // set the mode subscribe/unsubscribe
        $postString = 'hub.mode=' . $mode;
        $postString .= '&hub.callback=' . urlencode($this->callback_url);
        $postString .= '&hub.verify=' . $this->verify;
        $postString .= '&hub.verify_token=' . $this->verify_token;
        $postString .= '&hub.lease_seconds=' . $this->lease_seconds;

        // append the topic url parameters
        $postString .= '&hub.topic=' . urlencode($topicUrl);

        foreach ($this->hubUrls as $hubUrl) {
            if (false !== $this->http($hubUrl, $postString)) {
                return $hubUrl;
            }
        }

        return false;
    }

    /**
     * discover the hub url
     *
     * @param string $topicUrl
     * @return string|false
     */
    public function discoverHubs($topicUrl)
    {
        // search the link header first
        $headers = $this->httpHeader($topicUrl);

        $result = self::discoverHubsInHeader($headers);

        if (isset($result['topic_url'])) {
            $this->topicUrl = $result['topic_url'];
        }

        if (isset($result['hub_urls'])) {
            $this->hubUrls = $result['hub_urls'];

            return $this->hubUrls;
        }

        // if no headers were found, try to find
        // some <link />s in the body
        $body = $this->http($topicUrl);

        $result = self::discoverHubsInBody($body);

        if (isset($result['topic_url'])) {
            $this->topicUrl = $result['topic_url'];
        }

        if (isset($result['hub_urls'])) {
            $this->hubUrls = $result['hub_urls'];

            return $this->hubUrls;
        }

        return false;
    }

    /**
     * discover link header
     *
     * @param string $header the http-header
     * @return array
     */
    public static function discoverHubsInHeader($header)
    {
        $header = self::parseHeader($header);

        if (!array_key_exists('Link', $header)) {
            return false;
        }

        if (is_array($header['Link'])) {
            $linkHeader = implode($header['Link'], ', ');
        } else {
            $linkHeader = $header['Link'];
        }

        $result = array();

        if (preg_match_all('/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?hub\/?[\"\']?/i', $linkHeader, $match)) {
            $result['hub_urls'] = $match[1];
        }
        if (preg_match('/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?self\/?[\"\']?/i', $linkHeader, $match)) {
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
    public static function discoverHubsInBody($body)
    {
        $result = array();

        // get hub urls
        if (preg_match_all('/<([a-zA-Z^:]+:)?link\s+href=[\"\']([^"\']+)[\"\']\s+rel=[\"\']hub[\"\']\s*\/?>/i', $body, $match)) {
            $result['hub_urls'] = $match[2];
        }

        if (preg_match_all('/<([a-zA-Z^:]+:)?link\s+rel=[\"\']hub[\"\']\s+href=[\"\']([^"\']+)[\"\']\s*\/?>/i', $body, $match)) {
            $result['hub_urls'] = array_merge($result['hub_urls'], $match[2]);
        }

        // get self url
        if (preg_match('/<([a-zA-Z^:]+:)?link\s+href=[\"\']([^"\']+)[\"\']\s+rel=[\"\']self[\"\']\s*\/?>/i', $body, $match)) {
            $result['topic_url'] = $match[2];
        }

        if (preg_match('/<([a-zA-Z^:]+:)?link\s+rel=[\"\']self[\"\']\s+href=[\"\']([^"\']+)[\"\']\s*\/?>/i', $body, $match)) {
            $result['topic_url'] = $match[2];
        }

        return $result;
    }

    // default http function that uses curl to post to the hub endpoint
    private function http($url, $postString = null)
    {
        // add any additional curl options here
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'PubSubHubbub-Subscriber-PHP/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true
        );

        if ($postString) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postString;
        }

        if ($this->credentials) {
            $options[CURLOPT_USERPWD] = $this->credentials;
        }

        $options[CURLOPT_USERPWD] = $this->credentials;

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        $info = curl_getinfo($ch);

        // all good -- anything in the 200 range
        if (substr($info['http_code'], 0, 1) == '2') {
            return $response;
        }

        return false;
    }

    private function httpHeader($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PubSubHubbub-Subscriber-PHP/1.0');
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
    private static function parseHeader($header)
    {
        $ret_val = array();
        $fields = explode(PHP_EOL, preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach ($fields as $field) {
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
                if (isset($ret_val[$match[1]])) {
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
    public function setTopicUrl($topicUrl)
    {
        $this->topicUrl = $topicUrl;
    }

    // getter
    public function getTopicUrl()
    {
        return $this->topicUrl;
    }

    public function getHubUrls()
    {
        return $this->hubUrls;
    }

    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    public function getLastResponseHeader()
    {
        return $this->lastResponseHeader;
    }
}
