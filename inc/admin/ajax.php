<?php
defined( 'ABSPATH' ) or die( 'Cheatin\' uh?' );

/**
 * Process all thumbnails of a specific image with Imagify with the manual method.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_manual_upload'		, '_do_admin_post_imagify_manual_upload' );
add_action( 'admin_post_imagify_manual_upload'	, '_do_admin_post_imagify_manual_upload' );
function _do_admin_post_imagify_manual_upload() {
	if ( defined( 'DOING_AJAX' ) ) {
		check_ajax_referer( 'imagify-manual-upload' );
	} else {
		check_admin_referer( 'imagify-manual-upload' );
	}
	
	if ( ! isset( $_GET['attachment_id'] ) || ! current_user_can( 'upload_files' ) ) {
		if ( defined( 'DOING_AJAX' ) ) {
			wp_send_json_error();
		} else {
			wp_nonce_ays( '' );
		}
	}

	$attachment = new Imagify_Attachment( $_GET['attachment_id'] );
	
	// Optimize it!!!!!
	$attachment->optimize();

	if ( ! defined( 'DOING_AJAX' ) ) {
		wp_safe_redirect( wp_get_referer() );
		die();
	}

	// Return the optimization statistics
	$output = get_imagify_attachment_optimization_text( $attachment->id );
	wp_send_json_success( $output );
}

/**
 * Process a manual upload by overriding the optimization level.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_manual_override_upload', '_do_admin_post_imagify_manual_override_upload' );
add_action( 'admin_post_imagify_manual_override_upload', '_do_admin_post_imagify_manual_override_upload' );
function _do_admin_post_imagify_manual_override_upload() {
	if ( defined( 'DOING_AJAX' ) ) {
		check_ajax_referer( 'imagify-manual-override-upload' );
	} else {
		check_admin_referer( 'imagify-manual-override-upload' );
	}

	if ( ! isset( $_GET['attachment_id'] ) || ! current_user_can( 'upload_files' ) ) {
		if ( defined( 'DOING_AJAX' ) ) {
			wp_send_json_error();
		} else {
			wp_nonce_ays( '' );
		}
	}
	
	$attachment = new Imagify_Attachment( $_GET['attachment_id'] );
		
	// Restore the backup file
	$attachment->restore();

	// Optimize it!!!!!
	$attachment->optimize( (int) $_GET['optimization_level'] );

	if ( ! defined( 'DOING_AJAX' ) ) {
		wp_safe_redirect( wp_get_referer() );
		die();
	}

	// Return the optimization statistics
	$output = get_imagify_attachment_optimization_text( $attachment->id );
	wp_send_json_success( $output );
}

/**
 * Process a restoration to the original attachment.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_restore_upload', '_do_admin_post_imagify_restore_upload' );
add_action( 'admin_post_imagify_restore_upload', '_do_admin_post_imagify_restore_upload' );
function _do_admin_post_imagify_restore_upload() {
	if ( defined( 'DOING_AJAX' ) ) {
		check_ajax_referer( 'imagify-restore-upload' );
	} else {
		check_admin_referer( 'imagify-restore-upload' );
	}

	if ( ! isset( $_GET['attachment_id'] ) || ! current_user_can( 'upload_files' ) ) {
		if ( defined( 'DOING_AJAX' ) ) {
			wp_send_json_error();
		} else {
			wp_nonce_ays( '' );
		}
	}
	
	$attachment = new Imagify_Attachment( $_GET['attachment_id'] );
	
	// Restore the backup file
	$attachment->restore();
	
	if ( ! defined( 'DOING_AJAX' ) ) {
		wp_safe_redirect( wp_get_referer() );
		die();
	}

	// Return the optimization button
	$output = '<a id="imagify-upload-' . $attachment->id . '" href="' . get_imagify_admin_url( 'manual-upload', $attachment->id ) . '" class="button-primary button-imagify-manual-upload" data-waiting-label="' . esc_attr__( 'Optimizing...', 'imagify' ) . '">' . __( 'Optimize', 'imagify' ) . '</a>';
	wp_send_json_success( $output );
}

/**
 * Get all unoptimized attachment ids.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_get_unoptimized_attachment_ids', '_do_wp_ajax_imagify_get_unoptimized_attachment_ids' );
function _do_wp_ajax_imagify_get_unoptimized_attachment_ids() {
	check_ajax_referer( 'imagify-bulk-upload', 'imagifybulkuploadnonce' );

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error();
	}
	
	$user = new Imagify_User();
	
	if ( $user->is_over_quota() ) {
		wp_send_json_error( array( 'message' => 'over-quota' ) );
	}
	
	set_time_limit( 0 );
	
	$optimization_level = $_GET['optimization_level'];
	$optimization_level = ( -1 != $optimization_level ) ? $optimization_level : get_imagify_option( 'optimization_level', 1 );
	$optimization_level = (int) $optimization_level;
	
	$meta_query = array(
		'relation' => 'OR',
		array(
			'key'     => '_imagify_optimization_level',
			'value'   => $optimization_level,
			'compare' => '!='
		),
		array(
			'key'     => '_imagify_optimization_level',
			'compare' => 'NOT EXISTS'
		),
		array(
			'key'     => '_imagify_status',
			'value'   => 'error',
			'compare' => '='
		),
	);
	
	$args = array(
		'fields'                 => 'ids',
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'post_mime_type'         => get_imagify_mime_type(),
		'meta_query'			 => $meta_query,
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	);
	
	$data               = array();
	$query              = new WP_Query( $args );
	$ids                = $query->posts;

	// Save the optimization level in a transient to retrieve it later during the process
	set_transient( 'imagify_bulk_optimization_level', $optimization_level );
	
	foreach( $ids as $id ) {
		/** This filter is documented in inc/functions/process.php */
		$file_path = apply_filters( 'imagify_file_path', get_attached_file( $id ) );
		
		if ( file_exists( $file_path ) ) {
			$attachment        = new Imagify_Attachment( $id );
			$attachment_error  = $attachment->get_optimized_error();  
			$attachment_error  = trim( $attachment_error );
			$attachment_status = $attachment->get_status();
			
			// Don't try to re-optimize if the optimization level is still the same
			if ( $optimization_level === $attachment->get_optimization_level() && ! $attachment->has_error() ) {
				continue;					
			}
			
			// Don't try to re-optimize if there is no backup file
			if ( $optimization_level !== $attachment->get_optimization_level() && ! $attachment->has_backup() && $attachment->is_optimized() ) {
				continue;					
			}
			
			// Don't try to re-optimize images already compressed
			if ( $attachment->get_optimization_level() >= $optimization_level && $attachment_status == 'already_optimized' ) {
				continue;	
			}
			
			// Don't try to re-optimize images with an empty error message
			if ( $attachment_status == 'error' && empty( $attachment_error ) ) {
				continue;
			}
									
			$data[ '_' . $id ] = wp_get_attachment_url( $id );	
		}
	}
	
	if ( (bool) $data ) {
		wp_send_json_success( $data );
	}
		
	wp_send_json_error( array( 'message' => 'no-images' ) );
}

