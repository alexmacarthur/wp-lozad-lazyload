<?php
/*
 * Plugin Name: WP Lozad Lazy Load
 * Description: Lazy Load images, iframes, scripts, and other content with the Lozad library
 * Version: 0.0.1
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
		$this->version       = '0.0.1';
		$this->slug          = 'wp-lozad-lazyload';

		$this->lazy_load_anything = true;

		$this->add_actions();

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
		// convert html to lozad requirements
		add_filter( $this->option_prefix . 'convert_html', array( $this, 'convert_html' ), 10, 2 );
		// add css and javascript
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts_and_styles' ) );
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
				// todo: this is rather generic, but should still document it
				$dom = new DOMDocument();
				// @codingStandardsIgnoreStart
				@$dom->loadHTML( $output_html );
				// @codingStandardsIgnoreEnd
				$images = [];
				foreach ( $dom->getElementsByTagName( 'img' ) as $node ) {
					$images[] = $node;
				}

				foreach ( $images as $node ) {
					$fallback = $node->cloneNode( true );

					$oldsrc = $node->getAttribute( 'src' );
					$node->setAttribute( 'data-src', $oldsrc );
					$node->removeAttribute( 'src' );

					$oldsrcset = $node->getAttribute( 'srcset' );
					$node->setAttribute( 'data-srcset', $oldsrcset );
					$node->removeAttribute( 'srcset' );

					$classes    = $node->getAttribute( 'class' );
					$newclasses = $classes . ' lazy-load';
					$node->setAttribute( 'class', $newclasses );

					$noscript = $dom->createElement( 'noscript', '' );
					// @codingStandardsIgnoreStart
					$node->parentNode->insertBefore( $noscript, $node );
					// @codingStandardsIgnoreEnd
					$noscript->appendChild( $fallback );
				}

				$output_html = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( array( '<html>', '</html>', '<body>', '</body>' ), array( '', '', '', '' ), $dom->saveHTML() ) );

				break;
			case 'iframe':
				// todo: this is rather generic, but should still document it
				$output_html = preg_replace( '/<iframe(.*?)(src=)(.*?)>/i', '<iframe$1data-$2$3>', $output_html );
				break;
			default:
				// if the filter doesn't have a way to catch the given markup, it doesn't do anything.
				$output_html = $output_html;
				break;
		}
		return $output_html;
	}

	/**
	* Enqueue CSS and JavaScript libraries for front end
	*
	*/
	public function add_scripts_and_styles() {
		if ( true === $this->lazy_load_anything ) {
			wp_enqueue_style( $this->slug, plugins_url( 'assets/css/' . $this->slug . '.min.css', dirname( __FILE__ ) ), array(), $this->version, 'all' );
			wp_enqueue_script( 'polyfill', plugins_url( 'assets/js/intersectionobserver.min.js', dirname( __FILE__ ) ), array(), $this->version, true );
			wp_enqueue_script( 'postscribe', 'https://cdnjs.cloudflare.com/ajax/libs/postscribe/2.0.8/postscribe.min.js', array(), '2.0.8', true );
			wp_enqueue_script( 'lozad', 'https://cdn.jsdelivr.net/npm/lozad/dist/lozad.min.js', array( 'postscribe', 'polyfill' ), '1.6.0', true );
			wp_add_inline_script( 'lozad', "
				lozad('.lazy-load', { 
					rootMargin: '300px 0px', 
					loaded: function (el) {
						el.classList.add('is-loaded');
					}
				}).observe();
				"
			);
		}
	}

}

// Initialize the plugin
$wp_lozad_lazyload = WP_Lozad_LazyLoad::get_instance();