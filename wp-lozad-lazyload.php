<?php
/*
 * Plugin Name: WP Lozad Lazy Load
 * Description: Lazy Load images, iframes, scripts, and other content with the Lozad library
 * Version: 0.0.2
 * Author: Jonathan Stegall
 * License: GPL-2.0+
 * Text Domain: wp-lozad-lazyload
 */
class WP_Lozad_LazyLoad {

	/**
	* @var string
	* Prefix for plugin options
	*/
	private $option_prefix;

	/**
	* @var string
	* Current version of the plugin
	*/
	private $version;

	/**
	* @var string
	* The plugin's slug so we can include it when necessary
	*/
	private $slug;

	/**
	* @var object
	*/
	private $admin;

	/**
	 * @var object
	 * Static property to hold an instance of the class; this seems to make it reusable
	 *
	 */
	static $instance = null;

	/**
	* Load the static $instance property that holds the instance of the class.
	* This instance makes the class reusable by other plugins
	*
	* @return object
	*
	*/
	static public function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new WP_Lozad_LazyLoad();
		}
		return self::$instance;
	}

	/**
	 * This is our constructor
	 *
	 * @return void
	 */
	public function __construct() {

		$this->option_prefix = 'wp_lozad_lazyload_';
		$this->version       = '0.0.2';
		$this->slug          = 'wp-lozad-lazyload';

		// do we want to lazy load any thing or turn it off?
		$this->lazy_load_anything = filter_var( get_option( $this->option_prefix . 'lazy_load_anything', false ), FILTER_VALIDATE_BOOLEAN );
		// do we want to filter post_content to lazy load things inside it?
		$this->lazy_load_post_content = filter_var( get_option( $this->option_prefix . 'lazy_load_post_content', false ), FILTER_VALIDATE_BOOLEAN );
		// do we want to filter images?
		$this->lazy_load_images = filter_var( get_option( $this->option_prefix . 'lazy_load_images', false ), FILTER_VALIDATE_BOOLEAN );
		// do we want to filter iframes?
		$this->lazy_load_iframes = filter_var( get_option( $this->option_prefix . 'lazy_load_iframes', false ), FILTER_VALIDATE_BOOLEAN );

		// load admin
		$this->admin = $this->load_admin();

		$this->add_actions();

	}

	/**
	 * load the admin stuff
	 * creates admin menu to save the config options
	 *
	 * @return object
	 *   Instance of User_Account_Management_Admin
	 */
	private function load_admin() {
		require_once( plugin_dir_path( __FILE__ ) . 'classes/class-' . $this->slug . '-admin.php' );
		$admin = new WP_Lozad_LazyLoad_Admin( $this->option_prefix, $this->version, $this->slug );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
		add_action( 'plugins_loaded', array( $this, 'textdomain' ) );
		return $admin;
	}

	/**
	 * Display a Settings link on the main Plugins page
	 *
	 * @param array $links
	 * @param string $file
	 * @return array $links
	 * These are the links that go with this plugin's entry
	 */
	public function plugin_action_links( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$settings = '<a href="' . get_admin_url() . 'options-general.php?page=' . $this->slug . '">' . __( 'Settings', 'wp-lozad-lazyload' ) . '</a>';
			array_unshift( $links, $settings );
		}
		return $links;
	}

	/**
	 * Load textdomain
	 *
	 * @return void
	 */
	public function textdomain() {
		load_plugin_textdomain( 'wp-lozad-lazyload', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Add plugin shortcodes, actions, and filters
	 *
	 * @return void
	 */
	private function add_actions() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	public function init() {

		// filter to deal with supplied html
		if ( true === $this->lazy_load_anything ) {
			add_filter( $this->option_prefix . 'convert_html', array( $this, 'convert_html' ), 10, 2 );
		}

		// add css and javascript
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts_and_styles' ) );

		// don't do any lazy loading of post_content if we're inside the admin
		if ( is_admin() ) {
			return;
		}

		// deal with things inside post_content
		add_filter( 'the_content', array( $this, 'edit_post_content' ), 1000, 1 );
	}

	/**
	 * Process items in post_content
	 *
	 * @param string $content
	 * @return string $content
	 */
	public function edit_post_content( $content ) {

		// if the option is not true, get out
		if ( true !== $this->lazy_load_post_content ) {
			return $content;
		}

		// if this is not a singular item, get out
		if ( ! is_singular() ) {
			return $content;
		}

		if ( is_feed() ) {
			global $wp_query;
			$current_object = $wp_query;
		} else {
			$current_object = get_queried_object();
		}
		if ( is_object( $current_object ) ) {
			$post_type = isset( $current_object->post_type ) ? $current_object->post_type : '';
		}

		// Don't parse post_content if this post is not a supported type
		$post_types = get_option( $this->option_prefix . 'post_types', array() );
		if ( ! in_array( $post_type, $post_types ) ) {
			return $content;
		}

		try {
			$dom = new DOMDocument( null, 'UTF-8' );
			// @codingStandardsIgnoreStart
			@$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) );
			// @codingStandardsIgnoreEnd
			$content = $this->process_images( $dom ); // use domdocument to convert images
			$content = $this->process_iframes( $content ); // use content string to convert iframes
			return $content;
		} catch ( Exception $e ) {
			return $content;
		}
	}

	/**
	 * Convert lazy loaded items, if applicable, for lozad libray
	 *
	 * @param string|array $output_html
	 * @param array $params
	 * @return string|array $output_html
	 */
	public function convert_html( $output_html, $params = array() ) {
		if ( false === $this->lazy_load_anything ) {
			return $output_html;
		}
		switch ( $params['html_tag'] ) {
			case 'script':
				// todo: need to document how this works because it's very specific
				$output_html['script'] = '<div class="lozad" data-src="' . $params['url'] . '"></div>';
				break;
			case 'img':
				if ( false === $this->lazy_load_images ) {
					return $output_html;
				}
				$dom = new DOMDocument();
				// @codingStandardsIgnoreStart
				@$dom->loadHTML( $output_html );
				// @codingStandardsIgnoreEnd
				$output_html = $this->process_images( $dom );
				break;
			case 'iframe':
				if ( false === $this->lazy_load_iframes ) {
					return $output_html;
				}
				// todo: this is rather generic, but should still document it
				$output_html = $this->process_iframes( $output_html );
				break;
			default:
				// if the filter doesn't have a way to catch the given markup, it doesn't do anything.
				$output_html = $output_html;
				break;
		}
		return $output_html;
	}

	/**
	 * Process image tags for lazod
	 *
	 * @param string $dom
	 * @return string $output_html
	 */
	private function process_images( $dom ) {
		$images = [];
		foreach ( $dom->getElementsByTagName( 'img' ) as $node ) {
			$images[] = $node;
		}

		foreach ( $images as $node ) {

			// if this image has a no-lazy class, get out
			if ( $node->hasAttribute( 'class' ) && strstr( $node->getAttribute( 'class' ), 'no-lazy' ) ) {
				continue;
			}

			// create the content for noscript
			$fallback = $node->cloneNode( true );

			// change src to data-src
			$oldsrc = $node->getAttribute( 'src' );
			$node->setAttribute( 'data-src', $oldsrc );
			$node->removeAttribute( 'src' );

			// change srcset to data-srcset
			$oldsrcset = $node->getAttribute( 'srcset' );
			$node->setAttribute( 'data-srcset', $oldsrcset );
			$node->removeAttribute( 'srcset' );

			// preserve existing classes, if applicable, and add lazy-load as a class
			$classes = $node->getAttribute( 'class' );
			if ( ! empty( $classes ) ) {
				$newclasses = $classes . ' lazy-load';
			} else {
				$newclasses = 'lazy-load';
			}
			$node->setAttribute( 'class', $newclasses );

			// populate the noscript element
			$noscript = $dom->createElement( 'noscript', '' );
			// @codingStandardsIgnoreStart
			$node->parentNode->insertBefore( $noscript, $node );
			// @codingStandardsIgnoreEnd
			$noscript->appendChild( $fallback );
		}

		// save the html
		$output_html = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( array( '<html>', '</html>', '<body>', '</body>' ), array( '', '', '', '' ), $dom->saveHTML() ) );
		return $output_html;
	}

	/**
	 * Process iframe tags for lazod
	 *
	 * @param string $output_html
	 * @return string $output_html
	 */
	private function process_iframes( $output_html ) {
		$output_html = preg_replace( '/<iframe(.*?)(src=)(.*?)>/i', '<iframe$1data-$2$3>', $output_html );
		return $output_html;
	}

	/**
	* Enqueue CSS and JavaScript libraries for front end
	*
	*/
	public function add_scripts_and_styles() {
		if ( true === $this->lazy_load_anything ) {

			// allow individual posts to disable lazyload. this can be useful in the case of unresolvable javascript conflicts.
			if ( is_singular() ) {
				global $post;
				if ( get_post_meta( $post->ID, $this->option_prefix . 'prevent_lozad_lazyload', true ) ) {
					return;
				}
			}

			wp_enqueue_style( $this->slug, plugins_url( 'assets/css/' . $this->slug . '.min.css', __FILE__ ), array(), $this->version, 'all' );
			$lozad_dependencies = array(
				'postscribe',
			);
			if ( true === filter_var( get_option( $this->option_prefix . 'use_intersectionobserver_polyfill', false ), FILTER_VALIDATE_BOOLEAN ) ) {
				wp_enqueue_script( 'polyfill', plugins_url( 'assets/js/intersectionobserver.min.js', __FILE__ ), array(), $this->version, true );
				$lozad_dependencies[] = 'polyfill';
			}
			wp_enqueue_script( 'postscribe', 'https://cdnjs.cloudflare.com/ajax/libs/postscribe/2.0.8/postscribe.min.js', array(), '2.0.8', true );
			wp_enqueue_script( 'lozad', 'https://cdn.jsdelivr.net/npm/lozad/dist/lozad.min.js', $lozad_dependencies, '1.6.0', true );
			wp_add_inline_script( 'lozad', "
				if (typeof lozad != 'undefined') {
					window.addEventListener('load', function() {
						window.lozad('.lazy-load', {
							rootMargin: '300px 0px',
							loaded: function (el) {
								el.classList.add('is-loaded');
							}
						}).observe();
					});
				}
				"
			);
		}
	}

}

// Initialize the plugin
$wp_lozad_lazyload = WP_Lozad_LazyLoad::get_instance();
