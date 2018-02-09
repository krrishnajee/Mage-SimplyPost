<?php
/*
*	Observer Event : sales_order_shipment_save_before
*	This is the function which post data SimplyPost
*/
class Mymodule_Simplypost_Model_Observer {
    
    const XML_PATH_SWIFTVALUE_SENDER      = 'contacts/email/sender_email_identity';
    const XML_PATH_SWIFTVALUE_SENDER_EMAIL_TEMPLATE = 'simplypost/swiftvalue/swiftvalue_email_template'; //order Pickup email template, Make sure you created the mail template in transactional emails

    public function createSimplypost(Varien_Event_Observer $observer) {
        
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        
        //get simplyPost API congifurations
        $spEnable = trim(Mage::getStoreConfig('simplypost/general/enable'));
        $spAuthEndpoint = trim(Mage::getStoreConfig('simplypost/general/simplypost_auth_endpoint'));
        $spLoginToken = trim(Mage::getStoreConfig('simplypost/general/simplypost_login_token'));
        $spEndpoint = trim(Mage::getStoreConfig('simplypost/general/simplypost_endpoint'));
        $spMerchantCode = trim(Mage::getStoreConfig('simplypost/general/simplypost_merchant_code'));
        $spServiceCode = trim(Mage::getStoreConfig('simplypost/general/simplypost_service_code'));
        
        if($spEnable){
            
            //check all configurations exists
            if($spAuthEndpoint == null || $spLoginToken == null || $spEndpoint == null || $spMerchantCode == null || $spServiceCode == null){
                
                Mage::getSingleton('core/session')->addError('SimplyPost failed, Configurations Error.');
                //stop the process
                Mage::app()->getResponse()->setRedirect($_SERVER['HTTP_REFERER']);
                Mage::app()->getResponse()->sendResponse();
                exit;
            
            }
            else{
            
                // get all order items and make an array
                $orderitems = $order->getAllVisibleItems();
                $arrItem = array();
                $arrItems = array();
                foreach ($orderitems as $orderitem): 
                    $product = $orderitem->getProduct();
                    $arrItem['weight'] = ($product->getData('weight')) ? $product->getData('weight') : 0;
                    $arrItem['description'] = ($product->getData('short_description')) ? $product->getData('short_description') : 0;
                    $arrItem['weight_unit'] = "Kg";
                    $arrItems[] = $arrItem;
                    unset($arrItem);
                endforeach;
                
                /*
                 * Send data to Simplypost via Zend_Http_Client
                */
                //genarate new token, because this token is only valid for 1 hour
                $auth = $spLoginToken;
                $clientLogin = new Zend_Http_Client();
                $clientLogin->setHeaders(array(
                    'Authorization' => $auth
                ));
                $clientLogin->setUri($spAuthEndpoint);
                $clientLogin->setMethod(Zend_Http_Client::POST);
                $responseLogin = $clientLogin->request();
                if ($responseLogin->isSuccessful()) {
                    
                    $responseLogin = json_decode($responseLogin->getBody());
					//our new token
                    $token = "JWT ".$responseLogin->token;
                    
                    //end point post order details to simplypost
                    $url = $spEndpoint;
                    //obj Zend_Http_Client
                    $client = new Zend_Http_Client();
                    //set Authentication and other headers
                    $client->setHeaders(array(
                        'Content-Type' => 'application/json',
                        'Authorization' => $token
                    ));
                    //set endpoint url
                    $client->setUri($url);
        
                    //get order Pickup details from system configuration
                    $swName = trim(Mage::getStoreConfig('simplypost/swiftvalue/swiftvalue_contact_name'));
                    $swPhoneNumber = trim(Mage::getStoreConfig('simplypost/swiftvalue/swiftvalue_phone_number'));
                    $swEmail = trim(Mage::getStoreConfig('simplypost/swiftvalue/swiftvalue_email'));
                    $swAddress = trim(Mage::getStoreConfig('simplypost/swiftvalue/swiftvalue_address'));
                    $swPostcode = trim(Mage::getStoreConfig('simplypost/swiftvalue/swiftvalue_postcode'));
                    
                    //create post data into SimplyPost array format
                    $customerFName = ($order->getShippingAddress()->getData('firstname')) ? $order->getShippingAddress()->getData('firstname') : null;
                    $customerLName = ($order->getShippingAddress()->getData('lastname')) ? $order->getShippingAddress()->getData('lastname') : null;
                    $customerAddressStreet = ($order->getShippingAddress()->getData('street')) ? $order->getShippingAddress()->getData('street') : null;
                    $customerAddressCity = ($order->getShippingAddress()->getData('city')) ? $order->getShippingAddress()->getData('city') : null;
                    $customerAddressCountry = ($order->getShippingAddress()->getData('country_id')) ? Mage::app()->getLocale()->getCountryTranslation($order->getShippingAddress()->getData('country_id')) : null;
                    $data = array(
                        'merchant_code'  => $spMerchantCode, // copied from SimplyPost Mymodule account
                        'reference_number'   => ($order->getData('increment_id')) ? $order->getData('increment_id') : null, // order number
                        'order_source'   => 'mage_test', //random string
                        'service_code'   => $spServiceCode, //Delievery stype code
                        "pickup_details" => array( //Pickup details from admin cms
                            "contact_name" => ($swName) ? $swName : null,
                            "phone_number" => ($swPhoneNumber) ? $swPhoneNumber : null,
                            "email" => ($swEmail) ? $swEmail : null,
                            "address" => ($swAddress) ? $swAddress : null,
                            "postcode" => ($swPostcode) ? $swPostcode : null,
                            ),
                        'consignee_details' => array( //Customer details
                            "contact_name" => $customerFName." ".$customerLName,
                            "phone_number" => ($order->getShippingAddress()->getData('telephone')) ? $order->getShippingAddress()->getData('telephone') : null,
                            "email" => ($order->getShippingAddress()->getData('email')) ? $order->getShippingAddress()->getData('email') : null,
                            "address" => $customerAddressStreet." ".$customerAddressCity." ".$customerAddressCountry,
                            "postcode" => ($order->getShippingAddress()->getData('postcode')) ? $order->getShippingAddress()->getData('postcode') : null,
                            ),
                        'item_details' => $arrItems,
                    );
                    //set the parameters
                    $client->setParameterPost($data);
                    
                    // POST request
                    $client->setMethod(Zend_Http_Client::POST);
                    $response = $client->request();
                    if ($response->isSuccessful()) {
                        //decode the response
                        $responseBody = json_decode($response->getBody());
						
                        // update the tracking code into shipment in our system
                        $shipment = $observer->getEvent()->getShipment();
                        $track = Mage::getModel('sales/order_shipment_track')
                            ->setNumber($responseBody->tracking_id) //tracking number / awb number
                            ->setCarrierCode('simplypost') //carrier code
                            ->setTitle('SimplyPost'); //carrier title
                        $shipment->addTrack($track);
                        Mage::getSingleton('core/session')->addSuccess('Order added to the SimplyPost.');
                        
                        //send an email to Swiftvalue(Pickup email ID)
                        $translate = Mage::getSingleton('core/translate');
                        /* @var $translate Mage_Core_Model_Translate */
                        $translate->setTranslateInline(false);
                        $mailTemplate = Mage::getModel('core/email_template');
                        /* @var $mailTemplate Mage_Core_Model_Email_Template */
                        $mailTemplate->setDesignConfig(array('area' => 'frontend'))
                            ->setReplyTo(Mage::getStoreConfig(self::XML_PATH_SWIFTVALUE_SENDER))
                            ->sendTransactional(
                                Mage::getStoreConfig(self::XML_PATH_SWIFTVALUE_SENDER_EMAIL_TEMPLATE),
                                Mage::getStoreConfig(self::XML_PATH_SWIFTVALUE_SENDER),
                                $swEmail,
                                null,
                                array('data' => $swName)
                            );
                
                        if (!$mailTemplate->getSentSuccess()) {
                            throw new Exception();
                            Mage::getSingleton('core/session')->addError('Swiftvalue Email Failed.');
                        }
                        
                        $translate->setTranslateInline(true); 
                        
                    }
                    else{
                        //log error
                        Mage::log($response);
                        $responseBody = json_decode($response->getBody());
                        Mage::getSingleton('core/session')->addError('SimplyPost Failed: '.$responseBody->error->message);
                        //stop the process
                        Mage::app()->getResponse()->setRedirect($_SERVER['HTTP_REFERER']);
                        Mage::app()->getResponse()->sendResponse();
                        exit;
                    }
                }
                else{
                    //log error
                    Mage::log($responseLogin);
                    $responseLogin = json_decode($responseLogin->getBody());
                    Mage::getSingleton('core/session')->addError('SimplyPost Authentication Failed: '.$responseLogin->errors);
                    //stop the process
                    Mage::app()->getResponse()->setRedirect($_SERVER['HTTP_REFERER']);
                    Mage::app()->getResponse()->sendResponse();
                    exit;
                }
            }
        }
        
        
    }
    
    // This function is called on core_block_abstract_to_html_after event
    // We will append our block to the html in the adminhtml under order page
    public function getSalesOrderViewInfo(Varien_Event_Observer $observer) {
        $block = $observer->getBlock();
        // layout name should be same as used in app/design/adminhtml/default/default/layout/mymodule.xml
        if (($block->getNameInLayout() == 'order_info') && ($child = $block->getChild('simplypost.order.info.custom.block'))) {
            $transport = $observer->getTransport();
            if ($transport) {
                $html = $transport->getHtml();
                $html .= $child->toHtml();
                $transport->setHtml($html);
            }
        }
    }

}