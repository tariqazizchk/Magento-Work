<?php

require_once "../app/Mage.php";
require_once "feed_functions.php";
umask(0);
Mage::app();

// set Store ID
// make sure we don't time out

@ini_set('max_execution_time', '1000');
@ini_set('max_input_time', '1000');
@set_time_limit(1000);
@ignore_user_abort(true);

$storeCharset = Mage::getStoreConfig('design/head/default_charset');
ob_start();
// set headers to Magento character set

$feedName = 'reevoo';

$companyName = "GSM NATION";
$feed_dir = '../media/feed/';   // Feed directory
$date = date("Ymd");
$timestamp = date('Y-m-d/H:i:s');
define('SAVE_FEED_LOCATION', $feed_dir);
$starttime = microtime();


if (array_key_exists('code', $_REQUEST)) {
	$countryCode = $_REQUEST['code'];
}


if ($countryCode == 'US') {

	$country = "UnitedStates";
	$countryCode = "US";
	$currencyCode = "USD";
	$storeId = '1';
} elseif ($countryCode == 'UK') {

	$country = "UnitedKingdom";
	$countryCode = "UK";
	$currencyCode = "GBP";
	$storeId = '11';
} elseif ($countryCode == 'IE') {
	$country = "Ireland";
	$countryCode = "IE";
	$currencyCode = "EUR";
	$storeId = '13';
} else {
	$country = "UnitedKingdom";
	$countryCode = "UK";
	$currencyCode = "GBP";
	$storeId = '11';
}

$startText = $country . ' ' . $feedName . ' feed generation started at ' . date('l jS \of F Y h:i:s A');
logMessage($startText);

$productFileName = $storeId . "_reevoo-feed.csv";

generateFeed($productFileName, $country, $currencyCode, $storeId, $countryCode, $feedName);

