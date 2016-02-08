<?php
/**
 * Metadata base class.
 */
abstract class WP_REST_Meta_Controller extends WP_REST_Controller {
	/**
	 * Associated object type.
	 *
	 * @var string Type slug ("post", "user", or "comment")
	 */
	protected $parent_type = null;

	/**
	 * Base path for parent meta type endpoints.
	 *
	 * @var string
	 */
	protected $parent_base = null;

	/**
	 * Construct the API handler object.
	 */
	public function __construct() {
		if ( empty( $this->parent_type ) ) {
			_doing_it_wrong( 'WP_REST_Meta_Controller::__construct', __( 'The object type must be overridden' ), 'WPAPI-2.0' );
			return;
		}
		if ( empty( $this->parent_base ) ) {
			_doing_it_wrong( 'WP_REST_Meta_Controller::__construct', __( 'The parent base must be overridden' ), 'WPAPI-2.0' );
			return;
		}
	}

	/**
	 * Register the meta-related routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->parent_base . '/(?P<parent_id>[\d]+)/' . $this->rest_base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
			),

			'schema' => array( $this, 'get_public_item_schema' ),
		), true );
		register_rest_route( $this->namespace, '/' . $this->parent_base . '/(?P<parent_id>[\d]+)/' . $this->rest_base . '/(?P<key>[\w-]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'context'         => array( 
						'default' 		=> 'view',
						'enum'			=> array( 'view', 'edit' )
					)
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'args'                => array(
					'force'    => array(
						'default'     => false,
						'description' => __( 'Required to be true, as resource does not support trashing.' ),
					),
					'delete_all' => array(
						'default'	  => false,
						'description' => __( 'Whether to delete matching metadata entries for all objects, ignoring the specified object_id.')
					)
				),
			),

			'schema' => array( $this, 'get_public_item_schema' ),
		), true );
	}

	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_update_item_schema() {

		$registered_meta_key_data = $this->get_object_registered_meta_keys();

		$meta_key_properties = array();

		/*
		 * Base properties for every meta key.
		 * List each one with its registered data.
		 */
		foreach( $registered_meta_key_data as $key => $registered_data ){
			
			$description = ( ! empty( $registered_data['description'] ) ) ? $registered_data['description'] : 'Description of the meta key';
			$meta_key_data = array(
				'id'	=> $key,
				'type'	=> 'object',
				'properties'	=> array(
						'key'	=> array( 'type' => 'string' ),
						'value'	=> array( 'type' => sanitize_text_field( $registered_data['type'] ) ),
						'description'	=> sanitize_text_field( $registered_data['description'] ),
				)
			);
			$meta_key_properties[$key] = $meta_key_data;
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'meta',
			'type'       => 'object',
			'properties' => $meta_key_properties,
		);
		return $this->add_additional_fields_schema( $schema ); // @todo see what happens here. If it's bad things, then use $this->get_public_item_schema
	}

	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_item_schema() {

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'meta',
			'type'       => 'object',
			/*
			 * Base properties for every Post
			 */
			'properties' => array(
				'key' => array(
					'description' => __( 'The key for the custom field.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'value' => array(
					'description' => __( 'The value of the custom field.' ),
					'context'     => array( 'view', 'edit' ),
					'required'	  => true,
					'type'		  => 'meta_value' // there has to be a type here
				),
				'register_meta_data' => array(
					'description'	=> __( 'Used to register the new meta key.' ),
					'type'			=> 'array',
					'context'		=> array( 'view', 'edit' ),
					'required'		=> true,
					'arg_options'	=> array( 'sanitize_callback' => array( $this, 'sanitize_register_meta_callback' ) ),
					'properties'	=> array(
						'data_type'	=> array(
							'description'	=> __( 'Used to sanitize and validate the meta value.' ),
							'context'		=> array( 'view', 'edit' ),
							'required'		=> false
						),
						'sanitize_callback'	=> array(
							'description' 		=> __( 'Custom sanitization callback.' ), // @todo use WP functions? how does this work?
							'context'			=> array( 'view', 'edit' ),
							'type'				=> 'string',
							'required'			=> false
						),
						'auth_callback'		=> array(
							'description'		=> __( 'Custom authorization callback.' ), // @todo again, use WP functions? how does this work?
							'context'			=> array( 'view', 'edit' ),
							'type'				=> 'string',
							'required'			=> false
						),
						'description'	=> array(
							'description'	=> __( 'The description of the meta key.' ),
							'context'		=> array( 'view', 'edit' ),
							'type'			=> 'string',
							'required'		=> false
						),
					)
				)
			),
		);
		return $this->add_additional_fields_schema( $schema ); // @todo see what happens here. If it's bad things, then use $this->get_public_item_schema
	}

	public function sanitize_register_meta_callback( $value ){

		$args = array();

		foreach( $value as $arg_key => $arg_value ){
			if( ! is_array( $arg_value ) ){
				$args[ $arg_key ] = sanitize_text_field( $arg_value );
			}
			elseif( 'show_in_rest' === $arg_key ){
				$args[ $arg_key ] = (bool)$arg_value;
			}
			else{
				$args[ $arg_key ] = $this->sanitize_register_meta_callback( $arg_value );
			}
		}

		return $args;
	}

	public function get_object_registered_meta_keys(){
		return get_registered_meta_keys( $this->parent_type );
	}

	/**
	 * Retrieve custom fields for object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Request|WP_Error List of meta object data on success, WP_Error otherwise
	 */
	public function get_items( $request ) {

		$parent_id = (int) $request['parent_id'];
		$parent = $this->get_parent_object( $parent_id );

		$parent_type = $this->parent_post_type;

		if( is_wp_error( $parent) ){
			return $parent;
		}

		// currently using this patch https://core.trac.wordpress.org/attachment/ticket/35658/35658.diff
		
		$registered_keys = get_registered_meta_keys( $this->parent_type, $this->parent_post_type );

		$meta = array();

		foreach ( $registered_keys as $key => $registered_key_data ) {

			// get the meta data of the meta key here
			
			$single = ( !empty( $registered_key_data['single'] ) ) ? $registered_key_data['single'] : false;
			$meta_value = get_metadata( $this->parent_type, $parent_id, $key, $single );

			// let's put it all together
			$meta_data_array = array( 
				'meta_key' => $key,
				'parent_id' => $parent_id,
				'meta_value' => $meta_value, // @todo think this through about single and arrays and serialized data
				'registered_key_data' => $registered_key_data,
				'is_raw' => true, // have we checked for serialized data?
			);

			// if this not the edit context and the meta isn't registered to show in rest then error
			if( 'edit' !== $request['context'] && ( empty( $registered_key_data ) || empty( $registered_key_data['show_in_rest'] ) ) ){
				continue;
			}

			$authorization_callback = ( !empty( $registered_key_data[ 'authorization_callback' ] ) && is_callable( $registered_key_data[ 'authorization_callback' ] ) ) ? call_user_func( $registered_key_data[ 'authorization_callback' ] ) : $this->check_update_permission( $meta_data_array );

			if( 'edit' === $request['context']  && ! $authorization_callback ){
				return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to view the post meta in this context.' ), array( 'status' => rest_authorization_required_code() ) );
			}
	
			if( ! $this->check_read_permission( $meta_data_array ) ){
				continue;
			} 

			// hmmm, not sure about forcing an array here but I want it to be an array
			$response = $this->prepare_item_for_response( $meta_data_array, $request, true );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$meta[] = $this->prepare_response_for_collection( $response );
		}

		return rest_ensure_response( $meta );
	}

	/**
	 * Retrieve custom field object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Request|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function get_item( $request ) {

		$key = (string) $request['key'];
		$parent_id = (int) $request['parent_id'];

		$registered_key_data = get_registered_meta_keys( $this->parent_type, $this->parent_post_type );

		if( empty( $registered_key_data[ $key ]) ){
			return new WP_Error( 'rest_meta_invalid_key', __( 'Invalid meta key.' ), array( 'status' => 404 ) );
		}

		$single = ( !empty( $registered_key_data['single'] ) ) ? $registered_key_data['single'] : false;
		$meta_value = get_metadata( $this->parent_type, $parent_id, $key, $single );

		// let's put it all together
		$meta_data_array = array( 
			'meta_key' => $key,
			'parent_id' => $parent_id,
			'meta_value' => $meta_value, // @todo think this through about single and arrays and serialized data
			'registered_key_data' => $registered_key_data[ $key ],
			'is_raw' => true, // have we checked for serialized data?
		);

		// if this not the edit context and the meta isn't registered to show in rest then error
		if( 'edit' !== $request['context'] && ( empty( $registered_key_data[$key] ) || empty( $registered_key_data[$key]['show_in_rest'] ) ) ){
			return new WP_Error( 'rest_meta_not_shown', __( 'This meta is not public.' ), array( 'status' => 404 ) );
		}

		$authorization_callback = ( !empty( $registered_key_data[ 'authorization_callback' ] ) && is_callable( $registered_key_data[$key][ 'authorization_callback' ] ) ) ? call_user_func( $registered_key_data[ 'authorization_callback' ] ) : $this->check_update_permission( $meta_data_array );

		if( 'edit' === $request['context']  && ! $authorization_callback ){
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to view the post meta in this context.' ), array( 'status' => rest_authorization_required_code() ) );
		}
	
		if( ! $this->check_read_permission( $meta_data_array ) ){
			return new WP_Error( 'rest_meta_cannot_read', __( 'You do not have permission to read this meta.' ), array( 'status' => 404 ) );
		} 
		
		return $this->prepare_item_for_response( $meta_data_array, $request );
	}

	/**
	 * Prepares meta data for return as an object.
	 *
	 * @param stdClass $data Metadata row from database
	 * @param WP_REST_Request $request
	 * @param boolean $is_raw Is the value field still serialized? (False indicates the value has been unserialized)
	 * @return WP_REST_Response|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function prepare_item_for_response( $data, $request, $is_raw = false ) {

		$key       	= $data['meta_key'];
		$value     	= $data['meta_value'];
		$parent_id 	= $data['parent_id'];
		$type 		= $data['registered_key_data']['type'];
		$description	= $data['registered_key_data']['description'];

		$meta = array(
					'key'   => $key,
					'value' => $value,
					'type' 	=> $type,
					'description' => $description
				);

		$response = rest_ensure_response( $meta );

		$response->add_link( 'self', rest_url( $this->namespace . '/' . $this->parent_base . '/' . $parent_id . '/' . $key ) );
		$response->add_link( 'collection', rest_url( $this->namespace . '/' . $this->parent_base . '/' . $parent_id . '/' . 'meta' ) );
		$response->add_link( 'about', rest_url( $this->namespace . '/' . $this->parent_base . '/' . $parent_id ), array( 'embeddable' => true ) );

		/**
		 * Filter a meta value returned from the API.
		 *
		 * Allows modification of the meta value right before it is returned.
		 *
		 * @param array           $response Key value array of meta data: id, key, value.
		 * @param WP_REST_Request $request  Request used to generate the response.
		 */
		return apply_filters( 'rest_prepare_meta_value', $response, $request );
	}

	/**
	 * Add meta to an object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$parent_id = (int) $request['parent_id'];
		$mid = (int) $request['id'];

		//$parent_column = $this->get_parent_column();
		$current = get_metadata_by_mid( $this->parent_type, $mid );

		if ( empty( $current ) ) {
			return new WP_Error( 'rest_meta_invalid_id', __( 'Invalid meta id.' ), array( 'status' => 404 ) );
		}

		if ( absint( $current->$parent_column ) !== $parent_id ) {
			return new WP_Error( 'rest_meta_' . $this->parent_type . '_mismatch', __( 'Meta does not belong to this object' ), array( 'status' => 400 ) );
		}

		if ( ! isset( $request['key'] ) && ! isset( $request['value'] ) ) {
			return new WP_Error( 'rest_meta_data_invalid', __( 'Invalid meta parameters.' ), array( 'status' => 400 ) );
		}
		if ( isset( $request['key'] ) ) {
			$key = $request['key'];
		} else {
			$key = $current->meta_key;
		}

		if ( isset( $request['value'] ) ) {
			$value = $request['value'];
		} else {
			$value = $current->meta_value;
		}

		if ( ! $key ) {
			return new WP_Error( 'rest_meta_invalid_key', __( 'Invalid meta key.' ), array( 'status' => 400 ) );
		}

		// for now let's not allow updating of arrays, objects or serialized values.
		if ( ! $this->is_valid_meta_data( $current->meta_value ) ) {
			$code = ( $this->parent_type === 'post' ) ? 'rest_post_invalid_action' : 'rest_meta_invalid_action';
			return new WP_Error( $code, __( 'Invalid existing meta data for action.' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_valid_meta_data( $value ) ) {
			$code = ( $this->parent_type === 'post' ) ? 'rest_post_invalid_action' : 'rest_meta_invalid_action';
			return new WP_Error( $code, __( 'Invalid provided meta data for action.' ), array( 'status' => 400 ) );
		}

		if ( is_protected_meta( $current->meta_key ) ) {
			return new WP_Error( 'rest_meta_protected', sprintf( __( '%s is marked as a protected field.' ), $current->meta_key ), array( 'status' => 403 ) );
		}

		if ( is_protected_meta( $key ) ) {
			return new WP_Error( 'rest_meta_protected', sprintf( __( '%s is marked as a protected field.' ), $key ), array( 'status' => 403 ) );
		}

		// update_metadata_by_mid will return false if these are equal, so check
		// first and pass through
		if ( (string) $value === $current->meta_value && (string) $key === $current->meta_key ) {
			return $this->get_item( $request );
		}

		if ( ! update_metadata_by_mid( $this->parent_type, $mid, $value, $key ) ) {
			return new WP_Error( 'rest_meta_could_not_update', __( 'Could not update meta.' ), array( 'status' => 500 ) );
		}

		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( array(
			'context'   => 'edit',
			'parent_id' => $parent_id,
			'id'        => $mid,
		) );
		$response = $this->get_item( $request );

		/**
		 * Fires after meta is added to an object or updated via the REST API.
		 *
		 * @param array           $value    The inserted meta data.
		 * @param WP_REST_Request $request  The request sent to the API.
		 * @param boolean         $creating True when adding meta, false when updating.
		 */
		do_action( 'rest_insert_meta', $value, $request, false );

		return rest_ensure_response( $response );
	}

	/**
	 * Add meta to an object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {

		$this->prepare_item_for_database( $request );

		$parent_id = (int) $request['parent_id'];

		if ( empty( $request['key'] ) ) {
			return new WP_Error( 'rest_meta_invalid_key', __( 'Invalid meta key.' ), array( 'status' => 400 ) );
		}

		$meta_key = wp_slash( $request['key'] );
		$value    = wp_slash( $request['value'] );

		$mid = add_metadata( $this->parent_type, $parent_id, $meta_key, $value );
		if ( ! $mid ) {
			return new WP_Error( 'rest_meta_could_not_add', __( 'Could not add meta.' ), array( 'status' => 400 ) );
		}

		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( array(
			'context'   => 'edit',
			'parent_id' => $parent_id,
			'id'        => $mid,
		) );
		$response = rest_ensure_response( $this->get_item( $request ) );

		//error_log( print_r( $response, true ), 0 );
		$response->set_status( 201 );
		$data = $response->get_data();
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->parent_base . '/' . $parent_id . '/meta/' . $data['id'] ) );

		/* This action is documented in lib/endpoints/class-wp-rest-meta-controller.php */
		do_action( 'rest_insert_meta', $data, $request, true );

		return $response;
	}

	/**
	 * Delete meta from an object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error Message on success, WP_Error otherwise
	 */
	public function delete_item( $request ) {
		$parent_id = (int) $request['parent_id'];
		$mid = (int) $request['id'];
		$force = isset( $request['force'] ) ? (bool) $request['force'] : false;

		// We don't support trashing for this type, error out
		if ( ! $force ) {
			return new WP_Error( 'rest_trash_not_supported', __( 'Meta does not support trashing.' ), array( 'status' => 501 ) );
		}

		$parent_column = 10770;
		$current = get_metadata_by_mid( $this->parent_type, $mid );

		if ( empty( $current ) ) {
			return new WP_Error( 'rest_meta_invalid_id', __( 'Invalid meta id.' ), array( 'status' => 404 ) );
		}

		if ( absint( $current->$parent_column ) !== (int) $parent_id ) {
			return new WP_Error( 'rest_meta_' . $this->parent_type . '_mismatch', __( 'Meta does not belong to this object' ), array( 'status' => 400 ) );
		}

		// for now let's not allow updating of arrays, objects or serialized values.
		if ( ! $this->is_valid_meta_data( $current->meta_value ) ) {
			$code = ( $this->parent_type === 'post' ) ? 'rest_post_invalid_action' : 'rest_meta_invalid_action';
			return new WP_Error( $code, __( 'Invalid existing meta data for action.' ), array( 'status' => 400 ) );
		}

		if ( is_protected_meta( $current->meta_key ) ) {
			return new WP_Error( 'rest_meta_protected', sprintf( __( '%s is marked as a protected field.' ), $current->meta_key ), array( 'status' => 403 ) );
		}

		if ( ! delete_metadata_by_mid( $this->parent_type, $mid ) ) {
			return new WP_Error( 'rest_meta_could_not_delete', __( 'Could not delete meta.' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a meta value is deleted via the REST API.
		 *
		 * @param WP_REST_Request $request The request sent to the API.
		 */
		do_action( 'rest_delete_meta', $request );

		return rest_ensure_response( array( 'message' => __( 'Deleted meta' ) ) );
	}

	/**
	 * Prepare a single meta for create or update.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|object $prepared_post Post object.
	 */
	protected function prepare_item_for_database( $request ){
		//error_log( print_r( $request, true ), 0 );
		//
		
		$schema->get_item_schema();

		// the meta value has not yet been sanitized. Let's do that now.
		
		if( ! empty( $schema['properties']['key'] ) && isset( $request['key'] ) ){
			$meta_key = esc_attr( $request['key'] ); // 
		}
		else{
			return new WP_Error( 'rest_meta_no_key', __( 'You must have a meta key.' ), array( 'status' => 400 ) );
		}

		if( ! empty( $schema['properties']['register_meta_data'] ) && isset( $request['register_meta_data'] ) && is_array( $request['register_meta_data'] ) ){
			$register_meta_data = $request['register_meta_data']; // this has already been sanitized, yes? It's not going in the database, it's being used to sanitize the value
		}
		else{
			return new WP_Error( 'rest_meta_no_register_meta_data', __( 'You must have registered meta data for this key.' ), array( 'status' => 400 ) );
		}
		
		if( ! empty( $schema['properties']['key'] ) && isset( $request['key'] ) ){
			$meta_key = esc_attr( $request['key'] ); // 
		}
		else{
			return new WP_Error( 'rest_meta_no_key', __( 'You must have a meta key.' ), array( 'status' => 400 ) );
		}
		
	}

	/**
	 * Check if we can read the meta. This does not account for context, so show everything that can possibly be shown, I think.
	 *
	 * @param string $key The meta key.
	 * @param array $value The registered key data array.
	 * @return boolean Can we read it?
	 */
	public function check_read_permission( $meta_data_array ){

		$parent = get_post( $meta_data_array['parent_id'] );

		$meta_key = $meta_data_array['meta_key'];
		$meta_value_array = $meta_data_array['meta_value'];
		$registered_key_data = $meta_data_array['registered_key_data'];
		$is_raw = $meta_data_array['is_raw'];

		// if you can't read the parent object, you can't read this
		if( empty( $meta_value_array ) || empty( $meta_key ) || empty( $parent ) || ! $this->parent_controller->check_read_permission( $parent ) ){
			return false;
		}

		foreach( $meta_value_array as $meta_value ){
			// For now, no serialized data 
			// Normalize serialized strings
			if ( $is_raw && is_serialized_string( $meta_value) ) {
				//$meta_value = unserialize( $meta_value);
			}
	
			// Don't expose serialized data
			if ( is_serialized( $meta_value) || ! is_string( $meta_value) ) {

				//return false;
			}
		}

		// Don't show protected meta unless it's been explicitly registered to show_in_rest
		if( empty( $registered_key_data['show_in_rest'] ) && is_protected_meta( $meta_key ) ){
			return false;
		}

		return true;
	}

	protected function check_update_permission( $meta_data_array ){
		

	}

	/**
	 * Get the query params for collections
	 * see register_routes for when this is used
	 *
	 * @return array
	 */
	public function get_collection_params() {
		
		$params = parent::get_collection_params();
		$params['context']['default'] = 'view';
		$params['context']['enum'] = array( 'view', 'edit' );
		return $params;
	}
}
