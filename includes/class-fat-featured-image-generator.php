<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Featured_Image_Generator {
    protected $client;
    protected $media_service;

    public function __construct( FAT_OpenAI_Client $client, FAT_Media_Service $media_service ) {
        $this->client        = $client;
        $this->media_service = $media_service;
    }

    public function execute( $tool, $inputs ) {
        $prompt = trim( (string) FAT_Helpers::array_get( $inputs, 'prompt', '' ) );
        if ( '' === $prompt ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Prompt is required.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_Error( 'fat_forbidden_media', __( 'You are not allowed to upload media.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $workflow = (array) FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'workflow_config', array() );
        $model    = sanitize_text_field( FAT_Helpers::array_get( $workflow, 'image_model', 'gpt-image-1-mini' ) );
        $quality  = sanitize_text_field( FAT_Helpers::array_get( $workflow, 'image_quality', 'low' ) );
        $size     = sanitize_text_field( FAT_Helpers::array_get( $workflow, 'source_size', '1536x1024' ) );

        $featured_dimensions = $this->parse_dimensions( (string) FAT_Helpers::array_get( $workflow, 'featured_size', '1200x675' ), 1200, 675 );
        $featured_format     = $this->normalize_featured_format( (string) FAT_Helpers::array_get( $workflow, 'featured_format', 'png' ) );

        $image_response = $this->client->generate_image(
            array(
                'model'   => $model,
                'prompt'  => $prompt,
                'quality' => $quality,
                'size'    => $size,
            )
        );
        if ( is_wp_error( $image_response ) ) {
            return $image_response;
        }

        $raw_image_bytes = base64_decode( FAT_Helpers::array_get( $image_response, 'b64_json', '' ), true );
        if ( false === $raw_image_bytes || '' === $raw_image_bytes ) {
            return new WP_Error( 'fat_image_decode_failed', __( 'Generated image could not be decoded.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $original_attachment_id = $this->save_generated_image_to_media( $raw_image_bytes, $prompt, 'original', 'png' );
        if ( is_wp_error( $original_attachment_id ) ) {
            return $original_attachment_id;
        }

        $metadata_response = $this->client->generate_image_metadata(
            array(
                'prompt'    => $prompt,
                'image_b64' => FAT_Helpers::array_get( $image_response, 'b64_json', '' ),
            )
        );
        if ( is_wp_error( $metadata_response ) ) {
            return $metadata_response;
        }

        $metadata = $this->media_service->normalize_attachment_metadata(
            (array) FAT_Helpers::array_get( $metadata_response, 'parsed', array() ),
            (string) FAT_Helpers::array_get( $metadata_response, 'request_id', '' ),
            (array) FAT_Helpers::array_get( $metadata_response, 'usage', array() )
        );

        $metadata_update = $this->media_service->persist_attachment_metadata( $original_attachment_id, $metadata );
        if ( is_wp_error( $metadata_update ) ) {
            return new WP_Error( 'fat_attachment_metadata_failed', $metadata_update->get_error_message(), array( 'status' => 500 ) );
        }

        $featured_attachment_id = $this->create_featured_derivative( $original_attachment_id, $prompt, $featured_dimensions['width'], $featured_dimensions['height'], $featured_format );
        if ( is_wp_error( $featured_attachment_id ) ) {
            return $featured_attachment_id;
        }

        $copied_metadata = $this->media_service->copy_attachment_metadata( $original_attachment_id, $featured_attachment_id, true );
        if ( is_wp_error( $copied_metadata ) ) {
            return $copied_metadata;
        }

        return array(
            'workflow'              => 'featured_image_generator',
            'prompt'                => $prompt,
            'image_model'           => $model,
            'image_quality'         => $quality,
            'source_size'           => $size,
            'featured_size'         => $featured_dimensions['width'] . 'x' . $featured_dimensions['height'],
            'featured_format'       => $featured_format,
            'original_attachment_id'=> $original_attachment_id,
            'featured_attachment_id'=> $featured_attachment_id,
            'title'                 => (string) FAT_Helpers::array_get( $metadata, 'title', get_the_title( $original_attachment_id ) ),
            'alt_text'              => (string) FAT_Helpers::array_get( $metadata, 'alt_text', '' ),
            'description'           => (string) FAT_Helpers::array_get( $metadata, 'description', '' ),
            'original_image_url'    => wp_get_attachment_url( $original_attachment_id ),
            'featured_image_url'    => wp_get_attachment_url( $featured_attachment_id ),
            'request_ids'           => array(
                'image'    => FAT_Helpers::array_get( $image_response, 'request_id', '' ),
                'metadata' => FAT_Helpers::array_get( $metadata_response, 'request_id', '' ),
            ),
            'usage'                 => FAT_Helpers::array_get( $metadata_response, 'usage', array() ),
        );
    }

    public function apply_featured_image( $post_id, $attachment_id, $user = null ) {
        $post_id       = absint( $post_id );
        $attachment_id = absint( $attachment_id );
        $user          = $this->normalize_user( $user );

        if ( $post_id <= 0 || $attachment_id <= 0 ) {
            return new WP_Error( 'fat_invalid_featured_apply', __( 'A valid post and generated image are required.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'attachment' === $post->post_type ) {
            return new WP_Error( 'fat_invalid_post', __( 'Selected content could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
            return new WP_Error( 'fat_invalid_post_type', __( 'Selected content type does not support featured images.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'fat_invalid_attachment', __( 'Generated image attachment could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $post_id ) ) {
            return new WP_Error( 'fat_target_forbidden', __( 'You are not allowed to edit this content.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        if ( ! user_can( $user, 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'fat_media_forbidden', __( 'You are not allowed to use this image.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $result = set_post_thumbnail( $post_id, $attachment_id );
        if ( false === $result ) {
            return new WP_Error( 'fat_apply_featured_failed', __( 'Could not set the featured image for this content.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        return array(
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
        );
    }

    protected function save_generated_image_to_media( $raw_image_bytes, $prompt, $suffix, $format = 'png' ) {
        $format      = 'webp' === strtolower( $format ) ? 'webp' : 'png';
        $mime        = 'webp' === $format ? 'image/webp' : 'image/png';
        $filename    = sanitize_file_name( 'fat-generated-' . gmdate( 'Ymd-His' ) . '-' . $suffix . '.' . $format );
        $upload      = wp_upload_bits( $filename, null, $raw_image_bytes );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'fat_media_save_failed', (string) $upload['error'], array( 'status' => 500 ) );
        }

        return $this->media_service->insert_attachment_from_path( $upload['file'], $this->default_title_from_prompt( $prompt ), $mime );
    }

    protected function create_featured_derivative( $source_attachment_id, $prompt, $width, $height, $format ) {
        $source_path = get_attached_file( $source_attachment_id );
        $saved       = $this->media_service->create_image_derivative( $source_path, $width, $height, $format, 'featured' );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        return $this->media_service->insert_attachment_from_path(
            (string) FAT_Helpers::array_get( $saved, 'path', '' ),
            $this->default_title_from_prompt( $prompt ) . ' (Featured ' . $width . 'x' . $height . ')',
            (string) FAT_Helpers::array_get( $saved, 'mime-type', 'image/png' ),
            $source_attachment_id
        );
    }

    protected function default_title_from_prompt( $prompt ) {
        $trimmed = trim( preg_replace( '/\s+/u', ' ', (string) $prompt ) );
        if ( '' === $trimmed ) {
            return __( 'Generated Featured Image', 'fabled-ai-tools' );
        }

        if ( mb_strlen( $trimmed ) > 70 ) {
            $trimmed = mb_substr( $trimmed, 0, 70 ) . '…';
        }

        return $trimmed;
    }

    protected function parse_dimensions( $raw_size, $default_width, $default_height ) {
        if ( preg_match( '/^(\d{2,5})x(\d{2,5})$/', trim( $raw_size ), $matches ) ) {
            return array(
                'width'  => max( 1, absint( $matches[1] ) ),
                'height' => max( 1, absint( $matches[2] ) ),
            );
        }

        return array(
            'width'  => absint( $default_width ),
            'height' => absint( $default_height ),
        );
    }

    protected function normalize_featured_format( $format ) {
        $format = strtolower( sanitize_key( $format ) );

        return in_array( $format, array( 'png', 'webp' ), true ) ? $format : 'png';
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
