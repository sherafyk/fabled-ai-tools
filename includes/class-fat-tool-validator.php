<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Tool_Validator {
    public function normalize_wp_integration( $config ) {
        $config = is_array( $config ) ? $config : array();

        $source = FAT_Helpers::array_get( $config, 'source', array() );
        $apply  = FAT_Helpers::array_get( $config, 'apply', array() );
        $workflow = sanitize_key( FAT_Helpers::array_get( $config, 'workflow', '' ) );
        $workflow_config = (array) FAT_Helpers::array_get( $config, 'workflow_config', array() );

        if ( ! in_array( $workflow, array( '', 'featured_image_generator', 'uploaded_image_processor', 'attachment_metadata_assistant' ), true ) ) {
            $workflow = '';
        }

        $source_type = sanitize_key( FAT_Helpers::array_get( $source, 'type', '' ) );
        if ( 'media' === $source_type ) {
            $source_type = 'attachment';
        }
        if ( ! in_array( $source_type, array( '', 'post', 'attachment' ), true ) ) {
            $source_type = '';
        }

        $apply_target = sanitize_key( FAT_Helpers::array_get( $apply, 'target', '' ) );
        if ( 'media' === $apply_target ) {
            $apply_target = 'attachment';
        }
        if ( ! in_array( $apply_target, array( '', 'post', 'attachment' ), true ) ) {
            $apply_target = '';
        }

        $mappings = array();
        foreach ( (array) FAT_Helpers::array_get( $apply, 'mappings', array() ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $output_key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $row, 'output_key', '' ) );
            $wp_field   = sanitize_key( FAT_Helpers::array_get( $row, 'wp_field', '' ) );
            if ( '' === $output_key || '' === $wp_field ) {
                continue;
            }

            $mappings[] = array(
                'output_key' => $output_key,
                'wp_field'   => $wp_field,
                'label'      => sanitize_text_field( FAT_Helpers::array_get( $row, 'label', $output_key ) ),
            );
        }

        if ( '' === $source_type && '' === $apply_target && empty( $mappings ) && '' === $workflow ) {
            return array();
        }

        return array(
            'workflow' => $workflow,
            'workflow_config' => array(
                'image_model'     => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'image_model', 'gpt-image-1-mini' ) ),
                'image_quality'   => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'image_quality', 'low' ) ),
                'source_size'     => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'source_size', '1536x1024' ) ),
                'featured_size'   => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'featured_size', '1200x675' ) ),
                'featured_format' => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'featured_format', 'png' ) ),
                'target_size'    => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'target_size', '1200x675' ) ),
                'target_format'  => sanitize_text_field( FAT_Helpers::array_get( $workflow_config, 'target_format', 'webp' ) ),
            ),
            'source' => array(
                'type'           => $source_type,
                'allow_manual'   => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $source, 'allow_manual', 1 ) ),
                'allow_draft'    => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $source, 'allow_draft', 1 ) ),
                'allow_publish'  => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $source, 'allow_publish', 1 ) ),
                'allow_attachment' => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $source, 'allow_attachment', 'attachment' === $source_type ? 1 : 0 ) ),
            ),
            'apply'  => array(
                'target'   => $apply_target,
                'mappings' => $mappings,
            ),
        );
    }

    public function normalize_input_schema( $rows ) {
        $rows   = is_array( $rows ) ? $rows : array();
        $output = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $row, 'key', '' ) );
            if ( '' === $key ) {
                continue;
            }

            $type = sanitize_key( FAT_Helpers::array_get( $row, 'type', 'text' ) );
            if ( ! in_array( $type, array( 'text', 'textarea', 'url' ), true ) ) {
                $type = 'text';
            }

            $output[] = array(
                'key'         => $key,
                'label'       => sanitize_text_field( FAT_Helpers::array_get( $row, 'label', $key ) ),
                'type'        => $type,
                'required'    => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $row, 'required', 0 ) ),
                'help_text'   => sanitize_text_field( FAT_Helpers::array_get( $row, 'help_text', '' ) ),
                'placeholder' => sanitize_text_field( FAT_Helpers::array_get( $row, 'placeholder', '' ) ),
                'max_length'  => max( 0, absint( FAT_Helpers::array_get( $row, 'max_length', 0 ) ) ),
            );
        }

        return $output;
    }

    public function normalize_output_schema( $rows ) {
        $rows   = is_array( $rows ) ? $rows : array();
        $output = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $row, 'key', '' ) );
            if ( '' === $key ) {
                continue;
            }

            $type = sanitize_key( FAT_Helpers::array_get( $row, 'type', 'text' ) );
            if ( ! in_array( $type, array( 'text', 'long_text' ), true ) ) {
                $type = 'text';
            }

            $output[] = array(
                'key'      => $key,
                'label'    => sanitize_text_field( FAT_Helpers::array_get( $row, 'label', $key ) ),
                'type'     => $type,
                'copyable' => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $row, 'copyable', 1 ) ),
            );
        }

        return $output;
    }

    public function validate_tool( $data, FAT_Tools_Repository $repo, $tool_id = 0 ) {
        $errors   = array();
        $warnings = array();
        $tool_id  = absint( $tool_id );

        if ( '' === trim( (string) FAT_Helpers::array_get( $data, 'name', '' ) ) ) {
            $errors[] = __( 'Tool name is required.', 'fabled-ai-tools' );
        }

        if ( '' === trim( (string) FAT_Helpers::array_get( $data, 'slug', '' ) ) ) {
            $errors[] = __( 'Tool slug is required.', 'fabled-ai-tools' );
        }

        if ( $repo->exists_slug( FAT_Helpers::array_get( $data, 'slug', '' ), $tool_id ) ) {
            $errors[] = __( 'Tool slug already exists. Use a unique slug.', 'fabled-ai-tools' );
        }

        if ( '' === trim( (string) FAT_Helpers::array_get( $data, 'system_prompt', '' ) ) ) {
            $errors[] = __( 'System prompt is required.', 'fabled-ai-tools' );
        }

        if ( '' === trim( (string) FAT_Helpers::array_get( $data, 'user_prompt_template', '' ) ) ) {
            $errors[] = __( 'User prompt template is required.', 'fabled-ai-tools' );
        }

        $input_schema  = (array) FAT_Helpers::array_get( $data, 'input_schema', array() );
        $output_schema = (array) FAT_Helpers::array_get( $data, 'output_schema', array() );
        $wp_integration = (array) FAT_Helpers::array_get( $data, 'wp_integration', array() );

        if ( empty( $input_schema ) ) {
            $errors[] = __( 'At least one input field is required.', 'fabled-ai-tools' );
        }

        if ( empty( $output_schema ) ) {
            $errors[] = __( 'At least one output field is required.', 'fabled-ai-tools' );
        }

        $input_keys = array();
        foreach ( $input_schema as $field ) {
            $key = FAT_Helpers::array_get( $field, 'key', '' );
            if ( '' === $key ) {
                $errors[] = __( 'Every input field needs a key.', 'fabled-ai-tools' );
                continue;
            }

            if ( in_array( $key, $input_keys, true ) ) {
                $errors[] = sprintf( __( 'Duplicate input key: %s', 'fabled-ai-tools' ), $key );
            }
            $input_keys[] = $key;
        }

        $output_keys = array();
        foreach ( $output_schema as $field ) {
            $key = FAT_Helpers::array_get( $field, 'key', '' );
            if ( '' === $key ) {
                $errors[] = __( 'Every output field needs a key.', 'fabled-ai-tools' );
                continue;
            }

            if ( in_array( $key, $output_keys, true ) ) {
                $errors[] = sprintf( __( 'Duplicate output key: %s', 'fabled-ai-tools' ), $key );
            }
            $output_keys[] = $key;
        }

        $placeholders = $this->extract_input_placeholders( FAT_Helpers::array_get( $data, 'user_prompt_template', '' ) );
        foreach ( $placeholders as $placeholder_key ) {
            if ( ! in_array( $placeholder_key, $input_keys, true ) ) {
                $errors[] = sprintf( __( 'Prompt template references undefined input key: %s', 'fabled-ai-tools' ), $placeholder_key );
            }
        }

        foreach ( $input_keys as $input_key ) {
            if ( false === strpos( (string) FAT_Helpers::array_get( $data, 'user_prompt_template', '' ), '{{input.' . $input_key . '}}' ) ) {
                $warnings[] = sprintf( __( 'Input field "%s" is not referenced in the user prompt template.', 'fabled-ai-tools' ), $input_key );
            }
        }

        if ( absint( FAT_Helpers::array_get( $data, 'max_input_chars', 0 ) ) < 1 ) {
            $errors[] = __( 'Max input chars must be at least 1.', 'fabled-ai-tools' );
        }

        if ( absint( FAT_Helpers::array_get( $data, 'max_output_tokens', 0 ) ) < 1 ) {
            $errors[] = __( 'Max output tokens must be at least 1.', 'fabled-ai-tools' );
        }

        $wp_integration_result = $this->validate_wp_integration( $wp_integration, $output_keys );
        $errors                = array_merge( $errors, FAT_Helpers::array_get( $wp_integration_result, 'errors', array() ) );

        return array(
            'errors'   => array_values( array_unique( $errors ) ),
            'warnings' => array_values( array_unique( $warnings ) ),
        );
    }

    protected function validate_wp_integration( $config, $output_keys ) {
        $errors         = array();

        if ( empty( $config ) ) {
            return array( 'errors' => array() );
        }

        $source = (array) FAT_Helpers::array_get( $config, 'source', array() );
        $apply  = (array) FAT_Helpers::array_get( $config, 'apply', array() );
        $workflow = sanitize_key( FAT_Helpers::array_get( $config, 'workflow', '' ) );

        if ( ! in_array( $workflow, array( '', 'featured_image_generator', 'uploaded_image_processor', 'attachment_metadata_assistant' ), true ) ) {
            $errors[] = __( 'Unsupported workflow value for WordPress integration.', 'fabled-ai-tools' );
        }

        $source_type = sanitize_key( FAT_Helpers::array_get( $source, 'type', '' ) );
        if ( 'media' === $source_type ) {
            $source_type = 'attachment';
        }
        if ( ! in_array( $source_type, array( '', 'post', 'attachment' ), true ) ) {
            $errors[] = __( 'WordPress integration source type must be post, attachment, or empty.', 'fabled-ai-tools' );
        }

        $target = sanitize_key( FAT_Helpers::array_get( $apply, 'target', '' ) );
        if ( 'media' === $target ) {
            $target = 'attachment';
        }
        if ( ! in_array( $target, array( '', 'post', 'attachment' ), true ) ) {
            $errors[] = __( 'WordPress integration apply target must be post, attachment, or empty.', 'fabled-ai-tools' );
        }

        $allowed_fields = 'post' === $target
            ? array( 'post_title', 'post_excerpt', 'post_content' )
            : array( 'post_title', 'post_excerpt', 'post_content', 'alt_text' );
        $seen_mappings = array();
        $seen_output_keys = array();
        $seen_wp_fields   = array();
        foreach ( (array) FAT_Helpers::array_get( $apply, 'mappings', array() ) as $mapping ) {
            $output_key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $mapping, 'output_key', '' ) );
            $wp_field   = sanitize_key( FAT_Helpers::array_get( $mapping, 'wp_field', '' ) );

            if ( '' === $output_key || '' === $wp_field ) {
                $errors[] = __( 'Each apply mapping needs output_key and wp_field.', 'fabled-ai-tools' );
                continue;
            }

            if ( ! in_array( $output_key, $output_keys, true ) ) {
                /* translators: %s: output key */
                $errors[] = sprintf( __( 'Apply mapping output key does not exist in output schema: %s', 'fabled-ai-tools' ), $output_key );
            }

            if ( ! in_array( $wp_field, $allowed_fields, true ) ) {
                /* translators: %s: field name */
                $errors[] = sprintf( __( 'Unsupported WordPress field in apply mapping: %s', 'fabled-ai-tools' ), $wp_field );
                continue;
            }

            $mapping_key = $output_key . '|' . $wp_field;
            if ( in_array( $mapping_key, $seen_mappings, true ) ) {
                /* translators: %s: mapping pair */
                $errors[] = sprintf( __( 'Duplicate apply mapping: %s', 'fabled-ai-tools' ), $mapping_key );
            }
            $seen_mappings[] = $mapping_key;
            if ( in_array( $output_key, $seen_output_keys, true ) ) {
                /* translators: %s: output key */
                $errors[] = sprintf( __( 'Each output key can only be mapped once: %s', 'fabled-ai-tools' ), $output_key );
            }
            if ( in_array( $wp_field, $seen_wp_fields, true ) ) {
                /* translators: %s: WordPress field */
                $errors[] = sprintf( __( 'Each WordPress field can only be mapped once: %s', 'fabled-ai-tools' ), $wp_field );
            }
            $seen_output_keys[] = $output_key;
            $seen_wp_fields[]   = $wp_field;

            if ( 'attachment' !== $target && 'alt_text' === $wp_field ) {
                $errors[] = __( 'alt_text mappings require apply target attachment.', 'fabled-ai-tools' );
            }
        }

        if ( ! empty( $apply['mappings'] ) && '' === $target ) {
            $errors[] = __( 'Apply target is required when mappings are configured.', 'fabled-ai-tools' );
        }

        return array(
            'errors' => array_values( array_unique( $errors ) ),
        );
    }

    public function extract_input_placeholders( $template ) {
        $template = is_string( $template ) ? $template : '';
        $matches  = array();
        preg_match_all( '/{{\s*input\.([a-zA-Z0-9_\-]+)\s*}}/', $template, $matches );

        if ( empty( $matches[1] ) ) {
            return array();
        }

        return array_values( array_unique( array_map( 'sanitize_key', $matches[1] ) ) );
    }
}
