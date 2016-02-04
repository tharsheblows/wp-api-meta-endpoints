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
		) );
		register_rest_route( $this->namespace, '/' . $this->parent_base . '/(?P<parent_id>[\d]+)/' . $this->rest_base . '/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'context'          => $this->get_context_param( array( 'default' => 'view' ) ),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( false ),
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
		) );
	}

	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_item_schema() {

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

	public function get_object_registered_meta_keys(){
		return get_registered_meta_keys( $this->parent_type );
	}

	/**
	 * Get an array of endpoint arguments from the item schema for the controller.
	 *
	 * @param string $method HTTP method of the request. The arguments
	 *                       for `CREATABLE` requests are checked for required
	 *                       values and may fall-back to a given default, this
	 *                       is not done on `EDITABLE` requests. Default is
	 *                       WP_REST_Server::CREATABLE.
	 * @return array $endpoint_args
	 */
	public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {

		$schema                = $this->get_item_schema();

		$schema_properties     = ! empty( $schema['properties'] ) ? $schema['properties'] : array();
		$endpoint_args = array();

		foreach ( $schema_properties as $key => $params ) {

			if( empty( $param_props = $params['properties'] ) ){
				continue;
			}
			// Arguments specified as `readonly` are not allowed to be set.
			if ( ! empty( $param_props['readonly'] ) ) {
				continue;
			}

			$endpoint_args[ $key ] = array(
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			);

			if ( WP_REST_Server::CREATABLE === $method && isset( $param_props['default'] ) ) {
				$endpoint_args[ $key ]['default'] = $param_props['default'];
			}

			if ( WP_REST_Server::CREATABLE === $method && ! empty( $param_props['required'] ) ) {
				$endpoint_args[ $key ]['required'] = true;
			}

			foreach( array( 'key', 'value' ) as $schema_prop ) {
				$endpoint_args[ $key ][ $schema_prop ] = ( isset( $schema_prop ) ) ? array( 'type' => $param_props[ $schema_prop ][ 'type' ] ) : 'string';
			}

			$endpoint_args[$key]['test'] = 'yay';

			if ( isset( $param_props[ 'description' ] ) ) {
				$endpoint_args[ $key ][ 'description' ] = $param_props[ 'description' ];
			}

			// Merge in any options provided by the schema property.
			if ( isset( $param_props['arg_options'] ) ) {

				// Only use required / default from arg_options on CREATABLE endpoints.
				if ( WP_REST_Server::CREATABLE !== $method ) {
					$param_props['arg_options'] = array_diff_key( $param_props['arg_options'], array( 'required' => '', 'default' => '' ) );
				}

				$endpoint_args[ $key ] = array_merge( $endpoint_args[ $key ], $param_props['arg_options'] );
			}
		}

		return $endpoint_args;
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

			$meta[$key] = $this->prepare_response_for_collection( $response );
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

		$parent_id = (int) $request['parent_id'];
		$mid = (int) $request['id'];

		$parent_column = $this->get_parent_column();
		$meta = get_metadata_by_mid( $this->parent_type, $mid );

		if ( empty( $meta ) ) {
			return new WP_Error( 'rest_meta_invalid_id', __( 'Invalid meta id.' ), array( 'status' => 404 ) );
		}

		if ( absint( $meta->$parent_column ) !== $parent_id ) {
			return new WP_Error( 'rest_meta_' . $this->parent_type . '_mismatch', __( 'Meta does not belong to this object' ), array( 'status' => 400 ) );
		}

		return $this->prepare_item_for_response( $meta, $request );
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

		$parent_column = $this->get_parent_column();
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
	 * Check if the data provided is valid data.
	 *
	 * Excludes serialized data from being sent via the API.
	 *
	 * @see https://github.com/WP-API/WP-API/pull/68
	 * @param mixed $data Data to be checked
	 * @return boolean Whether the data is valid or not
	 */
	protected function is_valid_meta_data( $data ) {
		if ( is_array( $data ) || is_object( $data ) || is_serialized( $data ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add meta to an object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$parent_id = (int) $request['parent_id'];

		if ( ! $this->is_valid_meta_data( $request['value'] ) ) {
			$code = ( $this->parent_type === 'post' ) ? 'rest_post_invalid_action' : 'rest_meta_invalid_action';

			// for now let's not allow updating of arrays, objects or serialized values.
			return new WP_Error( $code, __( 'Invalid provided meta data for action.' ), array( 'status' => 400 ) );
		}

		if ( empty( $request['key'] ) ) {
			return new WP_Error( 'rest_meta_invalid_key', __( 'Invalid meta key.' ), array( 'status' => 400 ) );
		}

		if ( is_protected_meta( $request['key'] ) ) {
			return new WP_Error( 'rest_meta_protected', sprintf( __( '%s is marked as a protected field.' ), $request['key'] ), array( 'status' => 403 ) );
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

		$parent_column = $this->get_parent_column();
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

		$registered_meta_keys = $this->get_object_registered_meta_keys();

		foreach ( $registered_meta_keys as $key => $registered_key_data ) {

			$params[ $key ][ 'key' ][ 'type' ] = 'string';
			$params[ $key ][ 'value' ][ 'type' ] = ( !empty( $registered_key_data['type'] ) ) ? $registered_key_data['type'] : 'string';

			if ( isset( $registered_key_data[ 'description' ] ) ) {
				$params[ $key ][ 'description' ] = $registered_key_data[ 'description' ];
			}

		}

		error_log( print_r( $params, true ), 0 ); // it would be nice to have key and value in the 
		return $params;
	}
}
