<?php

defined ('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
}

require_once(dirname(__FILE__).'/vendor/autoload.php'); // load modules imported from composer

class plgVmPaymentFastPay extends vmPSPlugin {

	function __construct (&$subject, $config) {

		parent::__construct($subject, $config);

		$this->_loggable = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

	}


	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL ('FastPay Table');
	}


	/**
	 * @return array
	 */
	function getTableSQLFields() {

		$SQLfields = array(
			'id'									=> 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'	=> 'int(1) UNSIGNED',
			'fastpay_demo'				=> 'char(1)',
			'fastpay_account'			=> 'varchar(5000)'
		);

		return $SQLfields;

	}


	/**
	 * @param $cart
	 * @param $order
	 * @return bool|null
	 */
	function plgVmConfirmedOrder($cart, $order) {

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}
		
		VmConfig::loadJLang('com_virtuemart', TRUE);
		VmConfig::loadJLang('com_virtuemart_orders', TRUE);

		$isDemo = $method->fastpay_demo=='1';
		
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$fastpay_server = $method->fastpay_demo;
		$this->logInfo('plgVmConfirmedOrder order number: '.$order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN.DS.'models'.DS.'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(VMPATH_ADMIN.DS.'models'.DS.'currency.php');
		}

		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists('TableVendors')) {
			require(VMPATH_ADMIN.DS.'tables'.DS.'vendors.php');
		}

		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);
		$this->getPaymentCurrency($method);
		$currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$method->payment_currency);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		if ($totalInPaymentCurrency <= 0) {
			vmInfo(JText::_('Incorrect payment amount'));
			return FALSE;
		}

		// setup fastpay
		\FastPayService::setup([
			'psbID' => $method->fastpay_psbid,
			'username' => $method->fastpay_account,
			'secureCode' => $method->fastpay_securecode
		]);

		\FastPayService::$testMode = $isDemo;

		// get payment URL
		$paymentURL = \FastPayPaynow::authenticate([
			'method' => '1',
			'language' => 'E',
			'inv' => $order['details']['BT']->order_number,
			'itm' => JText::_('Payment for order') . ': ' . $order['details']['BT']->order_number,
			'amt' => $totalInPaymentCurrency['value'],
			'curr_code' => $currency_code_3,
			'resp_front_url' => substr(JURI::root(FALSE,''),0,-1) . JROUTE::_( 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt('Itemid'), FALSE),
			'resp_back_url' => substr(JURI::root(FALSE,''),0,-1) . JROUTE::_('index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component', FALSE)
		]);


		$cart->_confirmDone = FALSE;
		$cart->_dataValidated = FALSE;
		$cart->setCartIntoSession();
		$this->redirect($paymentURL);
		
	}


	function redirect($url) {
		header("Location: $url");
	}


	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}


	function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {


	}


	function plgVmOnPaymentResponseReceived(&$html) {

		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE.DS.'helpers'.DS.'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE.DS.'helpers'.DS.'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN.DS.'models'.DS.'orders.php');
		}

		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return TRUE;

	}


	function plgVmOnUserPaymentCancel() {
		return TRUE;
	}


	function plgVmOnPaymentNotification() {

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
		}
		$fastpay_data = JRequest::get('post');
		if (!isset($fastpay_data['result'])) {
			return FALSE;
		}

		$result = $fastpay_data['result'];
		$payment_status = substr($result, 0, 2);
		$order_id = substr($result, 2);
		$amt = $fastpay_data['amt'];
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_id))) {
			return FALSE;
		}


		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `#__virtuemart_orders` WHERE `virtuemart_order_id` = '.$virtuemart_order_id;

		$db->setQuery ($q);
		$payments = $db->loadObject();

		$payments->order_total;
		if($payments->order_total != $amt){
			$this->logInfo("STATUS_URL FAIL: REASON: can not load ORDER; POST: ".serialize($fastpay_data)."; STRING: $string; HASH: $hash", 'message');
		}

		$method = $this->getVmPluginMethod($payments->virtuemart_paymentmethod_id);

		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}
		
		$modelOrder = VmModel::getModel('orders');
		$order = array();

		if($payment_status == '00') {

			$this->logInfo("STATUS_URL SUCCESS: POST: ".serialize($fastpay_data)."; STRING: $string; HASH: $hash", 'message');
			$order['order_status'] = 'C';
			$this->logInfo('plgVmOnPaymentNotification return new_status:'.$order['order_status'], 'message');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
			$this->emptyCart($order_number, $order_number);

		} else if ($payment_status == '02') {

			$this->logInfo("STATUS_URL PROCESS: POST: ".serialize($fastpay_data)."; STRING: $string; HASH: $hash", 'message');
			$order['order_status'] = 'P';
			$this->logInfo('plgVmOnPaymentNotification return new_status:'.$order['order_status'], 'message');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
			$this->emptyCart($order_number, $order_number);

		} else {

			$this->logInfo("STATUS_URL FAIL: POST: ".serialize($fastpay_data)."; STRING: $string; HASH: $hash", 'message');
			$order['order_status']='X';
			$this->logInfo('plgVmOnPaymentNotification return new_status:' . $order['order_status'], 'message');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
			$this->emptyCart($order_number, $order_number);

		}

		die('done');

	}


	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId($payment_method_id)) {
			return NULL;
		}

		if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
			return '';
		}

		$html = '<table class="adminlist" width="50%">'."\n";
		$html .= $this->getHtmlHeaderBE();
		$code = "fastpay_response_";
		$first = TRUE;

		foreach ($payments as $payment) {
			$html .= '<tr class="row1"><td>'.JText::_('VMPAYMENT_FASTPAY_DATE').'</td><td align="left">'.$payment->created_on.'</td></tr>';
			if ($first) {
				$html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $payment->payment_name);
				if ($payment->payment_order_total and $payment->payment_order_total != 0.00) {
					$html .= $this->getHtmlRowBE('fastpay_PAYMENT_ORDER_TOTAL', $payment->payment_order_total." ".shopFunctions::getCurrencyByID($payment->payment_currency, 'currency_code_3'));
				}
				if ($payment->email_currency and $payment->email_currency != 0) {
					$html .= $this->getHtmlRowBE('FASTPAY_PAYMENT_EMAIL_CURRENCY', shopFunctions::getCurrencyByID($payment->email_currency, 'currency_code_3'));
				}
				$first = FALSE;
			}
			foreach ($payment as $key => $value) {
				if ($value) {
					if (substr($key, 0, strlen($code)) == $code) {
						$html .= $this->getHtmlRowBE($key, $value);
					}
				}
			}

		}
		$html .= '</table>'."\n";
		return $html;
	}


	protected function checkConditions($cart, $method, $cart_prices) {
		return TRUE;
	}


	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}


	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg) {
		return $this->OnSelectCheck($cart);
	}


	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}


	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}


	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}


	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}


	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}


	function plgVmDeclarePluginParamsPaymentVM3(&$data) {
		return $this->declarePluginParams('payment',$data);
	}


	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}


}