<?php
/**
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2015 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Frenet extends CarrierModule
{
	protected $config_form = false;

    public  $id_carrier;

    private $html = '';
    private $postErrors = array();
    private $_moduleName = 'frenet';

    private $debug='no';

    private $minimum_height=2;
    private $minimum_width=11;
    private $minimum_length=16;
    private $minimum_weight=1;

    private $prazoEntrega = array();

    public function __construct()
    {
        $this->name = 'frenet';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Rafael Mancini';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->limited_countries = array('br');
		$this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Frenet');
        $this->description = $this->l('Frenet - Gateway de fretes');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('FRENET_SELLER_CEP'))
            $this->warning = $this->l('No CEP provided');

        if(Configuration::get('FRENET_DEBUG'))
            $this->debug = Configuration::get('FRENET_DEBUG');

    }

    function install() {

        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        if(!$this->maybeUpdateDatabase())
            return false;

        if (!parent::install()
            or !$this->registerHook('displayBeforeCarrier'))
            return false;

        if (!Configuration::hasKey('FRENET_SELLER_CEP')) {
            Configuration::updateValue('FRENET_SELLER_CEP', '');
        }

        if (!Configuration::hasKey('FRENET_TOKEN')) {
            Configuration::updateValue('FRENET_TOKEN', '');
        }

        if (!Configuration::hasKey('FRENET_DEBUG')) {
            Configuration::updateValue('FRENET_DEBUG', 'no');
        }

        return true;
    }

    //function used to upgrade the Carrier table
    private function maybeUpdateDatabase(){
        $ret = false;
        try
        {
            $sql = 'DESCRIBE '._DB_PREFIX_. 'carrier';
            $columns = Db::getInstance()->executeS($sql);
            $found = false;
            foreach($columns as $col){
                if($col['Field']=='cdfrenet'){
                    $found = true;
                    break;
                }
            }
            if(!$found){
                Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_. 'carrier' .'` ADD `cdfrenet` VARCHAR(64) NULL');
            }
            $ret = true;
        }
        catch (\Exception $e)
        {
            if ( 'yes' == $this->debug ) {
                $this->addLog(  var_dump($e->getMessage()));
            }
            $this->_errors[] = $this->l('Erro ao instalar transportadoras ' . var_dump($e->getMessage()));
        }

        return $ret;
    }

    public function uninstall()
    {
        if (!parent::uninstall()
            or !Configuration::deleteByName('FRENET_SELLER_CEP')
            or !Configuration::deleteByName('FRENET_TOKEN')
            or !Configuration::deleteByName('FRENET_DEBUG')
            or !$this->unregisterHook('displayBeforeCarrier'))
            return false;

        // Exclui transportadoras
        $this->uninstallCarriers();

        return true;
    }

    private function uninstallCarriers()
    {
        try
        {
            // Exclui as tabelas
            $sql = "delete from "._DB_PREFIX_."carrier where cdfrenet > '' ;";
            Db::getInstance()->execute($sql);
        }
        catch (\Exception $e)
        {
            if ( 'yes' == $this->debug ) {
                $this->addLog(  var_dump($e->getMessage()));
            }
        }
    }

    public function installCarriers()
    {

        // Gets the WebServices response.
        $token = Configuration::get('FRENET_TOKEN');
        $service_url = 'http://api.frenet.com.br/v1/Shipping/GetShippingServicesAvailable?token=' . $token;

        if ( 'yes' == $this->debug ) {
            $this->addLog( "installCarriers: " . $service_url);
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $service_url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $curl_response = curl_exec($curl);
        curl_close($curl);

        $response = json_decode($curl_response);

        if ( 'yes' == $this->debug ) {
            $this->addLog( "GetShippingServicesAvailable: " . $curl_response);
        }

        if ( isset( $response->ShippingSeviceAvailableArray ) ) {
            if(count($response->ShippingSeviceAvailableArray)==1)
                $servicosArray[0] = $response->ShippingSeviceAvailableArray;
            else
                $servicosArray = $response->ShippingSeviceAvailableArray;

            if(!empty($servicosArray))
            {
                $this->addLog( "installCarriers count: " . count($servicosArray));

                foreach($servicosArray as $servicos){

                    $serviceDescription = $servicos[0]->ServiceDescription;
                    $carrierCode = $servicos[0]->CarrierCode;                    
                    $code = (string) $servicos[0]->ServiceCode;
                    
                    if (!isset($servicos[0]->ServiceCode) || $servicos[0]->ServiceCode . '' == '') {
                        $this->addLog( "installCarriers code: " . $code . "; description: " . $serviceDescription . " carrier: " . $carrierCode);
                        continue;
                    }

                    if ( 'yes' == $this->debug ) {
                        $this->addLog( "code: " . $code);
                        $this->addLog( "serviceDescription: " . $serviceDescription);
                    }

                    $config = array(
                        'name' => $serviceDescription,
                        'id_tax_rules_group' => 0,
                        'active' => true,
                        'deleted' => 0,
                        'shipping_handling' => false,
                        'range_behavior' => 0,
                        'delay' => array("br" => "Prazo de Entrega"),
                        'id_zone' => 1,
                        'is_module' => true,
                        'shipping_external' => true,
                        'cdfrenet' => $this->_moduleName . "_" . $code,
                        'carrierCode' => $carrierCode,
                        'external_module_name' => $this->_moduleName,
                        'need_range' => true
                    );
                    try {                        
                        $id_carrier = $this->installExternalCarrier($config, $this);                        
                    }
                    catch(\Exception $e) {
                        $this->addLog( "error_config: " . var_dump($config) );
                        
                        $this->addLog( "installCarriers_exception: " . $e->getMessage() );
                    }
                    
                }// fim do foreach
            }
        }

    }

    public static function installExternalCarrier($config, $frenet)
    {
        $sqlCmd = "[Debug]";
        $carrier = new Carrier();
        $carrier->name = $config['name'];
        $carrier->id_tax_rules_group = $config['id_tax_rules_group'];
        $carrier->id_zone = $config['id_zone'];
        $carrier->active = $config['active'];
        $carrier->deleted = $config['deleted'];
        $carrier->delay = $config['delay'];
        $carrier->shipping_handling = $config['shipping_handling'];
        $carrier->range_behavior = $config['range_behavior'];
        $carrier->is_module = $config['is_module'];
        $carrier->shipping_external = $config['shipping_external'];
        $carrier->external_module_name = $config['external_module_name'];
        $carrier->cdfrenet = $config['cdfrenet'];
        $carrier->need_range = $config['need_range'];
        
        if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 01"); }
        
        $languages = Language::getLanguages(true);

        foreach ($languages as $language) {
            $langId = (int) $language['id_lang'];
            $carrier->delay[$langId] = $config['delay']['br'];
        }

        if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 02"); }

        if ($carrier->add())
        {
            $db = DB::getInstance();

            if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 03"); }

            Db::getInstance()->update('carrier', array('cdfrenet'=> $carrier->cdfrenet), 'id_carrier = '.(int)($carrier->id) );

            if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 04"); }

            $groups = Group::getGroups(true);

            foreach ($groups as $group) {
                
                try {
                    if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 05 id before:" . $sqlCmd); }
                    $rs = Db::getInstance()->insert('carrier_group', array('id_carrier' => (int)($carrier->id), 'id_group' => (int)($group['id_group'])) );
                    if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 05A id after:" . $rs ); }
                    if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 05B id after:" . $carrier->id . "; group: " . $group['id_group'] ); }
                }
                catch(\Exception $e) { 
                    if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 05 exception: " . var_dump($e->getMessage() ) ); }
                }
                
            }
            
            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '999999';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '999999';
            $rangeWeight->add();

            $zones = Zone::getZones(true);
            foreach ($zones as $zone)
            {
                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 06 id before:" . $carrier->id); }
                Db::getInstance()->insert('carrier_zone', array('id_carrier' => (int)($carrier->id), 'id_zone' => (int)($zone['id_zone'])) );
                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 06 id after:" . $carrier->id); }
                
                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 07 id before:" . $carrier->id); }
                Db::getInstance()->insert('delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => (int)($rangePrice->id), 'id_range_weight' => NULL, 'id_zone' => (int)($zone['id_zone']), 'price' => '0') );
                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 07 id after:" . $carrier->id); }

                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 08 id before:" . $carrier->id); }
                Db::getInstance()->insert('delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => NULL, 'id_range_weight' => (int)($rangeWeight->id), 'id_zone' => (int)($zone['id_zone']), 'price' => '0') );
                if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 08 id after:" . $carrier->id); }
            }

            if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 09"); }

            Configuration::updateValue('FRENET_CARRIER_ID', (int)$carrier->id);

            if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 10"); }

            // Copy Logo
            //if (!copy(dirname(__FILE__).'/logo.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg'))
            //    return false;
            if(isset($config['carrierCode']))
                copy('https://s3-sa-east-1.amazonaws.com/painel.frenet.com.br/Content/images/' . $config['carrierCode'] . '.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg');

            if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 11"); }
            // Return ID Carrier
            return (int)($carrier->id);
        }

        if (isset($frenet) && 'yes' == $frenet->debug) { $frenet->addLog("installExternalCarrier 12"); }
        return false;
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name))
        {
            $seller_cep = strval(Tools::getValue('FRENET_SELLER_CEP'));
            if (!$seller_cep
                || empty($seller_cep)
                || !Validate::isGenericName($seller_cep))
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            else
            {
                Configuration::updateValue('FRENET_SELLER_CEP', $seller_cep);
            }

            $debug = strval(Tools::getValue('FRENET_DEBUG'));
            if (!$debug
                || empty($debug)
                || !Validate::isGenericName($debug))
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            else
            {
                Configuration::updateValue('FRENET_DEBUG', $debug);
            }

            $token = strval(Tools::getValue('FRENET_TOKEN'));
            if (!$token
                || empty($token)
                || !Validate::isGenericName($token))
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            else
            {
                Configuration::updateValue('FRENET_TOKEN', $token);
                $this->uninstallCarriers();
                $this->installCarriers();
            }

            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $options = array(
            array(
                'id_option' => 'yes',
                'name' => $this->l('yes')
            ),
            array(
                'id_option' => 'no',
                'name' => $this->l('no')
            ),
        );

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('CEP origem'),
                    'name' => 'FRENET_SELLER_CEP',
                    'size' => 9,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Token de acesso'),
                    'name' => 'FRENET_TOKEN',
                    'size' => 15,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Debug'),
                    'name' => 'FRENET_DEBUG',
                    'required' => true,
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                        '&token='.Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['FRENET_SELLER_CEP'] = Configuration::get('FRENET_SELLER_CEP');
        $helper->fields_value['FRENET_DEBUG'] = Configuration::get('FRENET_DEBUG');
        $helper->fields_value['FRENET_TOKEN'] = Configuration::get('FRENET_TOKEN');

        return $helper->generateForm($fields_form);
    }

    /**
     *
     * @param type $params
     * @param type $shipping_cost
     * @return boolean
     */
    public function getOrderShippingCost($params, $shipping_cost) {
        $address = new Address($params->id_address_delivery);

        $sCepDestino = preg_replace("/([^0-9])/", "", $address->postcode);
        $nVlPeso= (string) $params->getTotalWeight();


        $carrier = new Carrier((int)$this->id_carrier);
        $cdfrenet = $carrier->cdfrenet;
        $cdfrenet = str_replace("frenet_","",$cdfrenet);

        if ( 'yes' == $this->debug ) {
            $this->addLog( "Código Frenet: " . $carrier->cdfrenet);
        }

        $values = $this->frenet_calculate_json($params, $cdfrenet);

        // prazo de entrega
        if(isset($values['deliveryTime']))
            $this->prazoEntrega[$this->id_carrier] = $values['deliveryTime'];

        $custoFrete=-1;
        if(isset($values['shippingPrice']))
            $custoFrete = (float)$values['shippingPrice'];

        if ($custoFrete === false || $custoFrete === -1)
            return false;

        return $custoFrete + $shipping_cost;
    }

    /**
     *
     * @param type $params
     * @return type
     */
    public function getOrderShippingCostExternal($params) {
        return $this->getOrderShippingCost($params, 0);
    }

    /**
     * Carregar o código do cupom se não existir retornar nulo
     * @param type $cartRules
     * @return type codigo do cupom 
     */
    protected function getCoupon($cartRules) {
        $coupon = null;
        if (count($cartRules) > 0 &&  in_array( "code", array_keys( $cartRules[0] ) ) ) {
            $coupon = $cartRules[0]["code"];
        }
        return $coupon;
    }

    protected function frenet_calculate_json( $params, $cdfrenet ){
        $shippingPrice = -1;
        $values = array();
        try
        {
            $RecipientCEP = '';
            $RecipientCountry='BR';
            
            $Coupon = $this->getCoupon($params->getCartRules());

            // Se o cliente esta logado
            if ($this->context->customer->isLogged()) {

                $address = new Address($params->id_address_delivery);

                // Recupera CEP destino
                if ($address->postcode) {
                    $RecipientCEP = $address->postcode;
                    $RecipientCountry = $address->country;
                }
            }

            // Pedidos efetuados via Admin
            if (!$RecipientCEP) {
                $address = new Address($params->id_address_delivery);

                // Ignora Carrier se não existir CEP
                if (!$address->postcode) {
                    return false;
                }

                $RecipientCEP = $address->postcode;
            }

            // Valida CEP
            $RecipientCEP = trim(preg_replace("/[^0-9]/", "", $RecipientCEP));
            if (strlen($RecipientCEP) <> 8) {
                return false;
            }

            // Checks if services and zipcode is empty.
            if (empty( $RecipientCEP ) && $RecipientCountry=='BR')
            {
                if ( 'yes' == $this->debug ) {
                    $this->addLog( "ERRO: CEP destino não informado");
                }
                return $values;
            }

            // product array
            $shippingItemArray = array();
            $count = 0;

            foreach ($params->getProducts() as $product) {
                // virtual product
                if ($product['is_virtual'] == 1) {
                    continue;
                }

                $qty = $product['quantity'];

                if ( 'yes' == $this->debug ) {
                    // $this->addLog(  'Product: ' . print_r($product, true));
                }

                $shippingItem = new stdClass();

                if ( $qty > 0  ) {
                    $_height = $product['height'];
                    $_width  = $product['width'];
                    $_length = $product['depth'];
                    $_weight = $product['weight'];

                    if(empty($_height) || $_height < $this->minimum_height)
                        $_height= $this->minimum_height;

                    if(empty($_width) || $_width < $this->minimum_width)
                        $_width= $this->minimum_width;

                    if(empty($_length) || $_length < $this->minimum_length)
                        $_length = $this->minimum_length;

                    if(empty($_weight) || $_weight < $this->minimum_weight)
                        $_weight = $this->minimum_weight;

                    $shippingItem->Weight = $_weight ;
                    $shippingItem->Length = $_length;
                    $shippingItem->Height = $_height;
                    $shippingItem->Width = $_width;
					$shippingItem->Quantity = $qty;
                    $shippingItem->Diameter = 0;
                    $shippingItem->SKU = $product['reference'];

                    $category = new Category((int)$product['id_category_default'], (int)$this->context->language->id);

                    $shippingItem->Category = $category->name;
                    $shippingItem->isFragile=false;

                    if ( 'yes' == $this->debug ) {
                        $this->addLog(  'shippingItem: ' . print_r($shippingItem, true));
                    }

                    $shippingItemArray[$count] = $shippingItem;

                    $count++;
                }
            }

            if (isset($this->context->cart)) {
                $shipmentInvoiceValue = $this->context->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);
            }else {
                //via Admin
                $cart = new cart($params->id);
                $shipmentInvoiceValue = $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);
            }

            $service_param = array (
                'Token' => Configuration::get('FRENET_TOKEN'),
                'Coupom' => $Coupon,
                'PlatformName' => 'Prestashop',// Identificar que está foi uma chamada do prestashop
                'PlatformVersion' => _PS_VERSION_,// Identificar que está foi uma chamada do prestashop
                'SellerCEP' => $cepOrigem = trim(preg_replace("/[^0-9]/", "", Configuration::get('FRENET_SELLER_CEP'))),
                'RecipientCEP' => $RecipientCEP,
                'RecipientDocument' => '',
                'ShipmentInvoiceValue' => $shipmentInvoiceValue,
                'ShippingItemArray' => $shippingItemArray,
                'RecipientCountry' => $RecipientCountry,
                'ShippingServiceCode' => $cdfrenet
            );

            // Gets the WebServices response.
            $service_url = 'http://api.frenet.com.br/v1/Shipping/GetShippingQuote?data=';
            $data_string = json_encode($service_param);
            $service_url = $service_url . urlencode($data_string);
            if ( 'yes' == $this->debug ) {
                $this->addLog(  'url: ' . $service_url);
            }

            $curl = curl_init();

            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_URL, $service_url);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $curl_response = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($curl_response);

            if ( 'yes' == $this->debug ) {
                $this->addLog( "GetShippingQuote: " . $curl_response);
            }

            if ( isset( $response->ShippingSevicesArray ) ) {
                if(count($response->ShippingSevicesArray)==1)
                    $servicosArray[0] = $response->ShippingSevicesArray;
                else
                    $servicosArray = $response->ShippingSevicesArray;

                if(!empty($servicosArray))
                {
                    foreach($servicosArray as $servicos){

                        if ( 'yes' == $this->debug ) {
                            $this->addLog(  'Percorrendo os serviços retornados');
                        }

                        if (!is_array($servicos) || !isset($servicos[0]->ServiceCode) || $servicos[0]->ServiceCode . '' == '' || !isset($servicos[0]->ShippingPrice)) {
                            continue;
                        }

                        $deliveryTime=0;
                        if(isset($shipping->ServiceDescription) )
                            $label=$shipping->ServiceDescription;

                        if (isset($servicos[0]->DeliveryTime))
                            $deliveryTime=$servicos[0]->DeliveryTime;

                        $shippingPrice = $servicos[0]->ShippingPrice;

                        $values = array(
                            'deliveryTime'  => $deliveryTime,
                            'shippingPrice'    => $shippingPrice,
                        );

                        break;
                    }
                }
            }
        }
        catch (\Exception $e)
        {
            if ( 'yes' == $this->debug ) {
                $this->addLog(  var_dump($e->getMessage()));
            }
        }

        return $values;

    }

    /**
     * Record a debug message to a file inside of PrestaShop's default
     * log dir, if it is writable
     *
     * @param $message
     * @return bool
     */
    private function addLog($message)
    {
        $rootDir = _PS_ROOT_DIR_;

        if (is_writable($rootDir . '/log/')) {
            $rootDir .='/log/';
        } else if (is_writable($rootDir . '/var/logs/')) {
            $rootDir .='/var/logs/';
        } else {
            $this->context->controller->warnings[] = 'O diretório de logs do PrestaShop não possui permissão de escrita. A função debug permanecerá desativada.';

            return false;
        }
        $logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
        $logger->setFilename($rootDir . 'frenet.log');
        $logger->logDebug($message);
    }

    private function fix_format( $value ) {
        $value = str_replace( ',', '.', $value );

        return $value;
    }

    public function hookDisplayBeforeCarrier($params) {
        if (null == $this->context->cart->getDeliveryOptionList()) {
            return;
        }

        $delivery_option_list = $this->context->cart->getDeliveryOptionList();

        foreach($delivery_option_list as $id_address => $carrier_list_raw) {
            foreach($carrier_list_raw as $key => $carrier_list) {
                foreach($carrier_list['carrier_list'] as $id_carrier => $carrier) {

                    $msg = "não disponível";
                    if (isset($this->prazoEntrega[$carrier['instance']->id])) {
                        if (is_numeric($this->prazoEntrega[$carrier['instance']->id])) {

                            if ($this->prazoEntrega[$carrier['instance']->id] == 0) {
                                $msg = $this->l('entrega no mesmo dia');
                            }else {
                                if ($this->prazoEntrega[$carrier['instance']->id] > 1) {
                                    $msg = 'entrega em até '.$this->prazoEntrega[$carrier['instance']->id].$this->l(' dias úteis');
                                }else {
                                    $msg = 'entrega em '.$this->prazoEntrega[$carrier['instance']->id].$this->l(' dia útil');
                                }
                            }
                        }else {
                            $msg = $this->prazoEntrega[$carrier['instance']->id];
                        }
                    }

                    if ( 'yes' == $this->debug ) {
                        $this->addLog("PRAZO: " . $msg );
                    }

                    $carrier['instance']->delay[$this->context->cart->id_lang] = $msg;

                }
            }
        }
    }

}

?>
