<?php

class WP_REST_Meta_Posts_Controller extends WP_REST_Meta_Controller {
	/**
	 * Associated object type.
	 *
	 * @var string Type slug ("post" or "user")
	 */
	protected $parent_type = 'post';

	/**
	 * Associated post type name.
	 *
	 * @var string
	 */
	protected $parent_post_type;

	/**
	 * Associated post type controller class object.
	 *
	 * @var WP_REST_Posts_Controller
	 */
	protected $parent_controller;

	/**
	 * Base path for post type endpoints.
	 *
	 * @var string
	 */
	protected $parent_base;

	public function __construct( $parent_post_type ) {
		$this->parent_post_type = $parent_post_type;
		$this->parent_controller = new WP_REST_Posts_Controller( $this->parent_post_type );
		$obj = get_post_type_object( $this->parent_post_type );
		$this->parent_base = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
		$this->namespace = 'wp/v2';
		$this->rest_base = 'meta';
	}

	public function get_parent_object( $id ){

		$post = get_post( $id );
		if( empty( $post ) || empty( $post->ID ) ){
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	public function get_object_registered_meta_keys(){
		return get_registered_meta_keys( $this->parent_type, $this->parent_post_type );
	}

	/**
	 * Check if a given request has access to get meta for a post.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		$parent = get_post( (int) $request['parent_id'] );

		if ( empty( $parent ) || empty( $parent->ID ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->parent_controller->check_read_permission( $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view the meta for this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$post_type = get_post_type_object( $parent->post_type );
		if ( 'edit' === $request['context'] && ! current_user_can( $post_type->cap->edit_post, $parent->ID ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to edit post meta in this post type.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Check if a given request has access to get a specific meta entry for a post.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {

		$parent = get_post( (int) $request['parent_id'] );

		// if there's no post then error
		if ( empty( $parent ) || empty( $parent->ID ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		// if you can't read this post type then error
		if ( ! $this->parent_controller->check_read_permission( $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}


		return true;
	}

	/**
	 * Check if a given request has access to create a meta entry for a post.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function create_item_permissions_check( $request ) {
		
		$parent = get_post( (int) $request['parent_id'] );

		// if there's no post then error
		if ( empty( $parent ) || empty( $parent->ID ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		// if you can't read this post type then error
		if ( ! $this->parent_controller->check_read_permission( $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		// if this is in the edit context and you can't edit, then error
		$post_type = get_post_type_object( $parent->post_type );
		if( ! current_user_can( $post_type->cap->edit_post, $parent->ID ) ){
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to create this post meta.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Check if a given request has access to update a meta entry for a post.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function update_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to delete meta for a post.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function delete_item_permissions_check( $request ) {
		$parent = get_post( (int) $request['parent_id'] );

		if ( empty( $parent ) || empty( $parent->ID ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->parent_controller->check_read_permission( $parent ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$post_type = get_post_type_object( $parent->post_type );
		if ( ! current_user_can( $post_type->cap->delete_post, $parent->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot delete the meta for this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Check if we can edit the post meta.
	 *
	 * @param object $post Post object.
	 * @return boolean Can we edit it?
	 */
	protected function check_update_permission( $meta_data_array ) {

		$post_type = get_post( $meta_data_array['parent_id'] )->post_type;

		if ( ! is_object( $post_type ) ) {
			$post_type = get_post_type_object( $post_type );
		}

		if ( ! empty( $post_type ) && ! empty( $post_type->show_in_rest ) ) {
			return true;
		}

		return false;
	}

}
