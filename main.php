<?php

/**
 * Plugin Name: Kryptoney - Accept Payments in Cryptocurrencies
 * Plugin URI: https://docs.kryptoney.com/?utm=wordpress
 * Description: A woocommerce payment method Ad-on which will allow you to receive payments in Cryptocurrencies
 * Version: 1.0.2
 * Author: Kryptoney
 * Author URI: https://www.kryptoney.com/?utm=wordpress
 * Requires at least: 4.2
 * Tested up to: 5.9
 *
 * Text Domain: wc-kryptoney
 * Domain Path: /languages/
 *
 */
class WC_Kryptoney_Init
{
    const GATEWAY_SANDBOX_URL   = 'https://sandbox.kryptoney.com';
    const GATEWAY_URL           = 'https://www.kryptoney.com';
    
    /**
     * Initializing wp hooks
     */
    public function __construct()
    {
        add_action( 'plugins_loaded', array($this, 'load_gateway') );
        add_action( 'woocommerce_payment_gateways', array($this, 'add_to_payment_gateways') );
        add_filter( 'query_vars', array($this, 'query_vars') );
        add_action('wp', array($this, 'process'));
        add_action('wp', array($this, 'process_ipn'));
        
    }
    
    /**
     * appending custom query vars. Used in hook
     * 
     * @param  array $vars
     * 
     * @return array
     */
    public function query_vars( $vars )
    {
        $vars[] = "order_id";
        $vars[] = "kryptoney_action";
        return $vars;
    }
    
    
    /**
     * adding payment gateway to woocommerce gateway list. Used in hook
     * 
     * @param  array $gateways 
     * 
     * @return array
     */
    public function add_to_payment_gateways($gateways)
    {
        $gateways[] = 'WC_Kryptoney';
        return $gateways;
    }
    
    /**
     * loading payment gateway for woocommerce
     * 
     * @return void
     */
    public function load_gateway()
    {
        require_once __DIR__ . '/class-wc-kryptoney.php';
    }
    
    /**
     * process post and post back call for easypay and redirects to easy pay
     * 
     * @return void
     */
    public function process()
    {
        if(get_query_var('kryptoney_action') === 'post') {
            
            global $woocommerce;
                    
            $order_id = get_query_var('order_id', null);
            $api_key = $this->get_option('api_key');
            $secret_key = $this->get_option('secret_key');

            $order = new WC_Order($order_id);

            $endpoint = $this->get_gateway_url() . '/api/checkout';
 
            $body = [
                'currency_code' => $order->get_order_currency(),
                'amount' => $order->get_total(),
                'type' => 'website_checkout',
                'external_order_id' => $order->id,
                'ipn_url' => site_url(sprintf('?kryptoney_action=ipn&order_id=%s', $order->id)),
                'items' => []
            ];

            foreach($order->get_items() as $item) {

                $body['items'][] = [
                    'qty' => $item->get_quantity(),
                    'description' => $item->get_name(),
                    'name' => $item->get_name(),
                    'amount' => $item->get_total()
                ];

            }

            $body = wp_json_encode( $body );
            
            $options = [
                'body'        => $body,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'api-key' => $api_key,
                    'secret-key' => $secret_key
                ],
                'timeout'     => 60,
                'redirection' => 5,
                'blocking'    => true,
                'httpversion' => '1.0',
                'sslverify'   => false,
                'data_format' => 'body',
            ];
            
            $checkout = wp_remote_post( $endpoint, $options );

            $response = json_decode( wp_remote_retrieve_body($checkout), true );

            if($response && isset($response['status']) && $response['status'] == true) {
                wp_redirect($response['redirect_uri']);
                exit;
            }

            die();
            
        }
    }
    
    /**
     * process ipn request
     * 
     * @return void
     */
    public function process_ipn()
    {
        $checkout_id = isset($_REQUEST['checkout_id']) ? sanitize_text_field($_REQUEST['checkout_id']) : null ;
        $external_order_id = isset($_REQUEST['external_order_id']) ? sanitize_text_field($_REQUEST['external_order_id']) : null ;
        
        if(get_query_var('kryptoney_action') === 'ipn' && $checkout_id && $external_order_id) {
            
            $api_key = $this->get_option('api_key');
            $secret_key = $this->get_option('secret_key');

            $endpoint = $this->get_gateway_url() . '/api/checkout/' . $checkout_id . '/' . $external_order_id;

            $options = [
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'api-key' => $api_key,
                    'secret-key' => $secret_key
                ],
                'timeout'     => 60,
                'redirection' => 5,
                'blocking'    => true,
                'httpversion' => '1.0',
                'sslverify'   => false,
                'data_format' => 'body',
            ];
            
            $response = wp_remote_get( $endpoint, $options );

            $responseBody = json_decode( wp_remote_retrieve_body($response), true );

            if(!$responseBody || !is_array($responseBody) || !isset($responseBody['status']) || !$responseBody['status']) {
                $this->kryptoney_ipn_log("Error in ipn data.");
                exit();
            }      
            
            $order = new WC_Order($external_order_id);

            if(!$order->post->ID) {
                $this->kryptoney_ipn_log("Request received but order not found.");
                exit();
            }

            /**
             * Updating order status to processing
             */

            if($responseBody['checkout']['payments']) {

                foreach($responseBody['checkout']['payments'] as $payment) {
                    $order->update_status('processing', sprintf( __('%s %s paid at %s', 'wc-kryptoney'), $payment['crypto_amount'], $payment['crypto_currency_code'], $payment['date']));
                }

            } else {

                $order->update_status( 'on-hold', __('No payment record found in the request', 'wc-kryptoney') );
            
            }

            /**
             * Adding ipn data to database as log
             */  
            add_post_meta($order->post->ID, 'KRYPTONEY_IPN_LOG', $responseBody);
            add_post_meta($order->post->ID, 'kryptoney_checkout_id', $checkout_id);
            exit();
        }
    }
    
    /**
     * Gateway url according to mode
     * 
     * @return string
     */
    public function get_gateway_url()
    {
        $sandbox = 'yes' === $this->get_option('sandbox', 'no');
        
        if($sandbox) {
            return self::GATEWAY_SANDBOX_URL;
        }
        
        return self::GATEWAY_URL;
    }
    
    /**
     * log ipn request from easypay
     * @param string $log_text
     * @return void
     */
    public function kryptoney_ipn_log($log_text) 
    {
        if('yes' === $this->get_option('debug', 'no')) { // if debug mode enable
            
            file_put_contents(__DIR__ . '/kryptoney-ipn-log', 
                                  date('Y-m-d H:i:s') 
                                  . "\n\n" 
                                  . $log_text 
                                  . "\nRequested Data: " 
                                  . json_encode($_REQUEST) 
                                  . "\n\n", FILE_APPEND
                             );
            
        }
    }
    
    /**
     * get easy pay gateway option
     * @param  string $option_name
     * @param  mixed [$default = null]
     * @return mixed(array/string)
     */
    public function get_option($option_name, $default = null)
    {
        $settings = get_option('woocommerce_kryptoney_settings');
                    
        return $settings && isset($settings[$option_name]) ? $settings[$option_name] : $default;
    }
    
    /**
     * handle ipn request here
     */
    public function ipn()
    {
        
    }
}

new WC_Kryptoney_Init();