<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Prompt_Engine {
    public function render_user_prompt( $template, $inputs ) {
        $template = is_string( $template ) ? $template : '';
        $inputs   = is_array( $inputs ) ? $inputs : array();

        $rendered = preg_replace_callback(
            '/{{\s*input\.([a-zA-Z0-9_\-]+)\s*}}/',
            function ( $matches ) use ( $inputs ) {
                $key = sanitize_key( $matches[1] );
                return isset( $inputs[ $key ] ) ? (string) $inputs[ $key ] : '';
            },
            $template
        );

        return trim( (string) $rendered );
    }

    public function build_json_schema_for_outputs( $tool ) {
        $properties = array();
        $required   = array();
        $keys       = array();

        foreach ( (array) FAT_Helpers::array_get( $tool, 'output_schema', array() ) as $output ) {
            $key = FAT_Helpers::array_get( $output, 'key', '' );
            if ( '' === $key ) {
                continue;
            }

            $properties[ $key ] = array(
                'type'        => 'string',
                'description' => sprintf(
                    /* translators: %s: field label */
                    __( 'Return the %s value as a plain string.', 'fabled-ai-tools' ),
                    FAT_Helpers::array_get( $output, 'label', $key )
                ),
            );
            $required[] = $key;
            $keys[]     = $key;
        }

        return array(
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        );
    }

    public function public_tool_definition( $tool ) {
        return array(
            'id'              => (int) FAT_Helpers::array_get( $tool, 'id', 0 ),
            'name'            => (string) FAT_Helpers::array_get( $tool, 'name', '' ),
            'slug'            => (string) FAT_Helpers::array_get( $tool, 'slug', '' ),
            'description'     => (string) FAT_Helpers::array_get( $tool, 'description', '' ),
            'max_input_chars' => (int) FAT_Helpers::array_get( $tool, 'max_input_chars', 0 ),
            'input_schema'    => array_values( (array) FAT_Helpers::array_get( $tool, 'input_schema', array() ) ),
            'output_schema'   => array_values( (array) FAT_Helpers::array_get( $tool, 'output_schema', array() ) ),
        );
    }

    public function sanitize_runtime_inputs( $tool, $inputs ) {
        $tool_inputs      = (array) FAT_Helpers::array_get( $tool, 'input_schema', array() );
        $raw_inputs       = is_array( $inputs ) ? $inputs : array();
        $clean_inputs     = array();
        $errors           = array();
        $total_characters = 0;

        foreach ( $tool_inputs as $field ) {
            $key       = FAT_Helpers::array_get( $field, 'key', '' );
            $label     = FAT_Helpers::array_get( $field, 'label', $key );
            $type      = FAT_Helpers::array_get( $field, 'type', 'text' );
            $required  = ! empty( $field['required'] );
            $max_field = absint( FAT_Helpers::array_get( $field, 'max_length', 0 ) );
            $value     = FAT_Helpers::array_get( $raw_inputs, $key, '' );
            $value     = is_scalar( $value ) ? (string) $value : '';
            $value     = trim( wp_unslash( $value ) );

            if ( 'url' === $type && '' !== $value ) {
                $value = esc_url_raw( $value );
                if ( '' === $value ) {
                    $errors[] = sprintf( __( '%s must be a valid URL.', 'fabled-ai-tools' ), $label );
                }
            } else {
                $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
                $value = wp_kses( $value, array() );
            }

            if ( $required && '' === $value ) {
                $errors[] = sprintf( __( '%s is required.', 'fabled-ai-tools' ), $label );
            }

            if ( $max_field > 0 && mb_strlen( $value ) > $max_field ) {
                $errors[] = sprintf( __( '%1$s exceeds the field max length of %2$d characters.', 'fabled-ai-tools' ), $label, $max_field );
            }

            $total_characters += mb_strlen( $value );
            $clean_inputs[ $key ] = $value;
        }

        $tool_max_input = absint( FAT_Helpers::array_get( $tool, 'max_input_chars', 0 ) );
        if ( $tool_max_input > 0 && $total_characters > $tool_max_input ) {
            $errors[] = sprintf( __( 'Combined input exceeds the tool limit of %d characters.', 'fabled-ai-tools' ), $tool_max_input );
        }

        return array(
            'inputs' => $clean_inputs,
            'errors' => array_values( array_unique( $errors ) ),
        );
    }

    public function validate_runtime_outputs( $tool, $outputs ) {
        $errors   = array();
        $clean    = array();
        $schema   = (array) FAT_Helpers::array_get( $tool, 'output_schema', array() );
        $outputs  = is_array( $outputs ) ? $outputs : array();

        foreach ( $schema as $field ) {
            $key   = FAT_Helpers::array_get( $field, 'key', '' );
            $label = FAT_Helpers::array_get( $field, 'label', $key );

            if ( '' === $key ) {
                continue;
            }

            if ( ! array_key_exists( $key, $outputs ) ) {
                $errors[] = sprintf( __( 'Missing expected output field: %s', 'fabled-ai-tools' ), $label );
                continue;
            }

            $value = $outputs[ $key ];
            if ( is_array( $value ) || is_object( $value ) ) {
                $errors[] = sprintf( __( 'Output field %s must be a string.', 'fabled-ai-tools' ), $label );
                continue;
            }

            $clean[ $key ] = trim( (string) $value );
        }

        return array(
            'outputs' => $clean,
            'errors'  => array_values( array_unique( $errors ) ),
        );
    }
}
