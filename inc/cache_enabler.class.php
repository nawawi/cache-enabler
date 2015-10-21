<?php


// exit
defined('ABSPATH') OR exit;


/**
* Cache_Enabler
*
* @since 1.0.0
*/

final class Cache_Enabler {


	/**
	* plugin options
	*
	* @since  1.0.0
	* @var    array
	*/

	private static $options;


	/**
	* disk cache object
	*
	* @since  1.0.0
	* @var    object
	*/

	private static $disk;


	/**
	* minify default settings
	*
	* @since  1.0.0
	* @var    integer
	*/

	const MINIFY_DISABLED = 0;
	const MINIFY_HTML_ONLY = 1;
	const MINIFY_HTML_JS = 2;


	/**
	* constructor wrapper
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function instance()
	{
		new self();
	}


	/**
	* constructor
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   void
	* @return  void
	*/

	public function __construct()
	{
		// set default vars
		self::_set_default_vars();

		// register publish hook
		add_action(
			'init',
			array(
				__CLASS__,
				'register_publish_hooks'
			),
			99
		);

		// clear cache hooks
		add_action(
			'ce_remove_post_cache',
			array(
				__CLASS__,
				'clear_page_cache_by_post_id'
			)
		);
		add_action(
			'ce_clear_cache',
			array(
				__CLASS__,
				'clear_total_cache'
			)
		);
		add_action(
			'_core_updated_successfully',
			array(
				__CLASS__,
				'clear_total_cache'
			)
		);
		add_action(
			'switch_theme',
			array(
				__CLASS__,
				'clear_total_cache'
			)
		);
		add_action(
			'wp_trash_post',
			array(
				__CLASS__,
				'clear_total_cache'
			)
		);

        // add admin clear link
		add_action(
			'admin_bar_menu',
			array(
				__CLASS__,
				'add_clear_link'
			),
			90
		);
		add_action(
			'init',
			array(
				__CLASS__,
				'process_clear_request'
			)
		);

		// admin
		if ( is_admin() ) {
			add_action(
				'wpmu_new_blog',
				array(
					__CLASS__,
					'install_later'
				)
			);
			add_action(
				'delete_blog',
				array(
					__CLASS__,
					'uninstall_later'
				)
			);

			add_action(
				'admin_init',
				array(
					__CLASS__,
					'register_textdomain'
				)
			);
			add_action(
				'admin_init',
				array(
					__CLASS__,
					'register_settings'
				)
			);

			add_action(
				'admin_menu',
				array(
					__CLASS__,
					'add_settings_page'
				)
			);
			add_action(
				'admin_enqueue_scripts',
				array(
					__CLASS__,
					'add_admin_resources'
				)
			);

			add_action(
				'transition_comment_status',
				array(
					__CLASS__,
					'change_comment'
				),
				10,
				3
			);
			add_action(
				'edit_comment',
				array(
					__CLASS__,
					'edit_comment'
				)
			);

			add_filter(
				'dashboard_glance_items',
				array(
					__CLASS__,
					'add_dashboard_count'
				)
			);
			add_action(
				'post_submitbox_misc_actions',
				array(
					__CLASS__,
					'add_clear_dropdown'
				)
			);

			add_filter(
				'plugin_row_meta',
				array(
					__CLASS__,
					'row_meta'
				),
				10,
				2
			);
			add_filter(
				'plugin_action_links_' .CE_BASE,
				array(
					__CLASS__,
					'action_links'
				)
			);

			// warnings and notices
			add_action(
				'admin_notices',
				array(
					__CLASS__,
					'warning_is_permalink'
				)
			);

		// caching
		} else {
			add_action(
				'pre_comment_approved',
				array(
					__CLASS__,
					'new_comment'
				),
				99,
				2
			);
			add_action(
				'template_redirect',
				array(
					__CLASS__,
					'manage_cache'
				),
				0
			);
		}
	}


