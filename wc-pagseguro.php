<?php
/**
 * Plugin Name: WooCommerce PagSeguro
 * Plugin URI: http://claudiosmweb.com/plugins/pagseguro-para-woocommerce/
 * Description: Gateway de pagamento PagSeguro para WooCommerce.
 * Author: claudiosanches, Gabriel Reguly
 * Author URI: http://www.claudiosmweb.com/
 * Version: 1.2
 * License: GPLv2 or later
 * Text Domain: wcpagseguro
 * Domain Path: /languages/
 */

/**
 * WooCommerce fallback notice.
 */
function wcpagseguro_woocommerce_fallback_notice(){
    $message = '<div class="error">';
        $message .= '<p>' . __( 'WooCommerce PagSeguro Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcpagseguro' ) . '</p>';
    $message .= '</div>';

    echo $message;
}

/**
 * Load functions.
 */
add_action( 'plugins_loaded', 'wcpagseguro_gateway_load', 0 );

function wcpagseguro_gateway_load() {

    if ( !class_exists( 'WC_Payment_Gateway' ) || !class_exists( 'WC_Order_Item_Meta' ) ) {
        add_action( 'admin_notices', 'wcpagseguro_woocommerce_fallback_notice' );

        return;
    }

    /**
     * Load textdomain.
     */
    load_plugin_textdomain( 'wcpagseguro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    /**
     * Add the gateway to WooCommerce.
     *
     * @access public
     * @param array $methods
     * @return array
     */
    add_filter( 'woocommerce_payment_gateways', 'wcpagseguro_add_gateway' );

    function wcpagseguro_add_gateway( $methods ) {
        $methods[] = 'WC_PagSeguro_Gateway';
        return $methods;
    }

    /**
     * WC PagSeguro Gateway Class.
     *
     * Built the PagSeguro method.
     */
    class WC_PagSeguro_Gateway extends WC_Payment_Gateway {

        /**
         * Constructor for the gateway.
         *
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id            = 'pagseguro';
            $this->icon          = plugins_url( 'images/pagseguro.png', __FILE__ );
            $this->has_fields    = false;
            $this->pagseguro_url = 'https://pagseguro.uol.com.br/v2/checkout/payment.html';
            $this->pagseguro_ipn = 'https://pagseguro.uol.com.br/pagseguro-ws/checkout/NPI.jhtml';
            $this->method_title  = __( 'PagSeguro', 'wcpagseguro' );

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables.
            $this->title            = $this->settings['title'];
            $this->description      = $this->settings['description'];
            $this->email            = $this->settings['email'];
            $this->token            = $this->settings['token'];
            $this->invoice_prefix   = !empty( $this->settings['invoice_prefix'] ) ? $this->settings['invoice_prefix'] : 'WC-';

            // Actions.
            add_action( 'init', array( &$this, 'check_pagseguro_ipn_response' ) );
            add_action( 'valid_pagseguro_ipn_request', array( &$this, 'successful_request' ) );
            add_action( 'woocommerce_receipt_pagseguro', array( &$this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );

            // Valid for use.
            $this->enabled = ( 'yes' == $this->settings['enabled'] ) && !empty( $this->email ) && !empty( $this->token ) && $this->is_valid_for_use();

            // Checks if email is not empty.
            $this->email == '' ? add_action( 'admin_notices', array( &$this, 'mail_missing_message' ) ) : '';

            // Checks if token is not empty.
            $this->token == '' ? add_action( 'admin_notices', array( &$this, 'token_missing_message' ) ) : '';
        }

        /**
         * Check if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use() {
            if ( !in_array( get_woocommerce_currency() , array( 'BRL' ) ) ) {
                return false;
            }

            return true;
        }

        /**
         * Admin Panel Options.
         * - Options for bits like 'title' and availability on a country-by-country basis.
         *
         * @since 1.0.0
         */
        public function admin_options() {

            ?>
            <h3><?php _e( 'PagSeguro standard', 'wcpagseguro' ); ?></h3>
            <p><?php _e( 'PagSeguro standard works by sending the user to PagSeguro to enter their payment information.', 'wcpagseguro' ); ?></p>
            <table class="form-table">
            <?php
                if ( !$this->is_valid_for_use() ) {

                    // Valid currency.
                    echo '<div class="inline error"><p><strong>' . __( 'Gateway Disabled', 'wcpagseguro' ) . '</strong>: ' . __( 'PagSeguro does not support your store currency.', 'wcpagseguro' ) . '</p></div>';

                } else {

                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                }
            ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Initialise Gateway Settings Form Fields.
         *
         * @return void
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'wcpagseguro' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable PagSeguro standard', 'wcpagseguro' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'wcpagseguro' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'wcpagseguro' ),
                    'default' => __( 'PagSeguro', 'wcpagseguro' )
                ),
                'description' => array(
                    'title' => __( 'Description', 'wcpagseguro' ),
                    'type' => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'wcpagseguro' ),
                    'default' => __( 'Pay via PagSeguro', 'wcpagseguro' )
                ),
                'email' => array(
                    'title' => __( 'PagSeguro Email', 'wcpagseguro' ),
                    'type' => 'text',
                    'description' => __( 'Please enter your PagSeguro email address; this is needed in order to take payment.', 'wcpagseguro' ),
                    'default' => ''
                ),
                'token' => array(
                    'title' => __( 'PagSeguro Token', 'wcpagseguro' ),
                    'type' => 'text',
                    'description' => sprintf( __( 'Please enter your PagSeguro token; is necessary to process the payment and notifications. Is possible generate a new token %shere%s', 'wcpagseguro' ), '<a href="https://pagseguro.uol.com.br/integracao/token-de-seguranca.jhtml">', '</a>' ),
                    'default' => ''
                ),
                'invoice_prefix' => array(
                    'title' => __( 'Invoice Prefix', 'wcpagseguro' ),
                    'type' => 'text',
                    'description' => __( 'Please enter a prefix for your invoice numbers. If you use your PagSeguro account for multiple stores ensure this prefix is unqiue as PagSeguro will not allow orders with the same invoice number.', 'wcpagseguro' ),
                    'default' => 'WC-'
                )
            );

        }

        /**
         * Get PagSeguro Args.
         *
         * @param mixed $order
         * @return array
         */
        public function get_pagseguro_args( $order ) {
            global $woocommerce;

            $order_id = $order->id;

            // Fixed phone number.
            $order->billing_phone = str_replace( array( '(', '-', ' ', ')' ), '', $order->billing_phone );
            $phone_args = array(
                'senderAreaCode' => substr( $order->billing_phone, 0, 2 ),
                'senderPhone' => substr( $order->billing_phone, 2 ),
            );

            // Fixed postal code.
            $order->billing_postcode = str_replace( array( '-', ' ' ), '', $order->billing_postcode );

            // Fixed Address.
            $order->billing_address_1 = explode( ',', $order->billing_address_1 );

            // Fixed PagSeguro Country.
            if ( $order->billing_country == 'BR' ) {
                $order->billing_country = 'BRA';
            }

            // PagSeguro Args.
            $pagseguro_args = array_merge(
                array(
                    'receiverEmail'             => $this->email,
                    'currency'                  => get_woocommerce_currency(),
                    'encoding'                  => 'UTF-8',

                    // Sender info.
                    'senderName'                => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'senderEmail'               => $order->billing_email,

                    // Address info.
                    'shippingAddressPostalCode' => $order->billing_postcode,
                    'shippingAddressStreet'     => $order->billing_address_1[0],
                    'shippingAddressNumber'     => (int) $order->billing_address_1[1],
                    'shippingAddressComplement' => $order->billing_address_2,
                    'shippingAddressCity'       => $order->billing_city,
                    'shippingAddressState'      => $order->billing_state,
                    'shippingAddressCountry'    => $order->billing_country,

                    // Extras.
                    'extraAmount'               => $order->get_total_tax(),

                    // Payment Info.
                    'reference'                 => $this->invoice_prefix . $order_id,
                ),
                $phone_args
            );

            // If prices include tax or have order discounts, send the whole order as a single item.
            if ( get_option('woocommerce_prices_include_tax') == 'yes' || $order->get_order_discount() > 0 ) {

                // Discount.
                $pagseguro_args['extraAmount'] = $order->get_order_discount();

                // Don't pass items - pagseguro borks tax due to prices including tax.
                // PagSeguro has no option for tax inclusive pricing sadly. Pass 1 item for the order items overall.
                $item_names = array();

                if ( sizeof( $order->get_items() ) > 0 ) {
                    foreach ( $order->get_items() as $item ) {
                        if ( $item['qty'] ) {
                            $item_names[] = $item['name'] . ' x ' . $item['qty'];
                        }
                    }
                }

                $pagseguro_args['itemId1']          = 1;
                $pagseguro_args['itemDescription1'] = substr( sprintf( __( 'Order %s' , 'wcpagseguro' ), $order->get_order_number() ) . " - " . implode(', ', $item_names), 0, 110 );
                $pagseguro_args['itemQuantity1']    = 1;
                $pagseguro_args['itemAmount1']      = number_format( $order->get_total() - $order->get_shipping() - $order->get_shipping_tax() + $order->get_order_discount(), 2, '.', '' );

                if ( ( $order->get_shipping() + $order->get_shipping_tax() ) > 0 ) {
                    $pagseguro_args['itemId2'] = 2;
                    $pagseguro_args['itemDescription2'] = __( 'Shipping via', 'wcpagseguro' ) . ' ' . ucwords( $order->shipping_method_title );
                    $pagseguro_args['itemQuantity2']  = '1';
                    $pagseguro_args['itemAmount2']    = number_format( $order->get_shipping() + $order->get_shipping_tax() , 2, '.', '' );
                }

            } else {

                // Cart Contents.
                $item_loop = 0;
                if ( sizeof( $order->get_items() ) > 0 ) {
                    foreach ( $order->get_items() as $item ) {
                        if ( $item['qty'] ) {

                            $item_loop++;

                            $product = $order->get_product_from_item( $item );

                            $item_name  = $item['name'];

                            $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                            if ( $meta = $item_meta->display( true, true ) ) {
                                $item_name .= ' (' . $meta . ')';
                            }

                            $pagseguro_args['itemId' . $item_loop] = $item_loop;
                            $pagseguro_args['itemDescription' . $item_loop] = $item_name;
                            $pagseguro_args['itemQuantity' . $item_loop] = $item['qty'];
                            $pagseguro_args['itemAmount' . $item_loop] = $order->get_item_total( $item, false );

                        }
                    }
                }

                // Shipping Cost item.
                if ( $order->get_shipping() > 0 ) {
                    $item_loop++;
                    $pagseguro_args['itemId' . $item_loop] = $item_loop;
                    $pagseguro_args['itemDescription' . $item_loop] = __( 'Shipping via', 'wcpagseguro' ) . ' ' . ucwords( $order->shipping_method_title );
                    $pagseguro_args['itemQuantity' . $item_loop] = '1';
                    $pagseguro_args['itemAmount' . $item_loop] = number_format( $order->get_shipping(), 2, '.', '' );
                }

            }

            $pagseguro_args = apply_filters( 'woocommerce_pagseguro_args', $pagseguro_args );

            return $pagseguro_args;
        }

        /**
         * Generate the PagSeguro button link.
         *
         * @param mixed $order_id
         * @return string
         */
        public function generate_pagseguro_form( $order_id ) {
            global $woocommerce;

            $order = new WC_Order( $order_id );

            $pagseguro_args = $this->get_pagseguro_args( $order );

            $pagseguro_args_array = array();

            foreach ( $pagseguro_args as $key => $value ) {
                $pagseguro_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
            }

            $woocommerce->add_inline_js( '
                jQuery("body").block({
                        message: "<img src=\"' . esc_url( $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif' ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />'.__( 'Thank you for your order. We are now redirecting you to PagSeguro to make payment.', 'wcpagseguro' ).'",
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity:    0.6
                        },
                        css: {
                            padding:         20,
                            textAlign:       "center",
                            color:           "#555",
                            border:          "3px solid #aaa",
                            backgroundColor: "#fff",
                            cursor:          "wait",
                            lineHeight:      "32px",
                            zIndex:          "9999"
                        }
                    });
                jQuery("#submit_pagseguro_payment_form").click();
            ' );

            return '<form action="' . esc_url( $this->pagseguro_url ) . '" method="post" id="pagseguro_payment_form" target="_top">
                    ' . implode( '', $pagseguro_args_array ) . '
                    <input type="submit" class="button alt" id="submit_pagseguro_payment_form" value="' . __( 'Pay via PagSeguro', 'wcpagseguro' ).'" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'wcpagseguro' ) . '</a>
                </form>';

        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            return array(
                'result'    => 'success',
                'redirect'  => add_query_arg( 'order', $order->id, add_query_arg( 'key', $order->order_key, get_permalink( woocommerce_get_page_id( 'pay' ) ) ) )
            );

        }

        /**
         * Output for the order received page.
         *
         * @return void
         */
        public function receipt_page( $order ) {
            global $woocommerce;

            echo '<p>' . __( 'Thank you for your order, please click the button below to pay with PagSeguro.', 'wcpagseguro' ).'</p>';

            echo $this->generate_pagseguro_form( $order );

            // Remove cart.
            $woocommerce->cart->empty_cart();
        }

        /**
         * Check PagSeguro ipn validity.
         *
         * @return bool
         */
        function check_ipn_request_is_valid() {
            global $woocommerce;

            $postdata = 'Comando=validar&Token=' . $this->token;

            // Get recieved values from post data.
            $received_values = (array) stripslashes_deep( $_POST );

            foreach ( $received_values as $key => $value ) {
                $postdata .= '&' . $key . '=' . $value;
            }

            // Send back post vars to PagSeguro.
            $params = array(
                'body'          => $postdata,
                'sslverify'     => false,
                'timeout'       => 30
            );

            // Post back to get a response.
            $response = wp_remote_post( $this->pagseguro_ipn, $params );

            // Check to see if the request was valid.
            if ( !is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], 'VERIFICADO' ) == 0 ) ) {

                return true;
            }

            return false;
        }

        /**
         * Check PagSeguro API Response.
         *
         * @return void
         */
        public function check_pagseguro_ipn_response() {

            if ( isset( $_POST['Referencia'] ) ) {

                if ( !empty( $this->token ) ) {

                    @ob_clean();

                    $posted = stripslashes_deep( $_POST );

                    if ( $this->check_ipn_request_is_valid() ) {

                        header( 'HTTP/1.1 200 OK' );

                        do_action( 'valid_pagseguro_ipn_request', $posted );

                    } else {

                        wp_die( __( 'PagSeguro Request Failure', 'wcpagseguro' ) );

                    }
                }

            }

        }

        /**
         * Successful Payment!
         *
         * @param array $posted
         * @return void
         */
        public function successful_request( $posted ) {
            global $woocommerce;

            if ( !empty( $posted['Referencia'] ) ) {
                $order_key = $posted['Referencia'];
                $order_id = (int) str_replace( $this->invoice_prefix, '', $order_key );

                $order = new WC_Order( $order_id );

                // Checks whether the invoice number matches the order.
                // If true processes the payment.
                if ( $order->id === $order_id ) {

                    $order_status = sanitize_title( $posted['StatusTransacao'] );

                    switch ( $order_status ) {
                        case 'completo':

                            // Order details.
                            if ( !empty( $posted['TransacaoID'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'PagSeguro Transaction ID', 'wcpagseguro' ),
                                    $posted['TransacaoID']
                                );
                            }
                            if ( !empty( $posted['CliEmail'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payer email', 'wcpagseguro' ),
                                    $posted['CliEmail']
                                );
                            }
                            if ( !empty( $posted['CliNome'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payer name', 'wcpagseguro' ),
                                    $posted['CliNome']
                                );
                            }
                            if ( !empty( $posted['TipoPagamento'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payment type', 'wcpagseguro' ),
                                    $posted['TipoPagamento']
                                );
                            }

                            // Payment completed.
                            $order->add_order_note( __( 'Payment completed.', 'wcpagseguro' ) );
                            $order->payment_complete();

                            break;
                        case 'aguardando-pagto':
                            $order->add_order_note( __( 'Awaiting payment.', 'wcpagseguro' ) );

                            break;
                        case 'aprovado':
                            $order->update_status( 'on-hold', __( 'Payment approved, awaiting compensation.', 'wcpagseguro' ) );

                            break;
                        case 'em-analise':
                            $order->update_status( 'on-hold', __( 'Payment approved, under review by PagSeguro.', 'wcpagseguro' ) );

                            break;
                        case 'cancelado':
                            $order->update_status( 'cancelled', __( 'Payment canceled by PagSeguro.', 'wcpagseguro' ) );

                            break;

                        default:
                            // No action xD.
                            break;
                    }
                }
            }
        }

        /**
         * Adds error message when not configured the PagSeguro email.
         *
         * @return string Error Mensage.
         */
        public function mail_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your email address in PagSeguro. %sClick here to configure!%s' , 'wcpagseguro' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

        /**
         * Adds error message when not configured the PagSeguro token.
         *
         * @return string Error Mensage.
         */
        public function token_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' .sprintf( __( '<strong>Gateway Disabled</strong> You should inform your token in PagSeguro. %sClick here to configure!%s' , 'wcpagseguro' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

    } // class WC_PagSeguro_Gateway.
} // function wcpagseguro_gateway_load.

/**
 * Hidden when the purchase is outside the Brazil.
 */
add_filter( 'woocommerce_available_payment_gateways', 'wcpagseguro_hidden_when_is_outside_brasil' );

function wcpagseguro_hidden_when_is_outside_brasil( $available_gateways ) {

    if ( isset( $_REQUEST['country'] ) && $_REQUEST['country'] != 'BR' ) {

        // remove standard shipping option.
        unset( $available_gateways['pagseguro'] );
    }

    return $available_gateways;
}

/**
 * Process billing fields in checkout.
 */
add_action( 'woocommerce_checkout_process', 'wcpagseguro_checkout_valid_fields' );

function wcpagseguro_checkout_valid_fields() {
    global $woocommerce;

    // Valid address format.
    if ( $_POST['billing_address_1'] ) {

        $address = $_POST['billing_address_1'];
        $address = str_replace( ' ', '', $address );
        $pattern = '/([^\,\d]*),([0-9]*)/';
        $results = preg_match_all($pattern, $address, $out);

        if ( empty( $out[2] ) || !is_numeric( $out[2][0] ) ) {
            $woocommerce->add_error( __( '<strong>Address</strong> format is invalid. Example of correct format: "Av. Paulista, 460"', 'wcpagseguro' ) );
        }

    }
}
