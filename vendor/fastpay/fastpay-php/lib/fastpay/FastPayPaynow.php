<?php

require_once dirname(__FILE__).'/service/PaysbuyService.php';

class PaysbuyPaynow extends PaysbuyService {

	const ENDPOINT_URL = 'api_paynow/api_paynow.asmx';
	const OP_AUTHENTICATE = 'api_paynow_authentication_v3';

	const PAYMENT_URL = 'paynow.aspx?refid=%paymentCode%';

	public static function authenticate($data = array(), $returnPaymentURL = TRUE) {
		$reqdFields = array(
			"psbID",
			"username",
			"securecode",
			"inv",
			"itm",
			"amt",
			"curr_type",
			"method",
			"language",
			"resp_front_url",
			"resp_back_url"
		);
		$allFields = array_merge($reqdFields, array(
			"paypal_amt",
			"com",
			"opt_fix_redirect",
			"opt_fix_method",
			"opt_name",
			"opt_email",
			"opt_mobile",
			"opt_address",
			"opt_detail",
			"opt_param"
		));

		$userParams = array(
			'psbID' => PaysbuyService::$psbID,
			'username' => PaysbuyService::$username,
			'securecode' => PaysbuyService::$secureCode
		);

		// do a currency code -> type conversion if code is passed!
		if (array_key_exists("curr_code", $data) && $data['curr_code']) {
			$data['curr_type'] = PaysbuyService::currencyCodeToType($data['curr_code']);
			unset($data['curr_code']);
		}

		$res = parent::post(
			self::_getURL(self::OP_AUTHENTICATE),
			parent::buildParams($userParams + $data, $allFields, $reqdFields)
		);

		$res = simplexml_load_string($res)[0];
		$code = substr($res, 0, 2);
		$paymentCode = substr($res, 2);
		if ($code == '00') {
			$final = $returnPaymentURL ? self::getPaymentUrl($paymentCode) : $paymentCode;
			return $final;
		} else {
			throw new Exception("Error Processing Request - '$paymentCode'", 1);
		}

	}

	public static function getPaymentUrl($paymentCode) {
		return parent::getDomain() . '/' . str_replace('%paymentCode%', $paymentCode, static::PAYMENT_URL);
	}

	private static function _getURL($operation = "") {
		return parent::getURL($operation);
	}

}