<?php
/**
Plugin Name: Email Post Approval
Description: Review and approve posts for publishing right from your inbox
Version: 2.0
Author: <a href="http://travislop.es">Travis Lopes</a> and <a href="http://binarym.com">Matt McInvale</a>
Author URI: http://binarym.com/
License: GPL2
 */

class Email_Post_Approval {

	/**
	 * Instance of Share Drafts Publicly class
	 *
	 * @var    object
	 * @access private
	 * @static
	 */
	private static $_instance = null;

	/**
	 * Get instance of this class.
	 *
	 * @access public
	 * @static
	 * @return $_instance
	 */
	public static function get_instance() {

		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		return self::$_instance;

	}

	/**
	 * Register needed hooks.
	 *
	 * @since  1.0
	 * @access public
	 */
	public function __construct() {

		register_activation_hook( __FILE__, array( 'Email_Post_Approval', 'activation' ) );
		register_deactivation_hook( __FILE__, array( 'Email_Post_Approval', 'deactivation' ) );

		add_action( 'save_post', array( $this, 'send_email' ) );
		add_action( 'init', array( $this, 'approve_post' ), 1 );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );

	}





	// # APPROVAL EMAIL ------------------------------------------------------------------------------------------------

	/**
	 * Send post approval email.
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param int $post_id Post ID to send approval email for.
	 *
	 * @uses Email_Post_Approval::generate_hash()
	 */
	public function send_email( $post_id = 0 ) {

		// If this is an auto-save post, exit.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Get post.
		$post = get_post( $post_id );

		// If post could not be found, exit.
		if ( ! $post ) {
			return;
		}

		// If this is a revision post, exit.
		if ( 'revision' === $post->post_type ) {
			return;
		}

		// Get post statuses that require approval.
		$approval_statuses = get_option( 'epa_post_statuses' );

		// If post is not a status that requires approval, exit.
		if ( ! in_array( $post->post_status, $approval_statuses ) ) {
			return;
		}

		// Get default author, post author, meta and taxonomies.
		$default_author  = get_userdata( get_option( 'epa_default_author' ) );
		$post_author     = get_userdata( $post->post_author );
		$post_meta       = get_post_meta( $post->ID );
		$post_taxonomies = get_the_taxonomies( $post->ID, array( 'template' => '%l' ) );

		// Get post fields to display.
		$fields_to_display = get_option( 'epa_email_fields' );

		// Initialize message.
		$message = '';

		// Loop through email fields and generate message.
		foreach ( $fields_to_display as $field ) {

			switch ( $field ) {

				case 'body':
					$message .= '<br/><strong>Post Body:</strong><br />';
					$message .= str_replace( '<!--more-->', '&lt;!--more--&gt;', $post->post_content );
					$message .= '<br /><br />';
					break;

				case 'categories':
					$message .= '<strong>Categories:</strong> ';
					$message .= isset( $post_taxonomies['category'] ) ? $post_taxonomies['category'] : 'None';
					$message .= '<br />';
					break;

				case 'post_author':
					$message    .= '<strong>Author:</strong> ' . esc_html( $post_author->display_name ) . '<br />';
					break;

				case 'post_date':
					$message .= '<strong>Post Date:</strong> ' . esc_html( $post->post_date ) . '<br />';
					break;

				case 'post_meta':

					$message .= '<br /><strong>Post Meta:</strong><br />';

					foreach ( $post_meta as $key => $value ) {

						// If this is a hidden meta key, skip it.
						if ( 0 === strpos( $key, '_' ) ) {
							continue;
						}

						// Prepare meta value.
						if ( is_array( $value ) ) {
							$value = implode( ', ', $value );
						}

						// Display meta value.
						$message .= esc_html( $key ) . ': ' . esc_html( $value ) . '<br />';

					}

					break;

				case 'tags':
					$message .= '<strong>Tags:</strong> ';
					$message .= isset( $post_taxonomies['post_tag'] ) ? $post_taxonomies['post_tag'] : 'None';
					$message .= '<br />';
					break;

				case 'thumbnail':
					$message .= '<strong>Featured Image:</strong><br />' . get_the_post_thumbnail( $post->ID, 'medium' ) . '<br /><br />';
					break;

				case 'title':
					$message .= '<strong>Title:</strong> ' . esc_html( $post->post_title ) . '<br />';
					break;

			}

		}

		// Add footer divider.
		$message .= '<br />----------------------------------------------------<br />';

		// Prepare base approval URL.
		$approval_url = add_query_arg(
			array(
				'approve_post' => true,
				'approve_key'  => $this->generate_hash( $post->ID ),
			),
			get_bloginfo( 'url' )
		);

		// Prepare default author link.
		$default_author_link = '';
		if ( $default_author ) {
			$default_author_link = sprintf(
				esc_html__( ' or %sapprove as %s%s', 'email-post-approval' ),
				'<a href="' . esc_url( add_query_arg( array( 'default_author' => 'true' ), $approval_url ) ) . '">',
				esc_html( $default_author->display_name ),
				'</a>'
			);
		}

		// Add approval links.
		$message .= sprintf(
			esc_html__( '%sApprove as %s%s%s.', 'email-post-approval' ),
			'<a href="' . esc_url( $approval_url ) . '">',
			esc_html( $post_author->display_name ),
			'</a>',
			$default_author_link
		);

		// Add disclaimer.
		$message .= '<br />' . esc_html__( 'This email was generated by the Email Post Approval plugin.', 'email-post-approval' );

		// Prepare email headers.
		$email_headers = array(
			'From: "' . get_bloginfo( 'name' ) . '" <' . get_bloginfo( 'admin_email' ) . '>',
			'Reply-To: ' . $post_author->user_email,
		);

		// Prepare email subject.
		$email_subject = 'Post Needing Approval: ' . esc_html( $post->post_title );

		// Prepare email to.
		$email_to = get_option( 'epa_send_to' );
		$email_to = explode( ',', $email_to );
		$email_to = array_map( 'sanitize_email', $email_to );

		// Set email content type.
		add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );

		// Send email.
		wp_mail( $email_to, $email_subject, $message, $email_headers );

	}





	// # POST APPROVAL -------------------------------------------------------------------------------------------------

	/**
	 * Approve post for publishing.
	 *
	 * @since  2.0
	 * @access public
	 */
	public function approve_post() {

		// If "approve_post" argument is not defined, return.
		if ( ! isset( $_GET['approve_post'] ) ) {
			return;
		}

		// Get approval key.
		$approval_key = isset( $_GET['approve_key'] ) ? sanitize_text_field( $_GET['approve_key'] ) : false;

		// If no approval key was provided, exit.
		if ( ! $approval_key ) {
			wp_die( esc_html__( 'You must provide a post approval key.', 'email-post-approval' ) );
		}

		// Get default author state.
		$default_author = isset( $_GET['default_author'] ) && 'true' == $_GET['default_author'];

		// Get post to approve.
		$post = get_posts(
			array(
				'meta_key'       => '_epa-approve_key',
				'meta_value'     => $approval_key,
				'posts_per_page' => 1,
				'post_status'    => 'any',
			)
		);

		// If post was not found, exit.
		if ( ! $post || empty( $post ) ) {
			wp_die( esc_html__( 'The post you are attempting to approve could not be found.', 'email-post-approval' ) );
		}

		// Reset post.
		$post = $post[0];

		// Set post status to future.
		$post->post_status = 'future';

		// Change post author to default author.
		if ( $default_author ) {

			// Get default author.
			$author = get_option( 'epa_default_author' );

			// Set post author.
			if ( $author ) {
				$post->post_author = sanitize_text_field( $author );
			}

		}

		// Save post.
		wp_update_post( $post );

		// Remove approval key.
		delete_post_meta( $post->ID, '_epa-approve_key' );

		// Redirect to post.
		wp_redirect( get_permalink( $post->ID ) );
		exit();

	}





	// # SETTINGS ------------------------------------------------------------------------------------------------------

	/**
	 * Register Email Post Approval settings page.
	 *
	 * @since  2.0
	 * @access public
	 */
	public function register_settings_page() {

		add_options_page( esc_html__( 'Email Post Approval Settings', 'email-post-approval' ), esc_html__( 'Email Post Approval', 'email-post-approval' ), 'manage_options', 'email-post-approval', array( $this, 'settings_page' ) );

	}

	/**
	 * Display Email Post Approval settings page.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @uses Email_Post_Approval::get_email_fields()
	 * @uses Email_Post_Approval::get_post_stati()
	 * @uses Email_Post_Approval::maybe_save_settings()
	 */
	public function settings_page() {

		// Open page.
		printf(
			'<div class="wrap"><h2>%s</h2>',
			esc_html__( 'Email Post Approval Settings', 'email-post-approval' )
		);

		// Save and get settings.
		$settings = $this->maybe_save_settings();

		// Get users.
		$users = get_users(
			array(
				'fields'  => array( 'ID', 'display_name' ),
				'orderby' => 'display_name',
			)
		);

		// Display settings.

	?>

		<form method="POST">

			<?php wp_nonce_field( 'email-post-approval-settings' ); ?>

			<table class="form-table">

				<tbody>

					<tr>
						<th scope="row"><label for="epa_send_to"><?php esc_html_e( 'Send Post Approval Email To:', 'email-post-approval' ); ?></label></th>
						<td><input class="regular-text" name="send_to" id="send_to" value="<?php echo esc_attr( $settings['send_to'] ); ?>" />
					</tr>

					<tr>
						<th scope="row"><label for="epa_default_author"><?php esc_html_e( 'Default Author For Approved Posts:', 'email-post-approval' ); ?></label></th>
						<td>
							<select name="default_author" id="epa_default_author">
								<option value="0"><?php esc_html_e( 'Use Post Author', 'email-post-approval' ); ?></option>
								<?php
									foreach ( $users as $user ) {
										echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $settings['default_author'], $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
									}
								?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label><?php esc_html_e( 'Send Email When Post Status Is:', 'email-post-approval' ); ?></label></th>
						<td>
							<fieldset>
								<?php

									// Loop through post stati.
									foreach ( $this->get_post_stati() as $post_status ) {

										// Get checked state.
										$checked = in_array( $post_status->name, $settings['post_statuses'] ) ? ' checked="checked"' : '';

										// Display field.
										echo '<label><input name="post_statuses[]" type="checkbox" value="' . esc_attr( $post_status->name ) . '"' . $checked . '>' . esc_html( $post_status->label ) . '</label><br />';

									}

								?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><label><?php esc_html_e( 'Fields To Include In Email:', 'email-post-approval' ); ?></label></th>
						<td>
							<fieldset>
								<?php

									// Loop through email fields.
									foreach ( $this->get_email_fields() as $email_field => $label ) {

										// Get checked state.
										$checked = in_array( $email_field, $settings['email_fields'] ) ? ' checked="checked"' : '';

										// Display field.
										echo '<label><input name="email_fields[]" type="checkbox" value="' . esc_attr( $email_field ) . '"' . $checked . '>' . $label . '</label><br />';

									}

								?>
							</fieldset>
						</td>
					</tr>

				</tbody>

			</table>

			<p class="submit">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'email-post-approval' ); ?>" />
			</p>

		</form>

	<?php

		// Close page.
		echo '</div>';

	}

	/**
	 * Save Email Post Approval settings.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @uses Email_Post_Approval::get_email_fields()
	 * @uses Email_Post_Approval::get_post_stati()
	 * @uses Email_Post_Approval::is_postback()
	 *
	 * @return array
	 */
	public function maybe_save_settings() {

		// Get current settings.
		$settings = array(
			'send_to'        => get_option( 'epa_send_to' ),
			'post_statuses'  => get_option( 'epa_post_statuses' ),
			'email_fields'   => get_option( 'epa_email_fields' ),
			'default_author' => get_option( 'epa_default_author' ),
		);

		// If there was no postback, return current settings.
		if ( ! $this->is_postback() ) {
			return $settings;
		}

		// Validate nonce.
		check_admin_referer( 'email-post-approval-settings' );

		// Get new settings.
		$new_settings = $_POST;

		// Sanitize settings.
		foreach ( $new_settings as $key => $value ) {

			switch ( $key ) {

				case 'email_fields':

					// Get email fields.
					$email_fields = $this->get_email_fields();
					$email_fields = array_keys( $email_fields );

					// Loop through values.
					foreach ( $value as $i => $field ) {

						// If this is a non-existent field, remove it.
						if ( ! in_array( $field, $email_fields ) ) {
							unset( $value[ $i ] );
						}

					}

					$new_settings[ $key ] = $value;

					break;

				case 'post_statuses':

					// Get post stati.
					$post_stati = $this->get_post_stati();
					$post_stati = array_keys( $post_stati );

					// Loop through values.
					foreach ( $value as $i => $field ) {

						// If this is a non-existent post status, remove it.
						if ( ! in_array( $field, $post_stati ) ) {
							unset( $value[ $i ] );
						}

					}

					$new_settings[ $key ] = $value;

					break;

				case 'send_to':
					$new_settings[ $key ] = sanitize_email( $value );
					break;

				default:
					$new_settings[ $key ] = sanitize_text_field( $value );
					break;

			}

		}

		// Save settings.
		foreach ( $new_settings as $key => $value ) {

			update_option( 'epa_' . $key, $value );

		}

		// Display save message.
		printf(
			'<div id="message" class="updated notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'email-post-approval' )
		);

		return $new_settings;

	}





	// # HELPER METHODS ------------------------------------------------------------------------------------------------

	/**
	 * Generate secret post approval key.
	 *
	 * @since  1.0
	 * @access private
	 *
	 * @param int $post_id Post ID to generate approval key for.
	 *
	 * @return string
	 */
	private function generate_hash( $post_id = 0 ) {

		// Check for existing hash.
		$existing_hash = get_post_meta( $post_id, '_epa-approve_key', true );

		// If hash exists, return it.
		if ( $existing_hash ) {
			return $existing_hash;
		}

		// Generate hash.
		$hash = sha1( $post_id * time() );

		// Save hash to post meta.
		update_post_meta( $post_id, '_epa-approve_key', $hash );

		return $hash;

	}

	/**
	 * Get fields available for Email Post Approval email.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @return array
	 */
	public function get_email_fields() {

		return array(
			'title'       => esc_html__( 'Post Title', 'email-post-approval' ),
			'post_author' => esc_html__( 'Post Author', 'email-post-approval' ),
			'post_date'   => esc_html__( 'Publish Date', 'email-post-approval' ),
			'categories'  => esc_html__( 'Categories', 'email-post-approval' ),
			'tags'        => esc_html__( 'Tags', 'email-post-approval' ),
			'post_meta'   => esc_html__( 'Post Meta', 'email-post-approval' ),
			'body'        => esc_html__( 'Post Body', 'email-post-approval' ),
			'thumbnail'   => esc_html__( 'Featured Image', 'email-post-approval' ),
		);

	}

	/**
	 * Get post stati available for Email Post Approval.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @return array
	 */
	public function get_post_stati() {

		// Get post stati.
		$post_stati = get_post_stati( null, 'objects' );

		// Loop through post stati.
		foreach ( $post_stati as $name => $post_status ) {

			// If this is an excluded post status, remove it.
			if ( in_array( $name, array( 'trash', 'auto-draft', 'inherit' ) ) ) {
				unset( $post_stati[ $name ] );
			}

		}

		return $post_stati;

	}

	/**
	 * Determine if the current request is a postback.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @return bool
	 */
	public function is_postback() {

		return is_array( $_POST ) && count( $_POST ) > 0;

	}





	// # INSTALLATION --------------------------------------------------------------------------------------------------

	/**
	 * Define initial settings upon activation.
	 *
	 * @since  1.0
	 * @access public
	 */
	public static function activation() {

		// Add default send to address.
		if ( ! get_option( 'epa_send_to' ) ) {
			add_option( 'epa_send_to', sanitize_email( get_bloginfo( 'admin_email' ) ) );
		}

		// Add default post statuses to cause Email Post Approval.
		if ( ! get_option( 'epa_post_statuses' ) ) {
			add_option( 'epa_post_statuses', array( 'pending' ) );
		}

		// Add default fields to display in post approval email.
		if ( ! get_option( 'epa_email_fields' ) ) {
			add_option( 'epa_email_fields', array( 'title', 'post_author', 'post_date', 'categories', 'tags', 'post_meta', 'body', 'thumbnail' ) );
		}

	}





	// # UNINSTALL -----------------------------------------------------------------------------------------------------

	/**
	 * Remove settings upon deactivation.
	 *
	 * @since  1.0
	 * @access public
	 */
	public static function deactivation() {

		delete_option( 'epa_send_to' );
		delete_option( 'epa_post_statuses' );
		delete_option( 'epa_email_fields' );

	}

}

Email_Post_Approval::get_instance();
