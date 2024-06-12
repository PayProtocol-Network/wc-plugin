<?php

/**
 * @wordpress-plugin
 * Plugin Name:             Crypto Gateway for WooCommerce
 * Plugin URI:              https://github.com/PayProtocol-Network/wc-plugin
 * Description:             Crypto Cryptocurrency Payment Gateway.
 * Version:                 1.0.0
 * Author:                  Crypto payments
 * License:                 GPLv2 or later
 * License URI:             http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             wc-crypto-gateway
 * Domain Path:             /i18n/languages/
 */

/**
 * Exit if accessed directly.
 */
if (! defined('ABSPATH')) {
    exit();
}

if (version_compare(PHP_VERSION, '7.0', '>=')) {
    ini_set('precision', 10);
    ini_set('serialize_precision', 10);
}

if (! defined('CRYPTO_FOR_WOOCOMMERCE_PLUGIN_DIR')) {
    define('CRYPTO_FOR_WOOCOMMERCE_PLUGIN_DIR', dirname(__FILE__));
}

if (! defined('CRYPTO_FOR_WOOCOMMERCE_ASSET_URL')) {
    define('CRYPTO_FOR_WOOCOMMERCE_ASSET_URL', plugin_dir_url(__FILE__));
}
//定义了插件的版本号
if (! defined('CRYPTO_VERSION_PFW')) {
    define('CRYPTO_VERSION_PFW', '1.0.0');
}
if (! defined('CRYPTO_SUPPORT_EMAIL')) {
    define('CRYPTO_SUPPORT_EMAIL', 'bd@payprotocol.network');
}

if (! defined('CRYPTO_SANDBOX_PAY_LINK')) {
    define('CRYPTO_SANDBOX_PAY_LINK', 'https://payment-sandbox.payprotocol.network');
}
if (! defined('CRYPTO_PAY_LINK')) {
    define('CRYPTO_PAY_LINK', 'https://payment.payprotocol.network');
}

if (! defined('CRYPTO_API_URL')) {
    define('CRYPTO_API_URL', 'https://api.payprotocol.network');
}
if (! defined('CRYPTO_SANDBOX_API_URL')) {
    define('CRYPTO_SANDBOX_API_URL', 'https://api-sandbox.payprotocol.network');
}

if (! defined('CRYPTO_CHAIN_LIST_API')) {
    define('CRYPTO_CHAIN_LIST_API', '/api/mer/conf/list/chain');
}

if (! defined('CRYPTO_CREATE_ORDER_API')) {
    define('CRYPTO_CREATE_ORDER_API', '/api/mer/payment/createPaymentOrder');
}

if (! defined('CRYPTO_PAYMENTS_DETAIL')) {
    define('CRYPTO_PAYMENTS_DETAIL', '/api/mer/payment/detail');
}