/**
 * Process all thumbnails of a specific image with Imagify with the bulk method.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_bulk_upload', '_do_wp_ajax_imagify_bulk_upload' );
function _do_wp_ajax_imagify_bulk_upload() {
	check_ajax_referer( 'imagify-bulk-upload', 'imagifybulkuploadnonce' );
	
	if ( ! isset( $_POST['image'] ) || ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error();
	}

	$attachment_id      = (int) $_POST['image'];
	$attachment         = new Imagify_Attachment( $_POST['image'] );
	$optimization_level = get_transient( 'imagify_bulk_optimization_level' );
	
	// Restore it if the optimization level is updated
	if ( $optimization_level !== $attachment->get_optimization_level() ) {
		$attachment->restore();
	}
	
	// Optimize it!!!!!
	$attachment->optimize( $optimization_level );

	// Return the optimization statistics
	$fullsize_data         = $attachment->get_size_data();
	$stats_data            = $attachment->get_stats_data();
	$saving_data           = imagify_count_saving_data();
	$user		   		   = new Imagify_User();
	$data                  = array(
		'global_already_optimized_attachments' => $saving_data['count'],
		'global_optimized_attachments'         => imagify_count_optimized_attachments(),
		'global_unoptimized_attachments'       => imagify_count_unoptimized_attachments(),
		'global_errors_attachments'            => imagify_count_error_attachments(),
		'global_optimized_attachments_percent' => imagify_percent_optimized_attachments(),
		'global_optimized_percent'             => $saving_data['percent'],
		'global_original_human'                => size_format( $saving_data['original_size'], 1 ),
		'global_optimized_human'               => size_format( $saving_data['optimized_size'], 1 ),
		'global_unconsumed_quota'              => $user->get_percent_unconsumed_quota(),
	);
	
	if ( ! $attachment->is_optimized() ) {
		$data['success'] 		= false;
		$data['error']   		= $fullsize_data['error'];
		
		wp_send_json_error( $data );
	}
	
	$data['success']               = true;
	$data['original_size']         = $fullsize_data['original_size'];
	$data['new_size']              = $fullsize_data['optimized_size'];
	$data['percent']               = $fullsize_data['percent'];
	$data['overall_saving'] 	   = $stats_data['original_size'] - $stats_data['optimized_size'];
	$data['original_overall_size'] = $stats_data['original_size'];
	$data['new_overall_size']      = $stats_data['optimized_size'];
	$data['thumbnails']            = $attachment->get_optimized_sizes_count();
		
	wp_send_json_success( $data );
}

/**
 * Create a new Imagify account.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_signup', '_do_wp_ajax_imagify_signup' );
function _do_wp_ajax_imagify_signup() {
	check_ajax_referer( 'imagify-signup', 'imagifysignupnonce' );

	if ( ! isset( $_GET['email'] ) ) {
		wp_send_json_error();
	}

	$data = array(
		'email'    => $_GET['email'],
		'password' => wp_generate_password( 12, false ),
		'lang'	   => get_locale()
	);

	$response = add_imagify_user( $data );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	wp_send_json_success();
}

/**
 * Process an API key check validity.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_check_api_key_validity', '_do_wp_ajax_imagify_check_api_key_validity' );
function _do_wp_ajax_imagify_check_api_key_validity() {
	check_ajax_referer( 'imagify-check-api-key', 'imagifycheckapikeynonce' );

	if ( ! isset( $_GET['api_key'] ) ) {
		wp_send_json_error();
	}

	$response = get_imagify_status( $_GET['api_key'] );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}
	
	$options            = get_site_option( IMAGIFY_SETTINGS_SLUG );
	$options['api_key'] = sanitize_key( $_GET['api_key'] );

	update_site_option( IMAGIFY_SETTINGS_SLUG, $options );

	wp_send_json_success();
}

/**
 * Process a dismissed notice.
 *
 * @since 1.0
 */
