<?php
namespace PWAcommerce\Includes;

use Automattic\WooCommerce\Client;
use \PWAcommerce\Includes\Options;
use \PWAcommerce\Includes\Uploads;

/**
 *  Class that sets up the rest routes for the app
 */
class PWAcommerce_API
{

	/**
	 * Returns an instance of the woocommerce api client with configurations in place
	 */
	protected function get_client() {

		$pwacommerce_options = new Options();

		return new Client(
			get_site_url(),
			$pwacommerce_options->get_setting('consumer_key'),
			$pwacommerce_options->get_setting('consumer_secret'),
			[
				'wp_api' => true,
				'version' => 'wc/v2',
			]
		);
	}

	/**
	 * Registers all the plugins rest routes
	 */
	public function register_pwacommerce_routes() {

		$pwacommerce_options = new Options();

		// Checks that the api keys were added to the database
		if ( $pwacommerce_options->get_setting('consumer_key') !== '' &&
		$pwacommerce_options->get_setting('consumer_secret') !== '' ) {

			register_rest_route( 'pwacommerce', '/export-manifest', [
				'methods' => 'GET',
				'callback' => [ $this, 'export_manifest' ],
			]);

			register_rest_route( 'pwacommerce', '/categories', [
				'methods' => 'GET',
				'callback' => [ $this, 'view_categories' ]
			]);

			register_rest_route( 'pwacommerce', '/products', [
				'methods' => 'GET',
				'callback' => [ $this, 'view_products' ],
				'args' => [
					'categId' => [
						'required' => false,
						'type' => 'integer',
					],
				],
			]);

			register_rest_route( 'pwacommerce', '/product/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => [ $this, 'view_product' ]
			]);

			register_rest_route( 'pwacommerce', '/reviews/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => [ $this, 'view_reviews' ]
			]);

			register_rest_route( 'pwacommerce', '/product-variations/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => [ $this, 'view_product_variations' ],
			]);

			register_rest_route( 'pwacommerce', '/proceed-checkout', [
				'methods' => 'POST',
				'callback' => [ $this, 'checkout_redirect' ],
			]);

		}
	}

	/**
	 * Exports the app manifest
	 */
	public function export_manifest() {

		$site_name = get_bloginfo('name');

		// add basic settings to manifest
		$arr_manifest = [
			'name' => $site_name,
			'short_name' => $site_name,
			'start_url' => home_url(),
			'display' => 'standalone',
			'orientation' => 'any',
			'theme_color' => '#a333c8',
			'background_color' => '#a333c8',
		];

		// add paths to all icon sizes if there is an icon uploaded
		$options = new Options();
		$icon = $options->get_setting( 'icon' );

		if ( $icon != '' ) {

			$base_path = $icon;
			$arr_manifest['icons'] = [];
			$uploads = new Uploads();

			foreach ( Uploads::$manifest_sizes as $manifest_size  ) {

				$icon_path = $uploads->get_file_url( $manifest_size . $base_path );

				if ( $icon_path != '' ) {

					$arr_manifest['icons'][] = [
						"src" => $icon_path,
						"sizes" => $manifest_size . 'x' . $manifest_size,
						"type" => "image/png"
					];
				}
			}
		}

		echo wp_json_encode( $arr_manifest );
		exit();
	}

	/**
	 * Returns a json response with 100 categories
	 */
	public function view_categories( \WP_REST_Request $request ) {

		$woocommerce = $this->get_client();

		return $woocommerce->get( 'products/categories', [ 'per_page' => 100 ] );

	}

	/**
	 * Returns a json response with the info of the latest 100 products in a category if the request has a categId param
	 * if the categoryId param is missing it returns a json with the info of the latest 100 featured products, if there are none
	 * it returns the info of the last 10 products added to the site
	 */
	public function view_products( \WP_REST_Request $request ) {
		$woocommerce = $this->get_client();

		if ( isset( $request['categId'] ) )  {
			return $woocommerce->get( 'products', [ 'category' => $request['categId'], 'per_page' => 100, 'orderby' => 'date'  ] );
		}

		$featured = $woocommerce->get( 'products', [ 'featured' => true , 'per_page' => 100, 'orderby' => 'date' ] );

		if ( !empty( $featured ) ) {
			return $featured;
		}

		return $woocommerce->get( 'products', ['per_page' => 10, 'orderby' => 'date' ] );

	}


	/**
	 * Returns a json response with a product based on its id
	 */
	public function view_product( \WP_REST_Request $request ){

		$woocommerce = $this->get_client();
		$id = $request->get_param( 'id' );

		return $woocommerce->get( "products/$id" );
	}

	/**
	 * Returns a json response with the latest 100 reviews of product based on its id
	 */
	public function view_reviews( \WP_REST_Request $request ) {

		$woocommerce = $this->get_client();
		$id = $request->get_param( 'id' );

		return $woocommerce->get( "products/$id/reviews", [ 'per_page' => 100, 'orderby' => 'date' ] );
	}

	/**
	 * Returns a json response with the variations of product based on its id
	 */
	public function view_product_variations( \WP_REST_Request $request ) {

		$woocommerce = $this->get_client();
		$id = $request->get_param( 'id' );

		return $woocommerce->get( "products/$id/variations", [ 'per_page' => 100 ] );
	}

	/**
	 * Adds the items that came with the request to the cart and redirects to the desktop checkout page
	 */
	public function checkout_redirect ( \WP_REST_Request $request ) {


		$products = json_decode( $request['items'], true );

		// make sure the cart content is set as cookie
		do_action( 'woocommerce_set_cart_cookies', true );
		global $woocommerce;

		foreach( $products as $product ) {

			if  ( isset( $product['id'] ) && isset( $product['quantity'] ) && is_numeric( $product['id'] ) && is_numeric( $product['quantity'] ) )  {

				// if the product has a variation add it as a variable product
				if ( isset( $product['variationId'] ) && is_numeric( $product['variationId'] ) ) {

					$woocommerce->cart->add_to_cart( $product['id'], $product['quantity'], $product['variationId'] );

				} else {

					$woocommerce->cart->add_to_cart( $product['id'], $product['quantity'] );
				}

			}
		}

		wp_redirect( $woocommerce->cart->get_checkout_url() );
		exit();

	}
}

