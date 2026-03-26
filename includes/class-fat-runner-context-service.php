<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Runner_Context_Service {
    public function resolve_contextual_inputs( $tool, $raw_inputs, $user = null ) {
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();

        $inputs = $this->resolve_inputs_from_selected_post( $tool, $inputs, $user );
        if ( is_wp_error( $inputs ) ) {
            return $inputs;
        }

        $inputs = $this->resolve_inputs_from_selected_attachment( $tool, $inputs, $user );
        if ( is_wp_error( $inputs ) ) {
            return $inputs;
        }

        return $inputs;
    }

    public function resolve_inputs_from_selected_post( $tool, $raw_inputs, $user = null ) {
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();

        if ( ! $this->tool_has_input_key( $tool, 'article_body' ) ) {
            return $inputs;
        }

        $source = sanitize_key( FAT_Helpers::array_get( $inputs, '__fat_article_source', 'paste' ) );
        if ( ! in_array( $source, array( 'draft', 'publish' ), true ) ) {
            return $inputs;
        }

        $post_id = absint( FAT_Helpers::array_get( $inputs, '__fat_article_post_id', 0 ) );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Please select a post for the chosen content source.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || ! $this->is_supported_content_target_post( $post ) ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected content item could not be found.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $post_id ) ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'You are not allowed to use the selected content item.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $status = get_post_status( $post );
        if ( ! in_array( $status, array( 'draft', 'publish' ), true ) || $status !== $source ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected content item is not valid for the chosen source.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $inputs['article_body'] = $this->normalize_post_content_for_article_body( $post->post_content );

        if ( '' === trim( (string) FAT_Helpers::array_get( $inputs, 'title', '' ) ) ) {
            $inputs['title'] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        }

        if ( 'publish' === $status && '' === trim( (string) FAT_Helpers::array_get( $inputs, 'url', '' ) ) ) {
            $permalink = get_permalink( $post );
            if ( $permalink ) {
                $inputs['url'] = $permalink;
            }
        }

        return $inputs;
    }

    public function resolve_inputs_from_selected_attachment( $tool, $raw_inputs, $user = null ) {
        $inputs = is_array( $raw_inputs ) ? $raw_inputs : array();

        $attachment_id = absint( FAT_Helpers::array_get( $inputs, '__fat_attachment_id', 0 ) );
        if ( $attachment_id <= 0 ) {
            return $inputs;
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'Selected attachment could not be found.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'fat_invalid_inputs', __( 'You are not allowed to use the selected attachment.', 'fabled-ai-tools' ), array( 'status' => 403 ) );
        }

        $attached_file = get_attached_file( $attachment_id );
        $file_url      = wp_get_attachment_url( $attachment_id );
        $parent_title  = '';

        if ( ! empty( $attachment->post_parent ) ) {
            $parent_post = get_post( (int) $attachment->post_parent );
            if ( $parent_post ) {
                $parent_title = html_entity_decode( get_the_title( $parent_post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            }
        }

        $context_values = array(
            'attachment_id'          => (string) $attachment_id,
            'attachment_title'       => html_entity_decode( get_the_title( $attachment ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
            'attachment_caption'     => (string) $attachment->post_excerpt,
            'attachment_description' => (string) $attachment->post_content,
            'attachment_alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'attachment_url'         => $file_url ? (string) $file_url : '',
            'attachment_filename'    => $attached_file ? wp_basename( $attached_file ) : '',
            'attachment_mime_type'   => (string) get_post_mime_type( $attachment ),
            'attachment_parent_title'=> $parent_title,
        );

        foreach ( $context_values as $key => $value ) {
            if ( $this->tool_has_input_key( $tool, $key ) ) {
                $inputs[ $key ] = $value;
            }
        }

        return $inputs;
    }

    public function is_supported_content_target_post( $post ) {
        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        if ( 'attachment' === $post->post_type ) {
            return false;
        }

        $supported_types = $this->get_supported_content_target_post_types();
        if ( ! in_array( sanitize_key( $post->post_type ), $supported_types, true ) ) {
            return false;
        }

        return true;
    }

    protected function normalize_post_content_for_article_body( $content ) {
        $content = (string) $content;
        $content = strip_shortcodes( $content );
        $content = wp_strip_all_tags( $content );
        $content = preg_replace( '/\s+/u', ' ', $content );

        return trim( (string) $content );
    }

    protected function tool_has_input_key( $tool, $key ) {
        $key = sanitize_key( $key );
        foreach ( (array) FAT_Helpers::array_get( $tool, 'input_schema', array() ) as $field ) {
            if ( $key === sanitize_key( FAT_Helpers::array_get( $field, 'key', '' ) ) ) {
                return true;
            }
        }

        return false;
    }

    protected function get_supported_content_target_post_types() {
        $objects = get_post_types(
            array(
                'public'  => true,
                'show_ui' => true,
            ),
            'objects'
        );
        $types = array();

        foreach ( (array) $objects as $post_type => $object ) {
            if ( 'attachment' === $post_type ) {
                continue;
            }
            if ( ! post_type_supports( $post_type, 'editor' ) ) {
                continue;
            }
            $types[] = sanitize_key( $post_type );
        }

        if ( empty( $types ) ) {
            $types[] = 'post';
        }

        return array_values( array_unique( $types ) );
    }
}
