<?php

// include the library
include dirname(__FILE__).'/lib/Paysbuy.php';

// set up Paysbuy account details
\PaysbuyService::setup(array(
	'psbID' => '0276761817',
	'username' => 'aomchom@paysbuy.com',
	'secureCode' => 'A1A849792963F2E5C5FAACFB8494C696'
));

// build the URL that can be redirected to to make the payment
$paymentURL = \PaysbuyPaynow::authenticate(array(
	'method' => '1',
	'language' => 'E',
	'inv' => '201607211',
	'itm' => 'An item being paid for!',
	'amt' => 3,
	'curr_type' => 'TH',
	'resp_front_url' => 'http://blah.com/front.php',
	'resp_back_url' => 'http://blah.com/back.php'
));

// show the URL (on a real site, you would redirect to the payment URL)
var_dump($paymentURL);
