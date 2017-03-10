<?php
/*
 * Billfloat Model having complete command functions
* @auther: Tariq aziz
* @date: March 2014
*/

class GsmNation_Billfloat_Model_Standard extends Mage_Payment_Model_Method_Abstract {
	public $_code = 'billfloat';

	protected $_isInitializeNeeded      = true;
	protected $_canUseInternal          = true;
	protected $_canUseForMultishipping  = false;
	protected $_canUseCheckout = true;
	//protected $_supportedCurrencyCodes = array('USD');

	public function getOrderPlaceRedirectUrl() {
		//return Mage::getUrl('billfloat/payment/redirect', array('_secure' => true));

		$marchantName =  $this->getConfigData('merchant_name');
		$marchantLogin =  Mage::helper('core')->decrypt($this->getConfigData('merchant_login'));
		$marchantPassword = Mage::helper('core')->decrypt($this->getConfigData('merchant_pass'));
		$biller_id = Mage::helper('core')->decrypt($this->getConfigData('biller_id'));
		$marchantCgiUrl = $this->getConfigData('cgi_url');
		$approvalUrl = $this->getConfigData('approval_url');
		$declineUrl = $this->getConfigData('decline_url');
		$confirmUrl = $this->getConfigData('confirm_url');
		
		//c5db53e728a1a63409275e428145354351fc8bac

		$quote = Mage::getSingleton('checkout/session')->getQuote();
		$quoteId = $quote->getId();

		$orderObj = Mage::getModel('sales/order')->loadByAttribute('quote_id',$quoteId);
		$increment_id = $orderObj->getIncrementId();
		$billing = $orderObj->getBillingAddress();
		$items = $orderObj->getAllItems();

		$itemsDesc = array();
		foreach ($items as $item) {
			$productname = $item->getName();
			$qty = intval($item->getQtyOrdered());
			$sku = $item->getSku();
			$price = number_format($item->getPrice(),2);
			$tax = number_format($item->getTax(),2);
				
			$itemsXml .= '<CartItem>
			<SKU>'.$sku.'</SKU>
			<Description>'.$productname.'</Description>
			<Quantity>'.$qty.'</Quantity>
			<Amount>'.$price.'</Amount>
			<Tax>'.$tax.'</Tax>
			</CartItem>
			';
		}

		$grandTotal = str_replace(',','',number_format($orderObj->getData('base_grand_total'), 2));

		$street = $billing->getStreet();
		$streetAdd = '';
		foreach($street as $st){
			$streetAdd .= $st;
		}
		$regionId = $billing->getRegionId();
		$region = Mage::getModel('directory/region')->load($regionId);
		$regionCode = $region->getCode();

		$customer_email = $orderObj->getCustomerEmail();
		try {
			$request = '<?xml version="1.0" encoding="utf-8"?>
			<BF>
			<Rq>
			<Authentication>
			<Organization>'.$marchantName.'</Organization>
			<User>'.$marchantLogin.'</User>
			<Password>'.$marchantPassword.'</Password>
			</Authentication>
			<CreateExtension>
			<UserProfile>
			<FirstName>'.$billing->getFirstname().'</FirstName>
			<LastName>'.$billing->getLastname().'</LastName>
			<EmailAddress>'.$customer_email.'</EmailAddress>
			<Street>'.$streetAdd.'</Street>
			<AptSuiteNumber>600</AptSuiteNumber>
			<City>'.$billing->getCity().'</City>
			<State>'.$regionCode.'</State>
			<PostalCode>'.$billing->getPostcode().'</PostalCode>
			<PhoneNumber>'.$billing->getTelephone().'</PhoneNumber>
			<PhoneNumberIsMobileNumber>false</PhoneNumberIsMobileNumber>
			</UserProfile>
			<Cart>
			<BillFloatBillerId>'.$biller_id.'</BillFloatBillerId>
			<OrderId>'.$increment_id.'</OrderId>
			'.$itemsXml.
			'<TotalAmount>'.$grandTotal.'</TotalAmount>
			<VendorToken>'.$increment_id.'</VendorToken>
			<ApprovalRedirectionUrl>'.$approvalUrl.'</ApprovalRedirectionUrl>
			<DeclineRedirectionUrl>'.$declineUrl.'</DeclineRedirectionUrl>
			<ConfirmationRedirectionUrl>'.$confirmUrl.'</ConfirmationRedirectionUrl>
			</Cart>
			</CreateExtension>
			</Rq>
			</BF>
			';
				
			Mage::log("orderPlaced request XML".$request, null, 'billfloat.log');
			$url = $marchantCgiUrl.'create_extension';
			$output = $this->curlRequest($url, $request);
			if($output){
				$xml = new SimpleXMLElement($output);
				Mage::log("orderPlaced response XML:: ".print_r($xml,true), null, 'billfloat.log');
				if($xml->Rs->Status->Code==0){
					$extensionKey = $xml->Rs->CreateExtensionResponse->BillFloatExtension->BillFloatExtensionKey;
					$vendorToken = $xml->Rs->CreateExtensionResponse->BillFloatExtension->VendorToken;
					$message = $xml->Rs->Status->BillFloatExtension->Message;
					
					$orderId = $orderObj->getId();
					$billfloatModel = Mage::getModel('billfloat/billfloat');
					
					$billfloatModel->setExtensionKey($extensionKey);
					$billfloatModel->setVendorToken($vendorToken);
					$billfloatModel->setMessage($message);
					$billfloatModel->setOrderId($orderId);
					$billfloatModel->save();
					
					$session = $this->getCheckout();
					$session->setQuoteId(null);
					Mage::getSingleton('checkout/session')->clear();
					Mage::getSingleton('customer/session')->unsetAll();

					header("Location: ".$xml->Rs->CreateExtensionResponse->BillFloatExtension->BillFloatRedirectionUrl);
					exit;
				}
			} else {
				Mage::getSingleton('core/session')->addError("Unfortunately, at this time SmartPay is unavailable in your State.");
				Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('onestepcheckout/index'));
				Mage::app()->getResponse()->sendResponse();
				exit;
			}

		}catch (Exception $e) {
			Mage::getSingleton('core/session')->addError($e->getMessage());
			Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('onestepcheckout/index'));
			Mage::app()->getResponse()->sendResponse();
			exit;
		}

		Mage::getSingleton('core/session')->addError('Unfortunately, at this time SmartPay is unavailable in your State.');
		Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('onestepcheckout/index'));
		Mage::app()->getResponse()->sendResponse();
		exit;
	}

	public function getCheckout() {
		return Mage::getSingleton('checkout/session');
	}

	/*
	 * General curl request
	*
	*/
	public function curlRequest($url, $request){

		try{
			$headers = array(
					'Content-Type'=>'application/xml',
					'Content-length'=>strlen($request),
					'Accept'=>'application/xml',
					'charset'=>'utf-8',
			);

			$handle = curl_init();
			curl_setopt($handle, CURLOPT_URL, $url);
			curl_setopt($handle, CURLOPT_PORT , 443);
			curl_setopt($handle, CURLOPT_VERBOSE, 0);
			curl_setopt($handle, CURLOPT_SSLVERSION, 3);
			curl_setopt($handle, CURLOPT_POST, 1);
			curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $request);
			curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

			$result = curl_exec($handle);
			if(!curl_errno($result)){
				$info = curl_getinfo($result);
			} else {
			}

			curl_close($result);
		}catch (Exception $e){
			return false;

		}

		return $result;
	}

	public function returnProcess($order, $creditmemo) {
		
		$payment_method_code = $order->getPayment()->getMethodInstance()->getCode();
		
		if($payment_method_code != $this->_code){
			return;
		}
		$totalAmount = number_format($order->getBaseGrandTotal(),2);
		$extensionKey = $order->getExtOrderId();
		
		$marchantName =  $this->getConfigData('merchant_name');
		$marchantLogin =  Mage::helper('core')->decrypt($this->getConfigData('merchant_login'));
		$marchantPassword = Mage::helper('core')->decrypt($this->getConfigData('merchant_pass'));
		$marchantCgiUrl = $this->getConfigData('cgi_url');
	
		$items = $creditmemo->getAllItems();
		$itemsXml = '';
		foreach ($items as $item) {
			$productname = $item->getName();
			$qty = intval($item->getQty());
			$sku = $item->getSku();
			$price = number_format($item->getPrice(),2);
			$tax = number_format($item->getTax(),2);
		
			$itemsXml .= '<CartItem>
			<Description>'.$productname.'</Description>
			<Quantity>'.$qty.'</Quantity>
			</CartItem>
			';
		}

		$request = '<?xml version="1.0" encoding="utf-8"?>
		<BF>
		<Rq>
		<Authentication>
		<Organization>'.$marchantName.'</Organization>
		<User>'.$marchantLogin.'</User>
		<Password>'.$marchantPassword.'</Password>
		</Authentication>
		<InitiateReturn>
		<BillFloatExtensionKey>'.$extensionKey.'</BillFloatExtensionKey>
		<ReturnAmount>'.$totalAmount.'</ReturnAmount>
		<CartItems>'.$itemsXml.'</CartItems>
		</InitiateReturn>
		</Rq>
		</BF>
		';
		
		Mage::log("order return event request XML ".$request, null, 'billfloat.log');
		$url = $marchantCgiUrl."initiate_return";
		
		$output = $this->curlRequest($url, $request);
		if($output){
			$xml = new SimpleXMLElement($output);
			Mage::log("order return event response orderId: ".$order->getIncrementId(), null, 'billfloat.log');
			Mage::log("xml: ".print_r($xml, true), null, 'billfloat.log');
			if($xml->Rs->Status->Code==0){
				return true;
			}
		} else {
			Mage::log("order shipped event orderId: ".$order->getIncrementId()."Output not found", null, 'billfloat.log');
			return false;
		}

	}
	
	public function paymentnotification($order, $paymentApp) {
		$orderId = $order->getId();
		$model = Mage::getModel('billfloat/billfloat')->load($orderId, 'order_id');
		
		$payment_method_code = $order->getPayment()->getMethodInstance()->getCode();
		
		if($payment_method_code != $this->_code){
			return false;
		}
		
		if($paymentApp == 1){
			$paymentApp = '<PaymentApplied>true</PaymentApplied>';	
		}else{
			$paymentApp = '<PaymentApplied>false</PaymentApplied>';
		}
		
		$extensionKey = $model->getExtensionKey();
		//exit;
		$marchantName =  $this->getConfigData('merchant_name');
		$marchantLogin =  Mage::helper('core')->decrypt($this->getConfigData('merchant_login'));
		$marchantPassword = Mage::helper('core')->decrypt($this->getConfigData('merchant_pass'));
		$marchantCgiUrl = $this->getConfigData('cgi_url');

		$request = '<?xml version="1.0" encoding="utf-8"?>
		<BF>
		<Rq>
		<Authentication>
		<Organization>'.$marchantName.'</Organization>
		<User>'.$marchantLogin.'</User>
		<Password>'.$marchantPassword.'</Password>
		</Authentication>
		<PaymentNotification>
		<BillFloatExtensionKey>'.$extensionKey.'</BillFloatExtensionKey>
		'.$paymentApp.'
		</PaymentNotification>
		</Rq>
		</BF>
		';
		
		Mage::log("order shipped event request orderId: ".$request, null, 'billfloat.log');
		$url = $marchantCgiUrl."payment_notification";
		$output = $this->curlRequest($url, $request);
		if($output){
			$xml = new SimpleXMLElement($output);
			Mage::log("order shipped event orderId: ".$order->getIncrementId(), null, 'billfloat.log');
			Mage::log("xml: ".print_r($xml, true), null, 'billfloat.log');
			if($xml->Rs->Status->Code==0){
				return $xml;
			}
		} else {
			Mage::log("order shipped event orderId: ".$order->getIncrementId()."<br />Output not find", null, 'billfloat.log');
			return false;
		}
	}

	public function inquirePayment($type = 'sent') {
		$marchantName =  $this->getConfigData('merchant_name');
		$marchantLogin =  Mage::helper('core')->decrypt($this->getConfigData('merchant_login'));
		$marchantPassword = Mage::helper('core')->decrypt($this->getConfigData('merchant_pass'));
		$marchantCgiUrl = $this->getConfigData('cgi_url');

		$request = '<?xml version="1.0" encoding="utf-8"?>
		<BF>
		<Rq>
		<Authentication>
		<Organization>'.$marchantName.'</Organization>
		<User>'.$marchantLogin.'</User>
		<Password>'.$marchantPassword.'</Password>
		</Authentication>

		<InquireAboutPayment>
		<ProductData>
		<MerchantId>'.$marchantLogin.'</MerchantId>
		</ProductData>

		<Parameters>
		<Type>'.$type.'</Type>
		</Parameters>
		</InquireAboutPayment>
		</Rq>
		</BF>
		';
		
		Mage::log("Inquire Pay Request".print_r($request, true), null, 'billfloat.log');
		
		$url = $marchantCgiUrl."inquire_about_payment";
		$output = $this->curlRequest($url, $request);
		if($output){
			$xml = new SimpleXMLElement($output);
			Mage::log("Inquire Pay Response".print_r($xml, true), null, 'billfloat.log');
	
			if($xml->Rs->Status->Code==0){
				return $xml;
			}
		} else {
			return false;
		}
	}

	public function inquire($extensionKey) {
		$marchantName =  $this->getConfigData('merchant_name');
		$marchantLogin =  Mage::helper('core')->decrypt($this->getConfigData('merchant_login'));
		$marchantPassword = Mage::helper('core')->decrypt($this->getConfigData('merchant_pass'));
		$marchantCgiUrl = $this->getConfigData('cgi_url');

		$request = '<?xml version="1.0" encoding="utf-8"?>
		<BF>
		<Rq>
		<Authentication>
		<Organization>'.$marchantName.'</Organization>
		<User>'.$marchantLogin.'</User>
		<Password>'.$marchantPassword.'</Password>
		</Authentication>
		<InquireAboutExtension>
		<BillFloatExtensionKey>'.$extensionKey.'</BillFloatExtensionKey>
		</InquireAboutExtension>
		</Rq>
		</BF>';

		$url = $marchantCgiUrl."inquire_about_extension";
		$output = $this->curlRequest($url, $request);
		if($output){
			$xml = new SimpleXMLElement($output);
			if($xml->Rs->Status->Code==0){
				return $xml;
			}else{
				return $xml->Rs->Status->Message;
			}
		} else {
			return false;
		}
	}
	
	public function testconfirmOrders(){
		$coreConn = Mage::getSingleton('core/resource')->getConnection('core_read');
		$res = $this->inquirePayment('sent');
		return $res;
	}
	
	/*
	 * The cronJob function to process billfloat order, if not returned from billfloat..
	* @auther: Tariq Aziz.
	* @usage: from the xml...
	*/
	public function confirmOrders(){
		$coreConn = Mage::getSingleton('core/resource')->getConnection('core_read');
		$res = $this->inquirePayment('sent');
	
		Mage::log("confirmOrders: ".print_r($res, true), null, 'billfloat.log');
		if($res->Rs->Status->Code==0){
			
			foreach($res->Rs->InquireAboutPaymentResponse->PaymentResponse as $response){
				$extensionKey = $response->BillFloatExtensionKey;
				
				$order = Mage::getModel('billfloat/billfloat')->getCollection()
				->addFieldToFilter('extension_key', array('LIKE' => $extensionKey));
				$data = $order->getData();
				//print_r($data);
				$orderId = $data[0]['order_id'];
				
				$orderObj = Mage::getModel('sales/order')->load($orderId);
				$state = $orderObj->getState();
				if(isset($orderObj) && $state != 'new'){
					continue;
				}
				
				$type = $response->Type; 
				$dateExtended = date('Y-m-d H:i:s',strtotime($response->DateExtended)); 
				$billFloatBillerId = $response->BillFloatBillerId; 
				$vendorToken = $response->VendorToken; 
				$TransactionNumber = $response->TransactionNumber; 
				
				$billAmount = $response->BillAmount; 
				$billDueDate = date('Y-m-d H:i:s',strtotime($response->BillDueDate)); 
				$billFloatExtKey = $response->ExtensionsInfo->Extension->BillFloatExtensionKey; 
				$perExtVendorToken = $response->ExtensionsInfo->Extension->PerExtensionVendorToken; 
				
				$sql = "UPDATE billfloat
				set type='$type', date_extended='$dateExtended', bill_float_biller_id='$billFloatBillerId', vendor_token='$vendorTokendorToken'
								,transaction_number='$TransactionNumber', bill_amount='$billAmount', bill_due_date='$billDueDate',
								billfloat_ext_key='$billFloatExtKey', per_ext_vendor_token='$perExtVendorToken' WHERE order_id='$orderId'";
				$coreConn->query($sql);
				
				$this->processOrder($orderId, $TransactionNumber);
			}
		}
		
		return $res;
	}
	
	public function shipmentCheck(){
		$coreConn = Mage::getSingleton('core/resource')->getConnection('core_read');
		$orders = Mage::getModel('billfloat/billfloat')->getCollection()
		->addFieldToFilter('order_shipped', array('eq' => "false"))
		->addFieldToFilter('shipment_sent', array('eq' => "1"));
		
		Mage::log("shipment check: ", null, 'billfloat.log');
		
		foreach($orders as $order){
			$orderObj = Mage::getModel('sales/order')->load($order->getOrderId());
			$extensionKey = $order->getExtensionKey();
			$orderId = $order->getId();
			
			$res = $this->inquire($extensionKey);
			if($res->Rs->Status->Code==0){
				$response = $res->Rs->InquireAboutExtensionResponse;
				$vendorToken = $response->VendorToken;
				$wasExtended = $response->ExtensionInfo->WasExtended;
				$amountExtended = $response->ExtensionInfo->AmountExtended;
				$orderShipped = $response->ShipmentNotificationInfo->OrderShipped;
				
				$sql = "UPDATE billfloat
				set vendor_token='$vendorToken', was_extended='$wasExtended', amount_extended='$amountExtended', order_shipped='$orderShipped'
				 WHERE order_id='$orderId'";
				
				$coreConn->query($sql);
			}
		}
		return;
	}

	/*
	 * Process orders triggered from cron job
	 */
	public function processOrder($orderId, $transId)
	{
		$orderObj = Mage::getModel('sales/order')->load($orderId);

		try {
			Mage::log("saving order: ", null, 'billfloat.log');
			$isCustomerNotified	= true;
			$message = "Billfloat Transaction ID: ".$transId;
			$orderObj->setData('ext_order_id', $transId);
			$orderObj->setData('state', "processing");
			$orderObj->addStatusToHistory('Processing', $message, $isCustomerNotified);
				
			$orderObj->save();
				
		} catch (Exception $e){
			Mage::log("saving order error: ".$e->getMessage(), null, 'billfloat.log');
			Mage::log($e->getMessage());
			Mage::log($e->getTraceAsString());
		}
			
		Mage::log("order created: ".$orderObj->getId(), null, 'billfloat.log');
		$orderObj->sendNewOrderEmail();
		echo $orderObj->getId();
			
		//$orderObj->sendNewOrderEmail();
		return $orderObj->getId();
	}
}
?>