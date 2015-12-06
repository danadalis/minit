<?php

class Minit {

	private static $instance;
	private $plugin;

	protected $queue = array(
		'css' => array(),
		'js' => array(),
	);

	protected $done = array(
		'css' => array(),
		'js' => array(),
	);


	public function __construct( $plugin ) {

		$this->plugin = $plugin;

	}


	public function init() {

		// Queue all assets
		add_filter( 'print_scripts_array', array( $this, 'register_js' ) );
		add_filter( 'print_styles_array', array( $this, 'register_css' ) );

		// Enqueue all registered Minit styles
		add_filter( 'print_styles_array', array( $this, 'minit_css' ), 20 );

		// Enqueue all registered Minit scripts in the footer
		add_filter( 'print_scripts_array', array( $this, 'minit_js' ), 20 );

		// Print external scripts asynchronously in the footer
		add_action( 'wp_print_footer_scripts', array( $this, 'print_async_scripts' ), 20 );

		// Load our JS files asynchronously
		add_filter( 'script_loader_tag', array( $this, 'script_tag_async' ), 20, 3 );

	}


	public static function instance() {

		return self::$instance;

	}


	function register_js( $todo ) {

		global $wp_scripts;

		return $this->register_assets( $wp_scripts, $todo );

	}


	function register_css( $todo ) {

		global $wp_styles;

		return $this->register_assets( $wp_styles, $todo );

	}


	function register_assets( $object, $todo ) {

		if ( empty( $todo ) )
			return $todo;

		$extension = $this->get_object_extension( $object );

		// Allow files to be excluded from Minit
		$minit_exclude = apply_filters( 'minit-exclude-' . $extension, array() );

		if ( ! is_array( $minit_exclude ) )
			$minit_exclude = array();

		$minit_todo = array_diff( $todo, $minit_exclude );

		if ( empty( $minit_todo ) )
			return $todo;

		foreach ( $minit_todo as $handle )
			if ( ! in_array( $handle, $this->queue[ $extension ] ) )
				$this->queue[ $extension ][] = $handle;

		return array_diff( $todo, $this->queue[ $extension ] );

	}


	function get_object_extension( $object ) {

		if ( is_a( $object, 'WP_Styles' ) )
			return 'css';
		elseif ( is_a( $object, 'WP_Scripts' ) )
			return 'js';

		return get_class( $object );

	}


	function minit_assets( $object ) {

		$done = array();
		$extension = $this->get_object_extension( $object );

		if ( ! isset( $this->queue[ $extension ] ) )
			return false;

		$todo = $this->queue[ $extension ];

		// Build a cache key
		$ver = array(
			$this->plugin->version,
			$extension,
			'is_ssl-' . is_ssl(), // Use different cache key for SSL and non-SSL
			'minit_cache_ver-' . get_option( 'minit_cache_ver' ), // Use a global cache version key to purge cache
		);

		// Include individual scripts version in the cache key
		foreach ( $todo as $script )
			$ver[] = sprintf( '%s-%s', $script, $object->registered[ $script ]->ver );

		$cache_ver = md5( 'minit-' . implode( '-', $ver ) );

		// Try to get queue from cache
		$cache = get_transient( 'minit-' . $cache_ver );

		if ( ! empty( $cache ) && isset( $cache['url'] ) ) {
			$this->mark_done( $cache['done'], $object );

			return $cache['url'];
		}

		foreach ( $todo as $script ) {

			// Ignore pseudo packages such as jquery which return src as empty string
			if ( empty( $object->registered[ $script ]->src ) )
				$done[ $script ] = null;

			// Get the relative URL of the asset
			$src = $this->get_asset_relative_path(
					$object->base_url,
					$object->registered[ $script ]->src
				);

			// Skip if the file is not hosted locally
			if ( empty( $src ) || ! file_exists( ABSPATH . $src ) )
				continue;

			$done[ $script ] = apply_filters(
					'minit-item-' . $extension,
					file_get_contents( ABSPATH . $src ),
					$object,
					$script
				);

		}

		if ( empty( $done ) )
			return false;

		$this->mark_done( array_keys( $done ), $object );

		$wp_upload_dir = wp_upload_dir();

		// Try to create the folder for cache
		if ( ! is_dir( $wp_upload_dir['basedir'] . '/minit' ) )
			if ( ! mkdir( $wp_upload_dir['basedir'] . '/minit' ) )
				return false;

		$combined_file_path = sprintf( '%s/minit/%s.%s', $wp_upload_dir['basedir'], $cache_ver, $extension );
		$combined_file_url = sprintf( '%s/minit/%s.%s', $wp_upload_dir['baseurl'], $cache_ver, $extension );

		// Allow other plugins to do something with the resulting URL
		$combined_file_url = apply_filters( 'minit-url-' . $extension, $combined_file_url, $done );

		// Allow other plugins to minify and obfuscate
		$done_imploded = apply_filters( 'minit-content-' . $extension, implode( "\n\n", $done ), $done );

		// Store the combined file on the filesystem
		if ( ! file_exists( $combined_file_path ) )
			if ( ! file_put_contents( $combined_file_path, $done_imploded ) )
				return false;

		// Cache this set of scripts, by default for 24 hours
		$cache_ttl = apply_filters( 'minit-cache-expiration', 24 * 60 * 60 );
		$cache_ttl = apply_filters( 'minit-cache-expiration-' . $extension, $cache_ttl );

		$result = array(
			'done' => array_keys( $done ),
			'url' => $combined_file_url,
			'file' => $combined_file_path,
		);

		set_transient( 'minit-' . $cache_ver, $result, $cache_ttl );

		return $combined_file_url;

	}