	/**
	* deactivation hook
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function on_deactivation() {
		self::clear_total_cache(true);
	}


	/**
	* activation hook
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function on_activation() {

		// multisite and network
		if ( is_multisite() && ! empty($_GET['networkwide']) ) {
			// blog ids
			$ids = self::_get_blog_ids();

			// switch to blog
			foreach ($ids as $id) {
				switch_to_blog($id);
				self::_install_backend();
			}

			// restore blog
			restore_current_blog();

		} else {
			self::_install_backend();
		}
	}


	/**
	* install on multisite setup
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function install_later($id) {

		// check if multisite setup
		if ( ! is_plugin_active_for_network(CE_BASE) ) {
			return;
		}

		// switch to blog
		switch_to_blog($id);

		// installation
		self::_install_backend();

		// restore
		restore_current_blog();
	}


	/**
	* installation options
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	private static function _install_backend() {

		add_option(
			'cache',
			array()
		);

		// clear
		self::clear_total_cache(true);
	}


	/**
	* uninstall per multisite blog
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function on_uninstall() {
		global $wpdb;

		// multisite and network
		if ( is_multisite() && ! empty($_GET['networkwide']) ) {
			// legacy blog
			$old = $wpdb->blogid;

			// blog id
			$ids = self::_get_blog_ids();

			// uninstall per blog
			foreach ($ids as $id) {
				switch_to_blog($id);
				self::_uninstall_backend();
			}

			// restore
			switch_to_blog($old);
		} else {
			self::_uninstall_backend();
		}
	}


	/**
	* uninstall for multisite and network
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function uninstall_later($id) {

		// check if network plugin
		if ( ! is_plugin_active_for_network(CE_BASE) ) {
			return;
		}

		// switch
		switch_to_blog($id);

		// uninstall
		self::_uninstall_backend();

		// restore
		restore_current_blog();
	}


	/**
	* uninstall
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	private static function _uninstall_backend() {

		// delete options
		delete_option('cache');

		// clear cache
		self::clear_total_cache(true);
	}


	/**
	* get blog ids
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  array  blog ids array
	*/

	private static function _get_blog_ids() {
		global $wpdb;

		return $wpdb->get_col("SELECT blog_id FROM `$wpdb->blogs`");
	}


	/**
	* set default vars
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	private static function _set_default_vars() {

		// get options
		self::$options = self::_get_options();

		// disk cache
		if ( Cache_Enabler_Disk::is_permalink() ) {
			self::$disk = new Cache_Enabler_Disk;
		}
	}


	/**
	* get options
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  array  options array
	*/

	private static function _get_options() {

		return wp_parse_args(
			get_option('cache'),
			array(
				'if_loggedin'	=> 1,
				'new_comment' 	=> 0,
				'excl_ids'	 	=> '',
				'minify_html' 	=> self::MINIFY_DISABLED,
			)
		);
	}


	/**
	* warning if no custom permlinks
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  array  options array
	*/

	public static function warning_is_permalink() {

		if ( !Cache_Enabler_Disk::is_permalink() AND current_user_can('manage_options') ) { ?>

			<div class="error">
				<p><?php printf( __('The <b>%s</b> plugin requires a custom permalink structure to start caching properly. Please go to <a href="%s">Permalink</a> to enable it.', 'cache'), 'Cache Enabler', admin_url( 'options-permalink.php' ) ); ?></p>
			</div>

		<?php
		}
	}


	/**
	* add action links
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   array  $data  existing links
	* @return  array  $data  appended links
	*/

	public static function action_links($data) {

		// check user role
		if ( ! current_user_can('manage_options') ) {
			return $data;
		}

		return array_merge(
			$data,
			array(
				sprintf(
					'<a href="%s">%s</a>',
					add_query_arg(
						array(
							'page' => 'cache-enabler'
						),
						admin_url('options-general.php')
					),
					__('Settings')
				)
			)
		);
	}


	/**
	* cache enabler meta links
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   array   $input  existing links
	* @param   string  $page   page
	* @return  array   $data   appended links
	*/

