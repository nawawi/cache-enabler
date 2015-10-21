<?php


// exit
defined('ABSPATH') OR exit;


/**
* Cache_Enabler_Disk
*
* @since 1.0.0
*/

final class Cache_Enabler_Disk {


	/**
	* permalink check
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if installed
	*/

	public static function is_permalink() {
		return get_option('permalink_structure');
	}


	/**
	* store asset
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string   $data	content of the asset
	*/

	public static function store_asset($data) {

		// check if empty
		if ( empty($data) ) {
			wp_die('Asset is empty.');
		}

		// save asset
		self::_create_files(
			$data . self::_cache_signatur()
		);
	}


	/**
	* check asset
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  boolean  true if asset exists
	*/

	public static function check_asset() {
		return is_readable(
			self::_file_html()
		);
	}


	/**
	* delete asset
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string   $url   url of cached asset
	*/

	public static function delete_asset($url) {

		// check if url empty
		if ( empty($url) ) {
			wp_die('URL is empty.');
		}

		// delete
		self::_clear_dir(
			self::_file_path($url)
		);
	}


	/**
	* clear cache
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function clear_cache() {
		self::_clear_dir(
			CE_CACHE_DIR
		);
	}


	/**
	* get asset
	*
	* @since   1.0.0
	* @change  1.0.0
	*/

	public static function get_asset() {

		// get if-modified request headers
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			$http_if_modified_since = ( isset( $headers[ 'If-Modified-Since' ] ) ) ? $headers[ 'If-Modified-Since' ] : '';
		} else {
			$http_if_modified_since = ( isset( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) ) ?$_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] : '';
		}

		// check modified since with cached file and return 304 if no difference
		if ( $http_if_modified_since && ( strtotime( $http_if_modified_since ) == filemtime( self::_file_html() ) ) ) {
			header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
			exit;
		}

		// deliver cached file
		readfile( self::_file_html() );
		exit;
	}


	/**
	* create signature
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  string  signature
	*/

	private static function _cache_signatur() {
		return sprintf(
			"\n<!-- %s @ %s -->",
			'Cache Enabler by KeyCDN',
			date_i18n(
				'd.m.Y H:i:s',
				current_time('timestamp')
			)
		);
	}


	/**
	* create files
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $data  html content
	*/

	private static function _create_files($data) {

		// create folder
		if ( ! wp_mkdir_p( self::_file_path() ) ) {
			wp_die('Unable to create directory.');
		}

		// create files
		self::_create_file( self::_file_html(), $data );
		self::_create_file( self::_file_gzip(), gzencode($data, 9) );
	}


	/**
	* create file
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $file  file path
	* @param   string  $data  content of the html
	*/

	private static function _create_file($file, $data) {

		// open file handler
		if ( ! $handle = @fopen($file, 'wb') ) {
			wp_die('Can not write to file.');
		}

		// write
		@fwrite($handle, $data);
		fclose($handle);
		clearstatcache();

		// set permissions
		$stat = @stat( dirname($file) );
		$perms = $stat['mode'] & 0007777;
		$perms = $perms & 0000666;
		@chmod($file, $perms);
		clearstatcache();
	}


	/**
	* clear directory
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $dir  directory
	*/

	private static function _clear_dir($dir) {

		// remove slashes
		$dir = untrailingslashit($dir);

		// check if dir
		if ( ! is_dir($dir) ) {
			return;
		}

		// get dir data
		$objects = array_diff(
			scandir($dir),
			array('..', '.')
		);

		if ( empty($objects) ) {
			return;
		}

		foreach ( $objects as $object ) {
			// full path
			$object = $dir. DIRECTORY_SEPARATOR .$object;

			// check if directory
			if ( is_dir($object) ) {
				self::_clear_dir($object);
			} else {
				unlink($object);
			}
		}

		// delete
		@rmdir($dir);

		// clears file status cache
		clearstatcache();
	}


	/**
	* get cache size
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $dir   folder path
	* @return  mixed   $size  size in bytes
	*/

	public static function cache_size($dir = '.') {

		// check if not dir
		if ( ! is_dir($dir) ) {
			return;
		}

		// get dir data
		$objects = array_diff(
			scandir($dir),
			array('..', '.')
		);

		if ( empty($objects) ) {
			return;
		}

		$size = 0;

		foreach ( $objects as $object ) {
			// full path
			$object = $dir. DIRECTORY_SEPARATOR .$object;

			// check if dir
			if ( is_dir($object) ) {
				$size += self::cache_size($object);
			} else {
				$size += filesize($object);
			}
		}

		return $size;
	}


	/**
	* cache path
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @param   string  $path  uri or permlink
	* @return  string  $diff  path to cached asset
	*/

	private static function _file_path($path = NULL) {

		$path = sprintf(
			'%s%s%s%s',
			CE_CACHE_DIR,
			DIRECTORY_SEPARATOR,
			parse_url(
				'http://' .strtolower($_SERVER['HTTP_HOST']),
				PHP_URL_HOST
			),
			parse_url(
				( $path ? $path : $_SERVER['REQUEST_URI'] ),
				PHP_URL_PATH
			)
		);

		if ( validate_file($path) > 0 ) {
			wp_die('Path is not valid.');
		}

		return trailingslashit($path);
	}


	/**
	* get file path
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  string  path to the html file
	*/

	private static function _file_html() {
		return self::_file_path(). 'index.html';
	}


	/**
	* get gzip file path
	*
	* @since   1.0.0
	* @change  1.0.0
	*
	* @return  string  path to the gzipped html file
	*/

	private static function _file_gzip() {
		return self::_file_path(). 'index.html.gz';
	}
}