	protected function mark_done( $handles, $object ) {

		$extension = $this->get_object_extension( $object );

		// Remove processed items from the queue
		$this->queue[ $extension ] = array_diff( $this->queue[ $extension ], $handles );

		// Mark them as processed by Minit
		$this->done[ $extension ] = array_merge( $this->done[ $extension ], $handles );

		$object->dequeue( $handles );

	}


	function minit_js( $todo ) {

		global $wp_scripts;

		// Run this only in the footer
		if ( 0 !== $wp_scripts->group )
			return $todo;

		if ( empty( $this->queue[ 'js' ] ) )
			return $todo;

		$handle = 'minit-js';
		$url = $this->minit_assets( $wp_scripts );

		if ( empty( $url ) ) {
			return false;
		}

		// Remove Minited items from the queue
		$todo = array_diff( $todo, $this->done[ 'js' ] );

		// @todo create a fallback for apply_filters( 'minit-js-in-footer', true )
		wp_register_script( $handle, $url, null, null, true );

		// Add our minit script to the queue because wp_register_script is too late already
		$todo[] = $handle;

		$inline_data = array();

		// Add inline scripts for all minited scripts
		foreach ( $this->done[ 'js' ] as $script )
			$inline_data[] = $wp_scripts->get_data( $script, 'data' );

		// Filter out empty elements
		$inline_data = array_filter( $inline_data );

		if ( ! empty( $inline_data ) )
			$wp_scripts->add_data( $handle, 'data', implode( "\n", $inline_data ) );

		return $todo;

	}


	function minit_css( $todo ) {

		global $wp_styles;

		$handle = 'minit-css';
		$url = $this->minit_assets( $wp_styles );

		if ( empty( $url ) ) {
			return $todo;
		}

		// Remove Minited items from the queue
		$todo = array_diff( $todo, $this->queue[ 'css' ] );

		wp_register_style( $handle, $url, null, null );

		// Add our minit script to the queue because wp_register_style is too late already
		$todo[] = $handle;

		// Add inline styles for all minited styles
		foreach ( $this->done[ 'css' ] as $script ) {

			$extras = $wp_styles->get_data( $script, 'after' );

			if ( ! empty( $extras ) )
				$wp_styles->add_data( $handle, 'after', $extras );

		}

		return $todo;

	}


	public static function get_asset_relative_path( $base_url, $item_url ) {

		// Remove protocol reference from the local base URL
		$base_url = preg_replace( '/^(https?:\/\/|\/\/)/i', '', $base_url );

		// Check if this is a local asset which we can include
		$src_parts = explode( $base_url, $item_url );

		// Get the trailing part of the local URL
		$maybe_relative = end( $src_parts );

		if ( ! file_exists( ABSPATH . $maybe_relative ) )
			return false;

		return $maybe_relative;

	}

	public function print_async_scripts() {

		global $wp_scripts;

		$async_queue = array();
		$minit_exclude = (array) apply_filters( 'minit-exclude-js', array() );

		foreach ( $wp_scripts->queue as $handle ) {

			// Skip asyncing explicitly excluded script handles
			if ( in_array( $handle, $minit_exclude ) ) {
				continue;
			}

			$script_relative_path = $this->get_asset_relative_path(
				$wp_scripts->base_url,
				$wp_scripts->registered[ $handle ]->src
			);

			if ( ! $script_relative_path ) {
				// Add this script to our async queue
				$async_queue[] = $handle;
			}

		}

		if ( empty( $async_queue ) )
			return;

		// Remove this script from being printed the regular way
		wp_dequeue_script( $async_queue );

		?>
		<!-- Asynchronous scripts by Minit -->
		<script id="minit-async-scripts" type="text/javascript">
		(function() {
			var js, fjs = document.getElementById('minit-async-scripts'),
				add = function( url, id ) {
					js = document.createElement('script');
					js.type = 'text/javascript';
					js.src = url;
					js.async = true;
					js.id = id;
					fjs.parentNode.insertBefore(js, fjs);
				};
			<?php
			foreach ( $async_queue as $handle ) {
				printf(
					'add( "%s", "%s" ); ',
					$wp_scripts->registered[$handle]->src,
					'async-script-' . esc_attr( $handle )
				);
			}
			?>
		})();
		</script>
		<?php

	}

	public function script_tag_async( $tag, $handle, $src ) {

		// Allow others to disable this feature
		if ( ! apply_filters( 'minit-script-tag-async', true ) )
			return $tag;

		// Do this for minit scripts only
		if ( false === stripos( $handle, 'minit-' ) )
			return $tag;

		// Bail if async is already set
		if ( false !== stripos( $tag, ' async' ) )
			return $tag;

		return str_ireplace( '<script ', '<script async ', $tag );

	}


	public static function cache_bump() {

		// Use this as a global cache version number
		update_option( 'minit_cache_ver', time() );

		// Allow other plugins to know that we purged
		do_action( 'minit-cache-purged' );

	}


	public static function cache_delete() {

  	$wp_upload_dir = wp_upload_dir();
  	$minit_files = glob( $wp_upload_dir['basedir'] . '/minit/*' );

  	if ( $minit_files ) {
  		foreach ( $minit_files as $minit_file ) {
  			unlink( $minit_file );
  		}
  	}

  }

}
