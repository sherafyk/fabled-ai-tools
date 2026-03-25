<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Media_Service {
    public function create_image_derivative( $source_path, $width, $height, $format, $suffix ) {
        if ( ! $source_path || ! file_exists( $source_path ) ) {
            return new WP_Error( 'fat_media_source_missing', __( 'Source image file could not be found.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $width  = max( 1, absint( $width ) );
        $height = max( 1, absint( $height ) );
        $format = $this->normalize_image_format( $format );
        $suffix = sanitize_file_name( (string) $suffix );

        $editor = wp_get_image_editor( $source_path );
        if ( is_wp_error( $editor ) ) {
            return $this->create_image_derivative_with_gd( $source_path, $width, $height, $format, $suffix );
        }

        $resized = $editor->resize( $width, $height, true );
        if ( is_wp_error( $resized ) ) {
            return $this->create_image_derivative_with_gd( $source_path, $width, $height, $format, $suffix );
        }

        $dest_path = trailingslashit( dirname( $source_path ) )
            . wp_basename( $source_path, '.' . pathinfo( $source_path, PATHINFO_EXTENSION ) )
            . '-' . $suffix . '-' . $width . 'x' . $height . '.' . $format;

        $saved = $editor->save( $dest_path, $this->mime_type_for_format( $format ) );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
            return $this->create_image_derivative_with_gd( $source_path, $width, $height, $format, $suffix );
        }

        return $saved;
    }

    public function insert_attachment_from_path( $path, $title, $mime_type, $parent_id = 0, $content = '' ) {
        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => sanitize_text_field( $mime_type ),
                'post_title'     => sanitize_text_field( $title ),
                'post_content'   => FAT_Helpers::sanitize_textarea_preserve_newlines( $content ),
                'post_status'    => 'inherit',
                'post_parent'    => absint( $parent_id ),
            ),
            $path,
            0,
            true
        );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $path );
        if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        return (int) $attachment_id;
    }

    public function persist_attachment_metadata( $attachment_id, $metadata ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return new WP_Error( 'fat_metadata_invalid_attachment', __( 'Attachment is required to persist metadata.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $title       = sanitize_text_field( FAT_Helpers::array_get( $metadata, 'title', '' ) );
        $alt_text    = sanitize_text_field( FAT_Helpers::array_get( $metadata, 'alt_text', '' ) );
        $description = FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $metadata, 'description', '' ) );

        $updated = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $attachment_id,
                    'post_title'   => '' !== $title ? $title : get_the_title( $attachment_id ),
                    'post_content' => $description,
                )
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

        return true;
    }

    public function copy_attachment_metadata( $source_attachment_id, $target_attachment_id, $preserve_target_title = false ) {
        $source_attachment_id = absint( $source_attachment_id );
        $target_attachment_id = absint( $target_attachment_id );

        $source = get_post( $source_attachment_id );
        $target = get_post( $target_attachment_id );
        if ( ! $source || ! $target || 'attachment' !== $source->post_type || 'attachment' !== $target->post_type ) {
            return new WP_Error( 'fat_copy_metadata_missing', __( 'Attachment metadata source or target is missing.', 'fabled-ai-tools' ), array( 'status' => 404 ) );
        }

        $meta = array(
            'title'       => $preserve_target_title ? get_the_title( $target_attachment_id ) : get_the_title( $source_attachment_id ),
            'alt_text'    => (string) get_post_meta( $source_attachment_id, '_wp_attachment_image_alt', true ),
            'description' => (string) $source->post_content,
        );

        $persisted = $this->persist_attachment_metadata( $target_attachment_id, $meta );
        if ( is_wp_error( $persisted ) ) {
            return $persisted;
        }

        $updated = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $target_attachment_id,
                    'post_excerpt' => (string) $source->post_excerpt,
                )
            ),
            true
        );

        return is_wp_error( $updated ) ? $updated : true;
    }

    public function build_fallback_metadata( $fallback_label ) {
        $title = $this->default_title_from_label( $fallback_label );

        return array(
            'title'       => $title,
            'alt_text'    => $title,
            'description' => '',
            '__meta'      => array(
                'request_id' => '',
                'usage'      => array(),
            ),
        );
    }

    public function normalize_attachment_metadata( $metadata, $request_id = '', $usage = array() ) {
        return array(
            'title'       => sanitize_text_field( FAT_Helpers::array_get( $metadata, 'title', '' ) ),
            'alt_text'    => sanitize_text_field( FAT_Helpers::array_get( $metadata, 'alt_text', '' ) ),
            'description' => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $metadata, 'description', '' ) ),
            '__meta'      => array(
                'request_id' => (string) $request_id,
                'usage'      => (array) $usage,
            ),
        );
    }

    public function attachment_image_as_base64( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        $path          = get_attached_file( $attachment_id );

        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'fat_attachment_file_missing', __( 'Attachment file could not be found.', 'fabled-ai-tools' ), array( 'status' => 400 ) );
        }

        $bytes = file_get_contents( $path );
        if ( false === $bytes || '' === $bytes ) {
            return new WP_Error( 'fat_attachment_file_read_failed', __( 'Attachment file could not be read.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        return array(
            'image_b64'  => base64_encode( $bytes ),
            'mime_type'  => (string) get_post_mime_type( $attachment_id ),
            'path'       => $path,
            'filename'   => wp_basename( $path ),
        );
    }

    public function default_title_from_label( $label ) {
        $label = (string) $label;
        $base  = preg_replace( '/\.[^.]+$/', '', wp_basename( $label ) );
        $base  = trim( str_replace( array( '-', '_' ), ' ', (string) $base ) );

        if ( '' === $base ) {
            return __( 'Generated Image', 'fabled-ai-tools' );
        }

        return sanitize_text_field( $base );
    }

    protected function normalize_image_format( $format ) {
        $format = strtolower( sanitize_key( $format ) );

        if ( ! in_array( $format, array( 'png', 'webp', 'jpeg', 'jpg' ), true ) ) {
            return 'png';
        }

        return 'jpg' === $format ? 'jpeg' : $format;
    }

    protected function mime_type_for_format( $format ) {
        $map = array(
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
        );

        return (string) FAT_Helpers::array_get( $map, $this->normalize_image_format( $format ), 'image/png' );
    }

    protected function create_image_derivative_with_gd( $source_path, $target_width, $target_height, $format, $suffix ) {
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return new WP_Error( 'fat_media_gd_missing', __( 'Server is missing required GD image functions.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $raw = file_get_contents( $source_path );
        if ( false === $raw || '' === $raw ) {
            return new WP_Error( 'fat_media_read_failed', __( 'Unable to read source image data.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $source_image = imagecreatefromstring( $raw );
        if ( ! $source_image ) {
            return new WP_Error( 'fat_media_decode_failed', __( 'Unable to decode source image data.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        $source_width  = imagesx( $source_image );
        $source_height = imagesy( $source_image );
        $target_ratio = $target_width / $target_height;
        $source_ratio = $source_width / $source_height;

        $crop_width  = $source_width;
        $crop_height = $source_height;
        $src_x       = 0;
        $src_y       = 0;

        if ( $source_ratio > $target_ratio ) {
            $crop_width = (int) floor( $source_height * $target_ratio );
            $src_x      = (int) floor( ( $source_width - $crop_width ) / 2 );
        } elseif ( $source_ratio < $target_ratio ) {
            $crop_height = (int) floor( $source_width / $target_ratio );
            $src_y       = (int) floor( ( $source_height - $crop_height ) / 2 );
        }

        $dest_image = imagecreatetruecolor( $target_width, $target_height );
        imagealphablending( $dest_image, true );
        imagesavealpha( $dest_image, true );

        imagecopyresampled( $dest_image, $source_image, 0, 0, $src_x, $src_y, $target_width, $target_height, $crop_width, $crop_height );

        $dest_path = trailingslashit( dirname( $source_path ) )
            . wp_basename( $source_path, '.' . pathinfo( $source_path, PATHINFO_EXTENSION ) )
            . '-' . $suffix . '-' . $target_width . 'x' . $target_height . '.' . $format;

        $saved = false;
        if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
            $saved = imagewebp( $dest_image, $dest_path, 85 );
        } elseif ( 'png' === $format && function_exists( 'imagepng' ) ) {
            $saved = imagepng( $dest_image, $dest_path );
        } elseif ( 'jpeg' === $format && function_exists( 'imagejpeg' ) ) {
            $saved = imagejpeg( $dest_image, $dest_path, 90 );
        }

        imagedestroy( $dest_image );
        imagedestroy( $source_image );

        if ( ! $saved || ! file_exists( $dest_path ) ) {
            return new WP_Error( 'fat_media_save_failed', __( 'Unable to save processed image.', 'fabled-ai-tools' ), array( 'status' => 500 ) );
        }

        return array(
            'path'      => $dest_path,
            'file'      => wp_basename( $dest_path ),
            'width'     => $target_width,
            'height'    => $target_height,
            'mime-type' => $this->mime_type_for_format( $format ),
            'filesize'  => (int) filesize( $dest_path ),
        );
    }
}
