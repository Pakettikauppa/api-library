<?php

namespace Pakettikauppa;

class Client
{
    private $api_key;
    private $secret;
    private $base_uri;
    private $user_agent = 'pk-client-lib/1.0';
    private $comment = null;
    private $response = null;

    private $http_response_code;
    private $http_error;

    /**
     * Client constructor.
     *
     * Params must contain ['test_mode' => true] OR your api credentials ['api_key' => '', 'secret' => '']
     *
     * @param array $params
     * @throws \Exception
     */
    public function __construct(array $params)
    {
        if(isset($params['test_mode']) and $params['test_mode'] === true) {
            $this->api_key      = '00000000-0000-0000-0000-000000000000';
            $this->secret       = '1234567890ABCDEF';
            $this->base_uri     = 'https://apitest.pakettikauppa.fi';
        } else {

            if(!isset($params['api_key']))
                throw new \Exception('Param api_key not set');

            if(!isset($params['secret']))
                throw new \Exception('Param secret not set');

            $this->api_key      = $params['api_key'];
            $this->secret       = $params['secret'];

            if(isset($params['base_uri'])) {
                $this->base_uri = $params['base_uri'];
            } else {
                $this->base_uri = 'https://api.pakettikauppa.fi';
            }
        }
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
    public function createTrackingCode(Shipment &$shipment, $language = "fi")
    {
        $this->createShipment($shipment, false, $language);

        return true;
    }

    /**
     * @param Shipment $shipment
     * @param bool     $draft
     *
     * @throws \Exception
     */
    private function createShipment(Shipment &$shipment, $draft = false, $language = "fi")
    {
        $id             = str_replace('.', '', microtime(true));
        $shipment_xml   = $shipment->asSimpleXml();

        $shipment_xml->{"ROUTING"}->{"Routing.Account"}     = $this->api_key;
        $shipment_xml->{"ROUTING"}->{"Routing.Id"}          = $id;
        $shipment_xml->{"ROUTING"}->{"Routing.Key"}         = md5("{$this->api_key}{$id}{$this->secret}");
        if($this->comment != null) {
            $shipment_xml->{"ROUTING"}->{"Routing.Comment"} = $this->comment;
        }
        if (!$draft) {
            $response = $this->doPost("/prinetti/create-shipment?lang={$language}", null, $shipment_xml->asXML());
        } else {
            $response = $this->doPost('/prinetti/create-shipment-draft', null, $shipment_xml->asXML());
        }

        $response_xml = @simplexml_load_string($response);

        if(!$response_xml) {
            throw new \Exception("Failed to load response xml");
        }

        $this->response = $response_xml;

        if($response_xml->{'response.status'} != 0) {
            throw new \Exception("Error: {$response_xml->{'response.status'}}, {$response_xml->{'response.message'}}");
        }

        $response_xml = $this->response;

        $shipment->setReference($response_xml->{'response.reference'});
        $shipment->setTrackingCode($response_xml->{'response.trackingcode'});
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
        $id     = str_replace('.', '', microtime(true));
        $xml    = new \SimpleXMLElement('<eChannel/>');

        $routing = $xml->addChild('ROUTING');
        $routing->addChild('Routing.Account', $this->api_key);
        $routing->addChild('Routing.Id', $id);
        $routing->addChild('Routing.Key', md5("{$this->api_key}{$id}{$this->secret}"));

        $label = $xml->addChild('PrintLabel');
        $label['responseFormat'] = 'File';
        $label->addChild('Reference', $shipment->getReference());
        $label->addChild('TrackingCode', $shipment->getTrackingCode());

        $response = $this->doPost('/prinetti/get-shipping-label', null, $xml->asXML());

        $response_xml = @simplexml_load_string($response);

        if(!$response_xml) {
            throw new \Exception("Failed to load response xml");
        }

        $this->response = $response_xml;

        if($response_xml->{'response.status'} != 0) {
            throw new \Exception("Error: {$response_xml->{'response.status'}}, {$response_xml->{'response.message'}}");
        }

        $shipment->setPdf($response_xml->{'response.file'});

        return true;
    }

    /**
     * Fetches the shipping labels in one pdf for a given tracking_codes and
     * saves it as base64 encoded string inside XML.
     *
     * @param mixed $trackingCodes
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
        $routing->addChild('Routing.Key', md5("{$this->api_key}{$id}{$this->secret}"));

        $label = $xml->addChild('PrintLabel');
        $label['responseFormat'] = 'File';

        if (!is_array($trackingCodes)) {
            $trackingCodes = [$trackingCodes];
        }
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
     *  Fetches a cost estimation for a shipment from Pakettikauppa.
     *  To get an estimation for a parcel the shipment must have shipping
     *  method set and at least 1 parcel with weight. When estimating cargo sender
     *  and receiver info should also be set.
     *
     *
     */
    public function estimateShippingCost(Shipment &$shipment)
    {
        $sender                 = $shipment->getSender();
        $receiver               = $shipment->getReceiver();
        $parcels                = $shipment->getParcels();
        $additional_services    = $shipment->getAdditionalServices();

        $shipment_data = array(
            'sender' => array(
                'postcode'  => $sender->getPostcode(),
                'country'   => $sender->getCountry(),
            ),
            'receiver' => array(
                'postcode'  => $receiver->getPostcode(),
                'country'   => $receiver->getCountry(),
            ),

            'product_code'          => $shipment->getShippingMethod(),
            'parcels'               => array(),
            'additional_services'   => array()
        );

        foreach ($parcels as $parcel)
        {
            $shipment_data['parcels'][] = array(
                'weight'    => $parcel->getWeight(),
                'volume'    => $parcel->getVolume(),
                'type'      => $parcel->getPackageType(),
                'x_dimension' => $parcel->getX(),
                'y_dimension' => $parcel->getY(),
                'z_dimension' => $parcel->getZ(),
            );
        }

        foreach ($additional_services as $service)
        {
            $shipment_data['additional_services'][] = $service->getServiceCode();
        }


        $response =  $this->doPost('/shipment/estimate-price', ['shipment' => json_encode($shipment_data)]);

        return json_decode($response);
    }


    /**
     * @param $tracking_code
     * @param string $lang
     * @return mixed
     */
    public function getShipmentStatus($tracking_code, $lang = 'fi')
    {
        return $this->doPost('/shipment/status', array('tracking_code' => $tracking_code, 'language' => $lang));
    }

    /**
     * @return mixed
     */
    public function listAdditionalServices()
    {
        return $this->doPost('/additional-services/list', array());
    }

    /**
     * @param $postcode
     *
     * @return bool|string
     */
    public function findCityByPostcode($postcode, $country) {
        return json_decode($this->doPost('/info/find-city', array('postcode' => $postcode, 'country' => $country)));
    }

    /**
     * @return mixed
     */
    public function listShippingMethods()
    {
        return $this->doPost('/shipping-methods/list', array());
    }

    /**
     * Search pickup points.
     *
     * @param int $postcode
     * @param string $street_address
     * @param string $country
     * @param string $service_provider Limits results for to certain providers possible values are packet service codes (like 2103 for Postipaketti. Use listShippingMethods to get service codes).
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

        return $this->doPost('/pickup-points/search', $post_params);
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

    /**
     *
     * Searches info about a single pickup point.
     *
     * @param string $point_id  is an id for a single pickup point. For example: 905253201
     * @param  $service is used to identify service provider. It can shipping method code like '2103'
     *          or name of the service provider: "Posti", "Matkahuolto" or "Db Schenker".
     * @return string|null
     */
    public function getPickupPointInfo($point_id, $service)
    {
        if (empty($service) or empty($point_id))
        {
            return null;
        }

        $post_params = array(
            'point_id'  => (string) $point_id,
            'timestamp' => time()
        );

        if(is_numeric($service))
        {
            $post_params['service_code'] = $service;
        }else {
            $post_params['service_provider'] = $service;
        }

        return $this->doPost('/pickup-point/info', $post_params);
    }

    /**
     * Creates an activation code (Helposti-koodi, aktivointikoodi) to shipment.
     * Only Posti shipments are supported for now.
     *
     * @param string $tracking_code
     *
     * @return mixed
     */
    public function createActivationCode($tracking_code)
    {
        if (empty($tracking_code)) {
            return null;
        }

        $post_params = array('tracking_code' => $tracking_code);

        return $this->doPost('/shipment/create-activation-code', $post_params);
    }

    /**
     * Creates draft shipment that can be created as real shipment later.
     *
     * @param Shipment $shipment
     *
     * @return string uuid
     * @throws \Exception
     */
    public function createShipmentDraft(Shipment &$shipment) {
        $this->createShipment($shipment, true);

        return $this->response->{'response.reference'}['uuid']->__toString();
    }

    /**
     * Creates real shipment from the draft shipment.
     *
     * @param $uuid
     *
     * @return string tracking code
     * @throws \Exception
     */
    public function confirmShipmentDraft($uuid)
    {
        $id     = str_replace('.', '', microtime(true));
        $xml    = new \SimpleXMLElement('<eChannel/>');

        $routing = $xml->addChild('ROUTING');
        $routing->addChild('Routing.Account', $this->api_key);
        $routing->addChild('Routing.Id', $id);
        $routing->addChild('Routing.Key', md5("{$this->api_key}{$id}{$this->secret}"));

        $label = $xml->addChild('ConfirmLabel');
        $label->addChild('Reference');
        $label->Reference['uuid'] = $uuid;

        $response = $this->doPost('/prinetti/confirm-shipment-draft', null, $xml->asXML());

        $response_xml = @simplexml_load_string($response);

        if(!$response_xml) {
            throw new \Exception("Failed to load response xml");
        }

        $this->response = $response_xml;

        if($response_xml->{'response.status'} != 0) {
            throw new \Exception("Error: {$response_xml->{'response.status'}}, {$response_xml->{'response.message'}}");
        }

        return $response_xml->{'response.trackingcode'}->__toString();
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

        $response                   = curl_exec($ch);
        $this->http_response_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->http_error           = curl_errno($ch);
        curl_close($ch);

        return $response;
    }
}
