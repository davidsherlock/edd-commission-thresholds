<?php
/**
 * Plugin Name:     Easy Digital Downloads - Commission Thresholds
 * Plugin URI:      https://sellcomet.com/downloads/commission-thresholds
 * Description:     Disable commissions until a specific sales or earnings threshold has been met.
 * Version:         1.0.0
 * Author:          Sell Comet
 * Author URI:      https://sellcomet.com
 * Text Domain:     edd-commission-thresholds
 * Domain Path:     languages
 *
 * @package         EDD\Commission_Thresholds
 * @author          Sell Comet
 * @copyright       Copyright (c) Sell Comet
 */


// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'EDD_Commission_Thresholds' ) ) {

    /**
     * Main EDD_Commission_Thresholds class
     *
     * @since       1.0.0
     */
    class EDD_Commission_Thresholds {

        /**
         * @var         EDD_Commission_Thresholds $instance The one true EDD_Commission_Thresholds
         * @since       1.0.0
         */
        private static $instance;


        /**
         * Get active instance
         *
         * @access      public
         * @since       1.0.0
         * @return      object self::$instance The one true EDD_Commission_Thresholds
         */
        public static function instance() {
            if( !self::$instance ) {
                self::$instance = new EDD_Commission_Thresholds();
                self::$instance->setup_constants();
                self::$instance->load_textdomain();
                self::$instance->hooks();
            }

            return self::$instance;
        }


        /**
         * Setup plugin constants
         *
         * @access      private
         * @since       1.0.0
         * @return      void
         */
        private function setup_constants() {
            // Plugin version
            define( 'EDD_COMMISSION_THRESHOLDS_VER', '1.0.0' );

            // Plugin path
            define( 'EDD_COMMISSION_THRESHOLDS_DIR', plugin_dir_path( __FILE__ ) );

            // Plugin URL
            define( 'EDD_COMMISSION_THRESHOLDS_URL', plugin_dir_url( __FILE__ ) );
        }


        /**
         * Run action and filter hooks
         *
         * @access      private
         * @since       1.0.0
         * @return      void
         */
        private function hooks() {

            if ( is_admin() ) {

                // Make sure we are at the minimum version of EDD Commissions - which is 3.4.6.
                add_action( 'admin_notices', array( $this, 'version_check_notice' ), 10 );

                // Register extension settings
                add_filter( 'eddc_settings', array( $this, 'settings' ), 1 );

                // Add verification nonce
                add_action( 'eddc_metabox_before_options', array( $this, 'add_commissions_meta_box_nonce' ), 10, 1 );

                // Add filterable "Threshold Type" options to Commissions meta box
                add_action( 'eddc_metabox_options_table_after', array( $this, 'add_commissions_meta_box_threshold_type_options' ), 10, 1 );

                // Add "Thresholds" table header and tooltip
                add_action( 'eddc_meta_box_table_header_after', array( $this, 'add_commissions_meta_box_threshold_table_header' ), 10, 1 );

                // Add 'initialization' threshold field/cell for when _edd_commission_settings meta is empty
                add_action( 'eddc_meta_box_table_cell_remove_before', array( $this, 'add_commissions_meta_box_empty_rates_threshold_field' ), 10, 1 );

                // Add threshold table fields/cells when _edd_commission_threshold_settings meta is not empty
                add_action( 'eddc_meta_box_table_cell_rates_remove_before', array( $this, 'add_commissions_meta_box_rates_threshold_fields' ), 10, 3 );

                // Save commission meta box threshold fields (user ids and threshold rates) to _edd_commission_threshold_settings meta
                add_action( 'save_post', array( $this, 'save_commissions_meta_box_threshold_fields' ), 10, 1 );

                // Add "threshold" rates to original rates array so the table cells can be displayed correctly.
                add_filter( 'eddc_render_commissions_meta_box_rates_args', array( $this, 'filter_commissions_meta_box_rates_query_args' ), 10, 3 );

                // Add "User's threshold rate" field to WordPress user profile admin page
                add_action( 'eddc_user_profile_table_end', array( $this, 'add_user_profile_fields' ), 10, 1 );

                // Santize and save user profile "User's threshold rate" field
                add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ), 10, 1 );
                add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ), 10, 1 );

            }

            // Get the commission threshold type (and rate) and check if the threshold is greater than either the earnings (or sales)
            add_filter( 'eddc_should_record_recipient_commissions', array( $this, 'check_commission_threshold' ), 10, 4 );

            // Add a payment note if the threshold has not been achieved
            add_action( 'edd_commission_thresholds_check_threshold_after', array( $this, 'maybe_add_payment_note' ), 10, 7 );
        }


        /**
         * Make sure we are at the minimum version of EDD Commissions - which is 3.4.6.
         *
         * @since       1.0.0
         * @access      public
         * @return      void
         */
        public function version_check_notice(){
            if ( defined( 'EDD_COMMISSIONS_VERSION' ) && version_compare( EDD_COMMISSIONS_VERSION, '3.4.6' ) == -1 ) {
                ?>
                <div class="notice notice-error">
                <p><?php echo __( 'EDD Commission Fees: Your version of EDD Commissions must be updated to version 3.4.6 or later to use the Commission Fees extension in conjunction with Commissions.', 'edd-commission-fees' ); ?></p>
                </div>
                <?php
            }
        }


        /**
         * Get the commission threshold type and rate and check if the threshold is greater than either the earnings or sales
         *
         * @access      public
         * @since       1.0.0
         * @param       boolean $record_commission Should we record the commission record?
         * @param       integer $recipient The WordPress user ID of the commission record
         * @param       integer $download_id The download ID of the commission record
         * @param       integer $payment_id The payment ID of the commission record
         * @return      boolean $record_commission false if threshold not met, true otherwise
         */
        public function check_commission_threshold( $record_commission = true, $recipient, $download_id, $payment_id ) {
            // If we were passed a numeric value as the payment id (which it should be)
            if ( ! is_object( $payment_id ) && is_numeric( $payment_id ) ) {
                $payment = new EDD_Payment( $payment_id );
            } elseif( is_a( $payment_id, 'EDD_Payment' ) ) {
                $payment = $payment_id;
            } else {
                return false;
            }

            do_action( 'edd_commission_thresholds_check_threshold_before', $record_commission, $recipient, $download_id, $payment_id, $payment );

            $commission_settings = get_post_meta( $download_id, '_edd_commission_threshold_settings', true );

            if ( empty( $commission_settings ) ) {
                $record_commission = true;
            }

            // Get download earnings stats
            $earnings = (float) edd_get_download_earnings_stats( $download_id );

            // Get download sales stats
            $sales = (int) edd_get_download_sales_stats( $download_id );

            // Get commission threshold type
            $type = $this->get_commission_threshold_type( $download_id );

            // Get commission recipient threshold rate
            $threshold_rate = $this->get_recipient_threshold_rate( $download_id, (int) $recipient );

            if ( 'earnings' === $type ) {
                $record_commission = ( $earnings < $threshold_rate ) ? false : true;
            } else {
                $record_commission = ( $sales < $threshold_rate ) ? false : true;
            }

            do_action( 'edd_commission_thresholds_check_threshold_after', $record_commission, $recipient, $download_id, $payment_id, $payment, $type, $threshold_rate );

            return apply_filters( 'edd_commission_thresholds_check_threshold', $record_commission, $recipient, $download_id, $payment_id, $payment, $type, $threshold_rate );
        }


        /**
         * Add a payment note if the threshold has not been reached
         *
         * @access      public
         * @since       1.0.0
         * @param       boolean $record_commission
         * @param       integer $recipient The WordPress user ID of the commission record
         * @param       integer $download_id The download ID of the commission record
         * @param       integer $payment_id The payment ID of the commission record
         * @param       object $payment The payment object of the commission record
         * @param       string $type The commission threshold type (sales or earnings)
         * @return      void
         */
        public function maybe_add_payment_note( $record_commission, $recipient, $download_id, $payment_id, $payment, $type, $threshold_rate ) {
            if ( false === $record_commission ) {
                $download = new EDD_Download( $download_id );
                $payment->add_note( sprintf( __( 'Commission for %s skipped because %s did not reach threshold.', 'edd-commission-thresholds' ), $download->get_name(), get_userdata( $recipient )->display_name ) );
            }
        }


        /**
         * Add Commissions meta box verification nonce
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function add_commissions_meta_box_nonce( $post_id ) {
            ?>
            <input type="hidden" name="edd_download_commission_meta_box_thresholds_nonce" value="<?php echo wp_create_nonce( basename( __FILE__ ) ); ?>" />
            <?php
        }


        /**
         * Add "threshold" rates to original rates array so the table cells can be displayed correctly.
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function filter_commissions_meta_box_rates_query_args( $rates, $users, $i ) {
            global $post;

            $meta       = get_post_meta( $post->ID, '_edd_commission_threshold_settings', true );
            $thresholds = isset( $meta['threshold'] ) ? $meta['threshold'] : '';
            $thresholds = ! empty( $thresholds ) ? array_map( 'trim', explode( ',', $thresholds ) ) : array();
            $rates['threshold'] = array_key_exists( $i, $thresholds ) ? $thresholds[ $i ] : '';

            return $rates;
        }


        /**
         * Add filterable "Threshold Type" options to Commissions meta box
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function add_commissions_meta_box_threshold_type_options( $post_id ) {
            $enabled = get_post_meta( $post_id, '_edd_commisions_enabled', true ) ? true : false;
            $meta    = get_post_meta( $post_id, '_edd_commission_threshold_settings', true );
            $type    = isset( $meta['type'] ) ? $meta['type'] : 'earnings';
            $display = $enabled ? '' : ' style="display:none";';

            ?>
              <tr <?php echo $display; ?> class="eddc_toggled_row" id="edd_commission_thresholds_type_wrapper">
          			<td class="edd_field_type_select">
          				<label for="edd_commission_threshold_settings[type]"><strong><?php _e( 'Threshold Type:', 'edd-commission-thresholds' ); ?></strong></label>
          				<span alt="f223" class="edd-help-tip dashicons dashicons-editor-help" title="<strong><?php _e( 'Type', 'edd-commission-thresholds' ); ?></strong>: <?php _e( 'With commissions enabled, you will need to specify who to assign commission thresholds to. Commission thresholds can be ether earnings or sales count based.', 'edd-commission-thresholds' ); ?>"></span><br/>
          				<p><?php

          				// Filter in the types of commission thresholds there could be.
          				$commission_types = apply_filters( 'edd_commission_threshold_types', array(
          					'earnings'    => __( 'Earnings', 'edd-commission-thresholds' ),
          					'sales'       => __( 'Sales', 'edd-commission-thresholds' ),
          				) );

          				foreach ( $commission_types as $commission_type => $commission_pretty_string ) {
          					?>
          					<span class="edd-commission-type-wrapper" id="edd_commission_threshold_type_<?php echo esc_attr( $commission_type ); ?>_wrapper">
          						<input id="edd_commission_threshold_type_<?php echo esc_attr( $commission_type ); ?>" type="radio" name="edd_commission_threshold_settings[type]" value="<?php echo esc_attr( $commission_type ); ?>" <?php checked( $type, $commission_type, true ); ?>/>
          						<label for="edd_commission_threshold_type_<?php echo esc_attr( $commission_type ); ?>"><?php echo esc_attr( $commission_pretty_string ); ?></label>
          					</span>
          					<?php
          				}
          				?>
          				</p>
          				<p><?php _e( 'Select the type of commission(s) thresholds to record.', 'edd-commission-thresholds' ); ?></p>
          			</td>
          		</tr>
            <?php
        }


        /**
         * Add "Thresholds" table header and tooltip
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function add_commissions_meta_box_threshold_table_header( $post_id ) {
            ?>
            <th class="eddc-commission-rate-rate">
            <?php _e( 'Threshold', 'edd-commission-thresholds' ); ?>
            <span alt="f223" class="edd-help-tip dashicons dashicons-editor-help" title="<strong> <?php _e( 'Threshold', 'edd-commission-thresholds' ); ?></strong>:&nbsp;
                <?php _e( 'Enter the earnings or sales threshold amount for each commission recipient. If no rate is entered, the default rate for the user will be used. If no user rate is set, the global default rate will be used. Currency symbols are not required.', 'edd-commission-thresholds' ); ?>">
            </span>
            </th>
            <?php
        }


        /**
         * Add threshold table fields/cells when _edd_commission_threshold_settings meta is not empty
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @param       int $key The commissions meta box table row key
         * @global      array $value The array containing the threshold value
         * @return      void
         */
        public function add_commissions_meta_box_rates_threshold_fields( $post_id, $key, $value ) {
            ?>
    		<td>
    			<input type="text" class="edd-commissions-rate-field" name="edd_commission_threshold_settings[thresholds][<?php echo edd_sanitize_key( $key ); ?>][threshold]" id="edd_commission_threshold_<?php echo edd_sanitize_key( $key ); ?>" value="<?php echo esc_attr( $value['threshold'] ); ?>" placeholder="<?php _e( 'Threshold for this user', 'edd-commission-thresholds' ); ?>"/>
    		</td>
    		<?php
        }


        /**
         * Add 'initialization' threshold field/cell for when _edd_commission_settings meta is empty
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function add_commissions_meta_box_empty_rates_threshold_field( $post_id ) {
            ?>
            <td>
                <input type="text" name="edd_commission_threshold_settings[thresholds][1][threshold]" id="edd_commission_threshold_1" placeholder=" <?php _e( 'Threshold for this user', 'edd-commission-thresholds' ); ?>"/>
            </td>
            <?php
        }


        /**
         * Save data when save_post is called
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function save_commissions_meta_box_threshold_fields( $post_id ) {
        	global $post;

        	// verify nonce
        	if ( ! isset( $_POST['edd_download_commission_meta_box_thresholds_nonce'] ) || ! wp_verify_nonce( $_POST['edd_download_commission_meta_box_thresholds_nonce'], basename( __FILE__ ) ) ) {
        	       return $post_id;
        	}

        	// Check for auto save / bulk edit
        	if ( ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX') && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
        		return $post_id;
        	}

        	if ( isset( $_POST['post_type'] ) && 'download' != $_POST['post_type'] ) {
        		return $post_id;
        	}

        	if ( ! current_user_can( 'edit_product', $post_id ) ) {
        		return $post_id;
        	}

        	if ( isset( $_POST['edd_commisions_enabled'] ) ) {

        		$new  = isset( $_POST['edd_commission_threshold_settings'] ) ? $_POST['edd_commission_threshold_settings'] : false;
        		$type = ! empty( $_POST['edd_commission_threshold_settings']['type'] ) ? $_POST['edd_commission_threshold_settings']['type'] : 'earnings';

        		if ( ! empty( $_POST['edd_commission_threshold_settings']['thresholds'] ) && is_array( $_POST['edd_commission_threshold_settings']['thresholds'] ) ) {
        			$users       = array();
        			$thresholds  = array();

                    // Get the threshold values
        			foreach( $_POST['edd_commission_threshold_settings']['thresholds'] as $rate ) {
        				$thresholds[] = sanitize_text_field( $rate['threshold'] );
        			}

                      // Get the user ids
                    foreach( $_POST['edd_commission_settings']['rates'] as $rate ) {
                        $users[]   = sanitize_text_field( $rate['user_id'] );
                    }

        			$new['user_id'] = implode( ',', $users );
        			$new['threshold']  = implode( ',', $thresholds );

        			// No need to store this value since we're saving as a string
        			unset( $new['thresholds'] );
        		}

        		if ( $new ) {
        			if ( ! empty( $new['user_id'] ) ) {
        				$new['threshold'] = str_replace( '%', '', $new['threshold'] );
        				$new['threshold'] = str_replace( '$', '', $new['threshold'] );

        				$values           = explode( ',', $new['threshold'] );
        				$sanitized_values = array();

        				foreach ( $values as $key => $value ) {

        					switch ( $type ) {
        						case 'earnings':
        							$value = $value < 0 || ! is_numeric( $value ) ? '' : $value;
        							$value = round( $value, edd_currency_decimal_filter() );
        							break;
        						case 'sales':
        						default:
        							if ( $value < 0 || ! is_numeric( $value ) ) {
        								$value = '';
        							}

        							$value = ( is_numeric( $value ) && $value < 1 ) ? round( $value, 0 ) : $value;
        							if ( is_numeric( $value ) ) {
        								$value = round( $value, 0 );
        							}

        							break;
        					}

        					$sanitized_values[ $key ] = $value;
        				}

        				$new_values    = implode( ',', $sanitized_values );
        				$new['threshold'] = trim( $new_values );
        			}
        		}
        		update_post_meta( $post_id, '_edd_commission_threshold_settings', $new );
        	}
        }


        /**
         * Add user profile threshold fields
         *
         * @since       1.0.0
         * @param       object $user The user object
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function add_user_profile_fields( $user ) {
            ?>
            <?php if ( current_user_can( 'manage_shop_settings' ) ) : ?>
            <tr>
              <th><label><?php _e('User\'s Threshold Rate', 'edd-commission-thresholds'); ?></label></th>
              <td>
                <input type="text" name="edd_commission_thresholds_user_rate" id="edd_commission_thresholds_user_rate" class="small-text" value="<?php echo esc_attr( get_user_meta( $user->ID, 'edd_commission_thresholds_user_rate', true ) ); ?>" />
                <span class="description"><?php _e('Enter a global commission threshold rate for this user. If a rate is not specified for a product, this rate will be used.', 'edd-commission-thresholds'); ?></span>
              </td>
            </tr>
            <?php endif; ?>
            <?php
        }


        /**
         * Santize and save user data when save_post is called
         *
         * @since       1.0.0
         * @param       int $post_id The ID of the post being saved
         * @global      object $post The WordPress post object for this download
         * @return      void
         */
        public function save_user_profile_fields( $user_id ) {
            if ( ! current_user_can( 'edit_user', $user_id ) ) {
            	return false;
            }

            if ( current_user_can( 'manage_shop_settings' ) ) {
            	if ( ! empty( $_POST['edd_commission_thresholds_user_rate'] ) ) {
            		update_user_meta( $user_id, 'edd_commission_thresholds_user_rate', sanitize_text_field( $_POST['edd_commission_thresholds_user_rate'] ) );
            	} else {
            		delete_user_meta( $user_id, 'edd_commission_thresholds_user_rate' );
            	}
            }
        }


        /**
         * Retrieve the threshold type of a commission for a download
         *
         * @access      public
         * @since       1.0.0
         * @param       int $download_id The download ID
         * @return      string The threshold type of the commission
         */
        public function get_commission_threshold_type( $download_id = 0 ) {
            $settings = get_post_meta( $download_id, '_edd_commission_threshold_settings', true );
            $type     = isset( $settings['type'] ) ? $settings['type'] : 'earnings';
            return apply_filters( 'edd_commission_thresholds_threshold_type', $type, $download_id );
        }


        /**
         *
         * Retrieves the commission threshold rate for a product and user
         *
         * If $download_id is empty, the default rate from the user account is retrieved.
         * If no default rate is set on the user account, the global default is used.
         *
         * This function requires very strict typecasting to ensure the proper rates are used at all times.
         *
         * 0 is a permitted rate so we cannot use empty(). We always use NULL to check for non-existent values.
         *
         * @access      public
         * @since       1.0.0
         * @param       $download_id INT The ID of the download product to retrieve the commission rate for
         * @param       $user_id INT The user ID to retrieve commission rate for
         * @return      $rate INT|FLOAT The commission rate
         */
        public function get_recipient_threshold_rate( $download_id = 0, $user_id = 0 ) {
        	$rate = null;

        	// Check for a threshold rate specified on a specific product
        	if ( ! empty( $download_id ) ) {
        		$settings   = get_post_meta( $download_id, '_edd_commission_threshold_settings', true );

                if ( ! empty( $settings ) && is_array( $settings ) ) {
            		$rates      = isset( $settings['threshold'] ) ? array_map( 'trim', explode( ',', $settings['threshold'] ) ) : array();
            		$recipients = array_map( 'trim', explode( ',', $settings['user_id'] ) );
            		$rate_key   = array_search( $user_id, $recipients );

            		if ( isset( $rates[ $rate_key ] ) ) {
            			$rate = $rates[ $rate_key ];
            		}
                }
        	}

        	// Check for a user specific global threshold rate
        	if ( ! empty( $user_id ) && ( null === $rate || '' === $rate ) ) {
        		$rate = get_user_meta( $user_id, 'edd_commission_thresholds_user_rate', true );

        		if ( '' === $rate ) {
        			$rate = null;
        		}
        	}

        	// Check for an overall global rate
        	if ( null === $rate && $this->get_default_threshold_rate() ) {
        		$rate = $this->get_default_threshold_rate();
        	}

        	// Set rate to 0 if no rate was found
        	if ( null === $rate || '' === $rate ) {
        		$rate = 0;
        	}

        	return apply_filters( 'edd_commission_thresholds_recipient_threshold_rate', (float) $rate, $download_id, $user_id );
        }


        /**
         * Get an array containing the user id's entered in the "Users" field in the Commissions metabox.
         *
         * @access      public
         * @since       1.0.0
         * @param       int $download_id The id of the download for which we want the recipients.
         * @return      array An array containing the user ids of the recipients.
         */
        public function get_recipients( $download_id = 0 ) {
        	$settings = get_post_meta( $download_id, '_edd_commission_threshold_settings', true );

        	// If the information for commissions was not saved or this happens to be for a post with commissions currently disabled
        	if ( !isset( $settings['user_id'] ) ){
        		return array();
        	}

        	$recipients = array_map( 'intval', explode( ',', $settings['user_id'] ) );
        	return (array) apply_filters( 'edd_commission_thresholds_recipients', $recipients, $download_id );
        }


        /**
         * Gets the default commission threshold rate
         *
         * @access      public
         * @since       2.1
         * @return      float
         */
        public function get_default_threshold_rate() {
        	global $edd_options;

        	$rate = isset( $edd_options['edd_commission_thresholds_default_rate'] ) ? $edd_options['edd_commission_thresholds_default_rate'] : false;

        	return apply_filters( 'edd_commission_thresholds_default_rate', $rate );
        }


        /**
         * Internationalization
         *
         * @access      public
         * @since       1.0.0
         * @return      void
         */
        public function load_textdomain() {
            // Set filter for language directory
            $lang_dir = EDD_COMMISSION_THRESHOLDS_DIR . '/languages/';
            $lang_dir = apply_filters( 'edd_plugin_name_languages_directory', $lang_dir );

            // Traditional WordPress plugin locale filter
            $locale = apply_filters( 'plugin_locale', get_locale(), 'edd-commission-thresholds' );
            $mofile = sprintf( '%1$s-%2$s.mo', 'edd-commission-thresholds', $locale );

            // Setup paths to current locale file
            $mofile_local   = $lang_dir . $mofile;
            $mofile_global  = WP_LANG_DIR . '/edd-commission-thresholds/' . $mofile;

            if( file_exists( $mofile_global ) ) {
                // Look in global /wp-content/languages/edd-commission-thresholds/ folder
                load_textdomain( 'edd-commission-thresholds', $mofile_global );
            } elseif( file_exists( $mofile_local ) ) {
                // Look in local /wp-content/plugins/edd-commission-thresholds/languages/ folder
                load_textdomain( 'edd-commission-thresholds', $mofile_local );
            } else {
                // Load the default language files
                load_plugin_textdomain( 'edd-commission-thresholds', false, $lang_dir );
            }
        }


        /**
         * Add new site-wide settings under "Downloads" > "Extensions" > "Commissions" for commissions threshold.
         *
         * @access    public
         * @since     1.0.0
         * @param     array $commission_settings The array of settings for the Commissions settings page.
         * @return    array $commission_settings The merged array of settings for the Commissions settings page.
         */
        public function settings( $commission_settings ) {
          $new_settings = array(
                array(
                    'id'      => 'edd_commission_thresholds_header',
                    'name'    => '<strong>' . __( 'Threshold Settings', 'edd-commission-thresholds' ) . '</strong>',
                    'desc'    => '',
                    'type'    => 'header',
                    'size'    => 'regular',
                ),
        		array(
        			'id'      => 'edd_commission_thresholds_default_rate',
        			'name'    => __( 'Default threshold', 'edd-commission-thresholds' ),
        			'desc'    => __( 'Enter the default threshold recipients are required to reach before commissions are recorded. This can be overwritten on a per-product basis.', 'edd-commission-thresholds' ),
        			'type'    => 'text',
        			'size'    => 'small',
        		),
          );

          return array_merge( $commission_settings, $new_settings );
        }
    }
} // End if class_exists check


/**
 * The main function responsible for returning the one true EDD_Commission_Thresholds
 * instance to functions everywhere
 *
 * @since       1.0.0
 * @return      \EDD_Commission_Thresholds The one true EDD_Commission_Thresholds
 */
function EDD_Commission_Thresholds_load() {
    if ( ! class_exists( 'Easy_Digital_Downloads' ) || ! class_exists( 'EDDC' ) ) {
        if ( ! class_exists( 'EDD_Extension_Activation' ) || ! class_exists( 'EDD_Commissions_Activation' ) ) {
          require_once 'includes/class-activation.php';
        }

        // Easy Digital Downloads activation
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			$edd_activation = new EDD_Extension_Activation( plugin_dir_path( __FILE__ ), basename( __FILE__ ) );
			$edd_activation = $edd_activation->run();
		}

        // Commissions activation
		if ( ! class_exists( 'EDDC' ) ) {
			$edd_commissions_activation = new EDD_Commissions_Activation( plugin_dir_path( __FILE__ ), basename( __FILE__ ) );
			$edd_commissions_activation = $edd_commissions_activation->run();
		}

    } else {

      return EDD_Commission_Thresholds::instance();
    }
}
add_action( 'plugins_loaded', 'EDD_Commission_Thresholds_load' );
