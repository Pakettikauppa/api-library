<?php

namespace Pakettikauppa;

class Client
{
    private $configs            = null;

    private $api_key            = null;
    private $secret             = null;
    private $base_uri           = null;
    private $user_agent         = 'pk-client-lib/0.3';
    private $comment            = null;
    private $response           = null;

    private $use_posti_auth     = null;
    private $posti_auth_url     = null;
    private $access_token       = null;

    /**
     * Client constructor.
     *
     *
     * @param array $params
     * @throws \Exception
     */
    public function __construct(array $configs = null, $use_config = null)
    {
        $this->configs = $configs;

        if( (isset($configs['test_mode']) and $configs['test_mode'] === true) or empty($configs))
        {

            $this->api_key      = '00000000-0000-0000-0000-000000000000';
            $this->secret       = '1234567890ABCDEF';
            $this->base_uri     = 'https://apitest.pakettikauppa.fi';
        } 
        else 
        {

            if(isset($configs['api_key'])) {
                $this->api_key  = $configs['api_key'];
            } 

            if(isset($configs['secret'])) {
                $this->secret   = $configs['secret'];
            }

            if(isset($configs['base_uri'])) {
                $this->base_uri = $configs['base_uri'];
            } else {
                $this->base_uri = 'https://api.pakettikauppa.fi';
            }
        }

        if($use_config and isset($configs[$use_config])) 
        {
            if(isset($this->configs[$use_config])) 
            {
                foreach ($this->configs[$use_config] as $key => $value) {
                    if(property_exists($this, $key)) {
                        $this->{$key} = $value;
                    }
                }
            }
        }
    }

    public function getToken()
    {
        if(empty($this->posti_auth_url)) {
            $this->posti_auth_url = 'https://oauth.barium.posti.com';
        }

        return json_decode($this->getPostiToken($this->posti_auth_url ."/oauth/token?grant_type=client_credentials", $this->api_key, $this->secret));
    }

    public function setAccessToken($token)
    {
        $this->access_token = $token;
    }

    /**
     * Sets comment for the request. You can set there information for Pakettikauppa. Like
     * "Generated from Foobar platform"
     *
     * @param string $comment
     */
    public function setComment($comment) {
        $this->comment = $comment;
    }
    /**
     * Posts shipment data to Pakettikauppa, if request was successful
     * sets $reference and $tracking_code params to given shipment.
     *
     * @param Shipment $shipment
     * @return bool
     * @throws \Exception
     */
    public function createTrackingCode(Shipment &$shipment)
    {
        $id             = str_replace('.', '', microtime(true));
        $shipment_xml   = $shipment->asSimpleXml();

        $shipment_xml->{"ROUTING"}->{"Routing.Account"}     = $this->api_key;
        $shipment_xml->{"ROUTING"}->{"Routing.Id"}          = $id;

        if($this->use_posti_auth === true) {
            if(empty($this->token)) {
                $this->access_token = $this->getToken()->access_token;
            }

            $shipment_xml->{"ROUTING"}->{"Routing.Token"}       = $this->access_token;
        } else {
            $shipment_xml->{"ROUTING"}->{"Routing.Version"}     = 2;
            $shipment_xml->{"ROUTING"}->{"Routing.Key"}         = hash_hmac('sha256',"{$this->api_key}{$id}", $this->secret);
        }

        if($this->comment != null) {
            $shipment_xml->{"ROUTING"}->{"Routing.Comment"} = $this->comment;
        }

        $response = $this->doPost('/prinetti/create-shipment', null, $shipment_xml->asXML());

        $response_xml = @simplexml_load_string($response);

        if(!$response_xml) {
            throw new \Exception("Failed to load response xml");
        }

        $this->response = $response_xml;

        if($response_xml->{'response.status'} != 0) {
            throw new \Exception("Error: {$response_xml->{'response.status'}}, {$response_xml->{'response.message'}}");
        }

        $shipment->setReference($response_xml->{'response.reference'});
        $shipment->setTrackingCode($response_xml->{'response.trackingcode'});

        return true;
    }

    /**
     * Returns latest response as XML
     * 
     * @return \SimpleXMLElement
     */
    public function getResponse() {
        return $this->response;
    }

    /**a
     * Fetches the shipping label pdf for a given Shipment and
     * saves it as base64 encoded string to $pdf parameter on the Shipment.
     * The shipment must have $tracking_code and $reference set.
     *
     * @param Shipment $shipment
     * @return bool
     * @throws \Exception
     */
    public function fetchShippingLabel(Shipment &$shipment)
    {
        $response_xml = $this->fetchShippingLabels(array($shipment->getTrackingCode()));

        $shipment->setPdf($response_xml->{'response.file'});

        return true;
    }

