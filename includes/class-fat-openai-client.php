<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_OpenAI_Client {
    protected $settings;
    protected $endpoint = 'https://api.openai.com/v1/responses';

    public function __construct( FAT_Settings $settings ) {
        $this->settings = $settings;
    }

    public function generate_structured_response( $args ) {
        $api_key = $this->settings->get_api_key();
        if ( '' === $api_key ) {
            return new WP_Error( 'fat_missing_api_key', __( 'OpenAI API key is not configured.', 'fabled-ai-tools' ) );
        }

        $model             = sanitize_text_field( FAT_Helpers::array_get( $args, 'model', '' ) );
        $system_prompt     = (string) FAT_Helpers::array_get( $args, 'system_prompt', '' );
        $user_prompt       = (string) FAT_Helpers::array_get( $args, 'user_prompt', '' );
        $json_schema       = FAT_Helpers::array_get( $args, 'json_schema', array() );
        $max_output_tokens = max( 1, absint( FAT_Helpers::array_get( $args, 'max_output_tokens', 600 ) ) );
        $timeout           = max( 5, absint( FAT_Helpers::array_get( $args, 'timeout', $this->settings->get( 'default_timeout', 45 ) ) ) );
        $format_name       = FAT_Helpers::clean_slug( FAT_Helpers::array_get( $args, 'format_name', 'fat_tool_response' ) );

        $request_body = array(
            'model'             => $model,
            'input'             => array(
                array(
                    'role'    => 'system',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $system_prompt,
                        ),
                    ),
                ),
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $user_prompt,
                        ),
                    ),
                ),
            ),
            'max_output_tokens' => $max_output_tokens,
            'text'              => array(
                'format' => array(
                    'type'        => 'json_schema',
                    'name'        => $format_name,
                    'strict'      => true,
                    'schema'      => $json_schema,
                    'description' => 'Return JSON matching the defined output fields only.',
                ),
            ),
        );

        $headers = array(
            'Authorization'       => 'Bearer ' . $api_key,
            'Content-Type'        => 'application/json',
            'X-Client-Request-Id' => wp_generate_uuid4(),
        );

        $response = wp_remote_post(
            $this->endpoint,
            array(
                'headers' => $headers,
                'timeout' => $timeout,
                'body'    => wp_json_encode( $request_body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );
        $request_id  = wp_remote_retrieve_header( $response, 'x-request-id' );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = __( 'OpenAI request failed.', 'fabled-ai-tools' );
            if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
                $error_message = (string) $body['error']['message'];
            }

            return new WP_Error(
                'fat_openai_http_error',
                $error_message,
                array(
                    'status_code' => $status_code,
                    'request_id'  => $request_id,
                    'raw_body'    => $raw_body,
                    'body'        => $body,
                )
            );
        }

        if ( ! is_array( $body ) ) {
            return new WP_Error( 'fat_openai_invalid_json', __( 'OpenAI returned an invalid JSON response.', 'fabled-ai-tools' ) );
        }

        if ( ! empty( $body['error']['message'] ) ) {
            return new WP_Error(
                'fat_openai_error',
                (string) $body['error']['message'],
                array(
                    'request_id' => $request_id,
                    'body'       => $body,
                )
            );
        }

        $text = $this->extract_output_text( $body );
        if ( '' === $text ) {
            return new WP_Error(
                'fat_openai_empty_output',
                __( 'OpenAI returned no text output.', 'fabled-ai-tools' ),
                array(
                    'request_id' => $request_id,
                    'body'       => $body,
                )
            );
        }

        $parsed = $this->decode_json_text( $text );
        if ( ! is_array( $parsed ) ) {
            return new WP_Error(
                'fat_openai_invalid_structured_output',
                __( 'OpenAI returned text that could not be parsed as the expected JSON object.', 'fabled-ai-tools' ),
                array(
                    'request_id' => $request_id,
                    'body'       => $body,
                    'text'       => $text,
                )
            );
        }

        return array(
            'request_body' => $request_body,
            'raw_response' => $body,
            'request_id'   => $request_id,
            'text'         => $text,
            'parsed'       => $parsed,
            'model'        => isset( $body['model'] ) ? (string) $body['model'] : $model,
            'usage'        => array(
                'input_tokens'  => isset( $body['usage']['input_tokens'] ) ? absint( $body['usage']['input_tokens'] ) : null,
                'output_tokens' => isset( $body['usage']['output_tokens'] ) ? absint( $body['usage']['output_tokens'] ) : null,
                'total_tokens'  => isset( $body['usage']['total_tokens'] ) ? absint( $body['usage']['total_tokens'] ) : null,
            ),
        );
    }

    protected function extract_output_text( $body ) {
        $parts = array();

        foreach ( (array) FAT_Helpers::array_get( $body, 'output', array() ) as $item ) {
            if ( 'message' !== FAT_Helpers::array_get( $item, 'type', '' ) ) {
                continue;
            }

            foreach ( (array) FAT_Helpers::array_get( $item, 'content', array() ) as $content_item ) {
                if ( 'output_text' === FAT_Helpers::array_get( $content_item, 'type', '' ) ) {
                    $parts[] = (string) FAT_Helpers::array_get( $content_item, 'text', '' );
                }
            }
        }

        return trim( implode( "\n", $parts ) );
    }

    protected function decode_json_text( $text ) {
        $decoded = json_decode( $text, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
            return $decoded;
        }

        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end || $end <= $start ) {
            return null;
        }

        $candidate = substr( $text, $start, ( $end - $start ) + 1 );
        $decoded   = json_decode( $candidate, true );

        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
            return $decoded;
        }

        return null;
    }
}
