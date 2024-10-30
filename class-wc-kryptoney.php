<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(class_exists('WC_Payment_Gateway')) {
    
    class WC_Kryptoney extends WC_Payment_Gateway 
    {
        /** @var bool Whether or not logging is enabled */
        public static $log_enabled = false;

        /** @var WC_Logger Logger instance */
        public static $log = false;

        
        /**
         * Constructor (Setting up extended parameters from woocommerce gateway class)
         * @return null
         */
        public function __construct()
        {

            $this->id                 = 'kryptoney';
            $this->icon               = plugin_dir_url( __FILE__ ).'assets/icons-group.png';
            $this->has_fields         = false;
            $this->order_button_text  = __( 'Proceed to Crypto Payment', 'wc-kryptoney' );
            $this->method_title       = __( 'Kryptoney', 'wc-kryptoney' );
            $this->method_description = '';
            $this->supports           = array(
                'products'
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title         = $this->get_option( 'title' );
            $this->description   = $this->get_option( 'description' );
            $this->sandbox       = 'yes' === $this->get_option( 'sandbox', 'no' );
            $this->debug         = 'yes' === $this->get_option( 'debug', 'no' );
            $this->api_key       = $this->get_option( 'api_key' );
            $this->secret_key    = $this->get_option( 'secret_key' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );


        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {

            $this->form_fields = apply_filters( 'wc_krytoney_form_fields', array(

                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'wc-kryptoney' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Kryptoney', 'wc-kryptoney' ),
                    'default' => 'yes',
                    'description' => '',
                ),

                'title' => array(
                    'title'       => __( 'Title', 'wc-kryptoney' ),
                    'type'        => 'text',
                    'default'     => __( 'Cryptocurrency', 'wc-kryptoney' ),
                    'desc_tip'    => true,
                ),

                'description' => array(
                    'title'       => __( 'Description', 'wc-kryptoney' ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-kryptoney' ),
                    'default'     => __( 'Powered by Kryptoney.', 'wc-kryptoney' ),
                    'desc_tip'    => true,
                ),

                'instructions' => array(
                    'title'       => __( 'Instructions', 'wc-kryptoney' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-kryptoney' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),

                'api_key' => array(
                    'title'       => __( 'API Key', 'wc-kryptoney' ),
                    'type'        => 'text',
                    'description' => __( sprintf('Your Krytoney API Key. See the docs for detail <a href="%s">Click here</a>', 'https://docs.kryptoney.com'), 'wc-kryptoney' ),
                    'desc_tip'    => true,
                ),

                'secret_key' => array(
                    'title'       => __( 'Secret Key', 'wc-kryptoney' ),
                    'type'        => 'text',
                    'description' => __( sprintf('Your Krytoney Secret Key. See the docs for detail <a href="%s">Click here</a>', 'https://docs.kryptoney.com'), 'wc-kryptoney' ),
                    'desc_tip'    => true,
                ),

                'sandbox' => array(
                    'title'   => __( 'Sandbox Mode', 'wc-kryptoney' ),
                    'type'    => 'checkbox',
                    'default' => 'no'
                ),
                'debug' => array(
                    'title'   => __( 'Debug Mode', 'wc-kryptoney' ),
                    'type'    => 'checkbox',
                    'description' => __( 'Log stores in kryptoney\'s plugin folder', 'wc-kryptoney' ),
                    'default' => 'no'
                )
            ) );
        }

        /**
         * process payment and generate redirect for easy pay
         * @param  integer  $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status( 'on-hold', __( 'Awaiting Kryptoney Payment', 'wc-kryptoney' ) );

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order )
            );
        }

        /**
         * Generate redirect url
         * @param  WC_Order $order
         * @return string
         */
        public function get_return_url( $order = null ) {
            return $url = add_query_arg(array(
                'order_id' => $order->id
            ), site_url('?kryptoney_action=post'));
        }

    }
} // end class exists
