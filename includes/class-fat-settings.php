<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Settings {
    const OPTION_KEY = 'fat_settings';

    protected $defaults = array(
        'api_key'             => '',
        'default_model'       => 'gpt-5.4-mini',
        'default_daily_limit' => 20,
        'default_timeout'     => 45,
    );

    public function hooks() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_settings() {
        register_setting(
            'fat_settings_group',
            self::OPTION_KEY,
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'fat_main_section',
            __( 'OpenAI Settings', 'fabled-ai-tools' ),
            array( $this, 'render_main_section' ),
            'fat-settings'
        );

        add_settings_field(
            'api_key',
            __( 'OpenAI API Key', 'fabled-ai-tools' ),
            array( $this, 'render_api_key_field' ),
            'fat-settings',
            'fat_main_section'
        );

        add_settings_field(
            'default_model',
            __( 'Default model', 'fabled-ai-tools' ),
            array( $this, 'render_default_model_field' ),
            'fat-settings',
            'fat_main_section'
        );

        add_settings_field(
            'default_daily_limit',
            __( 'Default daily limit per user', 'fabled-ai-tools' ),
            array( $this, 'render_default_daily_limit_field' ),
            'fat-settings',
            'fat_main_section'
        );

        add_settings_field(
            'default_timeout',
            __( 'Request timeout (seconds)', 'fabled-ai-tools' ),
            array( $this, 'render_default_timeout_field' ),
            'fat-settings',
            'fat_main_section'
        );
    }

    public function sanitize_settings( $input ) {
        $stored = $this->get_all();
        $input  = is_array( $input ) ? $input : array();

        $clean = array(
            'api_key'             => $this->has_constant_api_key() ? $stored['api_key'] : sanitize_text_field( FAT_Helpers::array_get( $input, 'api_key', '' ) ),
            'default_model'       => sanitize_text_field( FAT_Helpers::array_get( $input, 'default_model', $this->defaults['default_model'] ) ),
            'default_daily_limit' => max( 0, absint( FAT_Helpers::array_get( $input, 'default_daily_limit', $this->defaults['default_daily_limit'] ) ) ),
            'default_timeout'     => max( 5, absint( FAT_Helpers::array_get( $input, 'default_timeout', $this->defaults['default_timeout'] ) ) ),
        );

        if ( '' === $clean['default_model'] ) {
            $clean['default_model'] = $this->defaults['default_model'];
        }

        return $clean;
    }

    public function get_all() {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return wp_parse_args( $stored, $this->defaults );
    }

    public function get( $key, $default = null ) {
        $settings = $this->get_all();
        return FAT_Helpers::array_get( $settings, $key, $default );
    }

    public function get_api_key() {
        if ( $this->has_constant_api_key() ) {
            return trim( (string) FAT_OPENAI_API_KEY );
        }

        return trim( (string) $this->get( 'api_key', '' ) );
    }

    public function has_api_key() {
        return '' !== $this->get_api_key();
    }

    public function has_constant_api_key() {
        return defined( 'FAT_OPENAI_API_KEY' ) && FAT_OPENAI_API_KEY;
    }

    public function mask_api_key() {
        $key = $this->get_api_key();
        if ( '' === $key ) {
            return '';
        }

        $length = strlen( $key );
        if ( $length <= 8 ) {
            return str_repeat( '*', $length );
        }

        return substr( $key, 0, 4 ) . str_repeat( '*', max( 0, $length - 8 ) ) . substr( $key, -4 );
    }

    public function render_main_section() {
        echo '<p>' . esc_html__( 'Configure the server-side OpenAI connection and basic execution defaults.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_api_key_field() {
        $settings = $this->get_all();
        $value    = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';

        if ( $this->has_constant_api_key() ) {
            echo '<p><strong>' . esc_html__( 'API key is being loaded from the FAT_OPENAI_API_KEY constant in wp-config.php.', 'fabled-ai-tools' ) . '</strong></p>';
            echo '<p>' . esc_html( $this->mask_api_key() ) . '</p>';
            echo '<input type="password" class="regular-text" disabled value="' . esc_attr( $this->mask_api_key() ) . '" />';
            echo '<p class="description">' . esc_html__( 'The saved option will be ignored while the constant is defined.', 'fabled-ai-tools' ) . '</p>';
            return;
        }

        echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_key]" value="' . esc_attr( $value ) . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Stored in WordPress options unless FAT_OPENAI_API_KEY is defined in wp-config.php.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_default_model_field() {
        $value = (string) $this->get( 'default_model', $this->defaults['default_model'] );
        echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[default_model]" value="' . esc_attr( $value ) . '" />';
        echo '<p class="description">' . esc_html__( 'Used when a tool does not set its own model override.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_default_daily_limit_field() {
        $value = (int) $this->get( 'default_daily_limit', $this->defaults['default_daily_limit'] );
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPTION_KEY ) . '[default_daily_limit]" value="' . esc_attr( $value ) . '" />';
        echo '<p class="description">' . esc_html__( '0 disables the default limit. A tool-specific limit overrides this value.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_default_timeout_field() {
        $value = (int) $this->get( 'default_timeout', $this->defaults['default_timeout'] );
        echo '<input type="number" min="5" step="1" name="' . esc_attr( self::OPTION_KEY ) . '[default_timeout]" value="' . esc_attr( $value ) . '" />';
        echo '<p class="description">' . esc_html__( 'Timeout used for OpenAI requests when no other value is specified.', 'fabled-ai-tools' ) . '</p>';
    }
}
