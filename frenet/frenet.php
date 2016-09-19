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

    private $debug='yes';

    private $minimum_height=2;
    private $minimum_width=11;
    private $minimum_length=16;
    private $minimum_weight=1;

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
    }

    function install() {

        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        if(!$this->maybeUpdateDatabase())
            return false;

        $this->installCarriers();

        if (parent::install() == false or
            $this->registerHook('updateCarrier') == false or
            $this->registerHook('extraCarrier') == false or
            $this->registerHook('beforeCarrier') == false)
            return false;

        if (!Configuration::hasKey('FRENET_SELLER_CEP')) {
            Configuration::updateValue('FRENET_SELLER_CEP', '');
        }

        return true;
    }

    //function used to upgrade the Carrier table
    private function maybeUpdateDatabase(){
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
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('FRENET_SELLER_CEP'))
            return false;

        return true;
    }

    public function installCarriers()
    {
        // Gets the WebServices response.
        $token = 'E8BA248DR1E1FR4912R850CRC6255174690E';
        $service_url = 'http://api.frenet.com.br/v1/Shipping/GetShippingServicesAvailable?token=' . $token;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $service_url);
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
                foreach($servicosArray as $servicos){

                    if (!isset($servicos->ServiceCode) || $servicos->ServiceCode . '' == '') {
                        continue;
                    }

                    $code = (string) $servicos->ServiceCode;
                    $serviceDescription = $servicos->ServiceDescription;


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
                        'external_module_name' => $this->_moduleName,
                        'need_range' => true
                    );
                    $id_carrier = $this->installExternalCarrier($config);
                }
            }
        }

    }

    public static function installExternalCarrier($config)
    {
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

        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $carrier->delay[(int) $language['id_lang']] = $config['delay']['br'];
        }

        if ($carrier->add())
        {
            Db::getInstance()->update('carrier', array('cdfrenet'=> $carrier->cdfrenet), 'id_carrier = '.(int)($carrier->id) );

            $groups = Group::getGroups(true);
            foreach ($groups as $group)
                Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_group', array('id_carrier' => (int)($carrier->id), 'id_group' => (int)($group['id_group'])), 'INSERT');

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
                Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_zone', array('id_carrier' => (int)($carrier->id), 'id_zone' => (int)($zone['id_zone'])), 'INSERT');
                Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => (int)($rangePrice->id), 'id_range_weight' => NULL, 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
                Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => NULL, 'id_range_weight' => (int)($rangeWeight->id), 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
            }

            // Copy Logo
            if (!copy(dirname(__FILE__).'/logo.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg'))
                return false;

            Configuration::updateValue('FRENET_CARRIER_ID', (int)$carrier->id);

            // Return ID Carrier
            return (int)($carrier->id);
        }

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
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

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

        $custoFrete = $this->frenet_calculate_json($params, $cdfrenet);

        if ( 'yes' == $this->debug ) {
            $this->addLog( "CUSTO FRENET RETORNADO: " . $custoFrete);
        }


        if ($custoFrete === false || $custoFrete === 0.0)
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


    protected function frenet_calculate_json( $params, $cdfrenet ){
        $shippingPrice = 0;
        $values = array();
        try
        {
            $RecipientCEP = '';
            $RecipientCountry='BR';

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

                    $shippingItem->Weight = $_weight * $qty;
                    $shippingItem->Length = $_length;
                    $shippingItem->Height = $_height;
                    $shippingItem->Width = $_width;
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
                'Token' => 'E8BA248DR1E1FR4912R850CRC6255174690E',
                'SellerCEP' => $cepOrigem = trim(preg_replace("/[^0-9]/", "", Configuration::get('FRENET_SELLER_CEP'))),
                'RecipientCEP' => $RecipientCEP,
                'RecipientDocument' => '',
                'ShipmentInvoiceValue' => $shipmentInvoiceValue,
                'ShippingItemArray' => $shippingItemArray,
                'RecipientCountry' => $RecipientCountry,
                'ShippingServiceCode' => $cdfrenet
            );

            // Gets the WebServices response.
            $service_url = 'http://api.frenet.com.br/v1/Shipping/GetShippingQuote?data=' . json_encode($service_param);

            if ( 'yes' == $this->debug ) {
                $this->addLog(  'url: ' . $service_url);
            }

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $service_url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
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

                        if (!isset($servicos[0]->ServiceCode) || $servicos[0]->ServiceCode . '' == '' || !isset($servicos[0]->ShippingPrice)) {
                            continue;
                        }

                        /*** TODO ***/
                        $shippingPrice = $servicos[0]->ShippingPrice;
                        break;
                    }
                }
            }
        }
        catch (Exception $e)
        {
            if ( 'yes' == $this->debug ) {
                $this->addLog(  var_dump($e->getMessage()));
            }
        }

        if ( 'yes' == $this->debug ) {
            $this->addLog(  'VAI RETORNAR ' . $shippingPrice);
        }

        return (float)$shippingPrice;

    }

    private function addLog($message)
    {
        $logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
        $logger->setFilename(_PS_ROOT_DIR_."/log/debug.log");
        $logger->logDebug($message);
    }

    private function fix_format( $value ) {
        $value = str_replace( ',', '.', $value );

        return $value;
    }
}

?>
