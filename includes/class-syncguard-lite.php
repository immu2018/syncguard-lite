<?php
/**
 * Main plugin class.
 *
 * @package SyncGuardLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncGuard_Lite {

	const HISTORY_OPTION = 'syncguard_lite_import_history';
	const THRESHOLD_OPTION = 'syncguard_lite_price_threshold';
	const PREVIEW_TTL = 30 * MINUTE_IN_SECONDS;
	const MAX_FILE_SIZE = 2 * 1024 * 1024;
	const MAX_ROWS = 5000;

	/**
	 * Register plugin functionality.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_syncguard_lite_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_syncguard_lite_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_syncguard_lite_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );

		// This plugin uses WooCommerce product CRUD and does not write to order tables.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 */
	public static function declare_hpos_compatibility() {
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' )
		) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				SYNCGUARD_LITE_FILE,
				true
			);
		}
	}

	/**
	 * Register the WooCommerce submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'SyncGuard Lite', 'syncguard-lite' ),
			__( 'SyncGuard Lite', 'syncguard-lite' ),
			'manage_woocommerce',
			'syncguard-lite',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Show a dependency notice when WooCommerce is unavailable.
	 */
	public static function woocommerce_notice() {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'SyncGuard Lite requires WooCommerce to be installed and active.', 'syncguard-lite' );
		echo '</p></div>';
	}

	/**
	 * Render the plugin admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'syncguard-lite' ) );
		}

		$notice = isset( $_GET['sg_notice'] ) ? sanitize_key( wp_unslash( $_GET['sg_notice'] ) ) : '';
		$token  = isset( $_GET['sg_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['sg_preview'] ) ) : '';
		$preview = $token ? get_transient( self::preview_key( $token ) ) : false;
		$history = get_option( self::HISTORY_OPTION, array() );
		$threshold = (float) get_option( self::THRESHOLD_OPTION, 30 );

		echo '<div class="wrap syncguard-wrap">';
		echo '<h1>SyncGuard Lite <span class="sg-version">v' . esc_html( SYNCGUARD_LITE_VERSION ) . '</span></h1>';
		echo '<p class="description">' . esc_html__( 'Safely review supplier CSV changes before updating WooCommerce products.', 'syncguard-lite' ) . '</p>';

		self::render_notice( $notice );

		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Activate WooCommerce to use SyncGuard Lite.', 'syncguard-lite' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="sg-card">';
		echo '<h2>' . esc_html__( '1. Upload supplier CSV', 'syncguard-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Required columns: sku, price, stock. Price must be a number in your store currency; stock must be a whole number.', 'syncguard-lite' ) . '</p>';
		echo '<p><a href="' . esc_url( plugins_url( 'sample-supplier-feed.csv', SYNCGUARD_LITE_FILE ) ) . '" download>' . esc_html__( 'Download sample CSV', 'syncguard-lite' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="syncguard_lite_preview">';
		wp_nonce_field( 'syncguard_lite_preview', 'syncguard_lite_nonce' );
		echo '<input type="file" name="supplier_csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'Preview changes', 'syncguard-lite' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'For safety, the preview is temporary and expires after 30 minutes. No product data changes during preview.', 'syncguard-lite' ) . '</p>';
		echo '</div>';

		if ( is_array( $preview ) && isset( $preview['rows'] ) ) {
			self::render_preview( $token, $preview );
		}

		echo '<div class="sg-card">';
		echo '<h2>' . esc_html__( 'Settings', 'syncguard-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Flag price changes above this percentage for extra review. Flagged rows are never applied unless you explicitly select them.', 'syncguard-lite' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="syncguard_lite_save_settings">';
		wp_nonce_field( 'syncguard_lite_save_settings', 'syncguard_lite_nonce' );
		echo '<label for="sg-price-threshold">' . esc_html__( 'Price change warning threshold (%)', 'syncguard-lite' ) . '</label> ';
		echo '<input id="sg-price-threshold" type="number" min="1" max="500" step="1" name="price_threshold" value="' . esc_attr( $threshold ) . '"> ';
		submit_button( __( 'Save settings', 'syncguard-lite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';

		self::render_history( $history );

		echo '<div class="sg-footer"><strong>SyncGuard Lite</strong> · ' .
			esc_html__( 'Open-source portfolio project. Always test imports on a staging site and keep a database backup.', 'syncguard-lite' ) .
			'</div>';
		echo '</div>';

		echo '<style>
			.syncguard-wrap{max-width:1200px}.sg-version{font-size:12px;color:#646970;font-weight:400}
			.sg-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0}
			.sg-card h2{margin-top:0}.sg-table-wrap{overflow-x:auto}.sg-table{width:100%;border-collapse:collapse}
			.sg-table th,.sg-table td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
			.sg-table th{background:#f6f7f7}.sg-status{display:inline-block;padding:3px 8px;border-radius:12px;background:#f0f0f1;font-size:12px}
			.sg-status-warning{background:#fcf0c3}.sg-status-error{background:#fce8e6}.sg-status-ok{background:#e5f5e0}
			.sg-footer{color:#646970;margin:22px 0}.sg-metric{display:inline-block;margin:0 18px 8px 0}
			.sg-actions{margin-top:15px}.sg-small{color:#646970;font-size:12px}
			.syncguard-wrap input[type=checkbox]{appearance:auto;-webkit-appearance:checkbox;width:18px;height:18px;min-width:18px;vertical-align:middle;cursor:pointer}
		</style>';
	}

	/**
	 * Render notices based on redirect code.
	 *
	 * @param string $notice Notice key.
	 */
	private static function render_notice( $notice ) {
		$messages = array(
			'preview_ready' => array( 'success', __( 'Preview generated. No products have been changed.', 'syncguard-lite' ) ),
			'applied'       => array( 'success', __( 'Selected product updates have been applied.', 'syncguard-lite' ) ),
			'settings_saved'=> array( 'success', __( 'Settings saved.', 'syncguard-lite' ) ),
			'invalid_file'  => array( 'error', __( 'The CSV file could not be processed. Check the file type, size and required columns.', 'syncguard-lite' ) ),
			'upload_missing' => array( 'error', __( 'No file was received. Choose a CSV file and try again.', 'syncguard-lite' ) ),
			'upload_partial' => array( 'error', __( 'The CSV upload was interrupted. Please upload the file again.', 'syncguard-lite' ) ),
			'upload_size' => array( 'error', __( 'The CSV exceeds the server upload limit. Increase PHP upload_max_filesize and post_max_size, or use a smaller file.', 'syncguard-lite' ) ),
			'upload_tmp' => array( 'error', __( 'The server could not save the uploaded file. Check PHP upload_tmp_dir permissions and available disk space.', 'syncguard-lite' ) ),
			'upload_failed' => array( 'error', __( 'The server failed to receive the CSV (PHP upload error). Check the server error log.', 'syncguard-lite' ) ),
			'bad_extension' => array( 'error', __( 'Please upload a file with the .csv extension.', 'syncguard-lite' ) ),
			'csv_unreadable' => array( 'error', __( 'The uploaded CSV could not be read. Check that it is a valid, non-empty CSV file.', 'syncguard-lite' ) ),
			'csv_headers' => array( 'error', __( 'CSV headers were not recognised. The first row must contain sku, price, stock (comma- or semicolon-separated).', 'syncguard-lite' ) ),
			'expired'       => array( 'error', __( 'This preview has expired. Upload the CSV again.', 'syncguard-lite' ) ),
			'no_selection' => array( 'warning', __( 'Select at least one valid row to apply.', 'syncguard-lite' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}
	}

	/**
	 * Handle CSV upload and create a no-write preview.
	 */
	public static function handle_preview() {
		self::authorize_post( 'syncguard_lite_preview' );

		if ( ! isset( $_FILES['supplier_csv'] ) || ! is_array( $_FILES['supplier_csv'] ) ) {
			self::redirect( 'upload_missing' );
		}

		$file = $_FILES['supplier_csv'];
		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error_notices = array(
				UPLOAD_ERR_INI_SIZE   => 'upload_size',
				UPLOAD_ERR_FORM_SIZE  => 'upload_size',
				UPLOAD_ERR_PARTIAL    => 'upload_partial',
				UPLOAD_ERR_NO_FILE    => 'upload_missing',
				UPLOAD_ERR_NO_TMP_DIR => 'upload_tmp',
				UPLOAD_ERR_CANT_WRITE => 'upload_tmp',
				UPLOAD_ERR_EXTENSION  => 'upload_failed',
			);
			self::redirect( isset( $error_notices[ $upload_error ] ) ? $error_notices[ $upload_error ] : 'upload_failed' );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			self::redirect( 'upload_tmp' );
		}

		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || $actual_size < 1 ) {
			self::redirect( 'csv_unreadable' );
		}
		if ( $actual_size > self::MAX_FILE_SIZE ) {
			self::redirect( 'upload_size' );
		}

		$filename = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			self::redirect( 'bad_extension' );
		}

		$parse_result = self::parse_csv( $file['tmp_name'] );
		if ( false === $parse_result ) {
			self::redirect( 'csv_unreadable' );
		}
		if ( isset( $parse_result['error'] ) ) {
			self::redirect( 'csv_headers' );
		}
		$rows = $parse_result;
		if ( empty( $rows ) ) {
			self::redirect( 'csv_unreadable' );
		}

		$token = wp_generate_password( 32, false, false );
		$preview = array(
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
			'rows'       => $rows,
		);

		set_transient( self::preview_key( $token ), $preview, self::PREVIEW_TTL );
		self::redirect( 'preview_ready', array( 'sg_preview' => $token ) );
	}

	/**
	 * Parse and validate the supplier CSV.
	 *
	 * @param string $path Temporary uploaded file path.
	 * @return array|false
	 */
	private static function parse_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return false;
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return false;
		}

		$comma_count = substr_count( $first_line, ',' );
		$semicolon_count = substr_count( $first_line, ';' );
		$delimiter = $semicolon_count > $comma_count ? ';' : ',';
		rewind( $handle );
		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			return false;
		}

		$headers = array_map(
			static function ( $header ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) ) );
			},
			$headers
		);

		$sku_index   = array_search( 'sku', $headers, true );
		$price_index = array_search( 'price', $headers, true );
		$stock_index = array_search( 'stock', $headers, true );

		if ( false === $sku_index || false === $price_index || false === $stock_index ) {
			fclose( $handle );
			return array( 'error' => 'headers' );
		}

		$seen = array();
		$rows = array();
		$row_number = 1;
		$threshold = (float) get_option( self::THRESHOLD_OPTION, 30 );

		while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$row_number++;

			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}

			$sku = isset( $data[ $sku_index ] ) ? sanitize_text_field( trim( $data[ $sku_index ] ) ) : '';
			$price_raw = isset( $data[ $price_index ] ) ? trim( $data[ $price_index ] ) : '';
			$stock_raw = isset( $data[ $stock_index ] ) ? trim( $data[ $stock_index ] ) : '';

			$row = array(
				'row_number' => $row_number,
				'sku'        => $sku,
				'price'      => '',
				'stock'      => '',
				'product_id' => 0,
				'old_price'  => '',
				'old_stock'  => '',
				'status'     => 'valid',
				'message'    => __( 'Ready for approval', 'syncguard-lite' ),
				'warning'    => false,
			);

			if ( '' === $sku ) {
				$row['status']  = 'error';
				$row['message'] = __( 'Missing SKU', 'syncguard-lite' );
			} elseif ( isset( $seen[ $sku ] ) ) {
				$row['status']  = 'error';
				$row['message'] = __( 'Duplicate SKU in CSV', 'syncguard-lite' );
			} else {
				$seen[ $sku ] = true;

				if ( '' === $price_raw || ! is_numeric( $price_raw ) || (float) $price_raw < 0 ) {
					$row['status']  = 'error';
					$row['message'] = __( 'Invalid price', 'syncguard-lite' );
				} elseif ( '' === $stock_raw || ! preg_match( '/^\d+$/', $stock_raw ) ) {
					$row['status']  = 'error';
					$row['message'] = __( 'Stock must be a whole number (0 or greater)', 'syncguard-lite' );
				} else {
					$product_id = wc_get_product_id_by_sku( $sku );

					if ( ! $product_id ) {
						$row['status']  = 'error';
						$row['message'] = __( 'SKU not found in WooCommerce', 'syncguard-lite' );
					} else {
						$product = wc_get_product( $product_id );

						if ( ! $product ) {
							$row['status']  = 'error';
							$row['message'] = __( 'Product could not be loaded', 'syncguard-lite' );
						} else {
							$new_price = wc_format_decimal( $price_raw );
							$old_price = $product->get_regular_price();
							$old_stock = $product->get_stock_quantity();

							$row['product_id'] = $product_id;
							$row['price']      = $new_price;
							$row['stock']      = (int) $stock_raw;
							$row['old_price']  = '' === $old_price ? '—' : wc_format_localized_price( $old_price );
							$row['old_stock']  = null === $old_stock ? 'Not managed' : (int) $old_stock;

							if ( '' !== $old_price && (float) $old_price > 0 ) {
								$change = abs( ( (float) $new_price - (float) $old_price ) / (float) $old_price ) * 100;

								if ( $change > $threshold ) {
									$row['warning'] = true;
									$row['status']  = 'warning';
									$row['message'] = sprintf(
										/* translators: %s: percentage */
										__( 'Price changes by %s%% – review carefully', 'syncguard-lite' ),
										number_format_i18n( $change, 1 )
									);
								}
							}
						}
					}
				}
			}

			$rows[] = $row;
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Render the preview table.
	 *
	 * @param string $token Preview token.
	 * @param array  $preview Preview data.
	 */
	private static function render_preview( $token, $preview ) {
		if ( (int) $preview['user_id'] !== get_current_user_id() ) {
			return;
		}

		$valid_count = 0;
		foreach ( $preview['rows'] as $row ) {
			if ( in_array( $row['status'], array( 'valid', 'warning' ), true ) ) {
				$valid_count++;
			}
		}

		echo '<div class="sg-card">';
		echo '<h2>' . esc_html__( '2. Review supplier changes', 'syncguard-lite' ) . '</h2>';
		echo '<p>';
		echo '<span class="sg-metric"><strong>' . esc_html( count( $preview['rows'] ) ) . '</strong> ' . esc_html__( 'rows', 'syncguard-lite' ) . '</span>';
		echo '<span class="sg-metric"><strong>' . esc_html( $valid_count ) . '</strong> ' . esc_html__( 'valid / reviewable', 'syncguard-lite' ) . '</span>';
		echo '<span class="sg-metric"><strong>' . esc_html( count( $preview['rows'] ) - $valid_count ) . '</strong> ' . esc_html__( 'errors', 'syncguard-lite' ) . '</span>';
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="syncguard_lite_apply">';
		echo '<input type="hidden" name="preview_token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'syncguard_lite_apply_' . $token, 'syncguard_lite_nonce' );

		echo '<div class="sg-table-wrap"><table class="sg-table">';
		echo '<thead><tr><th>' . esc_html__( 'Apply', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'CSV row', 'syncguard-lite' ) . '</th><th>SKU</th><th>' . esc_html__( 'Current price', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'New price', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'Current stock', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'New stock', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'Result', 'syncguard-lite' ) . '</th></tr></thead><tbody>';

		foreach ( $preview['rows'] as $index => $row ) {
			$reviewable = in_array( $row['status'], array( 'valid', 'warning' ), true );
			$status_class = 'error' === $row['status'] ? 'sg-status-error' : ( 'warning' === $row['status'] ? 'sg-status-warning' : 'sg-status-ok' );

			echo '<tr>';
			echo '<td>';
			if ( $reviewable ) {
				echo '<input type="checkbox" name="selected_rows[]" value="' . esc_attr( $index ) . '"' . checked( ! $row['warning'], true, false ) . '>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td>' . esc_html( $row['row_number'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['sku'] ) . '</code></td>';
			echo '<td>' . esc_html( $row['old_price'] ) . '</td>';
			echo '<td>' . esc_html( '' === $row['price'] ? '—' : wc_format_localized_price( $row['price'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $row['old_stock'] ? '—' : $row['old_stock'] ) . '</td>';
			echo '<td>' . esc_html( '' === $row['stock'] ? '—' : $row['stock'] ) . '</td>';
			echo '<td><span class="sg-status ' . esc_attr( $status_class ) . '">' . esc_html( $row['message'] ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
		echo '<p class="sg-small">' . esc_html__( 'Warning rows are unchecked by default. Error rows cannot be applied. Review every selected row before confirming.', 'syncguard-lite' ) . '</p>';
		echo '<div class="sg-actions">';
		submit_button( __( 'Apply selected updates', 'syncguard-lite' ), 'primary', 'submit', false );
		echo '</div></form></div>';
	}

	/**
	 * Apply only explicitly selected preview rows.
	 */
	public static function handle_apply() {
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
		self::authorize_post( 'syncguard_lite_apply_' . $token );

		$preview = $token ? get_transient( self::preview_key( $token ) ) : false;

		if ( ! is_array( $preview ) || (int) $preview['user_id'] !== get_current_user_id() ) {
			self::redirect( 'expired' );
		}

		$selected = isset( $_POST['selected_rows'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['selected_rows'] ) ) : array();
		$selected = array_values( array_unique( $selected ) );

		if ( empty( $selected ) ) {
			self::redirect( 'no_selection', array( 'sg_preview' => $token ) );
		}

		$applied = 0;
		$failed  = 0;

		foreach ( $selected as $index ) {
			if ( ! isset( $preview['rows'][ $index ] ) ) {
				$failed++;
				continue;
			}

			$row = $preview['rows'][ $index ];

			if ( ! in_array( $row['status'], array( 'valid', 'warning' ), true ) || empty( $row['product_id'] ) ) {
				$failed++;
				continue;
			}

			$product_id = wc_get_product_id_by_sku( $row['sku'] );
			if ( (int) $product_id !== (int) $row['product_id'] ) {
				$failed++;
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$failed++;
				continue;
			}

			try {
				$new_price = wc_format_decimal( $row['price'] );
				$product->set_regular_price( $new_price );

				$sale_price = $product->get_sale_price();
				if ( '' !== $sale_price && (float) $sale_price > (float) $new_price ) {
					$product->set_sale_price( '' );
					$product->set_price( $new_price );
				} elseif ( '' === $sale_price ) {
					$product->set_price( $new_price );
				}

				$product->set_manage_stock( true );
				$product->set_stock_quantity( (int) $row['stock'] );
				$product->set_stock_status( ( (int) $row['stock'] > 0 ) ? 'instock' : 'outofstock' );
				$product->save();

				$applied++;
			} catch ( Exception $exception ) {
				$failed++;
				error_log( 'SyncGuard Lite update failed for SKU ' . $row['sku'] . ': ' . $exception->getMessage() );
			}
		}

		self::add_history(
			array(
				'date'    => current_time( 'mysql' ),
				'applied' => $applied,
				'failed'  => $failed,
				'user'    => get_current_user_id(),
			)
		);

		delete_transient( self::preview_key( $token ) );
		self::redirect( 'applied' );
	}

	/**
	 * Save plugin settings.
	 */
	public static function save_settings() {
		self::authorize_post( 'syncguard_lite_save_settings' );

		$threshold = isset( $_POST['price_threshold'] ) ? absint( wp_unslash( $_POST['price_threshold'] ) ) : 30;
		$threshold = max( 1, min( 500, $threshold ) );

		update_option( self::THRESHOLD_OPTION, $threshold, false );
		self::redirect( 'settings_saved' );
	}

	/**
	 * Store a short import history.
	 *
	 * @param array $entry History entry.
	 */
	private static function add_history( $entry ) {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, 20 );
		update_option( self::HISTORY_OPTION, $history, false );
	}

	/**
	 * Render recent import history.
	 *
	 * @param array $history History.
	 */
	private static function render_history( $history ) {
		echo '<div class="sg-card"><h2>' . esc_html__( 'Recent import activity', 'syncguard-lite' ) . '</h2>';

		if ( empty( $history ) || ! is_array( $history ) ) {
			echo '<p>' . esc_html__( 'No approved imports yet.', 'syncguard-lite' ) . '</p></div>';
			return;
		}

		echo '<div class="sg-table-wrap"><table class="sg-table"><thead><tr><th>' . esc_html__( 'Date', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'Applied', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'Failed', 'syncguard-lite' ) . '</th><th>' . esc_html__( 'User ID', 'syncguard-lite' ) . '</th></tr></thead><tbody>';

		foreach ( $history as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['date'] ) . '</td>';
			echo '<td>' . esc_html( $item['applied'] ) . '</td>';
			echo '<td>' . esc_html( $item['failed'] ) . '</td>';
			echo '<td>' . esc_html( $item['user'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Verify capability and nonce for admin-post requests.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function authorize_post( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'syncguard-lite' ), 403 );
		}

		$nonce = isset( $_POST['syncguard_lite_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['syncguard_lite_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'syncguard-lite' ), 403 );
		}
	}

	/**
	 * Build transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function preview_key( $token ) {
		return 'syncguard_lite_preview_' . hash( 'sha256', $token );
	}

	/**
	 * Redirect back to plugin page.
	 *
	 * @param string $notice Notice.
	 * @param array  $args Extra query args.
	 */
	private static function redirect( $notice, $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'      => 'syncguard-lite',
					'sg_notice' => $notice,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
