<?php
/*
*	Over-ride the order dashbord Block in cms to show the tracking status of shipping
*/
class Mymodule_Simplypost_Block_Adminhtml_Sales_Order_View_Info_Block extends Mage_Core_Block_Template {
    
    protected $order;
    
    public function getOrder() {
        if (is_null($this->order)) {
            if (Mage::registry('current_order')) {
                $order = Mage::registry('current_order');
            }
            elseif (Mage::registry('order')) {
                $order = Mage::registry('order');
            }
            else {
                $order = new Varien_Object();
            }
            $this->order = $order;
        }
        return $this->order;
    }
    
    public function getSimplypostOrderStatus($order){
        
        if($order){
            
            $orderId = ($order->getId()) ? $order->getId() : null;
            
            //get the last shipment id
            if($order->getShipmentsCollection()) foreach ($order->getShipmentsCollection() as $shipment) {
                $shipmentId = ($shipment->getId()) ? $shipment->getId() : null;
            }
            
            if($shipmentId){
                //get the last tracking number
                $trackingNumbers = Mage::getResourceModel('sales/order_shipment_track_collection')->addAttributeToSelect('*')->addAttributeToFilter('parent_id',$shipmentId);
                $trackingNumbers->getSelect()->order('entity_id desc')->limit(1);
                if($trackingNumbers->getData()){
                    $trackData = $trackingNumbers->getData();
                    //track id
                    $trackID = ($trackData[0]['track_number']) ? $trackData[0]['track_number'] : null;
                }
                
                //check the tracking id is correct and serve from Simplypost
                //get the merchant code from system conf 'X0234'
                $spMerchantCode = (trim(Mage::getStoreConfig('simplypost/general/simplypost_merchant_code'))) ? trim(Mage::getStoreConfig('simplypost/general/simplypost_merchant_code')) : null;
                $pattern = '/^'.$spMerchantCode.'/';
                //check if tracking code matchng with merchant code
                if(!preg_match($pattern, $trackID)){
                    return false;
                }
                
                //SimplyPost create Auth Tocken and tracking status
                $auth = trim(Mage::getStoreConfig('simplypost/general/simplypost_login_token'));
                $clientLogin = new Zend_Http_Client();
                $clientLogin->setHeaders(array(
                    'Authorization' => $auth
                ));
                $clientLogin->setUri(trim(Mage::getStoreConfig('simplypost/general/simplypost_auth_endpoint')));
                $clientLogin->setMethod(Zend_Http_Client::POST);
                $responseLogin = $clientLogin->request();
                
                if ($responseLogin->isSuccessful()) {
                    $responseLogin = json_decode($responseLogin->getBody());
                    
                    //get tracking details
                    $url = 'https://app.simplypost.asia/api/gateway/v1/track/'.$trackID;
                    $client = new Zend_Http_Client();
                    $client->setHeaders(array(
                        'Content-Type' => 'application/json',
                        'Authorization' => $responseLogin->token
                    ));
                    
                    $client->setUri($url);
                    $client->setMethod(Zend_Http_Client::GET);
                    $response = $client->request();
                    if ($response->isSuccessful()) {
                        return json_decode($response->getBody());
                    }
                }
                else{
                    Mage::log($responseLogin);
                    $responseLoginBody = json_decode($responseLogin->getBody());
                    Mage::getSingleton('core/session')->addError('SimplyPost Failed: '.$responseLoginBody->error);
                    return false;    
                }
                
                
            }
            
        }
        
        return false;
    }
}
