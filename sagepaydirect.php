<?php
/**
 * Plugin Name: PatSaTECH's Opayo Direct Gateway for WooCommerce
 * Plugin URI: http://www.patsatech.com/
 * Description: WooCommerce Plugin for accepting payment through Opayo Direct Gateway.
 * Version: 1.3.1
 * Author: PatSaTECH
 * Author URI: http://www.patsatech.com
 * Contributors: patsatech
 * Requires at least: 6.0
 * Tested up to: 6.4.3
 * WC requires at least: 6.0.0
 * WC tested up to: 8.2.2
 *
 * Text Domain: woo-sagepay-patsatech
 * Domain Path: /lang/
 *
 * @package Opayo Direct Gateway for WooCommerce
 * @author PatSaTECH
 */

add_action('plugins_loaded', 'init_woocommerce_sagepaydirect', 0);

function init_woocommerce_sagepaydirect()
{
    if (! class_exists('WC_Payment_Gateway_CC')) {
        return;
    }

    load_plugin_textdomain('woo-sagepay-patsatech', false, dirname(plugin_basename(__FILE__)) . '/lang');

    class woocommerce_sagepaydirect extends WC_Payment_Gateway_CC
    {
        public function __construct()
        {
            global $woocommerce;

            $this->id           = 'sagepaydirect';
            $this->method_title = __('Opayo Direct', 'woo-sagepay-patsatech');
            $this->icon         = apply_filters('woocommerce_sagepaydirect_icon', '');
            $this->has_fields   = true;
            $this->notify_url   = add_query_arg('wc-api', 'woocommerce_sagepaydirect', home_url('/'));

            $this->supports  = array(
              'products',
              'default_credit_card_form',
              'pre-orders'
            );

            $default_card_type_options = array(
            'MC' => 'MasterCard',
            'VISA' => 'VISA Credit',
            'DELTA' => 'VISA Debit',
            'UKE' => 'VISA Electron',
            'MAESTRO' => 'Maestro (Switch)',
            'AMEX' => 'American Express',
            'DC' => 'Diner\'s Club',
            'JCB' => 'JCB Card',
            'LASER' => 'Laser',
            );
            $this->card_type_options = apply_filters('woocommerce_sagepaydirect_card_types', $default_card_type_options);

            // load form fields
            $this->init_form_fields();

            // initialise settings
            $this->init_settings();

            // variables
            $this->title            = $this->get_option('title');
            $this->description      = $this->get_option('description');
            $this->vendor_name      = $this->get_option('vendorname');
            $this->mode             = $this->get_option('mode');
            $this->transtype        = $this->get_option('transtype');
            $this->send_shipping    = $this->get_option('send_shipping');
            $this->cardtypes        = $this->get_option('cardtypes');
            $this->auth_window_size = $this->get_option('auth_window_size');

            if( $this->auth_window_size == '' ){
              $this->auth_window_size = '03';
            }

            // actions
            add_action('init', array( $this, 'successful_request' ));
            add_action('woocommerce_api_woocommerce_sagepaydirect', array( $this, 'successful_request' ));
            add_action('woocommerce_receipt_sagepaydirect', array( $this, 'receipt_page' ));
            add_action('woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            add_filter('woocommerce_credit_card_form_fields', array( $this, 'sagepaydirect_fields' ), 10, 2);
        }

        public function sagepaydirect_fields($args, $payment_id)
        {
            if ($payment_id == $this->id) {

                $cardtypes = array(
                  'MC' => 'MasterCard',
                  'VISA' => 'VISA Credit',
                  'DELTA' => 'VISA Debit',
                  'UKE' => 'VISA Electron',
                  'MAESTRO' => 'Maestro (Switch)',
                  'AMEX' => 'American Express',
                  'DC' => 'Diner\'s Club',
                  'JCB' => 'JCB Card',
                  'LASER' => 'Laser'
                );
                $cards = '';
                foreach ($this->cardtypes as $value) {
                    $cards .= '<option value="'.$value.'">'.$cardtypes[$value].'</option>';
                }

                $args = array_merge(
                  array(
                    'card-type' => '<p class="form-row" style="width:200px;">
                              <label>' . __('Card Type', 'woo-sagepaydirect-patsatech') . ' <span class="required">*</span></label>
                    	        <select name="' . esc_attr($payment_id) . '-card-type">'.$cards.'</select>
                    			</p>',
                    'card-name' => '<p class="form-row form-row-wide">
                              <label for="' . esc_attr($payment_id) . '-card-name">' . __('Card Holder Name', 'woo-sagepaydirect-patsatech') . ' <span class="required">*</span></label>
                              <input id="' . esc_attr($payment_id) . '-card-name" class="input-text wc-credit-card-form-card-name" type="text" maxlength="20" autocomplete="off" placeholder="Name on Card" ' . $this->field_name('card-name') . ' />
                          </p>',
                    ),
                    $args
                  );
            }
            return $args;
        }

        /**
     * get_icon function.
     *
     * @access public
     * @return string
     */
        public function get_icon()
        {
            global $woocommerce;

            $icon = '';
            if ($this->icon) {
                // default behavior
                $icon = '<img src="' . $this->force_ssl($this->icon) . '" alt="' . $this->title . '" />';
            } elseif ($this->cardtypes) {
                // display icons for the selected card types
                $icon = '';
                foreach ($this->cardtypes as $cardtype) {
                    if (file_exists(plugin_dir_path(__FILE__) . '/images/card-' . strtolower($cardtype) . '.png')) {
                        $icon .= '<img src="' . $this->force_ssl(plugins_url('/images/card-' . strtolower($cardtype) . '.png', __FILE__)) . '" alt="' . strtolower($cardtype) . '" />';
                    }
                }
            }
            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        /**
        * Admin Panel Options
        **/
        public function admin_options()
        {
            ?>
              <h3><?php _e('Opayo Direct', 'woo-sagepay-patsatech'); ?></h3>
              <p><?php _e('Opayo Direct works by processing Credit Cards on site. So users do not leave your site to enter their payment information.', 'woo-sagepay-patsatech'); ?></p>
              <table class="form-table">
              <?php
              // Generate the HTML For the settings form.
              $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
        * Initialise Gateway Settings Form Fields
        */
        public function init_form_fields()
        {
            //  array to generate admin form
            $this->form_fields = array(
              'enabled' => array(
                'title' => __('Enable/Disable', 'woo-sagepay-patsatech'),
                'type' => 'checkbox',
                'label' => __('Enable Opayo Direct', 'woo-sagepay-patsatech'),
                'default' => 'yes',
                'desc_tip'    => true
              ),
              'title' => array(
                'title' => __('Title', 'woo-sagepay-patsatech'),
                'type' => 'text',
                'description' => __('This is the title displayed to the user during checkout.', 'woo-sagepay-patsatech'),
                'default' => __('Opayo Direct', 'woo-sagepay-patsatech'),
                'desc_tip'    => true
              ),
              'description' => array(
                'title' => __('Description', 'woo-sagepay-patsatech'),
                'type' => 'textarea',
                'description' => __('This is the description which the user sees during checkout.', 'woo-sagepay-patsatech'),
                'default' => __("Payment via Opayo, Please enter your credit or debit card below.", 'woo-sagepay-patsatech'),
                'desc_tip'    => true
              ),
              'vendorname' => array(
                'title' => __('Vendor Name', 'woo-sagepay-patsatech'),
                'type' => 'text',
                'description' => __('Please enter your vendor name provided by Opayo.', 'woo-sagepay-patsatech'),
                'default' => '',
                'desc_tip'    => true
              ),
              'mode' => array(
                'title' => __('Mode Type', 'woo-sagepay-patsatech'),
                'type' => 'select',
                'options' => array(
                  'test' => 'Test',
                  'live' => 'Live'
                ),
                'description' => __('Select Simulator, Test or Live modes.', 'woo-sagepay-patsatech'),
                'desc_tip'    => true
              ),
              'send_shipping' => array(
                'title' => __('Select Shipping Address', 'woo-sagepay-patsatech'),
                'type' => 'select',
                'options' => array(
                  'auto' => 'Auto',
                  'yes' => 'Billing Address'
                ),
                'description' => __('Select Auto if you want the plugin to decide which address to send based on type of Product. And select Billing Address if you want the plugin to send Billing Address irrespective of the type to Product.', 'woo-sagepay-patsatech'),
                'default' => 'auto',
                'desc_tip'    => true
              ),
              'transtype'    => array(
                'title' => __('Transition Type', 'woo-sagepay-patsatech'),
                'type' => 'select',
                'options' => array(
                  'PAYMENT' => __('Payment', 'woo-sagepay-patsatech'),
                  'DEFFERRED' => __('Deferred', 'woo-sagepay-patsatech'),
                  'AUTHENTICATE' => __('Authenticate', 'woo-sagepay-patsatech')
                ),
                'description' => __('Select Payment, Deferred or Authenticated.', 'woo-sagepay-patsatech'),
                'desc_tip'    => true
              ),
              'auth_window_size'    => array(
                'title' => __('Challenge Window Size', 'woo-sagepay-patsatech'),
                'type' => 'select',
                'options' => array(
                  '01' => __('250 x 400 pixels', 'woo-sagepay-patsatech'),
                  '02' => __('390 x 400 pixels', 'woo-sagepay-patsatech'),
                  '03' => __('500 x 600 pixels', 'woo-sagepay-patsatech'),
                  '04' => __('600 x 400 pixels', 'woo-sagepay-patsatech'),
                  '05' => __('Full screen', 'woo-sagepay-patsatech')
                ),
                'description' => __('Select Dimensions of the challenge window that is to be displayed to the cardholder.', 'woo-sagepay-patsatech'),
                'desc_tip'    => true
              ),
              'cardtypes'    => array(
                'title' => __('Accepted Card Types', 'woo-sagepay-patsatech'),
                'class' => 'wc-enhanced-select',
                'type' => 'multiselect',
                'description' => __('Select which card types to accept.', 'woo-sagepay-patsatech'),
                'default' => array('VISA'),
                'options' => $this->card_type_options,
                'desc_tip'    => true
              )
            );
        }

        /**
        * Payment fields for sagepay direct.
        **/
        public function payment_fields()
        {
            echo wpautop(wptexturize($this->description));
            $this->form();
        }

        /**
        * Validate payment fields
        */
        public function validate_fields()
        {
            global $woocommerce;

            if (empty($_POST['sagepaydirect-card-name'])) {
                wc_add_notice('<strong>Card Name</strong> ' . __('is a required field.', 'woo-sagepaydirect-patsatech'), 'error');
            }

            if (!$this->is_empty_credit_card($_POST['sagepaydirect-card-number'])) {
                wc_add_notice('<strong>Credit Card Number</strong> ' . __('is a required field.', 'woo-sagepaydirect-patsatech'), 'error');
            } elseif (!$this->is_valid_credit_card($_POST['sagepaydirect-card-number'])) {
                wc_add_notice('<strong>Credit Card Number</strong> ' . __('is not a valid credit card number.', 'woo-sagepaydirect-patsatech'), 'error');
            }

            if (!$this->is_empty_expire_date($_POST['sagepaydirect-card-expiry'])) {
                wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is a required field.', 'woo-sagepaydirect-patsatech'), 'error');
            } elseif (!$this->is_valid_expire_date($_POST['sagepaydirect-card-expiry'])) {
                wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is not a valid expiry date.', 'woo-sagepaydirect-patsatech'), 'error');
            }

            if (!$this->is_empty_ccv_nmber($_POST['sagepaydirect-card-cvc'])) {
                wc_add_notice('<strong>CCV Number</strong> ' . __('is a required field.', 'woo-sagepaydirect-patsatech'), 'error');
            }
        }

        /**
         * Generate the sagepaydirect button link
         **/
        public function generate_sagepaydirect_form($order_id)
        {
            global $woocommerce;

            $order = wc_get_order( $order_id );

            if( !empty( WC()->session->get('sagepay_pareq') ) ){
              $sagepaydirect_args = array(
                'PaReq'    => WC()->session->get('sagepay_pareq'),
                'MD'        => WC()->session->get('sagepay_md'),
                'TermUrl'    => $this->notify_url
              );
            }elseif( !empty( WC()->session->get('sagepay_creq') ) ){
              $sagepaydirect_args = array(
                'creq'                => WC()->session->get('sagepay_creq'),
                'threeDSSessionData'  => str_replace(array("{", "}"), "", WC()->session->get('sagepay_vpstxid')),
                'TermUrl'             => $this->notify_url
              );
            }



            $sagepaydirect_args_array = array();

            foreach ($sagepaydirect_args as $key => $value) {
                $sagepaydirect_args_array[] = '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />';
            }

            wc_enqueue_js('
      				jQuery("body").block({
                message: "<img src=\"'.esc_url($woocommerce->plugin_url()).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to verify your card.', 'woo-sagepay-patsatech').'",
      					overlayCSS:
                {
                  background: "#fff",
      						opacity: 0.6
                },
                css:
                {
                  padding:        20,
                  textAlign:      "center",
                  color:          "#555",
                  border:         "3px solid #aaa",
                  backgroundColor:"#fff",
                  cursor:         "wait",
                  lineHeight:		"32px"
                }
              });
              jQuery("#submit_sagepaydirect_payment_form").click();
            ');

                  return '<form action="'.esc_url(WC()->session->get('sagepay_acsurl')).'" method="post" id="sagepaydirect_payment_form">
            ' . implode('', $sagepaydirect_args_array) . '
            <input type="submit" class="button-alt" id="submit_sagepaydirect_payment_form" value="'.__('Submit', 'woo-sagepay-patsatech').'" /> <a class="button cancel" href="'.esc_url($order->get_cancel_order_url()).'">'.__('Cancel order &amp; restore cart', 'woo-sagepay-patsatech').'</a>
            </form>';
        }

        /**
       * process payment
       */
        public function process_payment($order_id)
        {
            global $woocommerce;

            $order = wc_get_order( $order_id );

            $credit_card = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($_POST['sagepaydirect-card-number']));
            $ccexp_expiry = $_POST['sagepaydirect-card-expiry'];
            $month = substr($ccexp_expiry, 0, 2);
            $year = substr($ccexp_expiry, 5, 7);

            $basket = '';

            // Cart Contents
            $item_loop = 0;

            if (sizeof($order->get_items()) > 0) {
                foreach ($order->get_items() as $item) {
                    if ($item['qty']) {
                        $item_loop++;
                        $product = $order->get_product_from_item($item);
                        $item_name    = $item['name'];
                        $item_meta = new WC_Order_Item_Meta($item['item_meta']);
                        if ($meta = $item_meta->display(true, true)) {
                            $item_name .= ' ( ' . $meta . ' )';
                        }
                        $item_cost = $order->get_item_subtotal($item, false)/$item['qty'];
                        if ($item_loop > 1) {
                            $basket .= ':';
                        }
                        $sku = '';
                        if ($product->get_sku()) {
                            $sku = '['.$product->get_sku().']';
                        }
                        $basket .= $sku.str_replace(':', ' = ', $item_name).':'.$item['qty'].':'.$item_cost.':---:'.$order->get_item_subtotal($item, false).':'.$order->get_item_subtotal($item, false);
                    }
                }
            }

            // Fees
            if (sizeof($order->get_fees()) > 0) {
                foreach ($order->get_fees() as $fee_item_id => $fee_item) {
                    $item_loop++;
                    $basket .= ':'.$fee_item->get_name().':1:'.wc_format_decimal($order->get_line_total($fee_item), 2).':---:'.wc_format_decimal($order->get_line_total($fee_item), 2).':'.wc_format_decimal($order->get_line_total($fee_item), 2);
                }
            }

            // Shipping Cost item - paypal only allows shipping per item, we want to send shipping for the order
            if ($order->get_shipping_methods() > 0) {
                foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
                    $item_loop++;
                    $basket .= ':'.__('Shipping via', 'woocommerce') . ' ' . ucwords($shipping_item->get_name()).':---:---:---:---:'.wc_format_decimal($shipping_item->get_total(), 2);
                }
            }

            // Discount
            if ($order->get_total_discount() > 0) {
                $item_loop++;
                $basket .= ':Discount:---:---:---:---:-'.$order->get_total_discount();
            }

            // Tax
            if ($order->get_total_tax() > 0) {
                $item_loop++;
                $basket .= ':Tax:---:---:---:---:'.$order->get_total_tax();
            }

            $item_loop++;
            $basket .= ':Order Total:---:---:---:---:'.$order->get_total();
            $basket = $item_loop.':'.$basket;

            $time_stamp = date("ymdHis");
            $orderid = $this->vendor_name . "-" . $time_stamp . "-" . $order_id;

            $sd_arg['ReferrerID']            = 'CC923B06-40D5-4713-85C1-700D690550BF';
            $sd_arg['Amount']                = $order->order_total;
            $sd_arg['CustomerEMail']        = $order->get_billing_email();
            $sd_arg['BillingSurname']        = $order->get_billing_last_name();
            $sd_arg['BillingFirstnames']    = $order->get_billing_first_name();
            $sd_arg['BillingAddress1']        = $order->get_billing_address_1();
            $sd_arg['BillingAddress2']        = $order->get_billing_address_2();
            $sd_arg['BillingCity']            = $order->get_billing_city();

            if ($order->get_billing_country() == 'US') {
                $sd_arg['BillingState']        = $order->get_billing_state();
            } else {
                $sd_arg['BillingState']        = '';
            }

            $sd_arg['BillingPostCode']        = $order->get_billing_postcode();
            $sd_arg['BillingCountry']        = $order->get_billing_country();
            $sd_arg['BillingPhone']        = $order->get_billing_phone();

            if ($this->cart_has_virtual_product() == true || $this->send_shipping == 'yes') {
                $sd_arg['DeliverySurname']        = $order->get_billing_last_name();
                $sd_arg['DeliveryFirstnames']    = $order->get_billing_first_name();
                $sd_arg['DeliveryAddress1']    = $order->get_billing_address_1();
                $sd_arg['DeliveryAddress2']    = $order->get_billing_address_2();
                $sd_arg['DeliveryCity']        = $order->get_billing_city();

                if ($order->get_billing_country() == 'US') {
                    $sd_arg['DeliveryState']    = $order->get_billing_state();
                } else {
                    $sd_arg['DeliveryState']    = '';
                }

                $sd_arg['DeliveryPostCode']    = $order->get_billing_postcode();
                $sd_arg['DeliveryCountry']        = $order->get_billing_country();
            } else {
                $sd_arg['DeliverySurname']        = $order->get_shipping_last_name();
                $sd_arg['DeliveryFirstnames']    = $order->get_shipping_first_name();
                $sd_arg['DeliveryAddress1']    = $order->get_shipping_address_1();
                $sd_arg['DeliveryAddress2']    = $order->get_shipping_address_2();
                $sd_arg['DeliveryCity']        = $order->get_shipping_city();
                if ($order->get_shipping_country() == 'US') {
                    $sd_arg['DeliveryState']    = $order->get_shipping_state();
                } else {
                    $sd_arg['DeliveryState']    = '';
                }
                $sd_arg['DeliveryPostCode']    = $order->get_shipping_postcode();
                $sd_arg['DeliveryCountry']        = $order->get_shipping_country();
            }

            $sd_arg['DeliveryPhone']        = $order->get_billing_phone();
            $sd_arg['CardHolder']            = $_POST['sagepaydirect-card-name'];
            $sd_arg['CardNumber']            = $credit_card;
            $sd_arg['StartDate']            = '';
            $sd_arg['ExpiryDate']            = $month . $year;
            $sd_arg['CV2']                    = $_POST['sagepaydirect-card-cvc'];
            $sd_arg['CardType']            = $_POST['sagepaydirect-card-type'];
            $sd_arg['VPSProtocol']            = "4.00";
            $sd_arg['Vendor']                = $this->vendor_name;
            $sd_arg['Description']            = sprintf(__('Order #%s', 'woo-sagepay-patsatech'), ltrim($order->get_order_number(), '#'));
            $sd_arg['Currency']            = get_woocommerce_currency();
            $sd_arg['TxType']                = $this->transtype;
            $sd_arg['VendorTxCode']        = $orderid;
            $sd_arg['Basket']                = $basket;


            $header = $this->getRequestHeaders();


            $sd_arg['ClientIPAddress']          = $order->get_customer_ip_address();
            $sd_arg['BrowserJavascriptEnabled'] = 0;
            $sd_arg['BrowserAcceptHeader']      = $header['Accept'];
            $sd_arg['BrowserLanguage']          = substr($header['Accept-Language'], 0, 2);
            $sd_arg['BrowserUserAgent']         = $header['User-Agent'];
            $sd_arg['ThreeDSNotificationURL']   = $this->notify_url;
            $sd_arg['ChallengeWindowSize']      = $this->auth_window_size;

            $post_values = "";
            foreach ($sd_arg as $key => $value) {
                $post_values .= "$key=" . urlencode($value) . "&";
            }
            $post_values = rtrim($post_values, "& ");

            if ($this->mode == 'test') {
                $gateway_url = 'https://sandbox.opayo.eu.elavon.com/gateway/service/vspdirect-register.vsp';
            } elseif ($this->mode == 'live') {
                $gateway_url = 'https://live.opayo.eu.elavon.com/gateway/service/vspdirect-register.vsp';
            }

            $response = wp_remote_post($gateway_url, array(
              'body' => $post_values,
              'method' => 'POST',
              'sslverify' => false
            ));

            WC()->session->set('sagepay_vtc', $orderid);
            WC()->session->set('sagepay_oid', $order_id);

            if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
                $resp = array();
                $lines = preg_split('/\r\n|\r|\n/', $response['body']);
                foreach ($lines as $line) {
                    $key_value = preg_split('/=/', $line, 2);
                    if (count($key_value) > 1) {
                        $resp[trim($key_value[0])] = trim($key_value[1]);
                    }
                }


                if (isset($resp['Status'])) {
                    $order->update_meta_data( 'Status', $resp['Status']);
                }
                if (isset($resp['StatusDetail'])) {
                    $order->update_meta_data( 'StatusDetail', $resp['StatusDetail']);
                }
                if (isset($resp['VPSTxId'])) {
                    $order->update_meta_data( 'VPSTxId', $resp['VPSTxId']);
                    WC()->session->set('sagepay_vpstxid', $resp['VPSTxId']);
                }
                if (isset($resp['CAVV'])) {
                    $order->update_meta_data( 'CAVV', $resp['CAVV']);
                }
                if (isset($resp['SecurityKey'])) {
                    $order->update_meta_data( 'SecurityKey', $resp['SecurityKey']);
                }
                if (isset($resp['TxAuthNo'])) {
                    $order->update_meta_data( 'TxAuthNo', $resp['TxAuthNo']);
                }
                if (isset($resp['AVSCV2'])) {
                    $order->update_meta_data( 'AVSCV2', $resp['AVSCV2']);
                }
                if (isset($resp['AddressResult'])) {
                    $order->update_meta_data( 'AddressResult', $resp['AddressResult']);
                }
                if (isset($resp['PostCodeResult'])) {
                    $order->update_meta_data( 'PostCodeResult', $resp['PostCodeResult']);
                }
                if (isset($resp['CV2Result'])) {
                    $order->update_meta_data( 'CV2Result', $resp['CV2Result']);
                }
                if (isset($resp['3DSecureStatus'])) {
                    $order->update_meta_data( '3DSecureStatus', $resp['3DSecureStatus']);
                }
                if(isset($orderid)){
                    $order->update_meta_data( 'VendorTxCode', $orderid );
                }

				$order->save(); // Don't forget to save the changes.

                if ($resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED") {
                    $order->add_order_note(__('Opayo Direct Payment Completed.', 'woo-sagepay-patsatech'));
                    $order->payment_complete();
                    $redirect_url = $this->get_return_url($order);

                    return array(
                        'result'    => 'success',
                        'redirect'    =>  $redirect_url
                    );
                } elseif ($resp['Status'] == "3DAUTH") {
                    if ($resp['3DSecureStatus'] == 'OK') {
                        if (isset($resp['ACSURL']) && ( isset($resp['PAReq']) || isset($resp['CReq'] ))) {
                            WC()->session->set('sagepay_acsurl', $resp['ACSURL']);

                            if( isset($resp['PAReq']) && !empty($resp['PAReq']) ){
                              WC()->session->set('sagepay_pareq', $resp['PAReq']);
                            }

                            if( isset($resp['CReq']) && !empty($resp['CReq']) ){
                              WC()->session->set('sagepay_pareq', "");
                              WC()->session->set('sagepay_creq', $resp['CReq']);
                            }

                            WC()->session->set('sagepay_md', $resp['MD']);

                            $redirect = $order->get_checkout_payment_url(true);

                            return array(
                                'result'    => 'success',
                                'redirect'    => $redirect
                            );
                        }
                    }
                } else {
                    if (isset($resp['StatusDetail'])) {
                        wc_add_notice(sprintf(__('Transaction Failed. %s - %s', 'woo-sagepay-patsatech'), $resp['Status'], $resp['StatusDetail']), 'error');
                    } else {
                        wc_add_notice(sprintf(__('Transaction Failed with %s - unknown error.', 'woo-sagepay-patsatech'), $resp['Status']), 'error');
                    }
                }
            } else {
                wc_add_notice(__('Gateway Error. Please Notify the Store Owner about this error.', 'woo-sagepay-patsatech'), 'error');
            }
        }

        /**
         * receipt_page
         **/
        public function receipt_page($order)
        {
            global $woocommerce;

            echo '<p>'.__('Thank you for your order, Please click button below to Authenticate your card.', 'woo-sagepay-patsatech').'</p>';
            echo $this->generate_sagepaydirect_form($order);
        }

        /**
         * Successful Payment!
         **/
        public function successful_request()
        {
            global $woocommerce;

            if ( ( isset($_REQUEST['MD']) && isset($_REQUEST['PaRes']) ) || isset($_REQUEST['cres']) ) {
                $order = wc_get_order( WC()->session->get('sagepay_oid') );

                if( isset($_REQUEST['cres']) ){
                  $request_array = array(
                    'CRes' => $_REQUEST['cres'],
                    'VPSTxId' => WC()->session->get('sagepay_vpstxid'),
                  );
                }elseif( isset($_REQUEST['PaRes']) ){
                  $request_array = array(
                    'MD' => $_REQUEST['MD'],
                    'PARes' => $_REQUEST['PaRes'],
                    'VendorTxCode' => WC()->session->get('sagepay_vtc'),
                  );
                }

                $request = http_build_query($request_array);

                $params = array(
                  'body' => $request,
                  'method' => 'POST',
                  'sslverify' => false
                );

                if ($this->mode == 'test') {
                    $gateway_url = 'https://sandbox.opayo.eu.elavon.com/gateway/service/direct3dcallback.vsp';
                } elseif ($this->mode == 'live') {
                    $gateway_url = 'https://live.opayo.eu.elavon.com/gateway/service/direct3dcallback.vsp';
                }

                $response = wp_remote_post($gateway_url, array(
                  'body' => $request,
                  'method' => 'POST',
                  'sslverify' => false
                ));

                if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
                    $resp = array();
                    $lines = preg_split('/\r\n|\r|\n/', $response['body']);
                    foreach ($lines as $line) {
                        $key_value = preg_split('/=/', $line, 2);
                        if (count($key_value) > 1) {
                            $resp[trim($key_value[0])] = trim($key_value[1]);
                        }
                    }

                    if (isset($resp['Status'])) {
                        $order->update_meta_data( 'Status', $resp['Status']);
                    }
                    if (isset($resp['StatusDetail'])) {
                        $order->update_meta_data( 'StatusDetail', $resp['StatusDetail']);
                    }
                    if (isset($resp['VPSTxId'])) {
                        $order->update_meta_data( 'VPSTxId', $resp['VPSTxId']);
                    }
                    if (isset($resp['CAVV'])) {
                        $order->update_meta_data( 'CAVV', $resp['CAVV']);
                    }
                    if (isset($resp['SecurityKey'])) {
                        $order->update_meta_data( 'SecurityKey', $resp['SecurityKey']);
                    }
                    if (isset($resp['TxAuthNo'])) {
                        $order->update_meta_data( 'TxAuthNo', $resp['TxAuthNo']);
                    }
                    if (isset($resp['AVSCV2'])) {
                        $order->update_meta_data( 'AVSCV2', $resp['AVSCV2']);
                    }
                    if (isset($resp['AddressResult'])) {
                        $order->update_meta_data( 'AddressResult', $resp['AddressResult']);
                    }
                    if (isset($resp['PostCodeResult'])) {
                        $order->update_meta_data( 'PostCodeResult', $resp['PostCodeResult']);
                    }
                    if (isset($resp['CV2Result'])) {
                        $order->update_meta_data( 'CV2Result', $resp['CV2Result']);
                    }
                    if (isset($resp['3DSecureStatus'])) {
                        $order->update_meta_data( '3DSecureStatus', $resp['3DSecureStatus']);
                    }
                    if (WC()->session->get('sagepay_vtc') != '') {
                        $order->update_meta_data( 'VendorTxCode', WC()->session->get('sagepay_vtc'));
                    }

                    $order->save(); // Don't forget to save the changes.

                    if ($resp['Status'] == "OK" || $resp['Status'] == "REGISTERED" || $resp['Status'] == "AUTHENTICATED") {
                        $order->add_order_note(__('Opayo Direct Payment Completed.', 'woo-sagepay-patsatech'));
                        $order->payment_complete();
                        $redirect_url = $this->get_return_url($order);
                        wp_redirect($redirect_url);
                        exit();
                    } elseif ($resp['Status'] == "3DAUTH") {
                        if ($resp['3DSecureStatus'] == 'OK') {
                          if (isset($resp['ACSURL']) && ( isset($resp['PAReq']) || isset($resp['CReq'] ))) {
                                WC()->session->set('sagepay_acsurl', $resp['ACSURL']);

                                if( isset($resp['PAReq']) && !empty($resp['PAReq']) ){
                                  WC()->session->set('sagepay_pareq', $resp['PAReq']);
                                }

                                if( isset($resp['CReq']) && !empty($resp['CReq']) ){
                                  WC()->session->set('sagepay_pareq', "");
                                  WC()->session->set('sagepay_creq', $resp['CReq']);
                                }

                                WC()->session->set('sagepay_md', $resp['MD']);

                                $redirect = $order->get_checkout_payment_url(true);
                                wp_redirect($redirect);
                                exit();
                            }
                        }
                    } else {
                        if (isset($resp['StatusDetail'])) {
                            wc_add_notice(sprintf(__('Transaction Failed. %s - %s', 'woo-sagepay-patsatech'), $resp['Status'], $resp['StatusDetail']), 'error');
                            $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
                            wp_redirect($get_checkout_url);
                            exit();
                        } else {
                            wc_add_notice(sprintf(__('Transaction Failed with %s - unknown error.', 'woo-sagepay-patsatech'), $resp['Status']), 'error');
                            $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
                            wp_redirect($get_checkout_url);
                            exit();
                        }
                    }
                } else {
                    wc_add_notice(__('Gateway Error. Please Notify the Store Owner about this error.', 'woo-sagepay-patsatech'), 'error');
                    $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
                    wp_redirect($get_checkout_url);
                    exit();
                }
            }
        }


        private function getRequestHeaders() {
            $headers = array();
            foreach($_SERVER as $key => $value) {
                if (substr($key, 0, 5) <> 'HTTP_') {
                    continue;
                }
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
            return $headers;
        }

        /*
         * Check whether the card number number is empty
         */
        private function is_empty_credit_card($credit_card)
        {
            if (empty($credit_card)) {
                return false;
            }
            return true;
        }

        /*
         * Check whether the card number number is valid
         */
        private function is_valid_credit_card($credit_card)
        {
            $credit_card = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($credit_card));
            $number = preg_replace('/[^0-9]+/', '', $credit_card);
            $strlen = strlen($number);
            $sum    = 0;
            if ($strlen < 13) {
                return false;
            }
            for ($i=0; $i < $strlen; $i++) {
                $digit = substr($number, $strlen - $i - 1, 1);

                if ($i % 2 == 1) {
                    $sub_total = $digit * 2;

                    if ($sub_total > 9) {
                        $sub_total = 1 + ($sub_total - 10);
                    }
                } else {
                    $sub_total = $digit;
                }
                $sum += $sub_total;
            }

            if ($sum > 0 and $sum % 10 == 0) {
                return true;
            }

            return false;
        }

        /*
         * Check expiry date is empty
         */
        private function is_empty_expire_date($ccexp_expiry)
        {
            $ccexp_expiry = str_replace(' / ', '', $ccexp_expiry);

            if (is_numeric($ccexp_expiry) && (strlen($ccexp_expiry) == 4)) {
                return true;
            }
            return false;
        }

        /*
         * Check expiry date is valid
         */
        private function is_valid_expire_date($ccexp_expiry)
        {
            $month = $year = '';
            $month = substr($ccexp_expiry, 0, 2);
            $year = substr($ccexp_expiry, 5, 7);
            $year = '20'. $year;

            if ($month > 12) {
                return false;
            }

            if (date("Y-m-d", strtotime($year . "-" . $month . "-01")) > date("Y-m-d")) {
                return true;
            }

            return false;
        }

        /*
         * Check whether the ccv number is empty
         */
        private function is_empty_ccv_nmber($ccv_number)
        {
            $length = strlen($ccv_number);
            return is_numeric($ccv_number) and $length > 2 and $length < 5;
        }

        /**
        * Check if the cart contains virtual product
        *
        * @return bool
        */
        private function cart_has_virtual_product()
        {
            global $woocommerce;

            $has_virtual_products = false;
            $virtual_products = 0;
            $products = $woocommerce->cart->get_cart();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $is_virtual = get_post_meta($product_id, '_virtual', true);

                // Update $has_virtual_product if product is virtual
                if ($is_virtual == 'yes') {
                    $virtual_products += 1;
                }
            }
            if (count($products) == $virtual_products) {
                $has_virtual_products = true;
            }
            return $has_virtual_products;
        }

        private function force_ssl($url)
        {
            if ('yes' == get_option('woocommerce_force_ssl_checkout')) {
                $url = str_replace('http:', 'https:', $url);
            }
            return $url;
        }
    }

    /**
       * Add the gateway to WooCommerce
       **/
    function add_sagepaydirect_gateway($methods)
    {
        $methods[] = 'woocommerce_sagepaydirect';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_sagepaydirect_gateway');


	add_action('before_woocommerce_init', function(){

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	
		}
	
	});
}