if (! defined('CRYPTO_CREATE_REFUND_API')) {
    define('CRYPTO_CREATE_REFUND_API', '/api/mer/refund/createRefundOrder');
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 *
 * @param array $gateways all available WC gateways
 *
 * @return array $gateways all WC gateways + offline gateway
 */
if (! function_exists('wc_crypto_add_to_gateways')) {
    function wc_crypto_add_to_gateways($gateways)
    {
        if (! in_array('WC_Gateway_crypto', $gateways)) {
            $gateways[] = 'WC_Gateway_crypto';
        }

        return $gateways;
    }
}
add_filter('woocommerce_payment_gateways', 'wc_crypto_add_to_gateways');

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 *
 * @param array $links all plugin links
 *
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
if (! function_exists('wc_crypto_gateway_plugin_links')) {
    function wc_crypto_gateway_plugin_links($links)
    {
        $plugin_links = [
            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=crypto_gateway')) . '">' . esc_html(__('Configure', 'wc-crypto-gateway')) . '</a>'
        ];

        return array_merge($plugin_links, $links);
    }
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_crypto_gateway_plugin_links');

if (! function_exists('add_cron_every_minute_interval')) {
    function add_cron_every_minute_interval($schedules)
    {
        if (! isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => esc_html__('Every minute'),
            ];
        }

        return $schedules;
    }
}
add_filter('cron_schedules', 'add_cron_every_minute_interval');

/**
 * Crypto payments Gateway
 *
 * @class       WC_Gateway_crypto
 * @extends     WC_Payment_Gateway
 *
 * @version     1.0.0
 *
 * @author      Echo
 */
add_action('plugins_loaded', 'wc_crypto_gateway_init', 11);
function wc_crypto_gateway_init()
{
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    add_action('check_crypto_order_result_cron_hook', ['WC_Gateway_crypto', 'check_crypto_order_result'], 10, 2);



    class WC_Gateway_crypto extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         *
         * @return void
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id                 = 'crypto_gateway';
            $this->has_fields         = false;
            $this->method_title       = esc_html(__('Crypto payments', 'wc-gateway-crypto'));
            $this->method_description = esc_html(__('Let your users allow crypto currency payments through Crypto', 'wc-crypto-gateway'));
            $this->supports           = ['refunds'];
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title           = $this->get_option('title');
            $this->description     = $this->get_option('description');
            $this->environment     = 'yes' === $this->get_option( 'environment' );
            $this->api_key         = $this->environment ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
            $this->secret_key      = $this->environment ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );
            $this->chain_id        = $this->get_option('chain_id');
            $this->instructions    = $this->get_option('instructions');
            $this->order_prefix    = $this->get_option('order_prefix', 'WP-ORDER-'.$this->api_key."-");
            $this->debug           = $this->get_option('debug', false);

            // Logs
            $this->log = new WC_Logger();

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'thanks_page']);
            add_action('woocommerce_api_wc_gateway_crypto', [$this, 'crypto_callback']);
            add_filter('woocommerce_settings_api_sanitized_fields_' . $this->id, [$this, 'deal_options'], 1, 1);
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @return void
         */
        public function init_form_fields()
        {
            $environment = $this->get_option('environment');
            $api_key    = $environment=="yes" ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
            $secret_key = $environment=="yes" ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

            $this->form_fields = apply_filters('wc_offline_form_fields', [
                'enabled' => [
                    'title'   => esc_html(__('Enable/Disable', 'wc-crypto-gateway')),
                    'type'    => 'checkbox',
                    'label'   => esc_html(__('Enable Crypto payments', 'wc-crypto-gateway')),
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => esc_html(__('Title', 'wc-crypto-gateway')),
                    'type'        => 'text',
                    'description' => esc_html(__('This controls the title which the user sees during checkout.', 'wc-crypto-gateway')),
                    'default'     => esc_html(__('Crypto payments', 'wc-crypto-gateway')),
                ],
                'description' => [
                    'title'       => esc_html(__('Description', 'wc-crypto-gateway')),
                    'type'        => 'textarea',
                    'description' => esc_html(__('This controls the description users see during checkout.', 'wc-crypto-gateway')),
                    'default'     => esc_html(__('Choose Crypto as your payment method!', 'wc-crypto-gateway')),
                ],

                'environment' => [
                    'title'         => esc_html(__('Enable/Disable', 'wc-crypto-gateway')),
                    'type'          => 'checkbox',
                    'label'         => esc_html(__('Enable Test Mode', 'wc-crypto-gateway')),
                    'description'   => esc_html(__('Place the payment gateway in test mode using test API keys.', 'wc-crypto-gateway')),
                    'default'       => 'yes',
                    'desc_tip'      => true,
                ],

                'test_api_key' => [
                    'title'             => esc_html(__('Test Api Key', 'wc-crypto-gateway')),
                    'type'              => 'text',
                    'description'       => esc_html(__('Generate the test environment API Key required for payment address', 'wc-crypto-gateway')),
                ],
                'test_secret_key' => [
                    'title'             => esc_html(__('Test Secret Key', 'wc-crypto-gateway')),
                    'type'              => 'password',
                    'description'       => esc_html(__('Generate the test environment Secret Key required for payment address', 'wc-crypto-gateway')),
                ],
                'api_key' => [
                    'title'             => esc_html(__('Live Api Key', 'wc-crypto-gateway')),
                    'type'              => 'text',
                    'description'       => esc_html(__('The production environment api key required to generate the payment address', 'wc-crypto-gateway')),
                ],
                'secret_key' => [
                    'title'             => esc_html(__('Live Secret Key', 'wc-crypto-gateway')),
                    'type'              => 'password',
                    'description'       => esc_html(__('The production environment Secret Key required to generate the payment address', 'wc-crypto-gateway')),
                ],

                'chain_id' => [
                    'title'       => esc_html(__('chain ', 'wc-crypto-gateway')),
                    'type'        => 'select',
                    'description' => esc_html(__('Select the default chain for generating payment addresses', 'wc-crypto-gateway')),
                    'options'     => $this->get_chain_lists($api_key, $secret_key, $environment),
                ],

                'instructions' => [
                    'title'       => esc_html(__('Instructions', 'wc-crypto-gateway')),
                    'type'        => 'textarea',
                    'description' => esc_html(__('The content of this option is displayed on the payment success page', 'wc-crypto-gateway')),
                    'default'     => esc_html('Thank you for using Crypto payments!'),
                ],
                'debug' => [
                    'title'       => esc_html(__('Debug', 'wc-crypto-gateway')),
                    'type'        => 'text',
                    'description' => esc_html(__('Debug Url', 'wc-crypto-gateway')),
                ],
            ]);
        }

        /**
         * Output for the order received page.
         */
        public function thanks_page()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        /**
         * Get gateway icon.
         *
         * @return string
         */
        public function get_icon()
        {
            if ($this->get_option('show_icons') === 'no') {
                return '';
            }

            $url = WC_HTTPS::force_https_url(plugins_url('/assets/images/payprotocol.png', __FILE__));
            $icon_html = '<img width="300" src="' . esc_attr($url) . '" alt="payprotocol" />';

            return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         *
         * @return array
         */
        public function process_payment($order_id)
        {
            $order        = wc_get_order($order_id);
            $payment_data = $this->generate_crypto_payment($order);
            if (! wp_next_scheduled('check_crypto_order_result_cron_hook', [$order_id,$payment_data['outPaymentNo']])) {
                wp_schedule_event(time(), 'every_minute', 'check_crypto_order_result_cron_hook', [$order_id,$payment_data['outPaymentNo']]);
            }

            return [
                'result'   => 'success',
                'redirect' => $payment_data['paymentUrl'],
            ];
        }

        /**
         * Generate the crypto button link
         *
         * @param mixed $order_id
         * @param mixed $order
         *
         * @return string
         */
        public function generate_crypto_payment($order)
        {
            global $woocommerce;

            if ($order->status != 'completed' && get_post_meta($order->id, 'Crypto payments complete', true) != 'Yes') {
                $order->add_order_note(esc_html('Customer is being redirected to Crypto...'));
            }

            $amount = $order->get_total();
            if ($amount === 0) {
                $order->update_status('completed', 'The order amount is zero.');

                return $this->get_return_url($order);
            } elseif ($amount === -1) {
                throw new Exception('The order amount is incorrect, please contact customer');
            }

            $crypto_params = $this->get_crypto_params($order);

            $payment_url = $this->get_environment_api($this->environment);
            $payment_data = $this->create_pay_data($crypto_params,$payment_url['payment_api']);

            if (empty($payment_data)) {
                throw new Exception('Server error, please try again.');
            }

            $payment_data['paymentUrl']  = esc_html($payment_url['payment_link'].$payment_data['paymentUrl']);

            return $payment_data;
        }

        /**
         * Get Crypto Params
         *
         * @param mixed $order
         *
         * @return array
         */
        public function get_crypto_params($order)
        {
            global $woocommerce;
            $crypto_args = [
                'chainId'            => $this->chain_id,
                'outTradeNo'         => $this->order_prefix . $order->get_order_number(),
                'description'        => 'Currently WooCommerce product information',
                'isLegalTender'      => 1,
                'notifyUrl'          => site_url() . '/?wc-api=wc_gateway_crypto',
                'quoteCurrencySymbol'=> $order->get_currency(),
                'quoteAmount'        => $order->get_total(),
                'redirectionUrl'    => $this->get_return_url($order),
            ];
            $crypto_args = apply_filters('woocommerce_crypto_args', $crypto_args);

            return $crypto_args;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */


        /**
         * @param array $posted callback
         *
         * @return void
         */
        public function crypto_callback()
        {

            ob_start();
            global $woocommerce;

            $request_json         = file_get_contents('php://input');
            $request_data         = json_decode($request_json, true);

            $payment_url          = $this->get_environment_api($this->environment);
            $payments_result_data = $this->get_payments_result($payment_url['payment_api'], $request_data['outTradeNo'], $request_data['outPaymentNo']);
            $wc_order_id          = str_replace($this->order_prefix, '', $request_data['outTradeNo']);
            $result               = $this->update_order_status($wc_order_id, $payments_result_data);

            ob_clean();
            wp_send_json($result);
        }

        public static function check_crypto_order_result($order_id, $outPaymentNo)
        {
            $crypto_gatway        = (new self());
            $payment_url          = $crypto_gatway->get_environment_api($crypto_gatway->environment);
            $result_data = $crypto_gatway->get_payments_result($payment_url['payment_api'], $crypto_gatway->order_prefix . $order_id, $outPaymentNo);
            $crypto_gatway->update_order_status($order_id, $result_data);
        }

        //update wc order status
        public function update_order_status($order_id, $payments_result_data)
        {
            $order  = new WC_Order($order_id);
            $result = "fail";

            $status_before_update = $order->get_status();

            if (($payments_result_data['paymentStatus'] == 1 || $payments_result_data['paymentStatus'] == 2) && $status_before_update == 'pending') {
                $order->update_status('processing', 'Order is processing.');
            } elseif ($payments_result_data['paymentStatus'] == 0 && in_array($status_before_update, ['pending', 'processing'])) {

                if ($payments_result_data['quoteCurrencySymbol'] == $order->get_currency()
                    && $payments_result_data['quoteAmount'] == $order->get_total()) {
                    $order->update_status('completed', 'Order has been paid.');
                } else {
                    $order->update_status('failed', 'Order has been failed');
                }
                $result = "success";
            } elseif ($payments_result_data['paymentStatus'] == 4) {
                $order->update_status('cancelled', "Order has been cancelled, reason: crypto order payment failed or the order expired.");
            } elseif ($status_before_update == 'cancelled' && $payments_result_data['paymentStatus'] == 0) {
                if ($payments_result_data['quoteCurrencySymbol'] == $order->get_currency()
                    && $payments_result_data['quoteAmount'] == $order->get_total()) {
                    $order->update_status('processing', 'Order is processing.');
                }
            }

            if (! $order->has_status(['pending', 'processing'])) {
                wp_clear_scheduled_hook('check_crypto_order_result_cron_hook', [$order_id, $payments_result_data['outPaymentNo']]);
            }

            $this->debug_post_callback(
                'order_data',
                [
                    'payments_result_data'       => $payments_result_data,
                    'order_status_before_update' => $status_before_update,
                    'order_status_after_update'  => $order->get_status(),
                ]
            );

            return $result;
        }

        //Payment order information
        public function get_payments_result($payment_api, $outTradeNo, $outPaymentNo)
        {
            $timestamp = time();
            $sign = self::signature($timestamp, "GET", CRYPTO_PAYMENTS_DETAIL."?outTradeNo={$outTradeNo}&outPaymentNo={$outPaymentNo}", '', $this->secret_key);

            $response = wp_remote_get($payment_api.CRYPTO_PAYMENTS_DETAIL."?outTradeNo={$outTradeNo}&outPaymentNo={$outPaymentNo}", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-PAY-KEY'  => $this->api_key,
                    'X-PAY-SIGN' => $sign,
                    'X-PAY-TIMESTAMP' => $timestamp
                ],
            ]);
            $payments_result_data = wp_remote_retrieve_body($response);

            return json_decode($payments_result_data, true)['data'];
        }
        public function get_environment_api($payment_environment){
            $data = [];

            if($payment_environment == "yes"){
                $data['payment_link'] = CRYPTO_SANDBOX_PAY_LINK;
                $data['payment_api'] = CRYPTO_SANDBOX_API_URL;
            }else{
                $data['payment_link'] = CRYPTO_PAY_LINK;
                $data['payment_api'] = CRYPTO_API_URL;
            }
            return $data;
        }

        //chain list
        public function get_chain_lists($api_key, $secret_key, $environment)
        {
            if(!$api_key || !$secret_key){
                return [];
            }
            $timestamp = time();
            $payment_url = $this->get_environment_api($environment);

            $sign = self::signature($timestamp, "GET", CRYPTO_CHAIN_LIST_API, '', $secret_key);

            $response = wp_remote_get($payment_url['payment_api'].CRYPTO_CHAIN_LIST_API, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-PAY-KEY'  => $api_key,
                    'X-PAY-SIGN' => $sign,
                    'X-PAY-TIMESTAMP' => $timestamp
                ],
            ]);
            $response_data = wp_remote_retrieve_body($response);

            $response_data = json_decode($response_data, true)['data'] ?? [];

            $lists = ["Please select a chain"];
            $new_chain_lists = [];

            foreach ($response_data as $v) {
                $chainId         = esc_attr($v['chainId']);
                $lists[$chainId] =  esc_html($v['chainName']);
            }

            if (! empty($lists)) {
                $new_chain_lists = $lists;
            }

            return $new_chain_lists;
        }

        //Create payment order
        public function create_pay_data($crypto_params, $payment_api){

            $timestamp = time();
            $body = $crypto_params ? json_encode($crypto_params, JSON_UNESCAPED_SLASHES) : '';

            $sign = self::signature($timestamp, "POST", CRYPTO_CREATE_ORDER_API, $body, $this->secret_key);
            $response = wp_remote_post($payment_api.CRYPTO_CREATE_ORDER_API, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-PAY-KEY'  => $this->api_key,
                    'X-PAY-SIGN' => $sign,
                    'X-PAY-TIMESTAMP' => $timestamp
                ],
            ]);
            $response_data = wp_remote_retrieve_body($response);
            $result        = json_decode($response_data, true);

            if ($result['code'] === 200) {
                return $result['data'];
            } else {
                throw new Exception($result['msg']);
            }
            return null;
        }

        //sign
        public static function signature($timestamp, $method, $requestPath, $body, $secretKey)
        {
            $message = (string) $timestamp . strtoupper($method) . $requestPath . (string) $body;
            return base64_encode(hash_hmac('sha256', $message, $secretKey, true));
        }

        public function deal_options($settings)
        {
            if (empty($settings['title'])) {
                WC_Admin_Settings::add_error(esc_html('Title is required'));
            }
            return $settings;
        }

        public function debug_post_callback($key, $callback_data)
        {
            if ($this->debug) {
                $data = [
                    'api_key' => $this->api_key,
                    $key         => $callback_data,
                ];
                wp_remote_post($this->debug, ['body' => $data]);
            }
        }
    }
}