	public static function row_meta($input, $page) {

		// check permissions
		if ( $page != CE_BASE ) {
			return $input;
		}

		return array_merge(
			$input,
			array(
				'<a href="https://www.keycdn.com/support/" target="_blank">Support Page</a>',
			)
		);
	}


	/**
	* add dashboard cache size count
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   array  $items  initial array with dashboard items
	* @return  array  $items  merged array with dashboard items
	*/

	public static function add_dashboard_count( $items = array() ) {

		// check user role
        if ( ! current_user_can('manage_options') ) {
            return $items;
        }

		// get cache size
		$size = self::get_cache_size();

		// display items
		$items[] = sprintf(
			'<a href="%s" title="Disk Cache">%s Cache Size</a>',
			add_query_arg(
				array(
					'page' => 'cache-enabler'
				),
				admin_url('options-general.php')
			),
			( empty($size) ? esc_html__('Empty', 'cache') : size_format($size) )
		);

		return $items;
	}


	/**
	* get cache size
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   integer  $size  cache size (bytes)
	*/

	public static function get_cache_size() {

		if ( ! $size = get_transient('cache_size') ) {

			$size = (int) self::$disk->cache_size(CE_CACHE_DIR);

			// set transient
			set_transient(
			  'cache_size',
			  $size,
			  60 * 15
			);
		}

		return $size;
	}


	/**
	* add admin clear link
	*
	* @since   1.0.0
	* @change  1.0.0
    *
    * @hook    mixed
	*
	* @param   object  menu properties
	*/

	public static function add_clear_link($wp_admin_bar) {

		// check user role
		if ( ! is_admin_bar_showing() OR ! apply_filters('user_can_clear_cache', current_user_can('manage_options')) ) {
			return;
		}

		// add admin purge link
		$wp_admin_bar->add_menu(
			array(
				'id' 	 => 'cache',
				'href'   => wp_nonce_url( add_query_arg('_cache', 'clear'), '_cache__clear_nonce'),
				'parent' => 'top-secondary',
				'title'	 => '<span class="ab-item">Clear Cache</span>',
				'meta'   => array( 'title' => esc_html__('clear Cache', 'cache') )
			)
		);
	}


	/**
	* process clear request
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   array  $data  array of metadata
	*/

