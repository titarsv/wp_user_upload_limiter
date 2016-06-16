<?php
/*
Plugin Name: WP user upload limiter
Plugin URI: http://yogurt-design.com
Description: Limit total size of files downloaded by the user.
Author: Tit@r
Version: 1.0
Author URI: http://yogurt-design.com
*/

/**
 * Регистрация пункта админ панели
 */
function register_wp_user_upload_limiter_submenu_page() {
	add_media_page( 'WP user upload limiter', 'Upload limiter', 'manage_options', 'wp_user_upload_limiter', 'wp_user_upload_limiter_submenu_page_callback' );
}
add_action('admin_menu', 'register_wp_user_upload_limiter_submenu_page');

/**
 * Обработчик страницы настроек
 */
function wp_user_upload_limiter_submenu_page_callback() {
	$roles = wp_roles()->roles;
	$roles_limit = get_roles_upload_limit();

	if(isset($_POST['submit'])) {
		foreach ($roles as $role => $data) {
			if (isset($_POST['unlimited-' . $role])) {
				if (empty($_POST[$role])) {
					$roles_limit[$role] = 0;
				} else {
					$roles_limit[$role] = sanitize_text_field( $_POST[$role] );
				}
			} else {
				$roles_limit[$role] = '';
			}
		}
	}

	update_option('wp_roles_upload_limit', $roles_limit);

	echo '<div class="wrap">';
	echo '<h1>WP user upload limiter</h1>';
	echo '<form method="post" action="">';
	echo '<table class="form-table"><tbody>';
	foreach ($roles as $role => $data){
		echo '<tr>';
			echo '<th scope="row">'.$data['name'].' limit (Mb):</th>';
			echo '<td><input type="checkbox" id="'.$role.'" name="unlimited-'.$role.'"'.($roles_limit[$role]===''?'':' checked').'><input type="number" name="'.$role.'" placeholder="&infin;" value="'.$roles_limit[$role].'"></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '<p class="submit"><input name="submit" id="submit" class="button button-primary" value="Сохранить изменения" type="submit"></p>';
	echo '</form>';
	echo '</div>';

}

/**
 * Получение ролей текущего пользователя
 * @return array
 */
function get_current_user_roles(){
	global $current_user;
	return $current_user->roles;
}

/**
 * Получение ролей пользователя по id
 * @param $user_id
 * @return array
 */
function get_user_roles($user_id){
	$userdata = get_userdata($user_id);
	return $userdata->roles;
}

/**
 * Получение настроек лимита загрузок для ролей
 * @return array|mixed|void
 */
function get_roles_upload_limit(){
	$roles_limit = get_option('wp_roles_upload_limit');

	if(empty($roles_limit))
		$roles_limit = array();

	return $roles_limit;
}

/**
 * Получение лимита загрузок роли
 * @param $role
 * @return mixed|string
 */
function get_role_upload_limit($role){
	$roles_limit = get_roles_upload_limit();

	if(isset($roles_limit[$role]))
		return $roles_limit[$role];
	else
		return '';
}

/**
 * Получение id файлов пользователя
 * @param $user_id
 * @return array|null|object
 */
function get_user_files_ids($user_id){
	global $wpdb;
	return $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_author = $user_id AND post_type = 'attachment'");
}

/**
 * Получение id файлов текущего пользователя
 * @return int
 */
function get_current_user_all_fiels_size(){
	return get_user_all_fiels_size(get_current_user_id());
}

/**
 * Получение суммарного размера файлов пользователя
 * @param $user_id
 * @return int
 */
function get_user_all_fiels_size($user_id){
	$s = 0;

	if ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) {
		$fiels = get_user_files_ids($user_id);

		foreach ($fiels as $file) {
			$metadata = wp_get_attachment_metadata($file->ID);
			$metadata['sizes']['full'] = array(
				'width' => $metadata['width'],
				'height' => $metadata['height'],
				'file' => $metadata['file']
			);

			foreach ($metadata['sizes'] as $size => $data) {
				$size_data = image_downsize($file->ID, $size);
				$path = str_replace($uploads['baseurl'], $uploads['basedir'], $size_data[0]);
				$s += filesize($path);
			}
		}
	}

	return $s;
}

