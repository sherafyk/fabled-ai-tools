<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Uploaded_Image_Processor {
    protected $client;
    protected $media_service;
    const DEFAULT_MAX_UPLOAD_BYTES = 10485760; // 10 MB.

    public function __construct( FAT_OpenAI_Client $client, FAT_Media_Service $media_service ) {
        $this->client        = $client;
        $this->media_service = $media_service;
    }

    public function execute( $tool, $inputs ) {
        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_Error( 'fat_forbidden_media', __( 'You are not allowed to upload media.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $workflow      = (array) FAT_Helpers::array_get( FAT_Helpers::array_get( $tool, 'wp_integration', array() ), 'workflow_config', array() );
        $target_size   = sanitize_text_field( FAT_Helpers::array_get( $workflow, 'target_size', '1200x675' ) );
        $target_format = sanitize_text_field( FAT_Helpers::array_get( $workflow, 'target_format', 'webp' ) );

        $dimensions = $this->parse_dimensions( $target_size, 1200, 675 );
        $target_size = $dimensions['width'] . 'x' . $dimensions['height'];
        if ( ! in_array( strtolower( $target_format ), array( 'webp', 'png' ), true ) ) {
            $target_format = 'webp';
        }
        $target_format = strtolower( $target_format );

        $uploaded_file = FAT_Helpers::array_get( $inputs, '__fat_uploaded_image_file', null );
        if ( ! is_array( $uploaded_file ) || empty( $uploaded_file['tmp_name'] ) ) {
            return new WP_Error( 'fat_missing_uploaded_image', __( 'An uploaded image file is required.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $diagnostics = array(
            'file'       => $this->uploaded_file_preview( $uploaded_file ),
            'validation' => array( 'status' => 'pending' ),
            'processing' => array( 'status' => 'pending' ),
            'media_save' => array( 'status' => 'pending' ),
            'metadata'   => array( 'status' => 'pending' ),
        );

        $prepared_upload = $this->validate_and_move_uploaded_image( $uploaded_file );
        if ( is_wp_error( $prepared_upload ) ) {
            return $prepared_upload;
        }
        $diagnostics['validation'] = array(
            'status'   => 'success',
            'mime'     => (string) FAT_Helpers::array_get( $prepared_upload, 'type', '' ),
            'size'     => (int) FAT_Helpers::array_get( $prepared_upload, '__fat_size', 0 ),
            'max_size' => $this->max_upload_size_bytes(),
        );

        $source_path = (string) FAT_Helpers::array_get( $prepared_upload, 'file', '' );
        $source_url  = (string) FAT_Helpers::array_get( $prepared_upload, 'url', '' );

        $processed = $this->create_derivative( $source_path, $dimensions['width'], $dimensions['height'], $target_format );
        if ( is_wp_error( $processed ) ) {
            return $processed;
        }
        $diagnostics['processing'] = array(
            'status' => 'success',
            'path'   => (string) FAT_Helpers::array_get( $processed, 'path', '' ),
            'mime'   => (string) FAT_Helpers::array_get( $processed, 'mime-type', 'image/webp' ),
            'width'  => (int) FAT_Helpers::array_get( $processed, 'width', 0 ),
            'height' => (int) FAT_Helpers::array_get( $processed, 'height', 0 ),
        );

        $attachment_id = $this->save_processed_attachment( (string) FAT_Helpers::array_get( $processed, 'path', '' ), (string) FAT_Helpers::array_get( $uploaded_file, 'name', '' ), (string) FAT_Helpers::array_get( $processed, 'mime-type', 'image/webp' ) );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        $diagnostics['media_save'] = array(
            'status'        => 'success',
            'attachment_id' => (int) $attachment_id,
        );

        $warnings = array();
        $metadata = $this->generate_image_metadata_for_attachment( (string) FAT_Helpers::array_get( $processed, 'path', '' ), (string) FAT_Helpers::array_get( $processed, 'mime-type', 'image/webp' ), FAT_Helpers::array_get( $inputs, 'prompt', '' ) );
        if ( is_wp_error( $metadata ) ) {
            $warnings[]             = $metadata->get_error_message();
            $diagnostics['metadata'] = array(
                'status'  => 'fallback',
                'message' => $metadata->get_error_message(),
            );
            $metadata = $this->media_service->build_fallback_metadata( (string) FAT_Helpers::array_get( $uploaded_file, 'name', '' ) );
        } else {
            $diagnostics['metadata'] = array(
                'status'     => 'success',
                'request_id' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $metadata, '__meta', array() ), 'request_id', '' ),
            );
        }

        $persisted = $this->media_service->persist_attachment_metadata( $attachment_id, $metadata );
        if ( is_wp_error( $persisted ) ) {
            return $persisted;
        }

        return array(
            'workflow'          => 'uploaded_image_processor',
            'processed_size'    => $target_size,
            'processed_format'  => $target_format,
            'source_upload_url' => $source_url,
            'attachment_id'     => $attachment_id,
            'title'             => (string) FAT_Helpers::array_get( $metadata, 'title', '' ),
            'alt_text'          => (string) FAT_Helpers::array_get( $metadata, 'alt_text', '' ),
            'description'       => (string) FAT_Helpers::array_get( $metadata, 'description', '' ),
            'processed_image_url' => wp_get_attachment_url( $attachment_id ),
            'request_ids'       => array(
                'metadata' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $metadata, '__meta', array() ), 'request_id', '' ),
            ),
            'usage'             => FAT_Helpers::array_get( FAT_Helpers::array_get( $metadata, '__meta', array() ), 'usage', array() ),
            'warnings'          => $warnings,
            'diagnostics'       => $diagnostics,
        );
    }

    protected function validate_and_move_uploaded_image( $uploaded_file ) {
        $uploaded_file = is_array( $uploaded_file ) ? $uploaded_file : array();

        if ( ! isset( $uploaded_file['error'] ) || UPLOAD_ERR_OK !== (int) $uploaded_file['error'] ) {
            return new WP_Error( 'fat_upload_error', __( 'Image upload failed before processing.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( empty( $uploaded_file['tmp_name'] ) || ! is_uploaded_file( $uploaded_file['tmp_name'] ) ) {
            return new WP_Error( 'fat_upload_invalid_source', __( 'Invalid uploaded file source.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }
        if ( empty( $uploaded_file['name'] ) ) {
            return new WP_Error( 'fat_upload_missing_name', __( 'Uploaded image filename is missing.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $file_size = isset( $uploaded_file['size'] ) ? (int) $uploaded_file['size'] : 0;
        if ( $file_size <= 0 ) {
            return new WP_Error( 'fat_upload_invalid_size', __( 'Uploaded image size is invalid.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $max_size = $this->max_upload_size_bytes();
        if ( $file_size > $max_size ) {
            return new WP_Error( 'fat_upload_too_large', __( 'Uploaded image exceeds the maximum allowed size.', 'fabled-ai-tools' ), array( 'status' => 400, 'max_size' => $max_size ) );
        }

        $sniffed = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );
        $mime    = (string) FAT_Helpers::array_get( $sniffed, 'type', '' );
        if ( '' === $mime || ! in_array( $mime, array_values( $this->allowed_image_mimes() ), true ) ) {
            return new WP_Error( 'fat_upload_invalid_type', __( 'Unsupported image type uploaded.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }
        if ( false === getimagesize( $uploaded_file['tmp_name'] ) ) {
            return new WP_Error( 'fat_upload_not_image', __( 'Uploaded file is not a valid image.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $overrides = array(
            'test_form' => false,
            'mimes'     => $this->allowed_image_mimes(),
        );

        $moved = wp_handle_upload( $uploaded_file, $overrides );
        if ( ! is_array( $moved ) || ! empty( $moved['error'] ) ) {
            return new WP_Error( 'fat_upload_move_failed', (string) FAT_Helpers::array_get( $moved, 'error', __( 'Unable to process uploaded file.', 'fabled-ai-tools' ) ), array( 'status' => 400 ) );
        }

        $type = sanitize_text_field( FAT_Helpers::array_get( $moved, 'type', '' ) );
        if ( ! in_array( $type, array_values( $this->allowed_image_mimes() ), true ) ) {
            return new WP_Error( 'fat_upload_invalid_type', __( 'Unsupported image type uploaded.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }
        $moved['__fat_size'] = file_exists( FAT_Helpers::array_get( $moved, 'file', '' ) ) ? (int) filesize( FAT_Helpers::array_get( $moved, 'file', '' ) ) : 0;

        return $moved;
    }

    protected function create_derivative( $source_path, $width, $height, $format ) {
        $saved = $this->media_service->create_image_derivative( $source_path, $width, $height, $format, 'processed' );
        if ( is_wp_error( $saved ) ) {
            return new WP_Error( 'fat_uploaded_processing_failed', $saved->get_error_message(), array( 'status' => 500 ) );
        }

        return $saved;
    }

    protected function save_processed_attachment( $path, $original_filename, $mime_type ) {
        $title = $this->default_title_from_filename( $original_filename );

        $attachment_id = $this->media_service->insert_attachment_from_path( $path, $title, $mime_type );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'fat_uploaded_attachment_create_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        return (int) $attachment_id;
    }

    protected function generate_image_metadata_for_attachment( $processed_path, $mime_type, $prompt_context ) {
        $bytes = file_get_contents( $processed_path );
        if ( false === $bytes || '' === $bytes ) {
            return new WP_Error( 'fat_uploaded_metadata_file_read_failed', __( 'Processed image could not be read for metadata generation.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $response = $this->client->generate_image_metadata(
            array(
                'prompt'          => (string) $prompt_context,
                'image_b64'       => base64_encode( $bytes ),
                'image_mime_type' => sanitize_text_field( (string) $mime_type ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->media_service->normalize_attachment_metadata(
            (array) FAT_Helpers::array_get( $response, 'parsed', array() ),
            (string) FAT_Helpers::array_get( $response, 'request_id', '' ),
            (array) FAT_Helpers::array_get( $response, 'usage', array() )
        );
    }



    protected function parse_dimensions( $raw_size, $default_width, $default_height ) {
        if ( preg_match( '/^(\d{2,5})x(\d{2,5})$/', trim( (string) $raw_size ), $matches ) ) {
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

    protected function allowed_image_mimes() {
        return array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        );
    }

    protected function default_title_from_filename( $filename ) {
        $filename = (string) $filename;
        $base     = preg_replace( '/\.[^.]+$/', '', wp_basename( $filename ) );
        $base     = trim( str_replace( array( '-', '_' ), ' ', (string) $base ) );

        if ( '' === $base ) {
            return __( 'Processed Uploaded Image', 'fabled-ai-tools' );
        }

        return $this->media_service->default_title_from_label( $base );
    }

    protected function max_upload_size_bytes() {
        $wp_limit = (int) wp_max_upload_size();
        $default  = self::DEFAULT_MAX_UPLOAD_BYTES;
        $limit    = (int) apply_filters( 'fat_uploaded_image_max_bytes', $default );
        $limit    = $limit > 0 ? $limit : $default;

        if ( $wp_limit > 0 ) {
            $limit = min( $limit, $wp_limit );
        }

        return max( 1, $limit );
    }


    protected function uploaded_file_preview( $uploaded_file ) {
        return array(
            'name'  => sanitize_file_name( (string) FAT_Helpers::array_get( $uploaded_file, 'name', '' ) ),
            'type'  => sanitize_text_field( (string) FAT_Helpers::array_get( $uploaded_file, 'type', '' ) ),
            'size'  => (int) FAT_Helpers::array_get( $uploaded_file, 'size', 0 ),
            'error' => isset( $uploaded_file['error'] ) ? (int) $uploaded_file['error'] : null,
        );
    }
}
