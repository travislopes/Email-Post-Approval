<?php

	/*
	Plugin Name: Email Post Approval
	Description: Ability to review and approve posts for publishing via email.
	Version: 1.2.1
	Author: BinaryM Inc - Travis Lopes & Matt McInvale
	Author URI: http://binarym.com/
	License: GPL2
	*/
	
	require_once('email-post-approval_options.php');
		
	class Email_Post_Approval {
		
		var $post_status_types;
		var $email_fields;
	
		// Initialize plugin	
		public function __construct() {
			add_action('save_post', array($this, 'send_email'));
		}
		
		// Fire off function when plugin is activated
		public function activation() {
			add_option('epa_send_to', get_bloginfo('admin_email'));
			add_option('epa_post_statuses', array('pending'));
			add_option('epa_email_fields', array('title', 'post_author', 'post_date', 'categories', 'tags', 'post_meta', 'body'));
		}		
		
		// Fire off function when plugin is deactivated
		public function deactivation() {
			delete_option('epa_send_to');
			delete_option('epa_post_statuses');
			delete_option('epa_email_fields');
		}
		
		// Generate hash for the post		
		private function generate_hash($post_ID){
			// Check to see if there is already a hash
			$existing_hash = get_post_meta($post_ID, '_epa-approve_key', true);
			if($existing_hash) {
				return $existing_hash;
			}
			
			// Otherwise, generate and return a hash
			$hash = sha1($post_ID*time());
			add_post_meta($post_ID, '_epa-approve_key', $hash);
			return $hash;
		}
		
		// Fire off function if a post is saved
		public function send_email($post_ID){
			$post_data = get_page($post_ID);
			
			// If post is saved via autosave, post is a revision or if it is not in the designated list of post status types, stop running.
			if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || $post_data->post_type=="revision" || ((array_search($post_data->post_status, get_option('epa_post_statuses')) == FALSE) && (array_search($post_data->post_status, get_option('epa_post_statuses')) !== 0))) { return false; }
			
			
			// Get the needed information
			$post_taxonomies = get_the_taxonomies($post_ID);
			$post_author_email = get_the_author_meta('user_email', $post_data->post_author);
			$post_hash = self::generate_hash($post_ID);
			$post_meta = get_post_meta($post_ID);

			// Clean up the taxonomies print out for the email message
			if (isset($post_taxonomies['post_tag'])) $post_taxonomies['post_tag'] = str_replace('Tags: ', '', $post_taxonomies['post_tag']);
			else $post_taxonomies['post_tag'] = '';
			
			if (isset($post_taxonomies['category'])) $post_taxonomies['category'] = str_replace('Categories: ', '', $post_taxonomies['category']);
			else $post_taxonomies['category'] = '';
			
			// Remove hidden keys from post meta and then print out
			foreach($post_meta as $key => $value) {
				if(strpos($key, '_')=="0")
					unset($post_meta[$key]);
			}
			
			$message = '';
			
			// Generate email message			
			if(in_array('title', get_option('epa_email_fields'))!==FALSE):
				$message .= '<b>Title:</b> '. $post_data->post_title .'<br />';
			endif;
			if(in_array('post_author', get_option('epa_email_fields'))!==FALSE):
				$message .= '<b>Author:</b> '. get_the_author_meta('display_name', $post_data->post_author) .'<br />';
			endif;
			if(in_array('post_date', get_option('epa_email_fields'))!==FALSE):
				$message .= '<b>Post Date:</b> '. $post_data->post_date .'<br />';
			endif;
			if(in_array('categories', get_option('epa_email_fields'))!==FALSE):
				$message .= '<b>Categories:</b> '.$post_taxonomies['category'] .'<br />';
			endif;
			if(in_array('tags', get_option('epa_email_fields'))!==FALSE):
				$message .= '<b>Tags:</b> '. $post_taxonomies['post_tag'] .'<br />';
			endif;
			if(in_array('post_meta', get_option('epa_email_fields'))!==FALSE):
				$message .= '<br /><b>Post Meta: </b><br />';
				foreach($post_meta as $key => $value) {
					$message .= $key .': '. $value[0] .'<br />';	
				}
			endif;
			if(in_array('body', get_option('epa_email_fields'))!==FALSE):
				$message .= '<br /><b>Post Body:</b><br />'. str_replace('<!--more-->', '&lt;!--more--&gt;', $post_data->post_content) .'<br />';
			endif;
			
			$message .= '<br />----------------------------------------------------<br />';
			$message .= '<a href="'. get_bloginfo('url') .'/?approve_post=true&approve_key='. $post_hash .'&default_author=false">Approve as '. get_the_author_meta('display_name', $post_data->post_author) .'</a> or <a href="'. get_bloginfo('url') .'/?approve_post=true&approve_key='. $post_hash .'&default_author=true">Approve as '. get_the_author_meta('display_name', get_option('epa_default_author')) .'</a><br />This email generated by the Email Post Approval plugin.';
			
			
			// Change From to site's name & admin email, author's email as the Reply-To email, set HTML header and send email.
			$headers[] = 'From: "'.get_bloginfo('name').'" <'. get_bloginfo('admin_email') .'>';
			$headers[] = 'Reply-To: '. $post_author_email;
			
			add_filter('wp_mail_content_type', create_function('', 'return "text/html";'));
			wp_mail(get_option('epa_send_to'), 'Post Needing Approval: '. $post_data->post_title, $message, $headers);
		}
	}
	
	$email_post_approval = new Email_Post_Approval;
	register_activation_hook(__FILE__, array('Email_Post_Approval', 'activation'));
	register_deactivation_hook(__FILE__, array('Email_Post_Approval', 'deactivation'));
	
	add_action('init', 'epa_approve_post', 0);
	function epa_approve_post() {
		// If URL includes "approve_post" argument, check the key and approve post if key exists.
		if(isset($_GET['approve_post'])){
			$get_post_to_approve = get_posts('posts_per_page=1&post_status=any&meta_key=_epa-approve_key&meta_value='. $_GET['approve_key']);
			$change_author = $_GET['default_author'];
					
			// If key exists, publish post, delete key, and redirect to published post.
			if($get_post_to_approve){
				$the_post = get_post($get_post_to_approve[0]->ID, 'ARRAY_A');
				$the_post['post_status'] = 'future';
				if($change_author==="true"){ $the_post['post_author'] = get_option('epa_default_author'); }
				wp_update_post($the_post);
				delete_post_meta($get_post_to_approve[0]->ID, '_epa-approve_key');
				wp_redirect(get_permalink($get_post_to_approve[0]->ID), 301);
				exit;
			// If key doesn't exist, display an alert saying post is not found.
			} else {
				if (!defined('MULTISITE')) echo '<script>alert(\'The post you are attempting to approve is not found.\');</script>';
			}
		}
	}