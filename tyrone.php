<?php
/*
Plugin Name: Tyrone the WP Watchdog
Plugin URI: http://jacksonwhelan.com/plugins/tyrone-the-wp-watchdog/
Description: Tyrone turns a WordPress installation into a website monitoring tool. Check the status of your sites, and keep tabs on which need upgrading, scan for spam and changes.
Version: 0.1.2
Author: Jackson Whelan
Author URI: http://jacksonwhelan.com/
Donate link: http://jacksonwhelan.com/plugins/tyrone/
*/

/*  
Copyright 2011  Jackson Whelan  (email : jackson.whelan@gmail.com )

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

Thanks to Scribu for http://scribu.net/wordpress/scb-framework
Thanks to Viper007Bond for http://wordpress.org/extend/plugins/regenerate-thumbnails/
Silk icon set 1.3 http://www.famfamfam.com/lab/icons/silk/
*/

require dirname(__FILE__) . '/scb-load.php';

if( !class_exists( 'WP_Http' ) )
	include_once( ABSPATH . WPINC. '/class-http.php' );

if( !function_exists( 'get_preferred_from_update_core' ) )	
	include_once( ABSPATH . '/wp-admin/includes/update.php' );

$wpcurr = get_preferred_from_update_core();
define( 'TYRONE_WP_VERSION', $wpcurr->current );
		
$Tyrone = new Tyrone;

class Tyrone {

	function install() {
    	if( WP_POST_REVISIONS > 101 || !is_int( WP_POST_REVISIONS ) )
    		wp_die( __( 'Please see this codex article <a href="http://codex.wordpress.org/Editing_wp-config.php#Specify_the_Number_of_Post_Revisions">http://codex.wordpress.org/Editing_wp-config.php#Specify_the_Number_of_Post_Revisions</a> to set WP_POST_REVISIONS to a number less than 100, otherwise your database will grow too large. Example (add this to wp-config.php) <br /><br/><pre>define(\'WP_POST_REVISIONS\', 75);</pre>', 'tyrone' ) );
    }
    
    function uninstall() {
    	wp_clear_scheduled_hook( 'tyrone_cron' );    
    }
	
