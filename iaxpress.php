<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin. [EDITADO]
 *
 * @link              https://ersolucoesewb.com.br
 * @since             1.0.0
 * @package           IAxPress
 *
 * @wordpress-plugin
 * Plugin Name:       IA xPress
 * Plugin URI:        https://iaxpress.app
 * Description:       Crie e gerencie posts com IA
 * Version:           1.1.3
 * Author:            ER Soluções Web LTDA
 * Author URI:        https://ersolucoesweb.com.br/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       iaxpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wp_app_url = getenv( 'IAXPRESS_APP_URL' );

define( 'IAXPRESS_APP_URL', $wp_app_url ? $wp_app_url : 'https://iaxpress.app' );

/**
 * Gera token de integração
 *
 * @return void
 */
function iaxpress_generate_token_and_redirect() {

	if ( ! is_user_logged_in() ) {
		wp_redirect( get_site_url( null, '/wp-login.php?redirect_to=' . get_admin_url( null, 'admin-ajax.php?action=iaxpress_generate_token' ) ) );
		exit;
	}

	if ( ! current_user_can( 'publish_posts' ) ) {
		wp_redirect( IAXPRESS_APP_URL . '/?403=1#' );
		exit();
	}
	$token     = bin2hex( random_bytes( 128 ) );
	$salt      = microtime();
	$token_key = md5( "{$token} {$salt}" );

	set_transient( 'iaxpress_token_' . $token_key, $token );
	set_transient( 'iaxpress_user_' . $token_key, get_current_user_id() );

	$redirect_url = IAXPRESS_APP_URL . "/#/{$token}:" . $token_key;

	wp_redirect( $redirect_url );
	exit();
}

add_action( 'wp_ajax_iaxpress_generate_token', 'iaxpress_generate_token_and_redirect' );
add_action( 'wp_ajax_nopriv_iaxpress_generate_token', 'iaxpress_generate_token_and_redirect' );

/**
 * Login automático no painel
 *
 * @return void
 */
function iaxpress_autologin() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$token_key = isset( $_GET['token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['token_key'] ) ) : '';
	/* phpcs:enable */
	$user_id    = get_transient( 'iaxpress_user_' . $token_key );
	$user_token = get_transient( 'iaxpress_token_' . $token_key );

	if ( $user_token !== $user_token ) {
		exit( 'invalid token' );
	}

	if ( $user_id ) {
		wp_set_auth_cookie( $user_id );
		wp_redirect( admin_url( '/edit.php' ) );
		exit;
	} else {
		echo 'Usuário não encontrado';
	}
}
add_action( 'wp_ajax_nopriv_iaxpress_autologin', 'iaxpress_autologin' );
add_action( 'wp_ajax_iaxpress_autologin', 'iaxpress_autologin' );

/**
 * Cria ou autaliza o post
 *
 * @param WP_REST_Request $request Dados da requisição.
 * @return string Dados do post inserido.
 */
function iaxpress_create_post_with_token_verification( WP_REST_Request $request ) {

	$token       = sanitize_text_field( $request->get_param( 'token' ) );
	$token_key   = sanitize_text_field( $request->get_param( 'token_key' ) );
	$title       = $request->get_param( 'title' );
	$content     = $request->get_param( 'content' );
	$categories  = $request->get_param( 'categories' );
	$date        = $request->get_param( 'date' );
	$tags        = $request->get_param( 'tags' );
	$update_post = absint( $request->get_param( 'update_post' ) );
	$post_id     = absint( $request->get_param( 'post_id' ) );
	$fonte       = sanitize_text_field( $request->get_param( 'fonte' ) );
	$image       = sanitize_text_field( $request->get_param( 'image' ) );
	$caption     = sanitize_text_field( $request->get_param( 'caption' ) );
	$status      = sanitize_text_field( $request->get_param( 'status' ) );

	if ( ! in_array( $status, array( 'publish', 'draft' ) ) ) {
		$status = 'draft';
	}

	$sent_token = $token;

	$stored_token = get_transient( 'iaxpress_token_' . $token_key );

	if ( $sent_token !== $stored_token || strlen( $sent_token ) !== 256 || empty( $sent_token ) ) {
		wp_send_json_error( 'Token incorreto' );
	}

	$post_title      = sanitize_text_field( $title );
	$post_content    = wp_kses_post( $content );
	$post_categories = $categories;
	$date            = new DateTime( $date, new DateTimeZone( wp_timezone_string() ) );
	$post_date       = $date->format( 'Y-m-d H:i:s' );
	$date->setTimezone( new DateTimeZone( 'GMT' ) );
	$gmt_date_string = $date->format( 'Y-m-d H:i:s' );
	$post_tags       = $tags;

	$post_data = array(
		'post_title'    => $post_title,
		'post_content'  => $post_content,
		'post_date'     => $post_date,
		'post_date_gmt' => $gmt_date_string,
		'post_status'   => $status,
		'post_author'   => get_transient( 'iaxpress_user_' . $token_key ),
	);

	if ( ! empty( $post_categories ) && is_array( $post_categories ) ) {
		$post_data['post_category'] = $post_categories;
	}

	if ( ! empty( $post_tags ) ) {
		$post_tags               = explode( ',', $post_tags );
		$post_tags               = array_map( 'trim', $post_tags );
		$post_data['tags_input'] = $post_tags;
	}

	if ( $update_post && $post_id ) {
		$post_data['ID'] = $post_id;
		$post_id         = wp_update_post( $post_data );
	} else {
		$post_id = wp_insert_post( $post_data );
	}

	update_post_meta( $post_id, 'fonte', $fonte );
	update_post_meta( $post_id, '_yoast_wpseo_focuskw', $post_title );

	if ( ! empty( $image ) ) {
		$image_data = base64_decode( preg_replace( '#^data:image/\w+;base64,#i', '', $image ) );

		$filename = sanitize_title( $post_title ) . '.jpg';

		$upload_dir = wp_upload_dir();
		$image_path = $upload_dir['path'] . '/' . $filename;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return false;
		}

		$wp_filesystem->put_contents( $image_path, $image_data, FS_CHMOD_FILE );

		$attachment = array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sanitize_file_name( $filename ),
			'post_excerpt'   => $caption,
			'post_content'   => $caption,
			'post_date'      => $post_date,
			'post_date_gmt'  => $gmt_date_string,
			'post_status'    => 'inherit',
			'post_author'    => get_transient( 'iaxpress_user_' . $token_key ),
		);

		$attach_id = wp_insert_attachment( $attachment, $image_path );

		if ( ! is_wp_error( $attach_id ) ) {
			set_post_thumbnail( $post_id, $attach_id );
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_date'     => $post_date,
					'post_date_gmt' => $gmt_date_string,
					'post_status'   => $status,
				)
			);
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $attach_id );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $attach_id );
		}
	}

	if ( function_exists( 'rocket_clean_home' ) ) {
		rocket_clean_home();
	}

	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}

	if ( ! is_wp_error( $post_id ) ) {
		wp_send_json_success( array( 'url' => get_post_permalink( $post_id ) ) );
	} else {
		wp_send_json_error( 'Erro ao criar o post' );
	}
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'iaxpress/v1',
			'/create-post',
			array(
				'permission_callback' => '__return_true',
				'methods'             => 'POST',
				'callback'            => 'iaxpress_create_post_with_token_verification',
			)
		);
	}
);