    /**
     * Fetches the shipping labels in one pdf for a given tracking_codes and
     * saves it as base64 encoded string inside XML.
     *
     * @param array $trackingCodes
     * @return xml
     * @throws \Exception
     */
    public function fetchShippingLabels($trackingCodes)
    {
        $id     = str_replace('.', '', microtime(true));
        $xml    = new \SimpleXMLElement('<eChannel/>');

        $routing = $xml->addChild('ROUTING');
        $routing->addChild('Routing.Account', $this->api_key);
        $routing->addChild('Routing.Id', $id);

        if($this->use_posti_auth === true) {
            if(empty($this->token)) {
                $this->access_token = $this->getToken()->access_token;
            }

            $routing->addChild('Routing.Token', $this->access_token);
        } else {
            $routing->addChild("Routing.Version", 2);
            $routing->addChild("Routing.Key", hash_hmac('sha256',"{$this->api_key}{$id}", $this->secret));
        }

        $label = $xml->addChild('PrintLabel');
        $label['responseFormat'] = 'File';

        foreach($trackingCodes as $trackingCode) {
            $label->addChild('TrackingCode', $trackingCode);
        }

        $response = $this->doPost('/prinetti/get-shipping-label', null, $xml->asXML());

        $response_xml = @simplexml_load_string($response);

        if(!$response_xml) {
            throw new \Exception("Failed to load response xml");
        }

        $this->response = $response_xml;

        if($response_xml->{'response.status'} != 0) {
            throw new \Exception("Error: {$response_xml->{'response.status'}}, {$response_xml->{'response.message'}}");
        }

        return $response_xml;
    }

    /**
     * @param $tracking_code
     * @return mixed
     */
    public function getShipmentStatus($tracking_code)
    {
        return json_decode($this->doPost('/shipment/status', array('tracking_code' => $tracking_code));
    }

    /**
     * @return mixed
     */
    public function listAdditionalServices()
    {
        return json_decode($this->doPost('/additional-services/list', array()));
    }

    /**
     * @return mixed
     */
    public function listShippingMethods()
    {
        return json_decode($this->doPost('/shipping-methods/list', array()));
    }

    /**
     * Search pickup points.
     *
     * @param int $postcode
     * @param string $street_address
     * @param string $country
     * @param string $service_provider Limits results for to certain providers possible values: Posti, Matkahuolto, Db Schenker.
     * @param int $limit 1 - 15
     * @return mixed
     */
    public function searchPickupPoints($postcode = null, $street_address = null, $country = null, $service_provider = null, $limit = 5)
    {
        if ( ($postcode == null && $street_address == null) || (trim($postcode) == '' && trim($street_address) == '') ) {
            return '[]';
        }

        $post_params = array(
            'postcode'          => (string) $postcode,
            'address'           => (string) $street_address,
            'country'           => (string) $country,
            'service_provider'  => (string) $service_provider,
            'limit'             => (int) $limit
        );

        return json_decode($this->doPost('/pickup-points/search', $post_params));
    }

    /**
     * Searches pickup points with a text query. For best results the query should contain a full address
     *
     * @param $query_text Text containing the full address, for example: "Keskustori 1, 33100 Tampere"
     * @param string $service_provider $service_provider Limits results for to certain providers possible values: Posti, Matkahuolto, Db Schenker.
     * @param int $limit 1 - 15
     * @return mixed
     */
    public function searchPickupPointsByText($query_text, $service_provider = null, $limit = 5)
    {
        if ( $query_text == null || trim($query_text) == '' ) {
            return '[]';
        }

        $post_params = array(
            'query'             => (string) $query_text,
            'service_provider'  => (string) $service_provider,
            'limit'             => (int) $limit
        );

        return $this->doPost('/pickup-points/search', $post_params);
    }

    private function doPost($url_action, $post_params = null, $body = null)
    {
        $headers = array();

        if(is_array($post_params))
        {
            if(!isset($post_params['api_key']))
                $post_params['api_key'] = $this->api_key;

            if(!isset($post_params['timestamp']))
                $post_params['timestamp'] = time();

            ksort($post_params);

            $post_params['hash'] = hash_hmac('sha256', join('&', $post_params), $this->secret);

            $post_data = http_build_query($post_params);
        }

        if(!is_null($body)) {
            $headers[] = 'Content-type: text/xml; charset=utf-8';
            $post_data = $body;
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $options = array(
                CURLOPT_POST            =>  1,
                CURLOPT_HEADER          =>  0,
                CURLOPT_URL             =>  $this->base_uri.$url_action,
                CURLOPT_FRESH_CONNECT   =>  1,
                CURLOPT_RETURNTRANSFER  =>  1,
                CURLOPT_FORBID_REUSE    =>  1,
                CURLOPT_USERAGENT       =>  $this->user_agent,
                CURLOPT_TIMEOUT         =>  30,
                CURLOPT_HTTPHEADER      =>  $headers,
                CURLOPT_POSTFIELDS      =>  $post_data
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        return $response;
    }

    private function getPostiToken($url, $user, $secret)
    {
        $headers = array();

        $headers[] = 'Accept: application/json';
        $headers[] = 'Authorization: Basic ' .base64_encode("$user:$secret");

        $options = array(
            CURLOPT_POST            => 1,
            CURLOPT_HEADER          => 0,
            CURLOPT_URL             => $url,
            CURLOPT_FRESH_CONNECT   => 1,
            CURLOPT_RETURNTRANSFER  => 1,
            CURLOPT_FORBID_REUSE    => 1,
            CURLOPT_USERAGENT       => $this->user_agent,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_HTTPHEADER      => $headers,

        );

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        return $response;
    }
}
