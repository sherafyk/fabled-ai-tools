<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Tool_Validator {
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

        return array(
            'errors'   => array_values( array_unique( $errors ) ),
            'warnings' => array_values( array_unique( $warnings ) ),
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
