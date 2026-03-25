<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Featured_Image_Generator {
    protected $client;

    public function __construct( FAT_OpenAI_Client $client ) {
        $this->client = $client;
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

        $original_attachment_id = $this->save_generated_image_to_media( $raw_image_bytes, $prompt, 'original' );
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

        $generated_metadata = (array) FAT_Helpers::array_get( $metadata_response, 'parsed', array() );
        $title              = sanitize_text_field( FAT_Helpers::array_get( $generated_metadata, 'title', '' ) );
        $alt_text           = sanitize_text_field( FAT_Helpers::array_get( $generated_metadata, 'alt_text', '' ) );
        $description        = FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $generated_metadata, 'description', '' ) );

        $metadata_update = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $original_attachment_id,
                    'post_title'   => '' !== $title ? $title : $this->default_title_from_prompt( $prompt ),
                    'post_content' => $description,
                )
            ),
            true
        );
        if ( is_wp_error( $metadata_update ) ) {
            return new WP_Error( 'fat_attachment_metadata_failed', $metadata_update->get_error_message(), array( 'status' => 500 ) );
        }

        update_post_meta( $original_attachment_id, '_wp_attachment_image_alt', $alt_text );

        $featured_attachment_id = $this->create_featured_derivative_png( $original_attachment_id, $prompt );
        if ( is_wp_error( $featured_attachment_id ) ) {
            return $featured_attachment_id;
        }

        return array(
            'workflow'              => 'featured_image_generator',
            'prompt'                => $prompt,
            'image_model'           => $model,
            'image_quality'         => $quality,
            'source_size'           => $size,
            'original_attachment_id'=> $original_attachment_id,
            'featured_attachment_id'=> $featured_attachment_id,
            'title'                 => '' !== $title ? $title : get_the_title( $original_attachment_id ),
            'alt_text'              => $alt_text,
            'description'           => $description,
            'original_image_url'    => wp_get_attachment_url( $original_attachment_id ),
            'featured_image_url'    => wp_get_attachment_url( $featured_attachment_id ),
            'request_ids'           => array(
                'image'    => FAT_Helpers::array_get( $image_response, 'request_id', '' ),
                'metadata' => FAT_Helpers::array_get( $metadata_response, 'request_id', '' ),
            ),
            'usage'                 => FAT_Helpers::array_get( $metadata_response, 'usage', array() ),
        );
    }

    public function apply_featured_image( $post_id, $attachment_id ) {
        $post_id       = absint( $post_id );
        $attachment_id = absint( $attachment_id );

        if ( $post_id <= 0 || $attachment_id <= 0 ) {
            return new WP_Error( 'fat_invalid_featured_apply', __( 'A valid post and generated image are required.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'fat_invalid_post', __( 'Selected post could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'fat_invalid_attachment', __( 'Generated image attachment could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'fat_target_forbidden', __( 'You are not allowed to edit this post.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'fat_media_forbidden', __( 'You are not allowed to use this image.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $result = set_post_thumbnail( $post_id, $attachment_id );
        if ( false === $result ) {
            return new WP_Error( 'fat_apply_featured_failed', __( 'Could not set the featured image for this post.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        return array(
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
        );
    }

    protected function save_generated_image_to_media( $raw_image_bytes, $prompt, $suffix ) {
        $filename     = sanitize_file_name( 'fat-generated-' . gmdate( 'Ymd-His' ) . '-' . $suffix . '.png' );
        $upload       = wp_upload_bits( $filename, null, $raw_image_bytes );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'fat_media_save_failed', (string) $upload['error'], array( 'status' => 500 ) );
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => 'image/png',
                'post_title'     => $this->default_title_from_prompt( $prompt ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $upload['file'],
            0,
            true
        );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'fat_attachment_create_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        return (int) $attachment_id;
    }

    protected function create_featured_derivative_png( $source_attachment_id, $prompt ) {
        $source_path = get_attached_file( $source_attachment_id );
        if ( ! $source_path || ! file_exists( $source_path ) ) {
            return new WP_Error( 'fat_source_image_missing', __( 'Source image file could not be found.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $editor = wp_get_image_editor( $source_path );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error( 'fat_crop_init_failed', $editor->get_error_message(), array( 'status' => 500 ) );
        }

        $resize = $editor->resize( 1200, 675, true );
        if ( is_wp_error( $resize ) ) {
            return new WP_Error( 'fat_crop_resize_failed', $resize->get_error_message(), array( 'status' => 500 ) );
        }

        $dest_path = trailingslashit( dirname( $source_path ) ) . wp_basename( $source_path, '.' . pathinfo( $source_path, PATHINFO_EXTENSION ) ) . '-featured-1200x675.png';
        $saved     = $editor->save( $dest_path, 'image/png' );

        if ( is_wp_error( $saved ) ) {
            return new WP_Error( 'fat_crop_save_failed', $saved->get_error_message(), array( 'status' => 500 ) );
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => 'image/png',
                'post_title'     => $this->default_title_from_prompt( $prompt ) . ' (Featured 1200x675)',
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $source_attachment_id,
            ),
            $saved['path'],
            0,
            true
        );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'fat_featured_attachment_create_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
        if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        return (int) $attachment_id;
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
}
