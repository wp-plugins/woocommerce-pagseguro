<?php
/**
 * Admin View: Notice - Token missing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="error">
	<p><strong><?php _e( 'PagSeguro Disabled', 'woocommerce-pagseguro' ); ?></strong>: <?php printf( __( 'You should inform your token. %s', 'woocommerce-pagseguro' ), '<a href="' . esc_url( $this->admin_url() ) . '">' . __( 'Click here to configure!', 'woocommerce-pagseguro' ) . '</a>' ); ?>
	</p>
</div>