	public static function process_clear_request($data) {

		// check if clear request
		if ( empty($_GET['_cache']) OR $_GET['_cache'] !== 'clear' ) {
			return;
		}

        // validate nonce
        if ( empty($_GET['_wpnonce']) OR ! wp_verify_nonce($_GET['_wpnonce'], '_cache__clear_nonce') ) {
            return;
        }

		// check user role
		if ( ! is_admin_bar_showing() OR ! apply_filters('user_can_clear_cache', current_user_can('manage_options')) ) {
			return;
		}

		// load if network
		if ( ! function_exists('is_plugin_active_for_network') ) {
			require_once( ABSPATH. 'wp-admin/includes/plugin.php' );
		}

		// multisite and network setup
		if ( is_multisite() && is_plugin_active_for_network(CE_BASE) ) {

			// legacy blog
			$legacy = $GLOBALS['wpdb']->blogid;

			// blog ids
			$ids = self::_get_blog_ids();

			// switch blogs
			foreach ($ids as $id) {
				switch_to_blog($id);
				self::clear_total_cache();
			}

			// restore
			switch_to_blog($legacy);

			// clear notice
			if ( is_admin() ) {
				add_action(
					'network_admin_notices',
					array(
						__CLASS__,
						'clear_notice'
					)
				);
			}
		} else {
			// clear cache
			self::clear_total_cache();

			// clear notice
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					array(
						__CLASS__,
						'clear_notice'
					)
				);
			}
		}

		if ( ! is_admin() ) {
			wp_safe_redirect(
				remove_query_arg(
					'_cache',
					wp_get_referer()
				)
			);

			exit();
		}
	}


	/**
	* notification after clear cache
	*
	* @since   1.0.0
	* @change  1.0.0
    *
    * @hook    mixed  user_can_clear_cache
	*/

	public static function clear_notice() {

		// check if admin
		if ( ! is_admin_bar_showing() OR ! apply_filters('user_can_clear_cache', current_user_can('manage_options')) ) {
			return false;
		}

		echo sprintf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__('The cache has been deleted.', 'cache')
		);
	}


	/**
	* clear cache if edit comment
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   integer  $id  id of the comment
	*/

	public static function edit_comment($id) {

		// clear complete cache if option enabled
		if ( self::$options['new_comment'] ) {
			self::clear_total_cache();
		} else {
			self::clear_page_cache_by_post_id(
				get_comment($id)->comment_post_ID
			);
		}
	}


	/**
	* clear cache if new comment
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   mixed  $approved  approval status
	* @param   array  $comment
	* @return  mixed  $approved  approval status
	*/

	public static function new_comment($approved, $comment) {

		// check if comment is approved
		if ( $approved === 1 ) {
			if ( self::$options['new_comment'] ) {
				self::clear_total_cache();
			} else {
				self::clear_page_cache_by_post_id( $comment['comment_post_ID'] );
			}
		}

		return $approved;
	}


	/**
	* clear cache if comment changes
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $after_status
	* @param   string  $before_status
	* @param   object  $comment
	*/

	public static function change_comment($after_status, $before_status, $comment) {

		// check if changes occured
		if ( $after_status != $before_status ) {
			if ( self::$options['new_comment'] ) {
				self::clear_total_cache();
			} else {
				self::clear_page_cache_by_post_id( $comment->comment_post_ID );
			}
		}
	}


	/**
	* register publish hooks for custom post types
	*
	* @since   1.0.0
	* @since   1.0.0
	*
	* @param   void
	* @return  void
	*/

	public static function register_publish_hooks() {

		// get post types
		$post_types = get_post_types(
			array('public' => true)
		);

		// check if empty
		if ( empty($post_types) ) {
			return;
		}

		// post type actions
		foreach ( $post_types as $post_type ) {
			add_action(
				'publish_' .$post_type,
				array(
					__CLASS__,
					'publish_post_types'
				),
				10,
				2
			);
			add_action(
				'publish_future_' .$post_type,
				array(
					__CLASS__,
					'clear_total_cache'
				)
			);
		}
	}


	/**
	* delete post type cache on post updates
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   integer  $post_ID  Post ID
	*/

	public static function publish_post_types($post_ID, $post) {

		// check if post id or post is empty
		if ( empty($post_ID) OR empty($post) ) {
			return;
		}

		// check post status
		if ( ! in_array( $post->post_status, array('publish', 'future') ) ) {
			return;
		}

		// purge cache if clean post on update
		if ( ! isset($_POST['_clear_post_cache_on_update']) ) {
			return self::clear_total_cache();
		}

		// validate nonce
		if ( ! isset($_POST['_cache__status_nonce_' .$post_ID]) OR ! wp_verify_nonce($_POST['_cache__status_nonce_' .$post_ID], CE_BASE) ) {
			return;
		}

		// validate user role
		if ( ! current_user_can('publish_posts') ) {
			return;
		}

		// save as integer
		$clear_post_cache = (int)$_POST['_clear_post_cache_on_update'];

		// save user metadata
		update_user_meta(
			get_current_user_id(),
			'_clear_post_cache_on_update',
			$clear_post_cache
		);

		// purge complete cache or specific post
		if ( $clear_post_cache ) {
			self::clear_page_cache_by_post_id( $post_ID );
		} else {
			self::clear_total_cache();
		}
	}


	/**
	* clear page cache by post id
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   integer  $post_ID  Post ID
	*/

	public static function clear_page_cache_by_post_id($post_ID) {

		// is int
		if ( ! $post_ID = (int)$post_ID ) {
			return;
		}

		// clear cache by URL
		self::clear_page_cache_by_url(
			get_permalink( $post_ID )
		);
	}


	/**
	* clear page cache by url
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param  string  $url  url of a page
	*/

	public static function clear_page_cache_by_url($url) {

		// validate string
		if ( ! $url = (string)$url ) {
			return;
		}

		call_user_func(
			array(
				self::$disk,
				'delete_asset'
			),
			$url
		);
	}


	/**
	* explode on comma
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $input  input string
	* @return  array           array of strings
	*/

	private static function _preg_split($input) {
		return (array)preg_split('/,/', $input, -1, PREG_SPLIT_NO_EMPTY);
	}


	/**
	* check if index.php
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if index.php
	*/

	private static function _is_index() {
		return basename($_SERVER['SCRIPT_NAME']) != 'index.php';
	}


	/**
	* check if mobile
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if mobile
	*/

	private static function _is_mobile() {
		return ( strpos(TEMPLATEPATH, 'wptouch') OR strpos(TEMPLATEPATH, 'carrington') OR strpos(TEMPLATEPATH, 'jetpack') OR strpos(TEMPLATEPATH, 'handheld') );
	}


	/**
	* check if logged in
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if logged in or cookie set
	*/

	private static function _is_logged_in() {

		// check if logged in
		if ( is_user_logged_in() ) {
			return true;
		}

		// check cookie
		if ( empty($_COOKIE) ) {
			return false;
		}

		// check cookie values
		foreach ( $_COOKIE as $k => $v) {
			if ( preg_match('/^(wp-postpass|wordpress_logged_in|comment_author)_/', $k) ) {
				return true;
			}
		}
	}


	/**
	* definitaion of cache expection
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if exception
	*
	* @hook    boolean  skip_cache
	*/

	private static function _skip_cache() {

		// skip cache hook
		if ( apply_filters('skip_cache', false) ) {
			return true;
		}

		// conditional tags
		if ( self::_is_index() OR is_search() OR is_404() OR is_feed() OR is_trackback() OR is_robots() OR is_preview() OR post_password_required() ) {
			return true;
		}

		// DONOTCACHEPAGE check e.g. woocommerce
		if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) {
			return true;
		}

		// cache enabler options
		$options = self::$options;

		// Request method GET
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] != 'GET' ) {
			return true;
		}

		// if logged in
		if ( $options['if_loggedin'] && self::_is_logged_in() ) {
			return true;
		}

		// if mobile request
		if ( self::_is_mobile() ) {
			return true;
		}

		// if post id excluded
		if ( $options['excl_ids'] && is_singular() ) {
			if ( in_array( $GLOBALS['wp_query']->get_queried_object_id(), self::_preg_split($options['excl_ids']) ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	* minify html
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $data  minify request data
	* @return  string  $data  minify response data
	*
	* @hook    array   cache_minify_ignore_tags
	*/

	private static function _minify_cache($data) {

		// check if disabled
		if ( ! self::$options['minify_html'] ) {
			return $data;
		}

		// strlen limit
		if ( strlen($data) > 700000) {
			return $data;
		}

		// ignore this tags
		$ignore_tags = (array)apply_filters(
			'cache_minify_ignore_tags',
			array(
				'textarea',
				'pre'
			)
		);

		// ignore JS if selected
		if ( self::$options['minify_html'] !== self::MINIFY_HTML_JS ) {
			$ignore_tags[] = 'script';
		}

		// return of no ignore tags
		if ( ! $ignore_tags ) {
			return $data;
		}

		// stringify
		$ignore_regex = implode('|', $ignore_tags);

		// regex minification
		$cleaned = preg_replace(
			array(
				'/<!--[^\[><](.*?)-->/s',
				'#(?ix)(?>[^\S ]\s*|\s{2,})(?=(?:(?:[^<]++|<(?!/?(?:' .$ignore_regex. ')\b))*+)(?:<(?>' .$ignore_regex. ')\b|\z))#'
			),
			array(
				'',
				' '
			),
			$data
		);

		// something went wrong
		if ( strlen($cleaned) <= 1 ) {
			return $data;
		}

		return $cleaned;
	}


	/**
	* clear complete cache
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function clear_total_cache() {

		// clear disk cache
		Cache_Enabler_Disk::clear_cache();

		// delete transient
		delete_transient('cache_size');
	}


	/**
	* set cache
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $data  content of a page
	* @return  string  $data  content of a page
	*/

	public static function set_cache($data) {

		// check if empty
		if ( empty($data) ) {
			return '';
		}

		// store as asset
		call_user_func(
			array(
				self::$disk,
				'store_asset'
			),
			self::_minify_cache($data)
		);

		return $data;
	}


	/**
	* manage cache
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function manage_cache() {

		// skip cache
		if ( self::_skip_cache() ) {
			return;
		}

		// check if asset is cached
		$cached = call_user_func(
			array(
				self::$disk,
				'check_asset'
			)
		);

		// check if cache empty
		if ( empty($cached) ) {
			ob_start('Cache_Enabler::set_cache');
			return;
		}

		// return cached asset
		call_user_func(
			array(
				self::$disk,
				'get_asset'
			)
		);
	}


	/**
	* add clear option dropdown on post publish widget
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function add_clear_dropdown() {

		// on published post page only
		if ( empty($GLOBALS['pagenow']) OR $GLOBALS['pagenow'] !== 'post.php' OR empty($GLOBALS['post']) OR ! is_object($GLOBALS['post']) OR $GLOBALS['post']->post_status !== 'publish' ) {
			return;
		}

		// check user role
		if ( ! current_user_can('publish_posts') ) {
			return;
		}

		// validate nonce
		wp_nonce_field(CE_BASE, '_cache__status_nonce_' .$GLOBALS['post']->ID);

		// get current action
		$current_action = (int)get_user_meta(
			get_current_user_id(),
			'_clear_post_cache_on_update',
			true
		);

		// init variables
		$dropdown_options = '';
		$available_options = array(
			esc_html__('Completely', 'cache'),
			esc_html__('Page specific', 'cache')
		);

		// set dropdown options
		foreach( $available_options as $key => $value ) {
			$dropdown_options .= sprintf(
				'<option value="%1$d" %3$s>%2$s</option>',
				$key,
				$value,
				selected($key, $current_action, false)
			);
		}

		// output drowdown
		echo sprintf(
			'<div class="misc-pub-section" style="border-top:1px solid #eee">
				<label for="cache_action">
					%1$s: <span id="output-cache-action">%2$s</span>
				</label>
				<a href="#" class="edit-cache-action hide-if-no-js">%3$s</a>

				<div class="hide-if-js">
					<select name="_clear_post_cache_on_update" id="cache_action">
						%4$s
					</select>

					<a href="#" class="save-cache-action hide-if-no-js button">%5$s</a>
	 				<a href="#" class="cancel-cache-action hide-if-no-js button-cancel">%6$s</a>
	 			</div>
			</div>',
			esc_html__('Clear cache', 'cache'),
			$available_options[$current_action],
			esc_html__('Edit'),
			$dropdown_options,
			esc_html__('OK'),
			esc_html__('Cancel')
		);
	}


	/**
	* enqueue scripts
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function add_admin_resources($hook) {

		// hook check
		if ( $hook !== 'index.php' AND $hook !== 'post.php' ) {
			return;
		}

		// plugin data
		$plugin_data = get_plugin_data(CE_FILE);

		// enqueue scripts
		switch($hook) {

			case 'post.php':
				wp_enqueue_script(
					'cache-post',
					plugins_url('js/post.js', CE_FILE),
					array('jquery'),
					$plugin_data['Version'],
					true
				);
			break;

			default:
			break;
		}
	}


	/**
	* add settings page
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function add_settings_page() {

		add_options_page(
			'Cache Enabler',
			'Cache Enabler',
			'manage_options',
			'cache-enabler',
			array(
				__CLASS__,
				'settings_page'
			)
		);
	}


	/**
	* minify caching dropdown
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  array    Key => value array
	*/

	private static function _minify_select() {

		return array(
			self::MINIFY_DISABLED  => esc_html__('Disabled', 'cache'),
			self::MINIFY_HTML_ONLY => 'HTML',
			self::MINIFY_HTML_JS   => 'HTML & Inline JS'
		);
	}


	/**
	* register textdomain
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function register_textdomain() {

		load_plugin_textdomain(
			'cache',
			false,
			'cache-enabler/lang'
		);
	}


	/**
	* register settings
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function register_settings() {

		register_setting(
			'cache-enabler',
			'cache',
			array(
				__CLASS__,
				'validate_settings'
			)
		);
	}


	/**
	* validate settings
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   array  $data  array form data
	* @return  array         array form data valid
	*/

	public static function validate_settings($data) {

		// check if empty
		if ( empty($data) ) {
			return;
		}

		// clear complete cache
		self::clear_total_cache(true);

		return array(
			'if_loggedin' 	=> (int)(!empty($data['if_loggedin'])),
			'minify_html' 	=> (int)$data['minify_html'],
			'excl_ids' 		=> (string)sanitize_text_field(@$data['excl_ids']),
			'new_comment' 	=> (int)(!empty($data['new_comment']))
		);
	}


	/**
	* settings page
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function settings_page() { ?>

		<div class="wrap" id="cache-settings">
			<h2>
				<?php _e("Cache Enabler Settings", "cache") ?>
			</h2>

			<p><?php $size=self::get_cache_size(); printf( __("Current cache size: <b>%s</b>", "cache"), ( empty($size) ? esc_html__("Empty", "cache") : size_format($size) ) ); ?></p>


			<form method="post" action="options.php">
				<?php settings_fields('cache-enabler') ?>

				<?php $options = self::_get_options() ?>

				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<?php _e("Cache Behavior", "cache") ?>
						</th>
						<td>
							<fieldset>
								<label for="cache_if_loggedin">
									<input type="checkbox" name="cache[if_loggedin]" id="cache_if_loggedin" value="1" <?php checked('1', $options['if_loggedin']); ?> />
									<?php _e("Disable caching if logged in.", "cache") ?>
								</label>

								<br />

								<label for="cache_new_comment">
									<input type="checkbox" name="cache[new_comment]" id="cache_new_comment" value="1" <?php checked('1', $options['new_comment']); ?> />
									<?php _e("Clear the complete cache if a new comment has been posted (instead of only the page specific cache).", "cache") ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<?php _e("Cache Exclusions", "cache") ?>
						</th>
						<td>
							<fieldset>
								<label for="cache_excl_ids">
									<input type="text" name="cache[excl_ids]" id="cache_excl_ids" value="<?php echo esc_attr($options['excl_ids']) ?>" />
									<p class="description" id="admin-email-description"><?php _e("Post or Pages IDs separated by a <code>,</code> that should not be cached.", "cache"); ?></p>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<?php _e("Cache Minification", "cache") ?>
						</th>
						<td>
							<label for="cache_minify_html">
								<select name="cache[minify_html]" id="cache_minify_html">
									<?php foreach( self::_minify_select() as $k => $v ) { ?>
										<option value="<?php echo esc_attr($k) ?>" <?php selected($options['minify_html'], $k); ?>>
											<?php echo esc_html($v) ?>
										</option>
									<?php } ?>
								</select>
							</label>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<?php submit_button() ?>
						</th>
						<td>
							<p class="description"><?php _e("Saving these settings will clear the complete cache.","cache") ?></p>
						</td>
					</tr>
				</table>
			</form>
			<p class="description"><?php _e("It is recommended to enable HTTP/2 on your origin server and use a CDN that supports HTTP/2. Avoid domain sharding and concatenation of your assets to benefit from parallelism of HTTP/2.","cache") ?></p>

		</div><?php
	}
}