add_action( 'wp_ajax_imagify_dismiss_notice', '_do_admin_post_imagify_dismiss_notice' );
add_action( 'admin_post_imagify_dismiss_notice', '_do_admin_post_imagify_dismiss_notice' );
function _do_admin_post_imagify_dismiss_notice() {
	if ( defined( 'DOING_AJAX' ) ) {
		check_ajax_referer( 'imagify-dismiss-notice' );
	} else {
		check_admin_referer( 'imagify-dismiss-notice' );
	}

	if ( ! isset( $_GET['notice'] ) || ! current_user_can( 'manage_options' ) ) {
		if ( defined( 'DOING_AJAX' ) ) {
			wp_send_json_error();
		} else {
			wp_nonce_ays( '' );
		}
	}

	imagify_dismiss_notice( $_GET['notice'] );
	
	if ( ! defined( 'DOING_AJAX' ) ) {
		wp_safe_redirect( wp_get_referer() );
		die();
	}
	
	wp_send_json_success();
}

/**
 * Disable a plugin which can be in conflict with Imagify
 *
 * @since 1.2
 */
add_action( 'admin_post_imagify_deactivate_plugin', '_imagify_deactivate_plugin' );
function _imagify_deactivate_plugin() {
	if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'imagifydeactivatepluginnonce' ) ) {
		wp_nonce_ays( '' );
	}

	deactivate_plugins( $_GET['plugin'] );

	wp_safe_redirect( wp_get_referer() );
	die();
}

/**
 * Get admin bar profile output
 *
 * @since 1.2.3
 */
