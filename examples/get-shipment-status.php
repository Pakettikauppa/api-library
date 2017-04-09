<?php
/**
 * Lists tracking and general info about a shipment. Works only on production environment with real credentials.
 * Returns empty json object if tried in testing environment.
 *
 */
error_reporting(E_ALL|E_STRICT);
ini_set('display_errors', 1);

require '../vendor/autoload.php';

use Pakettikauppa\Client;


$api_key        = '';
$secret         = '';
$tracking_code  = '';

$client = new Client(array('api_key' => $api_key, 'secret' => $secret));

$result = $client->getShipmentStatus($tracking_code);

var_dump(json_decode($result));