/**
 * Получение лимита загрузок текущего пользователя
 * @return mixed|void
 */
function get_current_user_upload_limit(){

	$limit = get_personal_user_upload_limit(get_current_user_id());

	if($limit === '') {
		foreach (get_current_user_roles() as $role) {
			$role_limit = get_role_upload_limit($role);
			if ($role_limit === '') {
				$limit = '';
				break;
			} elseif ($limit === '' || $role_limit > $limit) {
				$limit = $role_limit;
			}
		}
	}

	$limit = $limit * 1048576;

	return apply_filters('current_user_upload_limit', $limit, get_current_user_id());
}

/**
 * Ограничение загрузок согласно лимиту
 * @param $post_ID
 */
function remove_attachment_limit($post_ID){

	$limit = get_current_user_upload_limit();

	if($limit !== ''){
		$fiels_size = get_current_user_all_fiels_size();

		if($fiels_size > $limit){
			global $wpdb;
			$name = $wpdb->get_var("SELECT post_title FROM $wpdb->posts WHERE ID = $post_ID LIMIT 0, 1");

			wp_delete_attachment($post_ID, true);
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'Превышен лимит загрузок в '.ceil($limit/1048576).'Mb.' ),
					'filename' => $name,
				)
			) );
			wp_die();
		}
	}

}
add_action( 'add_attachment', 'remove_attachment_limit' );

/**
 * Получение персонального лимита загрузок пользователя
 * @param $user_id
 * @return mixed
 */
function get_personal_user_upload_limit($user_id){
	$limit = get_user_meta($user_id, 'upload_limit', true);
	return $limit;
}

/**
 * Поле настроек персонального лимита загрузок пользователя
 * @param $profileuser
 */
function add_personal_user_upload_limit($profileuser){
	$roles = get_current_user_roles();
	if(in_array('administrator', $roles)){
		$limit = get_personal_user_upload_limit($profileuser->id);
		echo '<tr>';
		echo '<th scope="row">Лимит загрузок (Mb):</th>';
		echo '<td>';
		echo '<input type="checkbox" name="unlimited"'.($limit===''?'':' checked').'><input type="number" name="upload_limit" placeholder="&infin;" value="'.$limit.'">';
		echo '</td>';
		echo '</tr>';
	}
}
add_action( 'personal_options', 'add_personal_user_upload_limit' );

/**
 * Сохранение настроек персонального лимита загрузок пользователя
 * @param $user_id
 */
function save_personal_user_upload_limit( $user_id )
{
	if (isset($_POST['unlimited'])) {
		if (empty($_POST['upload_limit'])) {
			$limit = 0;
		} else {
			$limit= $_POST['upload_limit'];
		}
	} else {
		$limit = '';
	}
	update_user_meta( $user_id, 'upload_limit', sanitize_text_field( $limit ) );
}
add_action( 'personal_options_update', 'save_personal_user_upload_limit' );
add_action( 'edit_user_profile_update', 'save_personal_user_upload_limit' );

/**
 * Персонализация медиа ресурсов
 * @param $query
 * @return array
 */
function media_person($query)
{
	if (!current_user_can('administrator') && is_array($query))
		$query['author'] = get_current_user_id();

	return $query;
}
add_filter('ajax_query_attachments_args', 'media_person');

/**
 * Персональные директории пользователей
 * @param $upload_dir
 * @return mixed
 */
function current_user_upload_dir($upload_dir){
	if(is_user_logged_in()){
		$user_id = get_current_user_id();
		$upload_dir['path'] = str_replace($upload_dir['subdir'], '/users/'.$user_id.$upload_dir['subdir'], $upload_dir['path']);
		$upload_dir['url'] = str_replace($upload_dir['subdir'], '/users/'.$user_id.$upload_dir['subdir'], $upload_dir['url']);
		$upload_dir['subdir'] = '/users/'.$user_id.$upload_dir['subdir'];
	}
	return $upload_dir;
}
add_filter( 'upload_dir', 'current_user_upload_dir');
