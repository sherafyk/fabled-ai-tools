<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_OpenAI_Client {
    protected $settings;
    protected $endpoint = 'https://api.openai.com/v1/responses';
    protected $images_endpoint = 'https://api.openai.com/v1/images/generations';

    public function __construct( FAT_Settings $settings ) {
        $this->settings = $settings;
    }

    public function generate_structured_response( $args ) {
        return $this->request_structured_response( $args );
    }

    public function generate_image( $args ) {
        $api_key = $this->settings->get_api_key();
        if ( '' === $api_key ) {
            return new WP_Error( 'fat_missing_api_key', __( 'OpenAI API key is not configured.', 'fabled-ai-tools' ) );
        }

        $model   = sanitize_text_field( FAT_Helpers::array_get( $args, 'model', 'gpt-image-1-mini' ) );
        $prompt  = trim( (string) FAT_Helpers::array_get( $args, 'prompt', '' ) );
        $size    = sanitize_text_field( FAT_Helpers::array_get( $args, 'size', '1536x1024' ) );
        $quality = sanitize_text_field( FAT_Helpers::array_get( $args, 'quality', 'low' ) );
        $timeout = max( 5, absint( FAT_Helpers::array_get( $args, 'timeout', $this->settings->get( 'default_timeout', 45 ) ) ) );

        if ( '' === $prompt ) {
            return new WP_Error( 'fat_empty_prompt', __( 'Prompt is required for image generation.', 'fabled-ai-tools' ) );
        }

        $request_body = array(
            'model'         => $model,
            'prompt'        => $prompt,
            'size'          => $size,
            'quality'       => $quality,
            'output_format' => 'png',
            'n'             => 1,
        );

        $response = $this->post_json_request( $this->images_endpoint, $request_body, $timeout, $api_key );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $image_data = (array) FAT_Helpers::array_get( $response['body'], 'data', array() );
        $first      = isset( $image_data[0] ) && is_array( $image_data[0] ) ? $image_data[0] : array();
        $b64        = (string) FAT_Helpers::array_get( $first, 'b64_json', '' );

        if ( '' === $b64 ) {
            return new WP_Error(
                'fat_openai_empty_image',
                __( 'OpenAI returned no image data.', 'fabled-ai-tools' ),
                array(
                    'request_id' => $response['request_id'],
                    'body'       => $response['body'],
                )
            );
        }

        return array(
            'request_id' => $response['request_id'],
            'request'    => $request_body,
            'body'       => $response['body'],
            'model'      => $model,
            'size'       => $size,
            'quality'    => $quality,
            'b64_json'   => $b64,
        );
    }

    public function generate_image_metadata( $args ) {
        $system_prompt = "You generate concise media metadata for a WordPress image attachment.\nReturn JSON only.\nTitle must be short and publication-ready.\nAlt text should describe essential visual content.\nDescription should be 1-2 concise sentences.";
        $user_prompt   = trim( (string) FAT_Helpers::array_get( $args, 'prompt', '' ) );
        $image_b64     = trim( (string) FAT_Helpers::array_get( $args, 'image_b64', '' ) );
        $model         = sanitize_text_field( FAT_Helpers::array_get( $args, 'model', 'gpt-5.4-mini' ) );
        $image_mime_type = sanitize_text_field( FAT_Helpers::array_get( $args, 'image_mime_type', 'image/png' ) );
        $timeout       = max( 5, absint( FAT_Helpers::array_get( $args, 'timeout', $this->settings->get( 'default_timeout', 45 ) ) ) );

        if ( '' === $image_b64 ) {
            return new WP_Error( 'fat_missing_image_for_metadata', __( 'Image data is required for metadata generation.', 'fabled-ai-tools' ) );
        }

        return $this->request_structured_response(
            array(
                'model'             => $model,
                'system_prompt'     => $system_prompt,
                'user_prompt'       => "Original generation prompt:\n" . $user_prompt . "\n\nGenerate production-ready attachment metadata for this image.",
                'json_schema'       => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'title'       => array( 'type' => 'string' ),
                        'alt_text'    => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                    ),
                    'required'             => array( 'title', 'alt_text', 'description' ),
                    'additionalProperties' => false,
                ),
                'max_output_tokens' => 250,
                'timeout'           => $timeout,
                'format_name'       => 'fat_featured_image_metadata',
                'user_image_b64'    => $image_b64,
                'user_image_mime_type' => $image_mime_type,
            )
        );
    }


    public function test_connection( $args = array() ) {
        $model   = sanitize_text_field( FAT_Helpers::array_get( $args, 'model', $this->settings->get( 'default_model', 'gpt-5.4-mini' ) ) );
        $timeout = max( 5, absint( FAT_Helpers::array_get( $args, 'timeout', $this->settings->get( 'default_timeout', 45 ) ) ) );

        $start    = microtime( true );
        $response = $this->request_structured_response(
            array(
                'model'             => $model,
                'system_prompt'     => 'Return a minimal JSON object for a connectivity check.',
                'user_prompt'       => 'Respond with {"status":"ok"}.',
                'json_schema'       => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'status' => array( 'type' => 'string' ),
                    ),
                    'required'             => array( 'status' ),
                    'additionalProperties' => false,
                ),
                'max_output_tokens' => 20,
                'timeout'           => $timeout,
                'format_name'       => 'fat_connection_test',
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return array(
            'ok'         => true,
            'model'      => (string) FAT_Helpers::array_get( $response, 'model', $model ),
            'request_id' => (string) FAT_Helpers::array_get( $response, 'request_id', '' ),
            'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'usage'      => (array) FAT_Helpers::array_get( $response, 'usage', array() ),
            'status'     => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $response, 'parsed', array() ), 'status', '' ),
        );
    }
    protected function request_structured_response( $args ) {
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

        $user_image_b64 = trim( (string) FAT_Helpers::array_get( $args, 'user_image_b64', '' ) );
        $user_image_mime_type = sanitize_text_field( FAT_Helpers::array_get( $args, 'user_image_mime_type', 'image/png' ) );
        if ( '' !== $user_image_b64 ) {
            $request_body['input'][1]['content'][] = array(
                'type'      => 'input_image',
                'image_url' => 'data:' . $user_image_mime_type . ';base64,' . $user_image_b64,
            );
        }

        $response = $this->post_json_request( $this->endpoint, $request_body, $timeout, $api_key );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body       = $response['body'];
        $request_id = $response['request_id'];

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

    protected function post_json_request( $url, $request_body, $timeout, $api_key ) {
        $headers = array(
            'Authorization'       => 'Bearer ' . $api_key,
            'Content-Type'        => 'application/json',
            'X-Client-Request-Id' => wp_generate_uuid4(),
        );

        $response = wp_remote_post(
            $url,
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

        return array(
            'status_code' => $status_code,
            'raw_body'    => $raw_body,
            'body'        => $body,
            'request_id'  => $request_id,
        );
    }
}
