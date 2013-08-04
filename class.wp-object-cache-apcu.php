<?php

if ( !function_exists( 'apcu_fetch' ) )
	backpress_die( 'You do not have APC installed, so you cannot use the APC object cache backend. Please stop loading this file in your config file' );

if ( !defined( 'WP_APC_KEY_SALT' ) )
	define( 'WP_APC_KEY_SALT', 'gp' );

require_once( GP_PATH . GP_INC . 'backpress/functions.wp-object-cache.php' );

class WP_Object_Cache {
	var $global_groups = array (
		'_cache_keys'
	);

	var $no_mc_groups = array();

	var $cache = array();
	var $stats = array( 'get' => 0, 'delete' => 0, 'add' => 0 );
	var $group_ops = array();

	var $cache_enabled = true;
	var $default_expiration = 0;
	var $abspath = '';
	var $debug = false;

	function __construct() {
		$this->abspath = md5( GP_PATH );

		global $blog_id, $table_prefix;
		$this->global_prefix = '';
		$this->blog_prefix = '';

		$this->cache_hits =& $this->stats['get'];
		$this->cache_misses =& $this->stats['add'];
	}

	function add( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );

		if ( is_object( $data ) )
			$data = clone $data;

		$store_data = $data;

		if ( is_array( $data ) )
			$store_data = new ArrayObject( $data );

		if ( in_array( $group, $this->no_mc_groups ) ) {
			$this->cache[$key] = $data;
			return true;
		} elseif ( isset( $this->cache[$key] ) && $this->cache[$key] !== false ) {
			return false;
		}

		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;

		$result = apcu_add( $key, $store_data, $expire );
		if ( false !== $result ) {
			@ ++$this->stats['add'];
			$this->group_ops[$group][] = "add $id";
			$this->cache[$key] = $data;
		}

		return $result;
	}

	function add_global_groups( $groups ) {
		if ( !is_array( $groups ) )
			$groups = (array) $groups;

		$this->global_groups = array_merge( $this->global_groups, $groups );
		$this->global_groups = array_unique( $this->global_groups );
	}

	function add_non_persistent_groups( $groups ) {
		if ( !is_array( $groups ) )
			$groups = (array) $groups;

		$this->no_mc_groups = array_merge( $this->no_mc_groups, $groups );
		$this->no_mc_groups = array_unique( $this->no_mc_groups );
	}

	// This is named incr2 because Batcache looks for incr
	// We will define that in a class extension if it is available (APC 3.1.1 or higher)
	function incr( $id, $n = 1, $group = 'default' ) {
		$key = $this->key( $id, $group );
		if ( function_exists( 'apcu_inc' ) )
			return apcu_inc( $key, $n );
		else
			return false;
	}

	function decr( $id, $n = 1, $group = 'default' ) {
		$key = $this->key( $id, $group );
		if ( function_exists( 'apcu_dec' ) )
			return apcu_dec( $id, $n );
		else
			return false;
	}

	function close() {
		return true;
	}

	function delete( $id, $group = 'default' ) {
		$key = $this->key( $id, $group );

		if ( in_array( $group, $this->no_mc_groups ) ) {
			unset( $this->cache[$key] );
			return true;
		}

		$result = apcu_delete( $key );

		@ ++$this->stats['delete'];
		$this->group_ops[$group][] = "delete $id";

		if ( false !== $result )
			unset( $this->cache[$key] );

		return $result; 
	}

	function flush() {
		// Don't flush if multi-blog.
		if ( function_exists( 'is_site_admin' ) || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) )
			return true;

		return apcu_clear_cache( 'user' );
	}

	function get($id, $group = 'default', $force = false) {
		$key = $this->key($id, $group);

		if ( isset($this->cache[$key]) && ( !$force || in_array($group, $this->no_mc_groups) ) ) {
			if ( is_object( $this->cache[$key] ) )
				$value = clone $this->cache[$key];
			else
				$value = $this->cache[$key];
		} else if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $value = false;
		} else {
			$value = apcu_fetch( $key );
			if ( is_object( $value ) && 'ArrayObject' == get_class( $value ) )
				$value = $value->getArrayCopy();
			if ( NULL === $value )
				$value = false;
			$this->cache[$key] = $value;
		}

		@ ++$this->stats['get'];
		$this->group_ops[$group][] = "get $id";

		if ( 'checkthedatabaseplease' === $value ) {
			unset( $this->cache[$key] );
			$value = false;
		}

		return $value;
	}

	function key( $key, $group ) {
		if ( empty( $group ) )
			$group = 'default';

		if ( false !== array_search( $group, $this->global_groups ) )
			$prefix = $this->global_prefix;
		else
			$prefix = backpress_get_option( 'application_id' ) . ':';

		return WP_APC_KEY_SALT . ':' . $this->abspath . ":$prefix$group:$key";
	}

	function replace( $id, $data, $group = 'default', $expire = 0 ) {
		return $this->set( $id, $data, $group, $expire );
	}

	function set( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );
		if ( isset( $this->cache[$key] ) && ('checkthedatabaseplease' === $this->cache[$key] ) )
			return false;

		if ( is_object( $data ) )
			$data = clone $data;

		$store_data = $data;

		if ( is_array( $data ) )
			$store_data = new ArrayObject( $data );

		$this->cache[$key] = $data;

		if ( in_array( $group, $this->no_mc_groups ) )
			return true;

		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;
		$result = apcu_store( $key, $store_data, $expire );

		return $result;
	}

	function colorize_debug_line( $line ) {
		$colors = array(
			'get' => 'green',
			'set' => 'purple',
			'add' => 'blue',
			'delete' => 'red');

		$cmd = substr( $line, 0, strpos( $line, ' ' ) );

		$cmd2 = "<span style='color:{$colors[$cmd]}'>$cmd</span>";

		return $cmd2 . substr( $line, strlen( $cmd ) ) . "\n";
	}

	function stats() {
		echo "<p>\n";
		foreach ( $this->stats as $stat => $n ) {
			echo "<strong>$stat</strong> $n";
			echo "<br/>\n";
		}
		echo "</p>\n";
		echo "<h3>APC:</h3>";
		foreach ( $this->group_ops as $group => $ops ) {
			if ( !isset( $_GET['debug_queries'] ) && 500 < count( $ops ) ) { 
				$ops = array_slice( $ops, 0, 500 ); 
				echo "<big>Too many to show! <a href='" . add_query_arg( 'debug_queries', 'true' ) . "'>Show them anyway</a>.</big>\n";
			} 
			echo "<h4>$group commands</h4>";
			echo "<pre>\n";
			$lines = array();
			foreach ( $ops as $op ) {
				$lines[] = $this->colorize_debug_line($op); 
			}
			print_r($lines);
			echo "</pre>\n";
		}
		if ( $this->debug ) {
			$apc_info = apcu_cache_info();
			echo "<p>";
			echo "<strong>Cache Hits:</strong> {$apc_info['num_hits']}<br/>\n";
			echo "<strong>Cache Misses:</strong> {$apc_info['num_misses']}\n";
			echo "</p>\n";
		}
	}

}
