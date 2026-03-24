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

    public function __construct( FAT_Tools_Repository $tools_repo, FAT_Runs_Repository $runs_repo, FAT_Settings $settings, FAT_Prompt_Engine $prompt_engine, FAT_OpenAI_Client $client, FAT_Usage_Limiter $usage_limiter ) {
        $this->tools_repo     = $tools_repo;
        $this->runs_repo      = $runs_repo;
        $this->settings       = $settings;
        $this->prompt_engine  = $prompt_engine;
        $this->client         = $client;
        $this->usage_limiter  = $usage_limiter;
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

        $validated = $this->prompt_engine->sanitize_runtime_inputs( $tool, $raw_inputs );
        if ( ! empty( $validated['errors'] ) ) {
            return new WP_Error( 'fat_invalid_inputs', implode( ' ', $validated['errors'] ), array( 'status' => 400, 'errors' => $validated['errors'] ) );
        }

        $can_run = $this->usage_limiter->assert_can_run( $tool, $user->ID );
        if ( is_wp_error( $can_run ) ) {
            $can_run->add_data( array( 'status' => 429 ) );
            return $can_run;
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
            ),
        );
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
}
