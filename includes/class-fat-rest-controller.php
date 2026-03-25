<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_REST_Controller {
    protected $tool_runner;

    public function __construct( FAT_Tool_Runner $tool_runner ) {
        $this->tool_runner = $tool_runner;
    }

    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            'fabled-ai-tools/v1',
            '/run',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'run_tool' ),
                'permission_callback' => array( $this, 'can_run_tools' ),
                'args'                => array(
                    'tool_id' => array(
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'inputs'  => array(
                        'required' => true,
                    ),
                ),
            )
        );

        register_rest_route(
            'fabled-ai-tools/v1',
            '/apply',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'apply_outputs' ),
                'permission_callback' => array( $this, 'can_run_tools' ),
                'args'                => array(
                    'tool_id'     => array(
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'target_type' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'target_id'   => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                    'post_id'     => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                    'attachment_id' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                    'image_attachment_id' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                    'outputs'     => array(
                        'required' => true,
                    ),
                    'apply_fields' => array(
                        'required' => true,
                    ),
                ),
            )
        );
    }

    public function can_run_tools() {
        return current_user_can( 'fat_run_ai_tools' ) || current_user_can( 'fat_manage_tools' ) || current_user_can( 'manage_options' );
    }

    public function run_tool( WP_REST_Request $request ) {
        $tool_id = absint( $request->get_param( 'tool_id' ) );
        $inputs  = $request->get_param( 'inputs' );
        $inputs  = is_array( $inputs ) ? $inputs : array();

        $result = $this->tool_runner->execute( $tool_id, $inputs, get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            $status = 400;
            $data   = $result->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) ) {
                $status = absint( $data['status'] );
            }

            return new WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'details' => is_array( $data ) ? $data : array(),
                ),
                $status
            );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $result,
            )
        );
    }

    public function apply_outputs( WP_REST_Request $request ) {
        $tool_id      = absint( $request->get_param( 'tool_id' ) );
        $target_type  = sanitize_key( $request->get_param( 'target_type' ) );
        $target_id    = absint( $request->get_param( 'target_id' ) );
        $post_id      = absint( $request->get_param( 'post_id' ) );
        $attachment_id = absint( $request->get_param( 'attachment_id' ) );
        $image_attachment_id = absint( $request->get_param( 'image_attachment_id' ) );
        $outputs      = $request->get_param( 'outputs' );
        $apply_fields = $request->get_param( 'apply_fields' );

        if ( $target_id <= 0 && $post_id > 0 ) {
            $target_id   = $post_id;
            $target_type = 'post';
        }
        if ( $target_id <= 0 && $attachment_id > 0 ) {
            $target_id   = $attachment_id;
            $target_type = 'attachment';
        }

        $outputs      = is_array( $outputs ) ? $outputs : array();
        $apply_fields = is_array( $apply_fields ) ? $apply_fields : array();
        if ( $image_attachment_id > 0 ) {
            $outputs['__fat_featured_attachment_id'] = (string) $image_attachment_id;
        }

        $result = $this->tool_runner->apply_generated_outputs(
            $tool_id,
            $target_type,
            $target_id,
            $outputs,
            $apply_fields,
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            $status = 400;
            $data   = $result->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) ) {
                $status = absint( $data['status'] );
            }

            return new WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'details' => is_array( $data ) ? $data : array(),
                ),
                $status
            );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $result,
            )
        );
    }
}
