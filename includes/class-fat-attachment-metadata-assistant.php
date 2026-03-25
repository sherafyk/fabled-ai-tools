<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Attachment_Metadata_Assistant {
    protected $client;
    protected $media_service;

    public function __construct( FAT_OpenAI_Client $client, FAT_Media_Service $media_service ) {
        $this->client        = $client;
        $this->media_service = $media_service;
    }

    public function execute( $tool, $inputs, $user = null ) {
        $user          = $this->normalize_user( $user );
        $attachment_id = absint( FAT_Helpers::array_get( $inputs, '__fat_attachment_id', 0 ) );
        if ( $attachment_id <= 0 ) {
            return new WP_Error( 'fat_missing_attachment', __( 'Select an attachment to generate metadata.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'fat_attachment_forbidden', __( 'You are not allowed to edit this attachment.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'fat_invalid_attachment', __( 'Selected attachment could not be found.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        $encoded = $this->media_service->attachment_image_as_base64( $attachment_id );
        if ( is_wp_error( $encoded ) ) {
            return $encoded;
        }

        $prompt_context = trim( (string) FAT_Helpers::array_get( $inputs, 'prompt', '' ) );
        if ( '' === $prompt_context ) {
            $prompt_context = sprintf(
                /* translators: %s: attachment title */
                __( 'Attachment title: %s', 'fabled-ai-tools' ),
                get_the_title( $attachment_id )
            );
        }

        $warnings = array();
        $metadata = $this->client->generate_image_metadata(
            array(
                'prompt'          => $prompt_context,
                'image_b64'       => (string) FAT_Helpers::array_get( $encoded, 'image_b64', '' ),
                'image_mime_type' => (string) FAT_Helpers::array_get( $encoded, 'mime_type', 'image/png' ),
            )
        );

        if ( is_wp_error( $metadata ) ) {
            $warnings[] = $metadata->get_error_message();
            $metadata   = $this->media_service->build_fallback_metadata( (string) FAT_Helpers::array_get( $encoded, 'filename', '' ) );
        } else {
            $metadata = $this->media_service->normalize_attachment_metadata(
                (array) FAT_Helpers::array_get( $metadata, 'parsed', array() ),
                (string) FAT_Helpers::array_get( $metadata, 'request_id', '' ),
                (array) FAT_Helpers::array_get( $metadata, 'usage', array() )
            );
        }

        return array(
            'workflow'       => 'attachment_metadata_assistant',
            'attachment_id'  => $attachment_id,
            'title'          => (string) FAT_Helpers::array_get( $metadata, 'title', '' ),
            'alt_text'       => (string) FAT_Helpers::array_get( $metadata, 'alt_text', '' ),
            'description'    => (string) FAT_Helpers::array_get( $metadata, 'description', '' ),
            'request_ids'    => array(
                'metadata' => (string) FAT_Helpers::array_get( FAT_Helpers::array_get( $metadata, '__meta', array() ), 'request_id', '' ),
            ),
            'usage'          => (array) FAT_Helpers::array_get( FAT_Helpers::array_get( $metadata, '__meta', array() ), 'usage', array() ),
            'warnings'       => $warnings,
            'attachment_url' => wp_get_attachment_url( $attachment_id ),
        );
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
