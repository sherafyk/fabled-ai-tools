<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Tool_Runner {
    protected $tools_repo;
    protected $runs_repo;
    protected $settings;
    protected $prompt_engine;
    protected $client;
    protected $usage_limiter;
    protected $featured_image_generator;
    protected $uploaded_image_processor;
    protected $attachment_metadata_assistant;
    protected $context_service;
    protected $apply_service;

    public function __construct( FAT_Tools_Repository $tools_repo, FAT_Runs_Repository $runs_repo, FAT_Settings $settings, FAT_Prompt_Engine $prompt_engine, FAT_OpenAI_Client $client, FAT_Usage_Limiter $usage_limiter, FAT_Featured_Image_Generator $featured_image_generator, FAT_Uploaded_Image_Processor $uploaded_image_processor, FAT_Attachment_Metadata_Assistant $attachment_metadata_assistant, FAT_Runner_Context_Service $context_service = null, FAT_Runner_Apply_Service $apply_service = null ) {
        $this->tools_repo     = $tools_repo;
        $this->runs_repo      = $runs_repo;
        $this->settings       = $settings;
        $this->prompt_engine  = $prompt_engine;
        $this->client         = $client;
        $this->usage_limiter  = $usage_limiter;
        $this->featured_image_generator = $featured_image_generator;
        $this->uploaded_image_processor = $uploaded_image_processor;
        $this->attachment_metadata_assistant = $attachment_metadata_assistant;
        $this->context_service = $context_service ? $context_service : new FAT_Runner_Context_Service();
        $this->apply_service   = $apply_service ? $apply_service : new FAT_Runner_Apply_Service( $this->context_service, array( $this, 'log_run' ) );
    }

    public function get_accessible_tools_for_user( $user ) {
        $user  = $this->normalize_user( $user );
        $tools = $this->tools_repo->get_all( array( 'active_only' => true ) );
        $list  = array();

        foreach ( $tools as $tool ) {
            if ( $this->user_can_access_tool( $tool, $user ) ) {
                $list[] = $tool;
            }
        }

        return $list;
    }

    public function user_can_access_tool( $tool, $user = null ) {
        $user = $this->normalize_user( $user );
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        if ( user_can( $user, 'manage_options' ) || user_can( $user, 'fat_manage_tools' ) ) {
            return true;
        }

        if ( empty( $tool['is_active'] ) ) {
            return false;
        }

        if ( ! user_can( $user, 'fat_run_ai_tools' ) ) {
            return false;
        }

        $allowed_roles = (array) FAT_Helpers::array_get( $tool, 'allowed_roles', array() );
        $allowed_caps  = (array) FAT_Helpers::array_get( $tool, 'allowed_capabilities', array() );

        if ( empty( $allowed_roles ) && empty( $allowed_caps ) ) {
            return true;
        }

        if ( ! empty( $allowed_roles ) ) {
            foreach ( (array) $user->roles as $role ) {
                if ( in_array( $role, $allowed_roles, true ) ) {
                    return true;
                }
            }
        }

        if ( ! empty( $allowed_caps ) ) {
            foreach ( $allowed_caps as $cap ) {
                if ( user_can( $user, $cap ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function execute( $tool_id, $raw_inputs, $user = null ) {
        $user    = $this->normalize_user( $user );
        $tool_id = absint( $tool_id );
        $tool    = $this->tools_repo->get_by_id( $tool_id );

        if ( ! $tool ) {
            return new WP_Error( 'fat_tool_not_found', __( 'Tool not found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( empty( $tool['is_active'] ) ) {
            return new WP_Error( 'fat_tool_inactive', __( 'This tool is currently inactive.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        if ( ! $this->user_can_access_tool( $tool, $user ) ) {
            return new WP_Error( 'fat_tool_forbidden', __( 'You are not allowed to run this tool.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        if ( ! $this->settings->is_execution_enabled() ) {
            return new WP_Error( 'fat_execution_paused', __( 'Fabled AI Tools is currently paused by an administrator.', 'fabled-ai-tools' ), array( 'status' => 503 ) );
        }

        $workflow = $this->tool_workflow_type( $tool );

        $resolved_inputs = $this->resolve_contextual_inputs( $tool, $raw_inputs, $user );
        if ( is_wp_error( $resolved_inputs ) ) {
            return $resolved_inputs;
        }

        $resolved_inputs = (array) apply_filters( 'fat_pre_run_inputs', $resolved_inputs, $tool, $user, $raw_inputs );

        $can_run = $this->usage_limiter->assert_can_run( $tool, $user->ID );
        if ( is_wp_error( $can_run ) ) {
            $can_run->add_data( array( 'status' => 429 ) );
            return $can_run;
        }

        if ( 'featured_image_generator' === $workflow ) {
            return $this->execute_featured_image_workflow( $tool, $resolved_inputs, $user );
        }

        if ( 'uploaded_image_processor' === $workflow ) {
            return $this->execute_uploaded_image_workflow( $tool, $resolved_inputs, $user );
        }

        if ( 'attachment_metadata_assistant' === $workflow ) {
            return $this->execute_attachment_metadata_workflow( $tool, $resolved_inputs, $user );
        }

        $validated = $this->prompt_engine->sanitize_runtime_inputs( $tool, $resolved_inputs );
        if ( ! empty( $validated['errors'] ) ) {
            return new WP_Error( 'fat_invalid_inputs', implode( ' ', $validated['errors'] ), array( 'status' => 400, 'errors' => $validated['errors'] ) );
        }

        $inputs        = $validated['inputs'];
        $request_start = microtime( true );
        $user_prompt   = $this->prompt_engine->render_user_prompt( FAT_Helpers::array_get( $tool, 'user_prompt_template', '' ), $inputs );
        $json_schema   = $this->prompt_engine->build_json_schema_for_outputs( $tool );
        $model         = FAT_Helpers::array_get( $tool, 'model', '' );
        $model         = '' !== $model ? $model : $this->settings->get( 'default_model', 'gpt-5.4-mini' );
        $timeout       = absint( $this->settings->get( 'default_timeout', 45 ) );

        $request_preview = FAT_Helpers::build_preview_from_inputs( $inputs );
        $request_log     = array(
            'model'             => $model,
            'system_prompt'     => FAT_Helpers::array_get( $tool, 'system_prompt', '' ),
            'user_prompt'       => $user_prompt,
            'max_output_tokens' => absint( FAT_Helpers::array_get( $tool, 'max_output_tokens', 700 ) ),
            'json_schema'       => $json_schema,
            'tool_id'           => $tool_id,
            'tool_slug'         => FAT_Helpers::array_get( $tool, 'slug', '' ),
            'inputs'            => $inputs,
        );

        $response = $this->client->generate_structured_response(
            array(
                'model'             => $model,
                'system_prompt'     => FAT_Helpers::array_get( $tool, 'system_prompt', '' ),
                'user_prompt'       => $user_prompt,
                'json_schema'       => $json_schema,
                'max_output_tokens' => absint( FAT_Helpers::array_get( $tool, 'max_output_tokens', 700 ) ),
                'timeout'           => $timeout,
                'format_name'       => 'fat_' . FAT_Helpers::array_get( $tool, 'slug', 'tool_response' ),
            )
        );

        $latency_ms = (int) round( ( microtime( true ) - $request_start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $this->log_run(
                $tool,
                $user,
                array(
                    'status'          => 'error',
                    'request_preview' => $request_preview,
                    'request_payload' => ! empty( $tool['log_inputs'] ) ? $request_log : null,
                    'response_payload'=> ! empty( $tool['log_outputs'] ) ? $response->get_error_data() : null,
                    'error_message'   => $response->get_error_message(),
                    'model_used'      => $model,
                    'latency_ms'      => $latency_ms,
                )
            );

            return $response;
        }

        $usage = FAT_Helpers::array_get( $response, 'usage', array() );

        $validated_outputs = $this->prompt_engine->validate_runtime_outputs( $tool, FAT_Helpers::array_get( $response, 'parsed', array() ) );
        if ( ! empty( $validated_outputs['errors'] ) ) {
            $error_message = implode( ' ', $validated_outputs['errors'] );

            $this->log_run(
                $tool,
                $user,
                array(
                    'status'          => 'error',
                    'request_preview' => $request_preview,
                    'request_payload' => ! empty( $tool['log_inputs'] ) ? $request_log : null,
                    'response_payload'=> ! empty( $tool['log_outputs'] ) ? FAT_Helpers::array_get( $response, 'raw_response', null ) : null,
                    'error_message'   => $error_message,
                    'model_used'      => FAT_Helpers::array_get( $response, 'model', $model ),
                    'latency_ms'      => $latency_ms,
                    'openai_request_id' => FAT_Helpers::array_get( $response, 'request_id', '' ),
                    'prompt_tokens'   => FAT_Helpers::array_get( $usage, 'input_tokens', null ),
                    'completion_tokens' => FAT_Helpers::array_get( $usage, 'output_tokens', null ),
                    'total_tokens'    => FAT_Helpers::array_get( $usage, 'total_tokens', null ),
                )
            );

            return new WP_Error( 'fat_invalid_outputs', $error_message, array( 'status' => 500 ) );
        }

        $outputs = $validated_outputs['outputs'];
        $outputs['__fat_openai_request_id'] = (string) FAT_Helpers::array_get( $response, 'request_id', '' );
        $outputs = (array) apply_filters( 'fat_post_run_outputs', $outputs, $tool, $user, $resolved_inputs, $response );

        $this->log_run(
            $tool,
            $user,
            array(
                'status'            => 'success',
                'request_preview'   => $request_preview,
                'response_preview'  => FAT_Helpers::build_preview_from_outputs( $outputs ),
                'request_payload'   => ! empty( $tool['log_inputs'] ) ? $request_log : null,
                'response_payload'  => ! empty( $tool['log_outputs'] ) ? FAT_Helpers::array_get( $response, 'raw_response', null ) : null,
                'error_message'     => '',
                'model_used'        => FAT_Helpers::array_get( $response, 'model', $model ),
                'prompt_tokens'     => FAT_Helpers::array_get( $usage, 'input_tokens', null ),
                'completion_tokens' => FAT_Helpers::array_get( $usage, 'output_tokens', null ),
                'total_tokens'      => FAT_Helpers::array_get( $usage, 'total_tokens', null ),
                'latency_ms'        => $latency_ms,
                'openai_request_id' => FAT_Helpers::array_get( $response, 'request_id', '' ),
            )
        );

        return array(
            'tool'    => array(
                'id'   => $tool['id'],
                'name' => $tool['name'],
                'slug' => $tool['slug'],
            ),
            'outputs' => $outputs,
            'meta'    => array(
                'model'      => FAT_Helpers::array_get( $response, 'model', $model ),
                'latency_ms' => $latency_ms,
                'usage'      => FAT_Helpers::array_get( $response, 'usage', array() ),
                'apply'      => $this->build_apply_runtime_meta( $tool, $raw_inputs, array_keys( $outputs ) ),
            ),
        );
    }

    public function apply_generated_outputs( $tool_id, $target_type, $target_id, $outputs, $apply_fields, $user = null ) {
        $user        = $this->normalize_user( $user );
        $tool_id     = absint( $tool_id );
        $target_id   = absint( $target_id );
        $target_type = sanitize_key( $target_type );
        $tool        = $this->tools_repo->get_by_id( $tool_id );

        if ( ! $tool ) {
            return new WP_Error( 'fat_tool_not_found', __( 'Tool not found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( ! $this->user_can_access_tool( $tool, $user ) ) {
            return new WP_Error( 'fat_tool_forbidden', __( 'You are not allowed to apply outputs for this tool.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        if ( ! $this->settings->is_execution_enabled() ) {
            return new WP_Error( 'fat_execution_paused', __( 'Fabled AI Tools is currently paused by an administrator.', 'fabled-ai-tools' ), array( 'status' => 503 ) );
        }

        $workflow = $this->tool_workflow_type( $tool );
        if ( in_array( $workflow, array( 'featured_image_generator', 'uploaded_image_processor' ), true ) ) {
            $attachment_id = absint( FAT_Helpers::array_get( $outputs, '__fat_featured_attachment_id', 0 ) );
            if ( $attachment_id <= 0 ) {
                $attachment_id = absint( FAT_Helpers::array_get( $outputs, 'attachment_id', 0 ) );
            }
            $apply_result = $this->featured_image_generator->apply_featured_image( $target_id, $attachment_id, $user );

            $this->log_apply_action(
                $tool,
                $user,
                'post',
                $target_id,
                array( 'featured_image' ),
                $outputs,
                $apply_result,
                array(
                    'workflow'      => $workflow,
                    'attachment_id' => $attachment_id,
                    'action'        => 'apply_featured_image',
                )
            );

            if ( ! is_wp_error( $apply_result ) ) {
                do_action( 'fat_apply_completed', $tool, $user, 'post', $target_id, $apply_result, array( 'featured_image' ) );
            }

            return $apply_result;
        }

        return $this->apply_service->apply_standard_outputs( $tool, $tool_id, $target_type, $target_id, $outputs, $apply_fields, $user );
    }

    protected function log_run( $tool, $user, $data ) {
        $record = wp_parse_args(
            $data,
            array(
                'tool_id'           => FAT_Helpers::array_get( $tool, 'id', 0 ),
                'user_id'           => $user->ID,
                'status'            => 'unknown',
                'request_preview'   => '',
                'response_preview'  => '',
                'request_payload'   => null,
                'response_payload'  => null,
                'error_message'     => '',
                'model_used'        => '',
                'prompt_tokens'     => null,
                'completion_tokens' => null,
                'total_tokens'      => null,
                'latency_ms'        => null,
                'openai_request_id' => '',
            )
        );

        $this->runs_repo->insert( $record );
    }

    protected function normalize_user( $user ) {
        if ( $user instanceof WP_User ) {
            return $user;
        }

        if ( is_numeric( $user ) && $user > 0 ) {
            return get_userdata( absint( $user ) );
        }

        return wp_get_current_user();
    }

    protected function resolve_contextual_inputs( $tool, $raw_inputs, $user = null ) {
        return $this->context_service->resolve_contextual_inputs( $tool, $raw_inputs, $user );
    }

    protected function resolve_inputs_from_selected_post( $tool, $raw_inputs, $user = null ) {
        return $this->context_service->resolve_inputs_from_selected_post( $tool, $raw_inputs, $user );
    }

    protected function resolve_inputs_from_selected_attachment( $tool, $raw_inputs, $user = null ) {
        return $this->context_service->resolve_inputs_from_selected_attachment( $tool, $raw_inputs, $user );
    }

    protected function build_apply_runtime_meta( $tool, $raw_inputs, $output_keys ) {
        return $this->apply_service->build_apply_runtime_meta( $tool, $raw_inputs, $output_keys );
    }

    protected function is_supported_content_target_post( $post ) {
        return $this->context_service->is_supported_content_target_post( $post );
    }

    protected function log_apply_action( $tool, $user, $target_type, $target_id, $apply_fields, $outputs, $apply_result, $extra = array() ) {
        $this->apply_service->log_apply_action( $tool, $user, $target_type, $target_id, $apply_fields, $outputs, $apply_result, $extra );
    }

    protected function execute_uploaded_image_workflow( $tool, $inputs, $user ) {
        $request_start = microtime( true );
        $result        = $this->uploaded_image_processor->execute( $tool, $inputs );
        $latency_ms    = (int) round( ( microtime( true ) - $request_start ) * 1000 );

        if ( is_wp_error( $result ) ) {
            $this->log_run(
                $tool,
                $user,
                array(
                    'status'          => 'error',
                    'request_preview' => FAT_Helpers::build_preview_from_inputs( $inputs ),
                    'request_payload' => ! empty( $tool['log_inputs'] ) ? array(
                        'file' => array(
                            'name' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'name', '' ),
                            'type' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'type', '' ),
                            'size' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'size', 0 ),
                            'error' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'error', null ),
                        ),
                        'workflow_config' => FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'workflow_config', array() ),
                    ) : null,
                    'response_payload'=> ! empty( $tool['log_outputs'] ) ? $result->get_error_data() : null,
                    'error_message'   => $result->get_error_message(),
                    'latency_ms'      => $latency_ms,
                )
            );

            return $result;
        }

        $outputs = array(
            'title'       => (string) FAT_Helpers::array_get( $result, 'title', '' ),
            'alt_text'    => (string) FAT_Helpers::array_get( $result, 'alt_text', '' ),
            'description' => (string) FAT_Helpers::array_get( $result, 'description', '' ),
            'attachment_id' => (string) FAT_Helpers::array_get( $result, 'attachment_id', 0 ),
            '__fat_featured_attachment_id' => (string) FAT_Helpers::array_get( $result, 'attachment_id', 0 ),
            '__fat_openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'metadata', '' ),
        );

        $this->log_run(
            $tool,
            $user,
            array(
                'status'            => 'success',
                'request_preview'   => FAT_Helpers::build_preview_from_inputs( $inputs ),
                'response_preview'  => FAT_Helpers::build_preview_from_outputs( $outputs ),
                'request_payload'   => ! empty( $tool['log_inputs'] ) ? array(
                    'file' => array(
                        'name' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'name', '' ),
                        'type' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'type', '' ),
                        'size' => FAT_Helpers::array_get( FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', array() ), 'size', 0 ),
                    ),
                    'workflow_config' => FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'workflow_config', array() ),
                ) : null,
                'response_payload'  => ! empty( $tool['log_outputs'] ) ? $result : null,
                'error_message'     => '',
                'prompt_tokens'     => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'input_tokens', null ),
                'completion_tokens' => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'output_tokens', null ),
                'total_tokens'      => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'total_tokens', null ),
                'latency_ms'        => $latency_ms,
                'openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'metadata', '' ),
            )
        );

        return array(
            'tool'    => array(
                'id'   => $tool['id'],
                'name' => $tool['name'],
                'slug' => $tool['slug'],
            ),
            'outputs' => $outputs,
            'meta'    => array(
                'workflow'   => 'uploaded_image_processor',
                'latency_ms' => $latency_ms,
                'usage'      => FAT_Helpers::array_get( $result, 'usage', array() ),
                'image'      => $result,
            ),
        );
    }

    protected function execute_featured_image_workflow( $tool, $inputs, $user ) {
        $request_start = microtime( true );
        $result        = $this->featured_image_generator->execute( $tool, $inputs );
        $latency_ms    = (int) round( ( microtime( true ) - $request_start ) * 1000 );

        if ( is_wp_error( $result ) ) {
            $this->log_run(
                $tool,
                $user,
                array(
                    'status'          => 'error',
                    'request_preview' => FAT_Helpers::build_preview_from_inputs( $inputs ),
                    'request_payload' => ! empty( $tool['log_inputs'] ) ? $inputs : null,
                    'response_payload'=> ! empty( $tool['log_outputs'] ) ? $result->get_error_data() : null,
                    'error_message'   => $result->get_error_message(),
                    'model_used'      => (string) FAT_Helpers::array_get( $result->get_error_data(), 'model', '' ),
                    'latency_ms'      => $latency_ms,
                )
            );

            return $result;
        }

        $outputs = array(
            'title'       => (string) FAT_Helpers::array_get( $result, 'title', '' ),
            'alt_text'    => (string) FAT_Helpers::array_get( $result, 'alt_text', '' ),
            'description' => (string) FAT_Helpers::array_get( $result, 'description', '' ),
            '__fat_featured_attachment_id' => (string) FAT_Helpers::array_get( $result, 'featured_attachment_id', 0 ),
            '__fat_openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'image', '' ),
        );

        $this->log_run(
            $tool,
            $user,
            array(
                'status'            => 'success',
                'request_preview'   => FAT_Helpers::build_preview_from_inputs( $inputs ),
                'response_preview'  => FAT_Helpers::build_preview_from_outputs( $outputs ),
                'request_payload'   => ! empty( $tool['log_inputs'] ) ? array(
                    'prompt' => FAT_Helpers::array_get( $result, 'prompt', '' ),
                    'model'  => FAT_Helpers::array_get( $result, 'image_model', '' ),
                    'quality' => FAT_Helpers::array_get( $result, 'image_quality', '' ),
                    'source_size' => FAT_Helpers::array_get( $result, 'source_size', '' ),
                ) : null,
                'response_payload'  => ! empty( $tool['log_outputs'] ) ? $result : null,
                'error_message'     => '',
                'model_used'        => (string) FAT_Helpers::array_get( $result, 'image_model', '' ),
                'prompt_tokens'     => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'input_tokens', null ),
                'completion_tokens' => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'output_tokens', null ),
                'total_tokens'      => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'total_tokens', null ),
                'latency_ms'        => $latency_ms,
                'openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'image', '' ),
            )
        );

        return array(
            'tool'    => array(
                'id'   => $tool['id'],
                'name' => $tool['name'],
                'slug' => $tool['slug'],
            ),
            'outputs' => $outputs,
            'meta'    => array(
                'workflow'   => 'featured_image_generator',
                'model'      => (string) FAT_Helpers::array_get( $result, 'image_model', '' ),
                'latency_ms' => $latency_ms,
                'usage'      => FAT_Helpers::array_get( $result, 'usage', array() ),
                'image'      => $result,
            ),
        );
    }

    protected function execute_attachment_metadata_workflow( $tool, $inputs, $user ) {
        $request_start = microtime( true );
        $result        = $this->attachment_metadata_assistant->execute( $tool, $inputs, $user );
        $latency_ms    = (int) round( ( microtime( true ) - $request_start ) * 1000 );

        if ( is_wp_error( $result ) ) {
            $this->log_run(
                $tool,
                $user,
                array(
                    'status'          => 'error',
                    'request_preview' => FAT_Helpers::build_preview_from_inputs( $inputs ),
                    'request_payload' => ! empty( $tool['log_inputs'] ) ? $inputs : null,
                    'response_payload'=> ! empty( $tool['log_outputs'] ) ? $result->get_error_data() : null,
                    'error_message'   => $result->get_error_message(),
                    'latency_ms'      => $latency_ms,
                )
            );

            return $result;
        }

        $outputs = array(
            'title'                 => (string) FAT_Helpers::array_get( $result, 'title', '' ),
            'alt_text'              => (string) FAT_Helpers::array_get( $result, 'alt_text', '' ),
            'description'           => (string) FAT_Helpers::array_get( $result, 'description', '' ),
            'attachment_id'         => (string) FAT_Helpers::array_get( $result, 'attachment_id', 0 ),
            '__fat_openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'metadata', '' ),
        );

        $this->log_run(
            $tool,
            $user,
            array(
                'status'            => 'success',
                'request_preview'   => FAT_Helpers::build_preview_from_inputs( $inputs ),
                'response_preview'  => FAT_Helpers::build_preview_from_outputs( $outputs ),
                'request_payload'   => ! empty( $tool['log_inputs'] ) ? $inputs : null,
                'response_payload'  => ! empty( $tool['log_outputs'] ) ? $result : null,
                'error_message'     => '',
                'prompt_tokens'     => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'input_tokens', null ),
                'completion_tokens' => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'output_tokens', null ),
                'total_tokens'      => FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'usage', array() ), 'total_tokens', null ),
                'latency_ms'        => $latency_ms,
                'openai_request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $result, 'request_ids', array() ), 'metadata', '' ),
            )
        );

        return array(
            'tool'    => array(
                'id'   => $tool['id'],
                'name' => $tool['name'],
                'slug' => $tool['slug'],
            ),
            'outputs' => $outputs,
            'meta'    => array(
                'workflow'   => 'attachment_metadata_assistant',
                'latency_ms' => $latency_ms,
                'usage'      => FAT_Helpers::array_get( $result, 'usage', array() ),
                'apply'      => $this->build_apply_runtime_meta( $tool, $inputs, array_keys( $outputs ) ),
                'image'      => $result,
            ),
        );
    }

    protected function tool_workflow_type( $tool ) {
        $workflow = sanitize_key( FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'workflow', '' ) );
        if ( '' !== $workflow ) {
            return $workflow;
        }

        $slug = sanitize_title( FAT_Helpers::array_get( $tool, 'slug', '' ) );
        if ( 'featured-image-generator' === $slug ) {
            return 'featured_image_generator';
        }
        if ( 'uploaded-image-processor' === $slug ) {
            return 'uploaded_image_processor';
        }
        if ( 'attachment-metadata-assistant' === $slug ) {
            return 'attachment_metadata_assistant';
        }

        return '';
    }
}
