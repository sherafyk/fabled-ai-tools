<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Helpers {
    public static function current_datetime_mysql() {
        return current_time( 'mysql' );
    }

    public static function day_bounds() {
        $timestamp = current_time( 'timestamp' );

        return array(
            'start' => wp_date( 'Y-m-d 00:00:00', $timestamp ),
            'end'   => wp_date( 'Y-m-d 23:59:59', $timestamp ),
        );
    }

    public static function clean_slug( $slug, $fallback = '' ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug && '' !== $fallback ) {
            $slug = sanitize_title( $fallback );
        }

        return $slug;
    }

    public static function sanitize_textarea_preserve_newlines( $value ) {
        $value = is_string( $value ) ? wp_unslash( $value ) : '';
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );

        return trim( wp_kses( $value, array() ) );
    }

    public static function array_get( $array, $key, $default = null ) {
        if ( is_array( $array ) && array_key_exists( $key, $array ) ) {
            return $array[ $key ];
        }

        return $default;
    }

    public static function truncate( $value, $length = 300 ) {
        $value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
        $value = trim( (string) $value );

        if ( mb_strlen( $value ) <= $length ) {
            return $value;
        }

        return mb_substr( $value, 0, $length - 1 ) . '…';
    }

    public static function to_bool_flag( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 1 : 0;
        }

        return ! empty( $value ) ? 1 : 0;
    }

    public static function sanitize_keyish( $value ) {
        $value = is_string( $value ) ? wp_unslash( $value ) : '';
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_\-]/', '_', $value );
        $value = preg_replace( '/_+/', '_', $value );

        return trim( $value, '_' );
    }

    public static function sanitize_roles_array( $roles ) {
        $roles      = is_array( $roles ) ? $roles : array();
        $wp_roles   = wp_roles();
        $valid_keys = array_keys( $wp_roles->roles );
        $clean      = array();

        foreach ( $roles as $role ) {
            $role = sanitize_key( $role );
            if ( in_array( $role, $valid_keys, true ) ) {
                $clean[] = $role;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    public static function sanitize_capabilities_list( $value ) {
        if ( is_array( $value ) ) {
            $parts = $value;
        } else {
            $value = is_string( $value ) ? wp_unslash( $value ) : '';
            $parts = preg_split( '/[\r\n,]+/', $value );
        }

        $caps = array();
        foreach ( (array) $parts as $part ) {
            $part = sanitize_key( trim( $part ) );
            if ( '' !== $part ) {
                $caps[] = $part;
            }
        }

        return array_values( array_unique( $caps ) );
    }

    public static function maybe_json_decode( $value, $default = array() ) {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || '' === $value ) {
            return $default;
        }

        $decoded = json_decode( $value, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return $default;
        }

        return $decoded;
    }

    public static function format_user_label( $user_id ) {
        $user = get_userdata( (int) $user_id );
        if ( ! $user ) {
            return '#'. (int) $user_id;
        }

        $label = $user->display_name;
        if ( $user->user_email ) {
            $label .= ' (' . $user->user_email . ')';
        }

        return $label;
    }

    public static function build_preview_from_inputs( $inputs ) {
        $preview = array();
        foreach ( (array) $inputs as $key => $value ) {
            $preview[ $key ] = self::truncate( $value, 220 );
        }

        return wp_json_encode( $preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    public static function build_preview_from_outputs( $outputs ) {
        $preview = array();
        foreach ( (array) $outputs as $key => $value ) {
            $preview[ $key ] = self::truncate( $value, 220 );
        }

        return wp_json_encode( $preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    public static function admin_url_with_notice( $page, $args = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'page' => $page,
            )
        );

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }
}
