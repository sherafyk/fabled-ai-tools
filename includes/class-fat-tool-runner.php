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

    public function __construct( FAT_Tools_Repository $tools_repo, FAT_Runs_Repository $runs_repo, FAT_Settings $settings, FAT_Prompt_Engine $prompt_engine, FAT_OpenAI_Client $client, FAT_Usage_Limiter $usage_limiter, FAT_Featured_Image_Generator $featured_image_generator, FAT_Uploaded_Image_Processor $uploaded_image_processor ) {
        $this->tools_repo     = $tools_repo;
        $this->runs_repo      = $runs_repo;
        $this->settings       = $settings;
        $this->prompt_engine  = $prompt_engine;
        $this->client         = $client;
        $this->usage_limiter  = $usage_limiter;
        $this->featured_image_generator = $featured_image_generator;
        $this->uploaded_image_processor = $uploaded_image_processor;
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

        if ( ! in_array( $target_type, array( 'post', 'attachment' ), true ) || $target_id <= 0 ) {
            return new WP_Error( 'fat_invalid_target', __( 'A valid apply target is required.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $raw_apply_fields = (array) $apply_fields;
        $apply_fields     = array_values(
            array_unique(
                array_filter(
                    array_map( array( $this, 'sanitize_apply_field_identifier' ), $raw_apply_fields )
                )
            )
        );

        if ( empty( $apply_fields ) ) {
            return new WP_Error( 'fat_no_apply_fields', __( 'Select at least one field to apply.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $mappings = $this->get_allowed_apply_mappings( $tool, $target_type );
        if ( empty( $mappings ) ) {
            return new WP_Error( 'fat_apply_not_configured', __( 'This tool has no apply mappings for the selected target type.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $selected_mappings = array();
        foreach ( $apply_fields as $requested_field ) {
            $field_key = $this->resolve_requested_apply_field_key( $requested_field, $mappings, $target_type );
            if ( '' === $field_key || ! isset( $mappings[ $field_key ] ) ) {
                return new WP_Error(
                    'fat_invalid_apply_field',
                    sprintf(
                        /* translators: %s: requested apply field key */
                        __( 'An unsupported field was requested for apply: %s', 'fabled-ai-tools' ),
                        $requested_field
                    ),
                    array( 'status' => 400 )
                );
            }
            $selected_mappings[ $field_key ] = $mappings[ $field_key ];
        }

        $target_post = get_post( $target_id );
        if ( ! $target_post ) {
            return new WP_Error( 'fat_target_not_found', __( 'Target content could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( 'post' === $target_type && 'post' !== $target_post->post_type ) {
            return new WP_Error( 'fat_target_type_mismatch', __( 'Target is not a post.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( 'attachment' === $target_type && 'attachment' !== $target_post->post_type ) {
            return new WP_Error( 'fat_target_type_mismatch', __( 'Target is not an attachment.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! user_can( $user, 'edit_post', $target_id ) ) {
            return new WP_Error( 'fat_target_forbidden', __( 'You are not allowed to edit this target.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $update_post_data = array(
            'ID' => $target_id,
        );
        $updated_fields   = array();

        foreach ( $selected_mappings as $apply_key => $mapping ) {
            $output_key = FAT_Helpers::array_get( $mapping, 'output_key', '' );
            $wp_field   = FAT_Helpers::array_get( $mapping, 'wp_field', '' );
            $field      = FAT_Helpers::array_get( $mapping, 'field', '' );

            if ( '' === $output_key || ! array_key_exists( $output_key, $outputs ) ) {
                return new WP_Error( 'fat_missing_output_value', __( 'Missing generated output for one of the selected apply fields.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
            }

            $value = $outputs[ $output_key ];
            if ( is_array( $value ) || is_object( $value ) ) {
                return new WP_Error( 'fat_invalid_output_value', __( 'Generated output values must be plain strings.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
            }

            $value = trim( wp_kses( (string) $value, array() ) );

            if ( 'alt_text' === $wp_field ) {
                update_post_meta( $target_id, '_wp_attachment_image_alt', $value );
                $updated_fields[] = $field ? $field : $apply_key;
                continue;
            }

            $update_post_data[ $wp_field ] = $value;
            $updated_fields[]              = $field ? $field : $apply_key;
        }

        if ( count( $update_post_data ) > 1 ) {
            $updated_post_id = wp_update_post( wp_slash( $update_post_data ), true );
            if ( is_wp_error( $updated_post_id ) ) {
                $apply_error = new WP_Error( 'fat_apply_failed', $updated_post_id->get_error_message(), array( 'status' => 500 ) );
                $this->log_apply_action( $tool, $user, $target_type, $target_id, array_keys( $selected_mappings ), $outputs, $apply_error, array( 'action' => 'apply_outputs' ) );
                return $apply_error;
            }
        }

        $result = array(
            'tool_id'        => $tool_id,
            'target_type'    => $target_type,
            'target_id'      => $target_id,
            'updated_fields' => array_values( array_unique( $updated_fields ) ),
        );

        $this->log_apply_action( $tool, $user, $target_type, $target_id, array_keys( $selected_mappings ), $outputs, $result, array( 'action' => 'apply_outputs' ) );

        do_action( 'fat_apply_completed', $tool, $user, $target_type, $target_id, $result, array_keys( $selected_mappings ) );

        return $result;
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
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();
        $user   = $this->normalize_user( $user );

        $inputs = $this->resolve_inputs_from_selected_post( $tool, $inputs, $user );
        if ( is_wp_error( $inputs ) ) {
            return $inputs;
        }

        $inputs = $this->resolve_inputs_from_selected_attachment( $tool, $inputs, $user );
        if ( is_wp_error( $inputs ) ) {
            return $inputs;
        }

        return $inputs;
    }

    protected function resolve_inputs_from_selected_post( $tool, $raw_inputs, $user = null ) {
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();
        $user   = $this->normalize_user( $user );

        if ( ! $this->tool_has_input_key( $tool, 'article_body' ) ) {
            return $inputs;
        }

        $source = sanitize_key( FAT_Helpers::array_get( $inputs, '__fat_article_source', 'paste' ) );
        if ( ! in_array( $source, array( 'draft', 'publish' ), true ) ) {
            return $inputs;
        }

        $post_id = absint( FAT_Helpers::array_get( $inputs, '__fat_article_post_id', 0 ) );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Please select a post for the chosen content source.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected post could not be found.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $post_id ) ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'You are not allowed to use the selected post.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $status = get_post_status( $post );
        if ( ! in_array( $status, array( 'draft', 'publish' ), true ) || $status !== $source ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected post is not valid for the chosen content source.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $inputs['article_body'] = $this->normalize_post_content_for_article_body( $post->post_content );

        if ( '' === trim( (string) FAT_Helpers::array_get( $inputs, 'title', '' ) ) ) {
            $inputs['title'] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        }

        if ( 'publish' === $status && '' === trim( (string) FAT_Helpers::array_get( $inputs, 'url', '' ) ) ) {
            $permalink = get_permalink( $post );
            if ( $permalink ) {
                $inputs['url'] = $permalink;
            }
        }

        return $inputs;
    }

    protected function resolve_inputs_from_selected_attachment( $tool, $raw_inputs, $user = null ) {
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();
        $user   = $this->normalize_user( $user );

        $attachment_id = absint( FAT_Helpers::array_get( $inputs, '__fat_attachment_id', 0 ) );
        if ( $attachment_id <= 0 ) {
            return $inputs;
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected attachment could not be found.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'You are not allowed to use the selected attachment.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $attached_file = get_attached_file( $attachment_id );
        $file_url      = wp_get_attachment_url( $attachment_id );
        $parent_title  = '';

        if ( ! empty( $attachment->post_parent ) ) {
            $parent_post = get_post( (int) $attachment->post_parent );
            if ( $parent_post ) {
                $parent_title = html_entity_decode( get_the_title( $parent_post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            }
        }

        $context_values = array(
            'attachment_id'          => (string) $attachment_id,
            'attachment_title'       => html_entity_decode( get_the_title( $attachment ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
            'attachment_caption'     => (string) $attachment->post_excerpt,
            'attachment_description' => (string) $attachment->post_content,
            'attachment_alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'attachment_url'         => $file_url ? (string) $file_url : '',
            'attachment_filename'    => $attached_file ? wp_basename( $attached_file ) : '',
            'attachment_mime_type'   => (string) get_post_mime_type( $attachment ),
            'attachment_parent_title'=> $parent_title,
        );

        foreach ( $context_values as $key => $value ) {
            if ( $this->tool_has_input_key( $tool, $key ) ) {
                $inputs[ $key ] = $value;
            }
        }

        return $inputs;
    }

    protected function normalize_post_content_for_article_body( $content ) {
        $content = (string) $content;
        $content = strip_shortcodes( $content );
        $content = wp_strip_all_tags( $content );
        $content = preg_replace( '/\s+/u', ' ', $content );

        return trim( (string) $content );
    }

    protected function tool_has_input_key( $tool, $key ) {
        $key = sanitize_key( $key );
        foreach ( (array) FAT_Helpers::array_get( $tool, 'input_schema', array() ) as $field ) {
            if ( $key === sanitize_key( FAT_Helpers::array_get( $field, 'key', '' ) ) ) {
                return true;
            }
        }

        return false;
    }

    protected function build_apply_runtime_meta( $tool, $raw_inputs, $output_keys ) {
        $wp_integration = (array) FAT_Helpers::array_get( $tool, 'wp_integration', array() );
        $target_type    = sanitize_key( FAT_Helpers::array_get( FAT_Helpers::array_get( $wp_integration, 'apply', array() ), 'target', '' ) );

        if ( '' === $target_type ) {
            return array(
                'enabled' => false,
            );
        }

        $target_id = 0;
        if ( 'post' === $target_type ) {
            $target_id = absint( FAT_Helpers::array_get( $raw_inputs, '__fat_article_post_id', 0 ) );
        } elseif ( 'attachment' === $target_type ) {
            $target_id = absint( FAT_Helpers::array_get( $raw_inputs, '__fat_attachment_id', 0 ) );
        }

        $allowed_mappings = array_values( $this->get_allowed_apply_mappings( $tool, $target_type ) );

        return array(
            'enabled'      => ! empty( $allowed_mappings ),
            'target_type'  => $target_type,
            'target_id'    => $target_id,
            'mappings'     => $allowed_mappings,
            'output_keys'  => array_values( array_map( 'sanitize_key', (array) $output_keys ) ),
        );
    }

    protected function get_allowed_apply_mappings( $tool, $target_type ) {
        $allowed_fields = array(
            'post'       => array( 'post_title', 'post_excerpt', 'post_content' ),
            'attachment' => array( 'post_title', 'post_excerpt', 'post_content', 'alt_text' ),
        );
        $target_type    = sanitize_key( $target_type );
        $tool_outputs   = array();

        foreach ( (array) FAT_Helpers::array_get( $tool, 'output_schema', array() ) as $field ) {
            $tool_outputs[] = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $field, 'key', '' ) );
        }

        $mappings = array();
        $apply    = (array) FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'apply', array() );
        if ( $target_type !== sanitize_key( FAT_Helpers::array_get( $apply, 'target', '' ) ) ) {
            return $mappings;
        }

        foreach ( (array) FAT_Helpers::array_get( $apply, 'mappings', array() ) as $mapping ) {
            $output_key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $mapping, 'output_key', '' ) );
            $wp_field   = sanitize_key( FAT_Helpers::array_get( $mapping, 'wp_field', '' ) );

            if ( '' === $output_key || '' === $wp_field ) {
                continue;
            }
            if ( ! isset( $allowed_fields[ $target_type ] ) || ! in_array( $wp_field, $allowed_fields[ $target_type ], true ) ) {
                continue;
            }
            if ( ! in_array( $output_key, $tool_outputs, true ) ) {
                continue;
            }

            $canonical_field = $this->canonical_apply_field_from_wp_field( $target_type, $wp_field );
            if ( '' === $canonical_field ) {
                continue;
            }
            $apply_key = $canonical_field;
            $mappings[ $apply_key ] = array(
                'apply_key'   => $apply_key,
                'field'       => $canonical_field,
                'output_key'  => $output_key,
                'wp_field'    => $wp_field,
                'label'       => sanitize_text_field( FAT_Helpers::array_get( $mapping, 'label', $output_key ) ),
            );
        }

        return $mappings;
    }

    protected function resolve_requested_apply_field_key( $requested_field, $mappings, $target_type ) {
        $requested_field = $this->sanitize_apply_field_identifier( $requested_field );
        if ( isset( $mappings[ $requested_field ] ) ) {
            return $requested_field;
        }

        foreach ( $mappings as $apply_key => $mapping ) {
            $legacy_pair = $this->sanitize_apply_field_identifier( FAT_Helpers::array_get( $mapping, 'output_key', '' ) . ':' . FAT_Helpers::array_get( $mapping, 'wp_field', '' ) );
            $wp_field    = $this->sanitize_apply_field_identifier( FAT_Helpers::array_get( $mapping, 'wp_field', '' ) );
            $legacy_slug = $this->sanitize_apply_field_identifier( $this->canonical_apply_field_from_wp_field( $target_type, $wp_field ) );
            if ( $requested_field === $legacy_pair || $requested_field === $wp_field || ( '' !== $legacy_slug && $requested_field === $legacy_slug ) ) {
                return $apply_key;
            }
        }

        return '';
    }

    protected function canonical_apply_field_from_wp_field( $target_type, $wp_field ) {
        $target_type = sanitize_key( $target_type );
        $wp_field    = sanitize_key( $wp_field );
        $map         = array(
            'post'       => array(
                'post_title'   => 'title',
                'post_excerpt' => 'excerpt',
                'post_content' => 'content',
            ),
            'attachment' => array(
                'post_title'   => 'title',
                'post_excerpt' => 'caption',
                'post_content' => 'description',
                'alt_text'     => 'alt_text',
            ),
        );

        return (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $map, $target_type, array() ), $wp_field, '' );
    }

    protected function sanitize_apply_field_identifier( $value ) {
        $value = strtolower( sanitize_text_field( (string) $value ) );
        $value = preg_replace( '/[^a-z0-9_:\-]/', '', $value );
        return is_string( $value ) ? trim( $value ) : '';
    }


    protected function log_apply_action( $tool, $user, $target_type, $target_id, $apply_fields, $outputs, $apply_result, $extra = array() ) {
        $openai_request_id = $this->extract_openai_request_id_from_outputs( $outputs );
        $request_payload   = wp_parse_args(
            (array) $extra,
            array(
                'action'           => 'apply_outputs',
                'target_type'      => sanitize_key( $target_type ),
                'target_id'        => absint( $target_id ),
                'apply_fields'     => array_values( array_map( array( $this, 'sanitize_apply_field_identifier' ), (array) $apply_fields ) ),
                'openai_request_id'=> $openai_request_id,
            )
        );

        if ( is_wp_error( $apply_result ) ) {
            $this->log_run(
                $tool,
                $user,
                array(
                    'status'            => 'error',
                    'request_preview'   => sprintf( 'Apply to %s #%d', sanitize_key( $target_type ), absint( $target_id ) ),
                    'request_payload'   => ! empty( $tool['log_inputs'] ) ? $request_payload : null,
                    'response_payload'  => ! empty( $tool['log_outputs'] ) ? $apply_result->get_error_data() : null,
                    'error_message'     => $apply_result->get_error_message(),
                    'openai_request_id' => $openai_request_id,
                )
            );
            return;
        }

        $this->log_run(
            $tool,
            $user,
            array(
                'status'            => 'success',
                'request_preview'   => sprintf( 'Apply to %s #%d', sanitize_key( $target_type ), absint( $target_id ) ),
                'response_preview'  => 'Updated fields: ' . implode( ', ', (array) FAT_Helpers::array_get( $apply_result, 'updated_fields', (array) $apply_fields ) ),
                'request_payload'   => ! empty( $tool['log_inputs'] ) ? $request_payload : null,
                'response_payload'  => ! empty( $tool['log_outputs'] ) ? $apply_result : null,
                'openai_request_id' => $openai_request_id,
            )
        );
    }

    protected function extract_openai_request_id_from_outputs( $outputs ) {
        $request_id = (string) FAT_Helpers::array_get( $outputs, '__fat_openai_request_id', '' );
        return sanitize_text_field( $request_id );
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

        return '';
    }
}
