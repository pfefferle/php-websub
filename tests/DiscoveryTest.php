<?php
class DiscoveryTest extends PHPUnit_Framework_TestCase {
  
  function testParseHeaderSingleHub() {
    $headers = "HTTP/1.1 200 OK\r
Server: nginx/1.0.14\r
Date: Thu, 04 Jul 2013 15:56:21 GMT\r
Content-Type: text/html; charset=UTF-8\r
Connection: keep-alive\r
Link: <http://pubsubhubbub.appspot.com>; rel=\"hub\", <http://notizblog.org/>; rel=\"self\"";

    $result = Push\Subscriber::discover_hubs_in_header($headers);
    
    $this->assertEquals("http://notizblog.org/", $result['topic_url']);
    $this->assertTrue(in_array("http://pubsubhubbub.appspot.com", $result['hub_urls']));
  }
  
  function testParseHeaderMultipleHubs() {
    $headers = "HTTP/1.1 200 OK\r
Server: nginx/1.0.14\r
Date: Thu, 04 Jul 2013 15:56:21 GMT\r
Content-Type: text/html; charset=UTF-8\r
Connection: keep-alive\r
Link: <http://pubsubhubbub.appspot.com>; rel=\"hub\", <http://pubsubhubbub.superfeedr.com>; rel=\"hub\", <http://notizblog.org/>; rel=\"self\"";

    $result = Push\Subscriber::discover_hubs_in_header($headers);
    
    $this->assertEquals("http://notizblog.org/", $result['topic_url']);
    $this->assertTrue(in_array("http://pubsubhubbub.appspot.com", $result['hub_urls']));
    $this->assertTrue(in_array("http://pubsubhubbub.superfeedr.com", $result['hub_urls']));
  }
  
  function testParseHeaderMultipleHubsMultibleLinkHeader() {
    $headers = "HTTP/1.1 200 OK\r
Server: nginx/1.0.14\r
Date: Thu, 04 Jul 2013 15:56:21 GMT\r
Content-Type: text/html; charset=UTF-8\r
Connection: keep-alive\r
Link: <http://pubsubhubbub.appspot.com>; rel=\"hub\"\r
Link: <http://pubsubhubbub.superfeedr.com>; rel=\"hub\"\r
Link: <http://notizblog.org/>; rel=\"self\"";

    $result = Push\Subscriber::discover_hubs_in_header($headers);
    
    $this->assertEquals("http://notizblog.org/", $result['topic_url']);
    $this->assertTrue(in_array("http://pubsubhubbub.appspot.com", $result['hub_urls']));
    $this->assertTrue(in_array("http://pubsubhubbub.superfeedr.com", $result['hub_urls']));
  }    
  
  function testParseBodySingleHub() {
    $body = '<atom:link rel="hub" href="http://pubsubhubbub.appspot.com"> 
     <link href="http://pubsubhubbub.superfeedr.com" rel="hub" > <link href="http://notizblog.org/" rel="self" />';

    $result = Push\Subscriber::discover_hubs_in_body($body);
    
    $this->assertEquals("http://notizblog.org/", $result['topic_url']);
    $this->assertTrue(in_array("http://pubsubhubbub.appspot.com", $result['hub_urls']));
    $this->assertTrue(in_array("http://pubsubhubbub.superfeedr.com", $result['hub_urls']));
  }
}