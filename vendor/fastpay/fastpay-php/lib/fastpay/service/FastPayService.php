<?php

defined('PAYSBUY_LIVE_DOMAIN') || define('PAYSBUY_LIVE_DOMAIN', 'https://www.paysbuy.com');
defined('PAYSBUY_TEST_DOMAIN') || define('PAYSBUY_TEST_DOMAIN', 'https://demo.paysbuy.com');

class PaysbuyService {

	const TIMEOUT = 60;
	const CONNECT_TIMEOUT = 30;

	const METHOD_GET = 'GET';
	const METHOD_POST = 'POST';

	public static $currencyCodeTypes = array(
		'THB' => 'TH',
		'AUD' => 'AU',
		'GBP' => 'GB',
		'EUR' => 'EU',
		'HKD' => 'HK',
		'JPY' => 'JP',
		'NZD' => 'NZ',
		'SGD' => 'SG',
		'CHF' => 'CH',
		'USD' => 'US'
	);

	public static $testMode = FALSE;

	public static $psbID, $secureCode, $username;

	public static function setup($d) {
		self::$psbID = $d['psbID'];
		self::$secureCode = $d['secureCode'];
		self::$username = $d['username'];
	}

	public static function currencyCodeToType($code) {
		$code = strtoupper($code);
		return array_key_exists($code, self::$currencyCodeTypes) ? self::$currencyCodeTypes[$code] : false;
	}

	public static function getURL($operation) {
		return self::getDomain() . '/' . static::ENDPOINT_URL . '/' . $operation;
	}

	public static function getDomain() {
		return self::$testMode ? PAYSBUY_TEST_DOMAIN : PAYSBUY_LIVE_DOMAIN;
	}

	public static function buildParams($params = array(), $all = array(), $reqd = array()) {
		$p = $params + array_fill_keys($all, '');
		foreach($reqd as $r) if ($p[$r] == '') throw new Exception("Required parameter missing - ".$r, 1);
		return $p;
	}

	public static function get($url, $params = NULL) {
		return self::_executeCurl($url, self::METHOD_GET, $params);
	}

	public static function post($url, $params = NULL) {
		return self::_executeCurl($url, self::METHOD_POST, $params);
	}

	private static function _executeCurl($url, $method, $params = NULL) {
		$ch = curl_init($url);
		curl_setopt_array($ch, self::_getCURLOpts($method, $params));
		if(($result = curl_exec($ch)) === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new Exception($error);
		}
		curl_close($ch);
		return $result;
	}

	private static function _getCURLOpts($method, $params = NULL) {

		$options = array(
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_FRESH_CONNECT => true,
			CURLOPT_RETURNTRANSFER => true, // return a string
			CURLOPT_HEADER => 0,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_AUTOREFERER => true,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
		);

		// Add POST params if we have some
		if($method==self::METHOD_POST && count($params)) {
			$options += array(CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_POST => count($params));
		}

		return $options;

	}

}