add_action( 'wp_ajax_imagify_get_admin_bar_profile', '_do_wp_ajax_imagify_get_admin_bar_profile' );
function _do_wp_ajax_imagify_get_admin_bar_profile() {
	check_ajax_referer( 'imagify-get-admin-bar-profile', 'imagifygetadminbarprofilenonce' );
	
	$user 			  = new Imagify_User();
	$unconsumed_quota = $user->get_percent_unconsumed_quota();
	$meteo_icon       =  '<img src="' . IMAGIFY_ASSETS_IMG_URL . 'sun.svg" width="37" height="38" alt="" />';
	$bar_class        = 'positive';
	$message          = '';

	if ( $unconsumed_quota >= 21 && $unconsumed_quota <= 50 ) {
		$bar_class  = 'neutral';
		$meteo_icon = '<img src="' . IMAGIFY_ASSETS_IMG_URL . 'cloudy-sun.svg" width="37" height="38" alt="" />';
	}
	elseif ( $unconsumed_quota <= 20 ) {
		$bar_class  = 'negative';
		$meteo_icon = '<img src="' . IMAGIFY_ASSETS_IMG_URL . 'stormy.svg" width="38" height="36" alt="" />';
	}

	if ( $unconsumed_quota <= 20 && $unconsumed_quota > 0 ) {
		$message = '
			<div class="imagify-error">
				<p><i class="dashicons dashicons-warning" aria-hidden="true"></i><strong>' . __( 'Oops, It\'s almost over!', 'imagify' ) . '</strong></p>
					<p>' . sprintf( __( 'You have almost used all your credit.%sDon\'t forget to upgrade your subscription to continue optimizing your images.', 'imagify' ), '<br/><br/>' ) . '</p>
				<p class="center txt-center text-center"><a class="btn btn-ghost" href="' . IMAGIFY_APP_MAIN . '/#/subscription" target="_blank">' . __( 'View My Subscription', 'imagify' ) . '</a></p>
			</div>
		';
	}

	if ( $unconsumed_quota === 0 ) {
		$message = '
			<div class="imagify-error">
				<p><i class="dashicons dashicons-warning" aria-hidden="true"></i><strong>' . __( 'Oops, It\'s Over!', 'imagify' ) . '</strong></p>
				<p>' . sprintf( __( 'You have consumed all your credit for this month. You will have <strong>%s back on %s</strong>.', 'imagify' ), size_format( $user->quota * 1048576 ), date_i18n( get_option( 'date_format' ), strtotime( $user->next_date_update ) ) ) . '</p>
				<p class="center txt-center text-center"><a class="btn btn-ghost" href="' . IMAGIFY_APP_MAIN . '/#/subscription" target="_blank">' . __( 'Upgrade My Subscription', 'imagify' ) . '</a></p>
			</div>
		';
	}

	// custom HTML
	$quota_section = '
		<div class="imagify-admin-bar-quota">
			<div class="imagify-abq-row">';

	if ( 1 === $user->plan_id ) {
		$quota_section .= '
				<div class="imagify-meteo-icon">
					' . $meteo_icon . '
				</div>';
	}

	$quota_section .= '
				<div class="imagify-account">
					<p class="imagify-meteo-title">' . __( 'Account status', 'imagify' ) . '</p>
					<p class="imagify-meteo-subs">' . __( 'Your subscription:', 'imagify' ) . '&nbsp;<strong class="imagify-user-plan">' . $user->plan_label . '</strong></p>
				</div>
			</div>';

	if ( 1 === $user->plan_id ) {
		$quota_section .= '
			<div class="imagify-abq-row">
				<div class="imagify-space-left">
					<p>' . sprintf( __( 'You have %s space credit left', 'imagify'), '<span class="imagify-unconsumed-percent">' . $unconsumed_quota . '%</span>' ) . '</p>
					<div class="imagify-bar-' . $bar_class . '">
						<div style="width: ' . $unconsumed_quota . '%;" class="imagify-unconsumed-bar imagify-progress"></div>
					</div>
				</div>
			</div>';
	}

	$quota_section .= '
			<p class="imagify-abq-row">
				<a class="imagify-account-link" href="' . IMAGIFY_APP_MAIN . '/#/subscription" target="_blank">
					<span class="dashicons dashicons-admin-users"></span>
					<span class="button-text">' . __( 'View my subscription', 'imagify' ) . '</span>
				</a>
			</p>
		</div>
		' . $message;
	
	wp_send_json_success( $quota_section );
}