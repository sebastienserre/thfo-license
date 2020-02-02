# THFO License

## Introduction
This little PHP Class allow you to manage API Key Validity and update your premium WordPress Plugins

## How It Works?
* Simply clone this repo in your WordPress plugin & include class-licence.php
* In your main website add [License Manager for WooCommerce](https://wordpress.org/plugins/license-manager-for-woocommerce/)
* Follow this [link](https://rudrastyh.com/wordpress/self-hosted-plugin-update.html) to create the automatic upgrade mechanism

## In your Settings Plugins
Add a setting fields to let your customer enter the API Key
```php
		if ( '1' === get_option( 'thfo_key_validated' ) ){
			$text = __( 'Deactivate Key', 'wp-pericles-import' );
			$args = [
				'activate' => '2',
				'key'      => get_option( 'thfo_api_key' ),
			];

		} else {
			$text = __( 'Activate Key', 'wp-pericles-import' );
			$args = [
				'activate' => '1',
				'key'      => get_option( 'thfo_api_key' ),
			];
		}
		global $wp;
		$activation_url = wp_nonce_url(
			add_query_arg(
				$args,
				admin_url( 'options-general.php?page=wppi-settings' )
			),
			'validate',
			'_wpnonce'
		);
		?>
		<input type="text" name="thfo_api_key" value="<?php echo esc_html( get_option( 'thfo_api_key' ) ); ?>"/>
		<?php $url = esc_url( 'https://thivinfo.com' ); ?>
		<?php // translators: Add the OpenAGenda URL. ?>
		<p><?php printf( wp_kses( __( 'Find it in your account on <a href="%s" target="_blank">Thivinfo</a>. ', 'wp-pericles-import' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( $url ) ); ?></p>
		<a href="<?php echo esc_url( $activation_url ); ?>"><?php echo $text; ?></a>
		<?php
	}
```
Feel free to adapt it according your needs.