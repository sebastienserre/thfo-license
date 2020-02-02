<?php

namespace THFO\Licence;

use function _e;
use function add_action;
use function admin_url;
use function class_exists;
use function delete_option;
use function dirname;
use function function_exists;
use function get_field;
use function get_option;
use function get_transient;
use function load_plugin_textdomain;
use function set_transient;
use function update_option;
use function var_dump;
use function version_compare;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_verify_nonce;
use const DAY_IN_SECONDS;
use const THFO_PLUGIN_NAME;
use const THFO_PLUGIN_VERSION;
use const THFO_CONSUMER_KEY;
use const THFO_CONSUMER_SECRET;
use const THFO_OPENWP_PLUGIN_FILE;
use const WP_PLUGIN_ID;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly.

class Licence {
	private $ck;
	private $cs;
	private $key;
	private $product_id;
	private $order_id;
	private $plugin_version;
	private $plugin;
	private $plugin_name;
	private $slug;


	public function __construct() {
		$this->ck             = THFO_CONSUMER_KEY;
		$this->cs             = THFO_CONSUMER_SECRET;
		$this->plugin_version = THFO_PLUGIN_VERSION;
		$this->plugin         = THFO_OPENWP_PLUGIN_FILE;
		$this->slug           = THFO_SLUG;
		$this->plugin_name    = THFO_PLUGIN_NAME;
		$this->key            = $this->setKey();
		$this->order_id       = $this->set_order_id();
		$this->product_id     = $this->set_product_id();

		add_action( 'acf/save_post', [ $this, 'launch_check' ], 15 );
		if ( ! $this->check_key_validity() ) {
			add_action( 'admin_notices', [ $this, 'invalid_key_notice' ] );
		}

		if ( function_exists( 'get_field' ) && ! empty( get_field( 'api_key', 'options' ) ) ) {
			add_action( 'admin_notices', [ $this, 'empty_key_notice' ] );
		} elseif ( empty( get_option( 'openagenda4wp_api' ) ) ) {
			add_action( 'admin_notices', [ $this, 'empty_key_notice' ] );
		}

		add_action( 'admin_notices', [ $this, 'activation_needed' ] );

		add_action( 'admin_init', [ $this, 'get_version' ] );
		add_action( 'admin_init', [ $this, 'activate_deactivate' ] );
		add_filter( 'site_transient_update_plugins', [ $this, 'push_update' ] );
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Load plugin textdomain.
	 * TextDomain for this class and only it is "openwp_licence"
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'openwp_licence', false, THFO_PLUGIN_NAME . '/licence/lang' );
	}

	public function get_key_access() {
		$url          = "https://thivinfo.com/wp-json/lmfwc/v2/licenses/$this->key?consumer_key=$this->ck&consumer_secret=$this->cs";
		$decoded_body = $this->get_decoded_body( $url );

		return $decoded_body["data"];
	}

	public function get_order_id() {
		$orderid = get_transient( 'thfo_license_order_id' );
		if ( empty( $orderid ) ) {
			$decoded_body = $this->get_key_access();
			$orderid      = $decoded_body['orderId'];
			set_transient( 'thfo_license_order_id', $orderid, DAY_IN_SECONDS * 10 );
		}

		return $orderid;
	}

	public function set_product_id() {
		$product_id = $this->get_product_id();
		return $product_id;
	}

	public function get_product_id() {
		$productid = get_transient( 'thfo_license_product_id' );
		if ( empty( $productid ) ) {
			$decoded_body = $this->get_key_access();
			$productid    = $decoded_body["productId"];
			set_transient( 'thfo_license_product_id', $productid, DAY_IN_SECONDS * 10 );
		}

		return $productid;
	}

	public function set_order_id() {
		$order_id = $this->get_order_id();

		return $order_id;
	}

	public function setKey() {
		$key = $this->getKey();

		return $key;
	}

	/**
	 * @return mixed
	 */
	public function getKey() {
		$key = get_transient( 'thfo_license_key' );
		if ( empty( $key ) ) {
			if ( function_exists( 'get_field' ) && ! empty( get_field( 'api_key', 'options' ) ) ) {
				$key = get_field( 'api_key', 'options' );
			} elseif ( ! empty( get_option( 'openagenda4wp_api' ) ) ) {
				$key = get_option( 'openagenda4wp_api' );
			}
			set_transient( 'thfo_license_key', $key, DAY_IN_SECONDS * 10 );
		}

		return $key;
	}

	public function launch_check( $post_id ) {
		if ( 'options' === $post_id ) {
			$this->check_key_validity();
		}
	}

	public function get_decoded_body( $url ) {
		$decoded_body = get_transient( 'thfo_license_decoded' . $url );
		if ( empty( $decoded_body ) ) {
			$response = wp_remote_get( $url );
			if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$body         = wp_remote_retrieve_body( $response );
				$decoded_body = json_decode( $body, true );
				set_transient( 'thfo_license_decoded' . $url, $decoded_body, DAY_IN_SECONDS * 1 );
			}
		}
		if ( ! empty( $decoded_body ) ) {
			return $decoded_body;
		}

		return false;
	}

	public function check_key_validity() {
		if ( true === get_transient( 'thfo_license_key_valid' ) ) {
			return true;
		}


		$url          = "https://thivinfo.com/wp-json/lmfwc/v2/licenses/$this->key?consumer_key=$this->ck&consumer_secret=$this->cs";
		$decoded_body = $this->get_decoded_body( $url );
		if ( $decoded_body['success'] && $this->is_allowed_to_activate() ) {
			set_transient( 'thfo_license_key_valid', true, DAY_IN_SECONDS * 1 );

			return true;
		}
		set_transient( 'thfo_license_key_valid', false, DAY_IN_SECONDS * 1 );

		return false;
	}

	public function invalid_key_notice() {

		?>
        <div class="notice notice-error">

            <p>
				<?php
				_e( 'The API key seems invalid, please check again', 'openwp_licence' );
				?>
            </p>
        </div>
		<?php
	}

	public function empty_key_notice() {

		?>
        <div class="notice notice-error">

            <p>
				<?php

				_e( 'The API key field seems empty, please check again', 'openwp_licence' );
				?>
            </p>
        </div>
		<?php
	}

	public function get_product_data( $product_id = '' ) {
		if ( empty( $product_id ) ) {
			$product_id = WP_PLUGIN_ID;
		}
		$product_data = get_transient( 'thfo_license_product_data' . $product_id );
		if ( empty( $product_data ) ) {
			$url          = "https://thivinfo.com/wp-json/wc/v3/products/$product_id?consumer_key=ck_0e7d2eddb58ea1a2d56212e1042dbeb0511274c3&consumer_secret=cs_b0b9fb497e534026e45d2ce2335b8297fe90ead9";
			$product_data = $this->get_decoded_body( $url );
			set_transient( 'thfo_license_product_data' . $product_id, $product_data, DAY_IN_SECONDS * 1 );
		}

		return $product_data;
	}

	public function get_version() {
		$decoded_body = $this->get_product_data();
		if ( ! empty( $decoded_body["tags"] ) ) {
			$version = $decoded_body["tags"][0]["name"];

			return $version;
		}
	}

	public function is_allowed_to_activate() {
		$url     = "https://thivinfo.com/wp-json/lmfwc/v2/licenses/validate/$this->key?consumer_key=$this->ck&consumer_secret=$this->cs";
		$decoded = $this->get_decoded_body( $url );
		$nb      = $decoded['data']['timesActivated'];
		$nb_max  = $decoded['data']['timesActivatedMax'];
		if ( $nb <= $nb_max ) {
			return true;
		}

		return false;
	}

	public function activate_deactivate() {
		if ( ! empty( $_GET['activate'] ) && '1' === $_GET['activate'] ) {
			$this->activate_key();
		}
		if ( ! empty( $_GET['activate'] ) && '2' === $_GET['activate'] ) {
			$this->deactivate_key();
		}

	}

	public function is_activated() {
		if ( ! empty( get_option( 'thfo_key_validated' ) && '1' === get_option( 'thfo_key_validated' ) ) ) {
			return true;
		}

		return false;
	}

	public function deactivate_key() {
		if ( ! empty( $_GET['activate'] ) && '2' === $_GET['activate'] && wp_verify_nonce( $_GET['_wpnonce'], 'validate' ) ) {
			$url      = "https://thivinfo.com/wp-json/lmfwc/v2/licenses/deactivate/$this->key?consumer_key=$this->ck&consumer_secret=$this->cs";
			$activate = $this->get_decoded_body( $url );
			if ( $activate['success'] ) {
				delete_option( 'thfo_key_validated' );

				return true;
			}
		}

		return false;
	}

	public function activate_key() {
		if ( '1' === get_option( 'thfo_key_validated' ) ) {
			return;
		}
		if ( ! empty( $_GET['activate'] ) && '1' === $_GET['activate'] && wp_verify_nonce( $_GET['_wpnonce'], 'validate' ) ) {
			$url      = "https://thivinfo.com/wp-json/lmfwc/v2/licenses/activate/$this->key?consumer_key=$this->ck&consumer_secret=$this->cs";
			$activate = $this->get_decoded_body( $url );
			if ( $activate['success'] ) {
				update_option( 'thfo_key_validated', '1' );

				return true;
			}
		}

		return false;
	}

	public function activation_needed() {
		if ( ! $this->is_activated() ) {
			?>
            <div class="notice notice-error">
                <p><?php _e( 'Please activate your OpenAgenda WP Pro licence key', 'openwp_licence' ); ?></p>
                <p>
                    <a href="<?php echo admin_url( 'options-general.php?page=openagenda-settings' ); ?>"><?php _e( 'Settings', 'openwp_licence' ); ?></a>
                </p>
            </div>
			<?php
		}
	}

	public function push_update( $transient ) {
		if ( ! $this->is_activated() ) {
			return;
		}

		if ( false == $remote = get_transient( "thfo_upgrade_$this->slug" ) ) {

			// info.json is the file with the actual plugin information on your server
			$remote = wp_remote_get( "https://thivinfo.com/$this->plugin_name.json", array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				)
			);

			if ( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
				set_transient( "thfo_upgrade_$this->slug", $remote, 43200 ); // 12 hours cache
			}

		}

		if ( $remote ) {

			$remote = json_decode( $remote['body'] );

			// your installed plugin version should be on the line below! You can obtain it dynamically of course
			if ( $remote && version_compare( $this->plugin_version, $remote->version, '<' ) && version_compare( $remote->requires,
					get_bloginfo( 'version' ), '<' ) ) {
				$res                                 = new \stdClass();
				$res->slug                           = $this->slug;
				$res->plugin                         = $this->plugin;
				$res->new_version                    = $remote->version;
				$res->tested                         = $remote->tested;
				$res->package                        = $remote->download_url;
				$transient->response[ $res->plugin ] = $res;
			}
		}

		return $transient;
	}
}

new Licence();
