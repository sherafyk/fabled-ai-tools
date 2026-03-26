<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Runner_Apply_Service {
    protected $context_service;
    protected $log_callback;

    public function __construct( FAT_Runner_Context_Service $context_service, $log_callback ) {
        $this->context_service = $context_service;
        $this->log_callback    = $log_callback;
    }

    public function apply_standard_outputs( $tool, $tool_id, $target_type, $target_id, $outputs, $apply_fields, $user ) {
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

        if ( 'post' === $target_type && ! $this->context_service->is_supported_content_target_post( $target_post ) ) {
            return new WP_Error( 'fat_target_type_mismatch', __( 'Target is not supported content.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( 'attachment' === $target_type && 'attachment' !== $target_post->post_type ) {
            return new WP_Error( 'fat_target_type_mismatch', __( 'Target is not an attachment.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! user_can( $user, 'edit_post', $target_id ) ) {
            return new WP_Error( 'fat_target_forbidden', __( 'You are not allowed to edit this target.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $update_post_data = array( 'ID' => $target_id );
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

    public function build_apply_runtime_meta( $tool, $raw_inputs, $output_keys ) {
        $wp_integration = (array) FAT_Helpers::array_get( $tool, 'wp_integration', array() );
        $target_type    = sanitize_key( FAT_Helpers::array_get( FAT_Helpers::array_get( $wp_integration, 'apply', array() ), 'target', '' ) );

        if ( '' === $target_type ) {
            return array( 'enabled' => false );
        }

        $target_id = 0;
        if ( 'post' === $target_type ) {
            $target_id = absint( FAT_Helpers::array_get( $raw_inputs, '__fat_target_post_id', 0 ) );
            if ( $target_id <= 0 ) {
                $target_id = absint( FAT_Helpers::array_get( $raw_inputs, '__fat_article_post_id', 0 ) );
            }
        } elseif ( 'attachment' === $target_type ) {
            $target_id = absint( FAT_Helpers::array_get( $raw_inputs, '__fat_attachment_id', 0 ) );
        }

        $allowed_mappings = array_values( $this->get_allowed_apply_mappings( $tool, $target_type ) );

        return array(
            'enabled'      => ! empty( $allowed_mappings ),
            'target_type'  => $target_type,
            'target_family'=> 'attachment' === $target_type ? 'attachment' : 'content',
            'target_id'    => $target_id,
            'mappings'     => $allowed_mappings,
            'output_keys'  => array_values( array_map( 'sanitize_key', (array) $output_keys ) ),
        );
    }

    public function log_apply_action( $tool, $user, $target_type, $target_id, $apply_fields, $outputs, $apply_result, $extra = array() ) {
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
            call_user_func(
                $this->log_callback,
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

        call_user_func(
            $this->log_callback,
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

    protected function extract_openai_request_id_from_outputs( $outputs ) {
        $request_id = (string) FAT_Helpers::array_get( $outputs, '__fat_openai_request_id', '' );
        return sanitize_text_field( $request_id );
    }
}