	function Tyrone() {
		add_action( 'init', array( &$this, 'create_post_types' ) );
		add_action( 'init', array( &$this, 'tyrone_init' ) );
		add_action( 'query_vars', array( &$this, 'query_vars' ) );
		add_action( 'template_redirect', array( &$this, 'template_redirect' ) );
		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );		
		add_action( 'add_meta_boxes', array( &$this, 'create_meta_box' ) );
		add_action( 'save_post', array( &$this, 'save_meta_data' ),10 );
		add_action( 'transition_post_status', array( &$this, 'transition' ), 10, 3 );
		add_action( 'manage_posts_custom_column', array( &$this, 'custom_columns' ) );
		add_filter( 'manage_edit-site_columns', array( &$this, 'sites_columns') );	
		add_filter( 'wp_mail_content_type', array( &$this, 'mail_ctype' ) );
		add_filter( 'wp_mail_from', array( &$this, 'mail_from' ) );	
		add_filter( 'wp_mail_from_name', array( &$this, 'mail_from_name' ) );	
		add_action( 'wp_ajax_tyroneprowl', array( &$this, 'ajax_prowl_site' ) );
		add_action( 'wp_ajax_tyroneimport', array( &$this, 'ajax_import_site' ) );
		add_filter( 'manage_edit-site_sortable_columns', array( &$this, 'sites_sort' ) );
		add_filter( 'the_content', array( &$this, 'content_filter' ) );
		add_action( 'tyrone_cron', array( &$this, 'do_tyrone_cron' ) );
		add_action( 'post_row_actions', array( &$this, 'row_actions' ), 10, 2 );
		add_filter( 'request', array( &$this, 'sites_orderby' ) );
	}
	
	
	function row_actions( $actions, $post ) {
		if ( 'site' != $post->post_type )
			return $actions;
	    
	    unset( $actions['view'] );
	    $actions['tyrone_refresh'] = '<a href=\''.admin_url('edit.php?post_type=site&page=tyrone-prowl&_wpnonce='.wp_create_nonce( 'tyrone' ).'&ids='.$post->ID).'\'>Refresh</a>';
		return $actions;
	}

	// Tyrone cron function to loop through sites
	function do_tyrone_cron() {
	
		global $wpdb;
		$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__ );
		
		if( 'Yes' != $tyrone_opts->tyrone_cron_setting )
			wp_die( 'Cron Job Disabled' );
		
		if( 'Yes' == $tyrone_opts->tyrone_alerts_setting ) {
			wp_mail(
				$tyrone_opts->tyrone_admin_email, 
				"Tyrone Starting Cron Job",
				$this->email_prepare( "Tyrone Starting Cron at ".date( get_option( 'date_format' ), time() ) )
			);
		}
			
		$sites = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'site' AND post_status = 'publish' ORDER BY ID DESC" );
		
		foreach( $sites as $id ) {
		
			$site = get_post( $id->ID );
			$this->site_prowl( $site );
		
		}

		if( 'Yes' == $tyrone_opts->tyrone_alerts_setting ) {
			wp_mail(
				$tyrone_opts->tyrone_admin_email, 
				"Tyrone Finished Cron Job",
				$this->email_prepare( "Tyrone Finished Cron at ".date( get_option( 'date_format' ), time() ) )
			);
		}
		
	}	
	
	// Filter content for sites since we're storing HTML snapshots
	function content_filter($content) {
	
		global $post;
		
		if( 'site' == $post->post_type ) {
			
			$snapurl = get_permalink( $post->ID ).'&tyrone-action=snapdump';
			$content = "<p><a href='$snapurl' target='_blank'>Inspect in new window.</a></p><iframe src='$snapurl' style='height:300px; width:600px; overflow: scroll;'></iframe>";
		
		}
		
		return $content;
	
	}
	
	// Tyrone init
	function tyrone_init() {
		
		if ( !wp_next_scheduled( 'tyrone_cron' ) )
			wp_schedule_event( time(), 'hourly', 'tyrone_cron' );
		
		require_once( dirname( __FILE__ ) . '/terms.php' );
		
		$domain = parse_url(get_option('siteurl'));

		global $tyrone_opts;
		$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__, array(
			'tyrone_admin_email' => 'jax@mad-jax.com',
			'tyrone_message_from' => 'Tyrone',
			'tyrone_emailer' => 'tyrone@'.$domain['host'],
			'tyrone_terms' => implode( ',', $watchlist ),
			'tyrone_plugin_watch' => '',
			'tyrone_admin_css' => 'simplify',
			'tyrone_alerts_setting' => 'No',
			'tyrone_juniper_setting' => 'Yes',
			'tyrone_diff_setting' => 'Yes',
			'tyrone_cron_setting' => 'No',
		) );
	
		if ( is_admin() ) {
			require_once( dirname( __FILE__ ) . '/admin.php' );
			new Tyrone_Admin_Page( __FILE__, $tyrone_opts );
		}

	}
	
	// Register query vars
	function query_vars( $vars ) {
		$vars[] = 'tyrone-id';
		$vars[] = 'tyrone-action';
		$vars[] = 'tyrone-import';
		return($vars);
	}
	
	// Template redirect for actions
	function template_redirect() {
		if( 'snapdump' == get_query_var( 'tyrone-action' ) ) {
			global $post;
			if( is_object( $post ) )
				echo( $post->post_content );
			exit;
		} elseif( 'tyronecron' == get_query_var( 'tyrone-action' ) ) {
			$this->do_tyrone_cron();
			exit;
		} else {
			return;
		}	
	}

	// Register the admin pages
	function add_admin_menu() {	
		$this->menu_id = add_submenu_page( 'edit.php?post_type=site', __( 'Run Tyrone Prowl', 'tyrone' ), __( 'Prowl Sites', 'tyrone' ), 'manage_options', 'tyrone-prowl', array(&$this, 'prowl_interface') );
		$this->import_id = add_submenu_page( 'edit.php?post_type=site', __( 'Import Sites', 'tyrone' ), __( 'Import Sites', 'tyrone' ), 'manage_options', 'tyrone-import', array(&$this, 'import_interface') );
	}

	// Enqueue the needed Javascript and CSS
	function admin_enqueues( $hook_suffix ) {
	
		wp_register_style( 'tyrone_wp_admin_css', plugins_url( 'tyrone-the-wp-watchdog/css/tyrone.css' ), false, '0.1' );
   	    wp_enqueue_style( 'tyrone_wp_admin_css' );

		$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__ );
		
		if( 'simplify' == $tyrone_opts->tyrone_admin_css ) {
	        wp_register_style( 'tyrone_wp_simple_css', plugins_url( 'tyrone-the-wp-watchdog/css/tyrone-simplify.css' ), false, '0.1' );
    	    wp_enqueue_style( 'tyrone_wp_simple_css' );
		}
		
		if ( $hook_suffix != $this->menu_id && $hook_suffix != $this->import_id )
			return;

		// WordPress 3.1 vs older version compatibility
		if ( wp_script_is( 'jquery-ui-widget', 'registered' ) )
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'tyrone-the-wp-watchdog/jquery-ui/jquery.ui.progressbar.min.js' ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		else
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'tyrone-the-wp-watchdog/jquery-ui/jquery.ui.progressbar.min.1.7.2.js' ), array( 'jquery-ui-core' ), '1.7.2' );

		wp_enqueue_style( 'jquery-ui-regenthumbs', plugins_url( 'tyrone-the-wp-watchdog/jquery-ui/redmond/jquery-ui-1.7.2.custom.css' ), array(), '1.7.2' );
	}
	
	// Mail options
	function mail_ctype() {
		return('text/html');		
	}
	
	function mail_from() {
		$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__ );
		return $tyrone_opts->tyrone_emailer;
	}
	
	function mail_from_name() {
		$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__ );
		return $tyrone_opts->tyrone_message_from;
	}

	// Tyrone transition function
	function transition( $new_status, $old_status, $post ) {
		
		global $prowl;
				
		if ( 'site' != $post->post_type || 'inherit' == $post->post_type || 'auto-draft' == $post->post_status || true == $prowl )
			return;

		$this->site_prowl( $post );
		
	}
	
	// Tyrone site prowl
	function site_prowl( $post ) {
	
		global $prowl;
	
		if( !is_object( $post ) )
			$post = get_post( $post );
										
		if ( is_object($post) && $post->post_type == 'site' && $post->post_status != 'inherit' ) {
		
			$tyrone_opts = new scbOptions( 'tyrone_options', __FILE__ );
						
			$prowl = true;
			
			$lastcheck = ( get_post_meta( $post->ID, '_tyrone_last_check', true ) > 0 ? get_post_meta( $post->ID, '_tyrone_last_check', true ) : 0 );
				
			$url = get_post_meta( $post->ID, '_tyrone_url', true );
			
			if( !$url ) 
				return;
					
			// Still here? Tyrone, go fetch the site in your Googlebot costume
			$httpargs = array(
				'user-agent' => 'Googlebot/2.1',
				'timeout' => 15
			);
			
			$reqstart = date( 'U' );
			$request = new WP_Http;
			$result = $request->request( $url, $httpargs );
			$totalreq = date( 'U' ) - $reqstart;
			
			if( is_wp_error( $result ) ) {
			
				$firstresult = $result;
			
				// Terrier tenacity, try again for twice as long
				
				$httpargs['timeout'] = 30;
				
				$request = new WP_Http;
				$reqstart = date( 'U' );
				$result = $request->request( $url, $httpargs );
				$totalreq = date( 'U' ) - $reqstart;	
				
				// A real terrier would try again, but twice is good for us
				
				if( is_wp_error( $result ) ) {
					$currstatus = $firstresult->get_error_message()."\r\nI tried twice:".$result->get_error_message();
					$prowlerror = true;
				} else {
					$currstatus = $result['response']['code'];
				}
			
			} else {
			
				$currstatus = $result['response']['code'];
			
			}
			
			update_post_meta( $post->ID, '_tyrone_last_check', $reqstart );
						
			// Status code change check
			
			$laststatus = get_post_meta( $post->ID, '_tyrone_status', true );
			
			update_post_meta( $post->ID, '_tyrone_status', $currstatus );
			
			if( $currstatus != $laststatus && $laststatus != '' )
				$statuschg = true;
			
			if( isset( $prowlerror ) )
				return false;
					
			// WP version check
			if( false === strpos( $result['body'], 'wp-content' ) )			
				update_post_meta( $post->ID, '_tyrone_version',	null );
			else
				update_post_meta( $post->ID, '_tyrone_version',	$this->tyrone_wp_version( $url ) );
			
			// Spam search aka Juniper snake hunt			
			if( 'Yes' == $tyrone_opts->tyrone_juniper_setting ) {

				$terms = ( $tyrone_opts->tyrone_terms ? explode( ',', $tyrone_opts->tyrone_terms ) : null );
				$allowed = ( get_post_meta( $post->ID, '_tyrone_allowed_terms', true ) ? explode( ',', get_post_meta( $post->ID, '_tyrone_allowed_terms', true ) ) : null );
				
				if( is_array( $terms ) ) {
					$spam = $this->juniper_snake_hunt( $result['body'], $terms, $allowed );
					update_post_meta( $post->ID, '_tyrone_juniper_result', $spam );
				} else {
					$spam = false;
				}
			
			} else {
			
				$spam = false;
			
			}
			
			// Strip cache timestamp from body
			$wpscpos = strpos( $result['body'], '<!-- Dynamic page generated' );
			$w3tcpos = strpos( $result['body'], '<!-- Performance optimized by W3 Total Cache' );
			
			if( $wpscpos > 0 )
				$result['body'] = substr( $result['body'], 0, $wpscpos );
			elseif( $w3tcpos > 0 )
				$result['body'] = substr( $result['body'], 0, $w3tcpos );
				
			// Store snapshot as revision
			$snapshot = array();
			$snapshot['ID'] = $post->ID;
			$snapshot['post_content'] = $result['body'];
			$snapshot['post_date'] = current_time( 'mysql' );		
			wp_update_post( $snapshot );
			
			// Look for diffs
			if( 'Yes' == $tyrone_opts->tyrone_diff_setting ) {		
					
				// Something is different
				
				if( $result['body'] != $post->post_content ) {
				
					$diffargs = array(
						'title' => 'Changes Observed '.$url,
						'title_left' => get_the_time( 'l jS \of F Y h:i:s A', $post->ID ),
						'title_right' => date_i18n( 'l jS \of F Y h:i:s A', date( 'U' ) )
					);
								
					$diff = wp_text_diff( $post->post_content, $result['body'], $diffargs );
					
					// Enter comment about observed change
					
					$data = array(
					    'comment_post_ID' => $post->ID,
					    'comment_content' => "I observed this change:\r\n".$diff,
						'comment_author' => 'Tyrone WP Monitor',
						'comment_author_email' => 'tyrone@jacksonwhelan.com',
						'comment_author_url' => 'http://tyrone.jacksonwhelan.com'
					);
					
					wp_insert_comment( $data );
					
					// Send email
					if( 'Yes' == $tyrone_opts->tyrone_alerts_setting ) {
						$to = $tyrone_opts->tyrone_admin_email;
						$subject = __( 'Changes Observed ', 'tyrone' ).$url;	
						$body = $this->email_prepare($diff);
						wp_mail( $to, $subject, $body );		
					}				
				}

			}
						
			if( isset( $statuschg ) && 'Yes' == $tyrone_opts->tyrone_alerts_setting ) {
				wp_mail( 
					$tyrone_opts->tyrone_admin_email, 
					"Status Change [$currstatus] $url",
					$this->email_prepare("New status: $currstatus\r\nOld status: $laststatus\r\nWroof!")
				);
			}
			
			if( ( $spam != false ) && 'Yes' == $tyrone_opts->tyrone_alerts_setting ) {
				wp_mail( 
					$tyrone_opts->tyrone_admin_email, 
					"Spam Alert [$url]",
					$this->email_prepare( $spam )
				);
			}
		
			$prowl = false;
			
			return true;
			
		} else {
			
			return false;
		
		}				
	}
	
	// Wrap email in HTML for easy to read display
	function email_prepare( $content = null ) {
	
$html = <<< EOF
<table width="99%" border="0" cellpadding="1" cellpsacing="0" bgcolor="#EAEAEA"><tr><td>
<table width="100%" border="0" cellpadding="5" cellpsacing="0" bgcolor="#FFFFFF">
<tr bgcolor="#EAF2FA"><td colspan="2"><font style="font-family:arial; font-size:16px;"><strong>Message from Tyrone</strong></font></td></tr>
<tr bgcolor="#FFFFFF"><td width="20">&nbsp;</td><td><font style="font-family:verdana; font-size:12px;">$content</td></tr>
</table>
</td></tr></table>
EOF;

		return( $html );
	
	}
	
	// Determine WP version from readme file
	function tyrone_wp_version( $site ) {		
		
		$site = rtrim($site,'/');
		$readme = wp_remote_get( $site.'/readme.html' );
		
		if( is_wp_error( $readme ) )
			return( $readme->get_error_message() ); 
		
		if( '200' == $readme['response']['code'] ) {
		
			$re1='(Version)';
			$re2='.*?';
			$re3='(\\d+)';
			$re4='(.)';
			$re5='([+-]?\\d*\\.\\d+)(?![-+0-9\\.])';
			
			if ( $c = preg_match_all( "/".$re1.$re2.$re3.$re4.$re5."/is", $readme['body'], $matches ) ) {
				$word1 = $matches[1][0];
				$int1 = $matches[2][0];
				$c1 = $matches[3][0];
				$float1 = $matches[4][0];
				$wp_ver_match = "$int1$c1$float1";
			} else {
				$wp_ver_match = null;
			}
								
			return( $wp_ver_match );
			
		} else {
		
			return( 'Site responded: '.$readme['response']['code'] );
		
		}

	}
	
	// Search content for terms
	function juniper_snake_hunt( $content, $terms, $allowed ) {
	
		$alerts = array();
		
		if( !is_array( $allowed ) )
			$allowed = array();
		
		foreach( $terms as $term ) {
		
			if( !in_array( $term, $allowed ) ) {
			
				$found = stripos( $content, $term );
				
				if( $found ) {
					$alerts[] = $term;
				}
					
			}   		
		}
		
		if( empty( $alerts ) ) 
			return false;
		else
			$results = date('U').'|'.implode( ',', $alerts );
		
		return $results;
			
	}

	// Prowl single site via ajax request
	function ajax_prowl_site() {
	
		@error_reporting( 0 ); // Don't break the JSON result

		header( 'Content-type: application/json' );
		
		$id = (int) $_REQUEST['id'];
		$site = get_post( $id );
		
		global $prowl;
				
		if ( 'site' != $site->post_type || 'inherit' == $site->post_type || 'auto-draft' == $site->post_status || true == $prowl )
			die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) not sniffed out.', 'tyrone' ), esc_html( get_the_title( $site->ID ) ), $site->ID, timer_stop() ) ) ) );

		@set_time_limit( 900 ); // 5 minutes each should be PLENTY

		if(	$this->site_prowl( $site ) )
			die( json_encode( array( 'success' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) was successfully sniffed in %3$s seconds.', 'tyrone' ), esc_html( get_the_title( $site->ID ) ), $site->ID, timer_stop() ) ) ) );
		else
			die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) error.', 'tyrone' ), esc_html( get_the_title( $site->ID ) ), $site->ID, timer_stop() ) ) ) );
			
	}
	
	// Import new single site via ajax request
	function ajax_import_site() {
	
		@error_reporting( 0 ); // Don't break the JSON result

		header( 'Content-type: application/json' );
		
		$title = esc_attr( $_REQUEST['id'] );
		$url = esc_url( $_REQUEST['id'] );
		
		if( preg_match( "/https?:\/\//", $url ) === 0 ) {
		    $urltocheck = 'http://'.$url;
		} else {
			$urltocheck = $url;
		}
		
		if( !filter_var( $urltocheck, FILTER_VALIDATE_URL ) )
			die( json_encode( array( 'error' => __( 'Error importing site - invalid URL', 'tyrone' ) ) ) );
		
		global $user_ID;		
		global $prowl;
		
		$sitein = array();
		
		$sitein['post_type'] = 'site';	
		$sitein['post_parent'] = 0;
		$sitein['post_author'] = $user_ID;
		$sitein['post_status'] = 'publish';
		$sitein['post_title'] = $title;
		
		$sitein['custom'] = array(
			'_tyrone_url' =>  $urltocheck
		);
		
		$site = wp_insert_post( $sitein );
			
		if( $site > 0) {  
			
			foreach($sitein['custom'] as $name => $value) {				
				add_post_meta( $site, $name, stripslashes($value), true );
			}
			
			die( json_encode( array( 'success' => __( 'Site imported successfully sniffed in '.timer_stop(), 'tyrone' ) ) ) );
		
		}
		
		else {
			die( json_encode( array( 'error' => __( 'Error importing site', 'tyrone' ) ) ) );
		}
			
	}

	// Metaboxes for site post type
	function site_meta_boxes() {
		$meta_boxes = array(			
			'ty-url' => array( 'name' => '_tyrone_url', 'title' => 'URL', 'type' => 'text' ),
			'client-email' => array( 'name' => '_tyrone_client_email', 'title' => 'Email to Notify', 'type' => 'text' ),
			'allowed-terms' => array( 'name' => '_tyrone_allowed_terms', 'title' => 'Allowed terms', 'type' => 'text' ),
		);
		return($meta_boxes);
	}
		
	function create_meta_box() {
	    add_meta_box('tyrone', __( 'Site Details', 'tyrone' ), array(&$this,'draw_meta_boxes'), 'site', 'normal', 'high');
	}

	function draw_meta_boxes() {
		global $post;
		if( $post->post_type == 'site' )
			$meta_boxes = $this->site_meta_boxes(); 		
		else
			return;
		?><table class="form-table">
		<?php foreach ( $meta_boxes as $meta ) :
	
			$value = stripslashes( get_post_meta( $post->ID, $meta['name'], true ) );
	
			if ( $meta['type'] == 'text' )
				$this->get_meta_text_input( $meta, $value, plugin_basename( __FILE__ ));
			elseif ( $meta['type'] == 'textarea' )
				$this->get_meta_textarea( $meta, $value, plugin_basename( __FILE__ ));
			elseif ( $meta['type'] == 'select' )
				$this->get_meta_select( $meta, $value, plugin_basename( __FILE__ ));
				
		endforeach; 
		$this->get_status_view(); 
		?></table><?php
	}
	
	function get_meta_text_input( $args = array(), $value = false, $basename ) {
		extract( $args ); 
		?><tr>
			<th>
				<label for="<?php echo $name; ?>"><?php echo $title; ?></label>
			</th>
			<td>
				<input type="text" name="<?php echo $name; ?>" id="<?php echo $name; ?>" value="<?php echo esc_html( $value, 1 ); ?>" size="30" tabindex="30" />
				<?php wp_nonce_field($basename, $name.'_nonce'); ?>
			</td>
		</tr><?php
	}
	
	function get_meta_select( $args = array(), $value = false, $basename ) {
		extract( $args ); 
		?><tr>
			<th>
				<label for="<?php echo $name; ?>"><?php echo $title; ?></label>
			</th>
			<td>
				<select name="<?php echo $name; ?>" id="<?php echo $name; ?>">
				<?php foreach ( $options as $option ) : ?>
					<option <?php if ( htmlentities( $value, ENT_QUOTES ) == $option ) echo ' selected="selected"'; ?>>
						<?php echo $option; ?>
					</option>
				<?php endforeach; ?>
				</select>
				<?php wp_nonce_field($basename, $name.'_nonce'); ?>
			</td>
		</tr><?php
	}

	function get_status_view() {
		global $post;
		?><tr>
			<td>WordPress Version</td><td><?php echo $this->upgrade_link( get_post_meta($post->ID, '_tyrone_version', TRUE), get_post_meta($post->ID, '_tyrone_url', TRUE) ); ?></td>
		</tr><?php
		if( get_post_meta( $post->ID, '_tyrone_last_check', TRUE ) > 0 ) { 
		?><tr>
			<td colspan="2">
				<h4><?php echo __( 'Most Recent Snapshot', 'tyrone' ); ?></h4>
				<?php 				
				$date = ( get_post_meta($post->ID, '_tyrone_last_check', TRUE) > 0 ? get_the_time( 'l jS \of F Y h:i:s A', $post->ID ) : 'Not Checked Yet' );
				echo '<p>Taken '.$date.'</p>';
				echo '<p>Status '.get_post_meta($post->ID, '_tyrone_status', TRUE).'</p>';
				
				if( $spam = explode( '|', get_post_meta( $post->ID, '_tyrone_juniper_result', TRUE ) ) )
					if( !empty( $spam[1] ) )
						echo '<p><strong>Spam</strong>: '.$spam[1].'</p>';
				?>
				<hr />
				<div style="height:300px; width:450px; overflow: scroll;">
					<pre><?php echo( esc_html( $post->post_content ) ) ;?></pre>
				</div>
				<p><a href="<?php echo( get_permalink( $post->ID ) ); ?>?tyrone-action=snapdump" target="_blank">Inspect in new window.</a></p>
				<iframe src="<?php echo( get_permalink( $post->ID ) ); ?>?tyrone-action=snapdump" style="height:300px; width:450px; overflow: scroll;"></iframe>
				
			</td>
		</tr><?php
		}
	}
		
	function save_meta_data($post_id) {
		global $post;
		if( !isset($_POST['post_type']) )
			return($post_id);
		if( 'site' == $_POST['post_type'] )
			$meta_boxes = array_merge( $this->site_meta_boxes() );			
		else
			return $post_id;
					
		foreach ( $meta_boxes as $meta_box ) :
	
			if ( !wp_verify_nonce( $_POST[$meta_box['name'].'_nonce'], plugin_basename( __FILE__ ) ) )
				return $post_id;
	
			if ( 'page' == $_POST['post_type'] && !current_user_can( 'edit_page', $post_id ) )
				return $post_id;
	
			elseif ( 'post' == $_POST['post_type'] && !current_user_can( 'edit_post', $post_id ) )
				return $post_id;
	
			$data = stripslashes( $_POST[$meta_box['name']] );
	
			if ( get_post_meta( $post_id, $meta_box['name'] ) == '' )
				add_post_meta( $post_id, $meta_box['name'], $data, true );
	
			elseif ( $data != get_post_meta( $post_id, $meta_box['name'], true ) )
				update_post_meta( $post_id, $meta_box['name'], $data );
	
			elseif ( $data == '' )
				delete_post_meta( $post_id, $meta_box['name'], get_post_meta( $post_id, $meta_box['name'], true ) );
	
		endforeach;
	}
	
	function create_post_types() {
		 $sitelabels = array(
	    'name' => _x('Sites', 'post type general name'),
	    'singular_name' => _x('Site', 'post type singular name'),
	    'add_new' => _x('Add New', 'site'),
	    'add_new_item' => __('Add New Site'),
	    'edit_item' => __('Edit Site'),
	    'new_item' => __('New Site'),
	    'view_item' => __('View Site'),
	    'search_items' => __('Search Sites'),
	    'not_found' =>  __('No sites found'),
	    'not_found_in_trash' => __('No sites found in Trash'), 
	    'parent_item_colon' => '');
	
		register_post_type( 'site', 
			array(
				'labels' => $sitelabels,
				'public' => true,
				'menu_position' => 5,
				'show_ui' => true,
				'menu_icon' => plugins_url('/tyrone-the-wp-watchdog/images/server.png'),
				'capability_type' => 'post',
				'hierarchical' => false,
			    '_builtin' => false,
				'rewrite' => array('slug' => 'sites'),
				'query_var' => true,
				'supports' => array(
					'title',
					'author',
					'comments',
					'thumbnail',
					'revisions'
				),
				'has_archive' => true,				
			) 
		);
		
	}
	
	function sites_columns( $columns ) {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'title' => 'Name',
			'url' => 'URL',
			'tyrone_status' => 'Status',
			'tyrone_version' => 'Version',
			'tyrone_juniper' => 'Spam Watch',
			'last_check' => 'Last Check'
		);
		return $columns;
	}
	
	function custom_columns( $column ) {
		global $post;
		$date = ( get_post_meta($post->ID, '_tyrone_last_check', TRUE) > 0 ? get_the_time( get_option( 'date_format' ).' @ '.get_option( 'time_format' ), $post->ID ) : 'Not Checked Yet' );
		if ("last_check" == $column) echo $date;
		elseif ("url" == $column) echo get_post_meta($post->ID, '_tyrone_url', TRUE );
		elseif ("tyrone_status" == $column) echo '<span class="status'.get_post_meta( $post->ID, '_tyrone_status', TRUE ).'">'.get_post_meta( $post->ID, '_tyrone_status', TRUE ).'</span>';
		elseif ("tyrone_version" == $column) echo $this->upgrade_link( get_post_meta( $post->ID, '_tyrone_version', TRUE ), get_post_meta($post->ID, '_tyrone_url', TRUE ) );
		elseif ("tyrone_juniper" == $column) {
			if( $spam = explode( '|', get_post_meta( $post->ID, '_tyrone_juniper_result', TRUE ) ) )
				if( !empty( $spam[1] ) )
					echo $spam[1];
		}
	}
	
	function upgrade_link( $version, $site ) {
		$upgradelink = "<a href='$site/wp-admin/update-core.php' class='up-req' target='_blank'>$version</a>";
		$current = "<span class='wp-current'>$version</span>";
		$wp_version = ( $version != TYRONE_WP_VERSION ? $upgradelink : $current );
		return $wp_version;
	}
	
	function sites_sort($columns) {
		$custom = array(
			'tyrone_status' => 'tyrone_status',
			'tyrone_version' => 'tyrone_version',
			'tyrone_juniper' => 'tyrone_juniper',
		);
		return wp_parse_args($custom, $columns);
	}
	
	function sites_orderby( $vars ) {
		if ( isset( $vars['orderby'] ) && 'tyrone_status' == $vars['orderby'] ) {
			$vars = array_merge( $vars, array(
				'meta_key' => '_tyrone_status',
				'orderby' => 'meta_value'
			) );
		} elseif ( isset( $vars['orderby'] ) && 'tyrone_version' == $vars['orderby'] ) {
			$vars = array_merge( $vars, array(
				'meta_key' => '_tyrone_version',
				'orderby' => 'meta_value'
			) );
		} elseif ( isset( $vars['orderby'] ) && 'tyrone_juniper' == $vars['orderby'] ) {
			$vars = array_merge( $vars, array(
				'meta_key' => '_tyrone_juniper_result',
				'orderby' => 'meta_value'
			) );
		}
		return $vars;
	}

	// Prowling interface
	// Thanks to Viper007Bond and Regenerate Thumbnails for this ajax wonder
	function prowl_interface() {
		global $wpdb; 
		?>		
		<div id="message" class="updated fade" style="display:none"></div>
			<div class="wrap regenthumbs">
				<h2><?php _e('Prowl Sites', 'tyrone'); ?></h2>
		<?php
		// If the button was clicked
		if ( ! empty( $_POST['tyrone'] ) || ! empty( $_REQUEST['ids'] ) ) {
			if ( !current_user_can( 'manage_options' ) )
				wp_die( __( 'Cheatin&#8217; uh?' ) );

			// Form nonce check
			check_admin_referer( 'tyrone' );

			// Create the list of site IDs
			if ( ! empty( $_REQUEST['ids'] ) ) {
				$sites = array_map( 'intval', explode( ',', trim( $_REQUEST['ids'], ',' ) ) );
				$ids = implode( ',', $sites );
			} else {
				if ( ! $sites = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'site' AND post_status = 'publish' ORDER BY ID DESC" ) ) {
					echo '	<p>' . sprintf( __( "Unable to find any sites. Are you sure <a href='%s'>some exist</a>?", 'tyrone' ), admin_url( 'edit.php?post_type=site' ) ) . "</p></div>";
					return;
				}

				// Generate the list of IDs
				$ids = array();
				foreach ( $sites as $site )
					$ids[] = $site->ID;
				$ids = implode( ',', $ids );
			}

			echo '	<p>' . __( "Please be patient while Tyrone sniffs out your sites. This can take a while if your server is slow (inexpensive hosting) or if you have many sites. Do not navigate away from this page until this script is done. You will be notified via this page when Tyrone returns from his prowl.", 'tyrone' ) . '</p>';

			$count = count( $sites );

			$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'tyrone' ), 'javascript:history.go(-1)' ) : '';
			$text_failures = sprintf( __( 'All done! %1$s site(s) were successfully sniffed out in %2$s seconds and there were %3$s failure(s). To try the failed sites again, <a href="%4$s">click here</a>. %5$s', 'tyrone' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'tools.php?page=tyrone-prowl&goback=1' ), 'tyrone' ) . '&ids=' ) . "' + rt_failedlist + '", $text_goback );
			$text_nofailures = sprintf( __( 'All done! %1$s site(s) were successfully sniffed out in %2$s seconds and there were 0 failures. %3$s', 'tyrone' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>
			<noscript><p><em><?php _e( 'Tyrone pities the fool who must enable Javascript in order to proceed!', 'tyrone' ) ?></em></p></noscript>

			<div id="regenthumbs-bar" style="position:relative;height:25px;">
				<div id="regenthumbs-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
			</div>
		
			<p><input type="button" class="button hide-if-no-js" name="regenthumbs-stop" id="regenthumbs-stop" value="<?php _e( 'Stop Prowl', 'tyrone' ) ?>" /></p>
		
			<h3 class="title"><?php _e( 'Debugging Information', 'tyrone' ) ?></h3>
		
			<p>
				<?php printf( __( 'Total Sites: %s', 'tyrone' ), $count ); ?><br />
				<?php printf( __( 'Sites Sniffed: %s', 'tyrone' ), '<span id="regenthumbs-debug-successcount">0</span>' ); ?><br />
				<?php printf( __( 'Site Failures: %s', 'tyrone' ), '<span id="regenthumbs-debug-failurecount">0</span>' ); ?>
			</p>
		
			<ol id="regenthumbs-debuglist">
				<li style="display:none"></li>
			</ol>
		
			<script type="text/javascript">
			// <![CDATA[
				jQuery(document).ready(function($){
					var i;
					var rt_images = [<?php echo $ids; ?>];
					var rt_total = rt_images.length;
					var rt_count = 1;
					var rt_percent = 0;
					var rt_successes = 0;
					var rt_errors = 0;
					var rt_failedlist = '';
					var rt_resulttext = '';
					var rt_timestart = new Date().getTime();
					var rt_timeend = 0;
					var rt_totaltime = 0;
					var rt_continue = true;
		
					// Create the progress bar
					$("#regenthumbs-bar").progressbar();
					$("#regenthumbs-bar-percent").html( "0%" );
		
					// Stop button
					$("#regenthumbs-stop").click(function() {
						rt_continue = false;
						$('#regenthumbs-stop').val("<?php echo esc_js( __( 'Stopping...', 'tyrone' ) ); ?>");
					});
		
					// Clear out the empty list element that's there for HTML validation purposes
					$("#regenthumbs-debuglist li").remove();
		
					// Called after each resize. Updates debug information and the progress bar.
					function RegenThumbsUpdateStatus( id, success, response ) {
						$("#regenthumbs-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
						$("#regenthumbs-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
						rt_count = rt_count + 1;
		
						if ( success ) {
							rt_successes = rt_successes + 1;
							$("#regenthumbs-debug-successcount").html(rt_successes);
							$("#regenthumbs-debuglist").append("<li>" + response.success + "</li>");
						}
						else {
							rt_errors = rt_errors + 1;
							rt_failedlist = rt_failedlist + ',' + id;
							$("#regenthumbs-debug-failurecount").html(rt_errors);
							$("#regenthumbs-debuglist").append("<li>" + response.error + "</li>");
						}
					}
		
					// Called when all images have been processed. Shows the results and cleans up.
					function RegenThumbsFinishUp() {
						rt_timeend = new Date().getTime();
						rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );
		
						$('#regenthumbs-stop').hide();
		
						if ( rt_errors > 0 ) {
							rt_resulttext = '<?php echo $text_failures; ?>';
						} else {
							rt_resulttext = '<?php echo $text_nofailures; ?>';
						}
		
						$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
						$("#message").show();
					}
		
					// Regenerate a specified image via AJAX
					function RegenThumbs( id ) {
						$.ajax({
							type: 'POST',
							url: ajaxurl,
							data: { action: "tyroneprowl", id: id },
							success: function( response ) {
								if ( response.success ) {
									RegenThumbsUpdateStatus( id, true, response );
								}
								else {
									RegenThumbsUpdateStatus( id, false, response );
								}
		
								if ( rt_images.length && rt_continue ) {
									RegenThumbs( rt_images.shift() );
								}
								else {
									RegenThumbsFinishUp();
								}
							},
							error: function( response ) {
								RegenThumbsUpdateStatus( id, false, response );
		
								if ( rt_images.length && rt_continue ) {
									RegenThumbs( rt_images.shift() );
								} 
								else {
									RegenThumbsFinishUp();
								}
							}
						});
					}
		
					RegenThumbs( rt_images.shift() );
				});
			// ]]>
			</script>
		<?php
		}
		// No button click? Display the form.
		else {
		?>
			<form method="post" action="">
			<?php wp_nonce_field('tyrone') ?>
		
			<p><?php _e( 'To begin, just press the button below.', 'tyrone '); ?></p>
		
			<p><input type="submit" class="button hide-if-no-js" name="tyrone" id="tyrone" value="<?php _e( 'Prowl All Sites', 'tyrone' ) ?>" /></p>
		
			<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'tyrone' ) ?></em></p></noscript>
		
			</form>
		<?php
				} // End if button
		?>
		</div>

	<?php }

	// Import interface
	function import_interface() {
		global $wpdb; 
		
		?>
		
			<div id="message" class="updated fade" style="display:none"></div>
				<div class="wrap regenthumbs">
					<h2><?php _e( 'Import Sites to Tyrone', 'tyrone' ); ?></h2>
		<?php
		
		if( isset( $_POST['tyrone-site-import'] ) ) {
			$import = $_POST['tyrone-site-import'];
			$sitearray = preg_split( '/\r\n|\r|\n/', $import );		
			echo( '<pre>'.$import.'</pre>' );
		}
			
		// If the button was clicked
		if ( ! empty( $_POST['tyrone'] ) || ! empty( $_REQUEST['ids'] ) ) {
			// Capability check
			if ( !current_user_can( 'manage_options' ) )
				wp_die( __( 'Cheatin&#8217; uh?' ) );

			// Form nonce check
			check_admin_referer( 'tyrone' );

			// Create the list of image IDs

			$ids = '"'.implode( '","', $sitearray ).'"';

			echo '	<p>' . __( "Please be patient while Tyrone sniffs out your sites. This can take a while if your server is slow (inexpensive hosting) or if you have many sites. Do not navigate away from this page until this script is done. You will be notified via this page when Tyrone returns from his prowl.", 'tyrone' ) . '</p>';

			$count = count( $import );

			$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'tyrone' ), 'javascript:history.go(-1)' ) : '';
			$text_failures = sprintf( __( 'All done! %1$s site(s) were successfully sniffed out in %2$s seconds and there were %3$s failure(s). To try the failed sites again, <a href="%4$s">click here</a>. %5$s', 'tyrone' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'tools.php?page=tyrone-prowl&goback=1' ), 'tyrone' ) . '&ids=' ) . "' + rt_failedlist + '", $text_goback );
			$text_nofailures = sprintf( __( 'All done! %1$s site(s) were successfully sniffed out in %2$s seconds and there were 0 failures. %3$s', 'tyrone' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>
			<noscript><p><em><?php _e( 'Tyrone pities the fool who must enable Javascript in order to proceed!', 'tyrone' ) ?></em></p></noscript>

			<div id="regenthumbs-bar" style="position:relative;height:25px;">
				<div id="regenthumbs-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
			</div>
		
			<p><input type="button" class="button hide-if-no-js" name="regenthumbs-stop" id="regenthumbs-stop" value="<?php _e( 'Stop Prowl', 'tyrone' ) ?>" /></p>
		
			<h3 class="title"><?php _e( 'Debugging Information', 'tyrone' ) ?></h3>
		
			<p>
				<?php printf( __( 'Total Sites: %s', 'tyrone' ), $count ); ?><br />
				<?php printf( __( 'Sites Sniffed: %s', 'tyrone' ), '<span id="regenthumbs-debug-successcount">0</span>' ); ?><br />
				<?php printf( __( 'Site Failures: %s', 'tyrone' ), '<span id="regenthumbs-debug-failurecount">0</span>' ); ?>
			</p>
		
			<ol id="regenthumbs-debuglist">
				<li style="display:none"></li>
			</ol>
		
			<script type="text/javascript">
			// <![CDATA[
				jQuery(document).ready(function($){
					var i;
					var rt_images = [<?php echo $ids ; ?>];
					var rt_total = rt_images.length;
					var rt_count = 1;
					var rt_percent = 0;
					var rt_successes = 0;
					var rt_errors = 0;
					var rt_failedlist = '';
					var rt_resulttext = '';
					var rt_timestart = new Date().getTime();
					var rt_timeend = 0;
					var rt_totaltime = 0;
					var rt_continue = true;
		
					// Create the progress bar
					$("#regenthumbs-bar").progressbar();
					$("#regenthumbs-bar-percent").html( "0%" );
		
					// Stop button
					$("#regenthumbs-stop").click(function() {
						rt_continue = false;
						$('#regenthumbs-stop').val("<?php echo esc_js( __( 'Stopping...', 'tyrone' ) ); ?>");
					});
		
					// Clear out the empty list element that's there for HTML validation purposes
					$("#regenthumbs-debuglist li").remove();
		
					// Called after each resize. Updates debug information and the progress bar.
					function RegenThumbsUpdateStatus( id, success, response ) {
						$("#regenthumbs-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
						$("#regenthumbs-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
						rt_count = rt_count + 1;
		
						if ( success ) {
							rt_successes = rt_successes + 1;
							$("#regenthumbs-debug-successcount").html(rt_successes);
							$("#regenthumbs-debuglist").append("<li>" + response.success + "</li>");
						}
						else {
							rt_errors = rt_errors + 1;
							rt_failedlist = rt_failedlist + ',' + id;
							$("#regenthumbs-debug-failurecount").html(rt_errors);
							$("#regenthumbs-debuglist").append("<li>" + response.error + "</li>");
						}
					}
		
					// Called when all images have been processed. Shows the results and cleans up.
					function RegenThumbsFinishUp() {
						rt_timeend = new Date().getTime();
						rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );
		
						$('#regenthumbs-stop').hide();
		
						if ( rt_errors > 0 ) {
							rt_resulttext = '<?php echo $text_failures; ?>';
						} else {
							rt_resulttext = '<?php echo $text_nofailures; ?>';
						}
		
						$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
						$("#message").show();
					}
		
					// Regenerate a specified image via AJAX
					function RegenThumbs( id ) {
						$.ajax({
							type: 'POST',
							url: ajaxurl,
							data: { action: "tyroneimport", id: id },
							success: function( response ) {
								if ( response.success ) {
									RegenThumbsUpdateStatus( id, true, response );
								}
								else {
									RegenThumbsUpdateStatus( id, false, response );
								}
		
								if ( rt_images.length && rt_continue ) {
									RegenThumbs( rt_images.shift() );
								}
								else {
									RegenThumbsFinishUp();
								}
							},
							error: function( response ) {
								RegenThumbsUpdateStatus( id, false, response );
		
								if ( rt_images.length && rt_continue ) {
									RegenThumbs( rt_images.shift() );
								} 
								else {
									RegenThumbsFinishUp();
								}
							}
						});
					}
		
					RegenThumbs( rt_images.shift() );
				});
			// ]]>
			</script>
		<?php
		}
		// No button click? Display the form.
		else {
		?>
			<form method="post" action="">
			
			<?php wp_nonce_field('tyrone') ?>
		
			<p><?php _e( 'To begin, just enter one site per line, and press the button below.', 'tyrone '); ?></p>
			
			<p><textarea name="tyrone-site-import" cols="100" rows="25"></textarea></p>
		
			<p><input type="submit" class="button hide-if-no-js" name="tyrone" id="tyrone" value="<?php _e( 'Import All Sites', 'tyrone' ) ?>" /></p>
		
			<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'tyrone' ) ?></em></p></noscript>
		
			</form>
		<?php
				} // End if button
		?>
		</div>

	<?php }	
	
}

register_activation_hook( __FILE__, array( 'Tyrone', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Tyrone', 'uninstall' ) );

?>