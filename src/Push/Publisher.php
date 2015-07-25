<?php
// a PHP client library for pubsubhubbub
// as defined at http://code.google.com/p/pubsubhubbub/
// written by Josh Fraser | joshfraser.com | josh@eventvue.com
// modified by Matthias Pfefferle | notizblog.org | matthias@pfefferle.org
// Released under Apache License 2.0

namespace Push;

/**
 * a pubsubhubbub publisher
 *
 * @author Matthias Pfefferle
 */
class Publisher
{
    protected $hubUrl;
    protected $lastResponse;

    // create a new Publisher
    public function __construct($hubUrl)
    {
        if (!isset($hubUrl)) {
            throw new \Exception('Please specify a hub url');
        }

        if (!preg_match('|^https?://|i', $hubUrl)) {
            throw new \Exception('The specified hub url does not appear to be valid: ' . $hubUrl);
        }

        $this->hubUrl = $hubUrl;
    }

    // accepts either a single url or an array of urls
    public function publishUpdate($topicUrls)
    {
        if (!isset($topicUrls)) {
            throw new \Exception('Please specify a topic url');
        }

        // check that we're working with an array
        if (!is_array($topicUrls)) {
            $topicUrls = array($topicUrls);
        }

        // set the mode to publish
        $postString = 'hub.mode=publish';
        // loop through each topic url
        foreach ($topicUrls as $topicUrl) {
            // lightweight check that we're actually working w/ a valid url
            if (!preg_match('|^https?://|i', $topicUrl)) {
                throw new \Exception('The specified topic url does not appear to be valid: ' . $topicUrl);
            }

            // append the topic url parameters
            $postString .= '&hub.url=' . urlencode($topicUrl);
        }

        return $this->httpPost($this->hubUrl, $postString);
    }

    // returns any error message from the latest request
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    // default http function that uses curl to post to the hub endpoint
    private function httpPost($url, $postString)
    {
        // add any additional curl options here
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postString,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'PubSubHubbub-Publisher-PHP/1.0'
        );

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $this->lastResponse = $response;
        $info = curl_getinfo($ch);

        curl_close($ch);

        // all good
        if ($info['http_code'] == 204) {
            return true;
        }

        return false;
    }
}
