<?php

class EMR_Smart_Notification {

	private static $_instance = null;
	private $plugins;
	private $options;
	
	function __construct( $args ) {
		
		$this->container_id = 'emr-smart-notification';
		$this->options = get_option( 'emr-recommended-plugin', array() );
		$this->plugins = $this->parse_plugins( $args['plugins'] );

		if ( is_admin() && $this->show_notice() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'admin_notices', array( $this, 'notification' ) );
			add_action( 'wp_ajax_emr_smart_notitification', array( $this, 'ajax' ) );
			add_action('admin_footer', array( $this, 'emr_script' ) );
		}

	}

	private function parse_plugins( $need_check ) {
		$plugins = array();

		$shortpixel_extra_check = get_option( 'emr_news', false );
		if ( $shortpixel_extra_check ) {
			$this->options[] = 'shortpixel-image-optimiser';
		}

		foreach ( $need_check as $slug => $plugin ) {

			if ( in_array( $slug, $this->options ) ) {
				continue;
			}

			$plugin_info = $this->check_plugin( $slug );
			if ( 'deactivate' == $plugin_info['needs'] ) {
				continue;
			}

			$plugins[ $slug ] = array_merge( $plugin, $plugin_info );
		}

		return $plugins;

	}

	private function show_notice() {

		if ( ! empty( $this->plugins ) ) {
			return true;
		}

		return false;

	}

	/**
	 * @since 1.0.0
	 * @return emr_Smart_Notification
	 */
	public static function get_instance( $args ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $args );
		}
		return self::$_instance;
	}

	public function notification() {
		$notice_html = '';

		foreach ( $this->plugins as $slug => $plugin ) {
			$notice_html .= '<div class="emr-plugin-card">';
			$url = $this->create_plugin_link( $plugin['needs'], $slug );
			if ( '' != $plugin['image'] ) {
				$notice_html .= '<div style="padding-right: 10px;">';
				$notice_html .= '<img src="' . esc_url( $plugin['image'] ) . '" width="75" height="75">';
				$notice_html .= '</div>';
			}
			$notice_html .= '<div style="align-self: center;flex-grow: 1;">';
			$notice_html .= '<h3 style="margin:0;">' . $plugin['name'] . '</h3>';
			$notice_html .= '<p>' . $plugin['description'] . '</p>';
			$notice_html .= '</div>';
			$notice_html .= '<div>';
			$notice_html .= '<a href="#" class="emr-dismiss" data-dismiss="' . esc_attr( $slug ) . '"><span class="screen-reader-text">Dismiss this notice.</span></a>';
			$notice_html .= '<span class="plugin-card-' . esc_attr( $slug ) . ' action_button ' . $plugin['needs'] . '">';
				$notice_html .= '<a data-slug="' . esc_attr( $slug ) . '" data-action="' . esc_attr( $plugin['needs'] ) . '" class="emr-plugin-button ' . esc_attr( $plugin['class'] ) . '" href="' . esc_url( $url ) . '">' . esc_attr( $plugin['label'] ) . '</a>';
			$notice_html .= '</span>';
			$notice_html .= '</div>';
			$notice_html .= '</div>';
		}

		$class = "emr-one-column";
		if ( count( $this->plugins ) > 1 ) {
			$class = "emr-two-column";
		}
		echo '<div id="' . $this->container_id . '" class="emr-custom-notice notice ' . $class . '" style="background:transparent;border: 0 none;box-shadow: none;padding: 0;display: flex;">';
		echo $notice_html;
		echo '<style>.emr-plugin-card {display: flex;background: #fff;border-left: 4px solid #46b450;padding: .5em 12px;box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);position:relative;align-items:center;}.emr-one-column .emr-plugin-card{ width:100%; }.emr-two-column .emr-plugin-card{width:49%;}.emr-two-column .emr-plugin-card:nth-child( 2n + 1 ){margin-right:2%;}.emr-dismiss { position: absolute;top: 0;right: 1px;border: none;margin: 0;padding: 9px;background: 0 0;color: #72777c;cursor: pointer;text-decoration:none; }.emr-dismiss:before { background: 0 0;color: #72777c;content: "\f153";display: block;font: 400 16px/20px dashicons; speak: none;height: 20px;text-align: center;width: 20px;-webkit-font-smoothing: antialiased;-moz-osx-font-smoothing: grayscale; }.emr-dismiss:active:before, .emr-dismiss:focus:before, .emr-dismiss:hover:before { color: #c00; }</style>';
		echo '</div>';
		

	}

	public function ajax() {

		check_ajax_referer( 'emr-smart-notitification', 'security' );

		if ( isset( $_POST['slug'] ) ) {
			$this->options[] = sanitize_text_field( $_POST['slug'] );
			update_option( 'emr-recommended-plugin', $this->options );
		}

		wp_die( 'ok' );

	}

	public function enqueue() {
		wp_enqueue_script( 'updates' );
		wp_enqueue_script( 'jquery' );
	}

	private function get_plugins( $plugin_folder = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins( $plugin_folder );
	}

	private function _get_plugin_basename_from_slug( $slug ) {
		$keys = array_keys( $this->get_plugins() );

		foreach ( $keys as $key ) {
			if ( preg_match( '|^' . $slug . '/|', $key ) ) {
				return $key;
			}
		}

		return $slug;
	}

	/**
	 * @return bool
	 */
	private function check_plugin_is_installed( $slug ) {
		$plugin_path = $this->_get_plugin_basename_from_slug( $slug );
		if ( file_exists( ABSPATH . 'wp-content/plugins/' . $plugin_path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @return bool
	 */
	private function check_plugin_is_active( $slug ) {
		$plugin_path = $this->_get_plugin_basename_from_slug( $slug );
		if ( file_exists( ABSPATH . 'wp-content/plugins/' . $plugin_path ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

			return is_plugin_active( $plugin_path );
		}
	}

	private function create_plugin_link( $state, $slug ) {
		$string = '';

		switch ( $state ) {
			case 'install':
				$string = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'install-plugin',
							'plugin' => $this->_get_plugin_basename_from_slug( $slug ),
						),
						network_admin_url( 'update.php' )
					),
					'install-plugin_' . $slug
				);
				break;
			case 'deactivate':
				$string = add_query_arg(
					array(
						'action'        => 'deactivate',
						'plugin'        => rawurlencode( $this->_get_plugin_basename_from_slug( $slug ) ),
						'plugin_status' => 'all',
						'paged'         => '1',
						'_wpnonce'      => wp_create_nonce( 'deactivate-plugin_' . $this->_get_plugin_basename_from_slug( $slug ) ),
					),
					admin_url( 'plugins.php' )
				);
				break;
			case 'activate':
				$string = add_query_arg(
					array(
						'action'        => 'activate',
						'plugin'        => rawurlencode( $this->_get_plugin_basename_from_slug( $slug ) ),
						'plugin_status' => 'all',
						'paged'         => '1',
						'_wpnonce'      => wp_create_nonce( 'activate-plugin_' . $this->_get_plugin_basename_from_slug( $slug ) ),
					),
					admin_url( 'plugins.php' )
				);
				break;
			default:
				$string = '';
				break;
		}// End switch().

		return $string;
	}

	private function check_plugin( $slug = '' ) {
		$arr = array(
			'installed' => $this->check_plugin_is_installed( $slug ),
			'active'    => $this->check_plugin_is_active( $slug ),
			'needs'     => 'install',
			'class'     => 'button button-primary',
			'label'     => __( 'Install and Activate', 'enable-media-replace' ),
		);

		if ( $arr['installed'] ) {
			$arr['needs'] = 'activate';
			$arr['class'] = 'button button-primary';
			$arr['label'] = __( 'Activate now', 'enable-media-replace' );
		}

		if ( $arr['active'] ) {
			$arr['needs'] = 'deactivate';
			$arr['class'] = 'deactivate-now button';
			$arr['label'] = __( 'Deactivate now', 'enable-media-replace' );
		}

		return $arr;
	}

	public function emr_script() {

		$ajax_nonce = wp_create_nonce( 'emr-smart-notitification' );

		?>
		<script type="text/javascript">
			
			  
			function emrActivatePlugin( url, el ) {

				jQuery.ajax( {
				  async: true,
				  type: 'GET',
				  dataType: 'html',
				  url: url,
				  success: function() {
				    location.reload();
				  }
				} );
			}

		  	var emrContainer = jQuery( '#<?php echo $this->container_id ?>' );
		    emrContainer.on( 'click', '.emr-plugin-button', function( event ) {
		      var action = jQuery( this ).data( 'action' ),
		          url = jQuery( this ).attr( 'href' ),
		          slug = jQuery( this ).data( 'slug' );

		      jQuery(this).addClass( 'updating-message' );
		      jQuery(this).attr( 'disabled', 'disabled' );

		      event.preventDefault();

		      if ( 'install' === action ) {

		        wp.updates.installPlugin( {
		          slug: slug
		        } );

		      } else if ( 'activate' === action ) {

		        emrActivatePlugin( url, jQuery( this ) );

		      }

		    } );

		    emrContainer.on( 'click', '.emr-dismiss', function( event ) {
		    	var container = jQuery(this).parents( '.emr-plugin-card' ),
		    		data = jQuery(this).data(),
		    		ajaxData = {
						action: 'emr_smart_notitification',
						security: '<?php echo $ajax_nonce; ?>',
					};

		    	event.preventDefault();

		    	ajaxData.slug = data.dismiss;

		    	jQuery.post( '<?php echo admin_url( 'admin-ajax.php' ) ?>', ajaxData, function( response ) {
					container.slideUp( 'fast', function() {
						jQuery( this ).remove();
					} );
				});

		    });

		    jQuery( document ).on( 'wp-plugin-install-success', function( response, data ) {
		      var el = emrContainer.find( '.emr-plugin-button[data-slug="' + data.slug + '"]' );
		      event.preventDefault();
		      emrActivatePlugin( data.activateUrl, el );
		    } );

		</script>

		<?php
	}

}