function generateFeeD($productFileName, $country, $currencyCode, $storeId, $countryCode, $feedName) {
	try {
		// get basic configuration
		$baseUrl = Mage::app()->getStore($storeId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
		$baseMediaUrl = Mage::getSingleton('catalog/product_media_config')->getBaseMediaUrl();

		$singleProds = array();
		$assocProdIds = array();
		$allProdIds = array();

		//getting configurable products.
		$products = Mage::getResourceModel('catalog/product_collection')
		->addAttributeToFilter('type_id', array('eq' => 'configurable'));
		$products->addAttributeToSelect('sku');
		$confProdIds = $products->getAllIds();
		
		//set the product inclusion criteria.
		$asseCheck = true;
		$openBoxCheck = true;
		$statusCheck = true;
		$visCheck = false;
		$upcommingCheck = false;

		foreach ($confProdIds as $productId) {
			$confproduct = Mage::getModel('catalog/product');
			$confproduct->setStore($storeId)->setStoreId($storeId)->load($productId);

			if($confproduct->getVisibility()!=4 || $confproduct->getStatus()!=1)
				continue;
			
			//$confProductUrl = $baseUrl .'index.php/checkout/cart/add?product='.$confProduct->getId().'&qty=1';
			$_product = new Mage_Catalog_Model_Product();
			$childIds = Mage::getModel('catalog/product_type_configurable')->getChildrenIds($productId);
			if(count($childIds[0]) > 0){
				foreach($childIds[0] as $childId){
					$include = checkProductInclude($storeId, $childId , $asseCheck, $openBoxCheck, $statusCheck, $visCheck, $upcommingCheck);

					if(!$include){
						$notIncluded[] = $childId;
						continue;
					}
					array_push($assocProdIds, $childId);
					
				}
					
			}
		}
		
		$visCheck = true;
		//getting simple products, not include those which are associated with configurable.
		$products = Mage::getModel('catalog/product')->getCollection();
		$products->addAttributeToFilter('type_id', array('eq' => 'simple'));
		$products->addAttributeToSelect('sku');
		//$products->addAttributeToFilter('id', array('nin' => array(1636,1639,1640)));
		$prodIds = $products->getAllIds();

		foreach ($prodIds as $prodId) {
			
			$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
      				->getParentIdsByChild($prodId);
      				
      		if(isset($parentIds[0])){
      			continue;
      		}
			
			$include = checkProductInclude($storeId, $prodId , $asseCheck, $openBoxCheck, $statusCheck, $visCheck, $upcommingCheck);
				
			if(!$include){
				$notIncluded[] = $prodId;
				continue;
			}
				
			/*if(in_array($prodId, $assocProdIds)){
				continue;
			}*/
			array_push($singleProds, $prodId);
		}
		$allProdIds = array_merge($singleProds, $assocProdIds);
		foreach($allProdIds as $productId){
			// load product object
			$product = Mage::getModel('catalog/product');
			$product->setStore($storeId)->setStoreId($storeId)->load($productId);
			
			$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
      		->getParentIdsByChild($productId);
			
      		// get product url
      		if(isset($parentIds[0])){
      			$parentProd = Mage::getModel('catalog/product');
				$parentProd->setStore($storeId)->setStoreId($storeId)->load($parentIds[0][0]);
				$productUrl = $parentProd->getProductUrl();
      		}else{
				$productUrl = $product->getProductUrl();
      		}
			
			$pos = strpos($productUrl, "?");
			$url = substr($productUrl, 0, $pos);
			if(empty($url)):
			$url = $productUrl;
			endif;

			$product_data = array();
			$skus = getSkus($product);
			
			$urlStatus = testUrl($url , $productId);

			if ($urlStatus == true) {
				$totalPrice = 0;
				$totalPrice = $product->getPrice();
				$currentDate = strtotime(date('Y-m-d'));
				$specialPrice = $product->getSpecialPrice();
				if ($specialPrice) {

					$specialPriceFrom = strtotime($product->getSpecialFromDate());

					if (!empty($specialPrice)) {
						$specialPriceTo = strtotime($product->getSpecialToDate());
					} else {
						$specialPriceTo = strtotime(date('Y-m-d', strtotime('+1 Week')));
					}

					if (($specialPriceFrom <= $currentDate) && (isset($specialPriceTo) && $specialPriceTo >= $specialPriceTo)) {
						if ($specialPrice > $totalPrice) {
							$feedErrors .= "!!! SPECIAL price is greater then item price. Product included in feed => " . getTitle($product) . " - SKU=> " . $product->getSku() . " ID=>" . $productId . " URL=>" . $url . '?cy=' . $currencyCode . '&affiliate=googleapps&utm_source=google&utm_medium=feed&utm_campaign=shopping' . " \r\n";
						}
						$totalPrice = $specialPrice;
					}
				}

				// Tax Rate finder
				$store = Mage::app()->getStore($storeId);
				$request = Mage::getSingleton('tax/calculation')->getRateRequest(null, null, null, $store);
				$taxclassid = $product->getData('tax_class_id');
				$percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($taxclassid));

				if($storeId==1) {
					$percent = 0;
				}


				if ($skus) {
					$x = 0;
					$ean = 0;
					$ean_counter = 0;

					$mpn = 0;
					$mpn_counter = 0;
					foreach ($skus['sku'] as $customSku) {

						$upc_arr = explode(",", $product->getUpc());

						if (count($upc_arr) > 1) {
							$ean = $upc_arr[$ean_counter];
							$ean_counter++;
						} else {
							$ean = $upc_arr[0];
						}

						$mpn_arr = explode(",", $product->getGoogleProductMpn());
						if (count($mpn_arr) > 1) {
							$mpn = $mpn_arr[$mpn_counter];
							$mpn_counter++;
						} else {
							$mpn = $mpn_arr[0];
						}

						if (!empty($mpn) && !empty($ean)) {
							$product_data['name'] = $skus['title'][$x];
							$product_data['manufacturer'] = $product->getAttributeText('manufacturer');
							$product_data['model'] = $product->getModel();
							$product_data['mpn'] = trim($mpn);
							//$product_data['ean']           = '"'.$product->getUpc().'"';
							$product_data['ean'] = trim($ean);
							$product_data['category'] = getCategory($product);
							$product_data['link'] = $url . '?affiliate=' . $feedName . '&utm_source=' . $feedName . '&utm_medium=feed&utm_campaign=shopping"';
							$product_data['image-URL'] = $baseMediaUrl . $product->getImage();
							$product_data['price'] = getPrice(($totalPrice + $skus['price'][$x]), $currencyCode,$percent);
							$product_data['delivery-cost'] = '';
							$product_data['availability'] = getReevoAvailability($product);
							$product_data['ProductId'] = $customSku;
							$product_data['Description'] = "'".getDescription($product)."'";

							$x++;
							//sanitize data
							foreach ($product_data as $k => $val) {
								$bad = array("\r\n", "\n", "\r", "\t", ";", ",");
							$good = array(" ", " ", "", "", "", "&#130;");
								$product_data[$k] = str_replace($bad, $good, $val);
							}
							$allProductData[$recordsCount] = $product_data;
							$recordsCount++;
						} else {
							$feedErrors .= "EAN or MPN missing in " . $skus['title'][$x] . " - SKU=> " . $customSku . " ID=>" . $productId . " \r\n";
							$x++;
						}
					}
				} else {
					$prUPC = $product->getUpc();
					$prMPN = $product->getGoogleProductMpn();
					if (!empty($prUPC) && !empty($prMPN)) {
						$product_data['name'] = getTitle($product);
						$product_data['manufacturer'] = $product->getAttributeText('manufacturer');
						$product_data['model'] = $product->getModel();/** @todo fetch product model number */
						$product_data['mpn'] = trim($product->getGoogleProductMpn());
						$product_data['ean'] = trim($product->getUpc());
						$product_data['category'] = getCategory($product);
						$product_data['link'] = $url . '?affiliate=reevoo&utm_source=reevoo&utm_medium=feed&utm_campaign=shopping"';
						$product_data['image-URL'] = $baseMediaUrl . $product->getImage();
						$product_data['price'] = getPrice($totalPrice, $currencyCode,$percent);
						$product_data['delivery-cost'] = '';
						$product_data['availability'] = getReevoAvailability($product);
						$product_data['ProductId'] = $product->getSku();
						$product_data['Description'] = "'".getDescription($product)."'";
						//sanitize data
						foreach ($product_data as $k => $val) {
							$bad = array("\r\n", "\n", "\r", "\t", ";", ",");
							$good = array(" ", " ", "", "", "", "&#130;");
							$product_data[$k] = str_replace($bad, $good, $val);
						}
						$allProductData[$recordsCount] = $product_data;
						$recordsCount++;
					} else {
						$feedErrors .= "EAN or MPN missing in " . getTitle($product) . " - SKU=> " . $product->getSku() . " ID=>" . $productId . " \r\n";
					}
				}
			} else {
				$feedErrors .= "URL is empty or no reponse in " . getTitle($product) . " - SKU=> " . $product->getSku() . " ID=>" . $productId . " URL=>" . $url . '?cy=' . $currencyCode . '&affiliate=googleapps&utm_source=google&utm_medium=feed&utm_campaign=shopping' . " \r\n";
			}
		}
		$productHeader = "name,manufacturer,model,mpn,ean,category,link,image url,price,delivery cost,availability,ProductId,Description";
		$productHeader = explode(",",$productHeader);
		
		$productFileHandle = fopen(SAVE_FEED_LOCATION . $productFileName, 'w');
		fputcsv($productFileHandle, $productHeader);
		foreach ($allProductData as $proudctcsv) {
			fputcsv($productFileHandle, $proudctcsv);
		}
		fclose($productFileHandle);
		$sourceProduct = SAVE_FEED_LOCATION . $productFileName;
		
		logMessage($prodText);
		logMessage($feedErrors);
		$endtime = microtime();
		$endText = $feedName . ' feed generation ended at : ' . date('l jS \of F Y h:i:s A');
		logMessage($endText);
		$summaryText = 'Time taken for feed generation: ' . ($endtime - $starttime) . ' seconds';
		$email = " $startText \r\n $endText \r\n $summaryText \r\n ";

		if ($feedErrors) {
			//email($email . $feedErrors, ': Errors in ' . $feedName . ' ' . $countryCode . ' Feed', array('babar ali' => 'babar@gsmnation.com', 'Sajid Ghani' => 'ghani@gsmnation.com', 'Asif Ejaz' => 'asif@gsmnation.com'));
			//email($feedErrors, ': Errors in Google ' . $countryCode . ' Feed', array('Asif Ejaz' => 'asif@gsmnation.com'));
		} else {
			//email($email, ': Success - ' . $feedName . ' ' . $countryCode . ' Feed');
		}
		logMessage($summaryText);
		$string = ob_get_clean();

		echo $recordsCount . " Products Exported to CSV.";
	} catch (Exception $e) {

		logMessage($e->getMessage());
		email($e->getMessage());
	}
}
