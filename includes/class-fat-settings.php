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
        'execution_enabled'   => 1,
        'log_retention_days'  => 30,
    );

    public function hooks() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'fat_daily_log_cleanup', array( $this, 'handle_daily_log_cleanup' ) );
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

        add_settings_section(
            'fat_operations_section',
            __( 'Operational Controls', 'fabled-ai-tools' ),
            array( $this, 'render_operations_section' ),
            'fat-settings'
        );

        add_settings_field(
            'execution_enabled',
            __( 'Runner availability', 'fabled-ai-tools' ),
            array( $this, 'render_execution_enabled_field' ),
            'fat-settings',
            'fat_operations_section'
        );

        add_settings_field(
            'log_retention_days',
            __( 'Log retention (days)', 'fabled-ai-tools' ),
            array( $this, 'render_log_retention_days_field' ),
            'fat-settings',
            'fat_operations_section'
        );
    }

    public function sanitize_settings( $input ) {
        $stored = $this->get_all();
        $input  = is_array( $input ) ? $input : array();

        $api_key_input = sanitize_text_field( FAT_Helpers::array_get( $input, 'api_key', '' ) );
        $clear_api_key = ! empty( FAT_Helpers::array_get( $input, 'clear_api_key', 0 ) );
        $clean_api_key = (string) FAT_Helpers::array_get( $stored, 'api_key', '' );

        if ( ! $this->has_constant_api_key() ) {
            if ( $clear_api_key ) {
                $clean_api_key = '';
            } elseif ( '' !== trim( $api_key_input ) ) {
                $clean_api_key = trim( $api_key_input );
            }
        }

        $clean = array(
            'api_key'             => $clean_api_key,
            'default_model'       => sanitize_text_field( FAT_Helpers::array_get( $input, 'default_model', $this->defaults['default_model'] ) ),
            'default_daily_limit' => max( 0, absint( FAT_Helpers::array_get( $input, 'default_daily_limit', $this->defaults['default_daily_limit'] ) ) ),
            'default_timeout'     => max( 5, absint( FAT_Helpers::array_get( $input, 'default_timeout', $this->defaults['default_timeout'] ) ) ),
            'execution_enabled'   => ! empty( FAT_Helpers::array_get( $input, 'execution_enabled', 0 ) ) ? 1 : 0,
            'log_retention_days'  => max( 0, absint( FAT_Helpers::array_get( $input, 'log_retention_days', $this->defaults['log_retention_days'] ) ) ),
        );

        if ( '' === $clean['default_model'] ) {
            $clean['default_model'] = $this->defaults['default_model'];
        }

        $this->ensure_log_cleanup_schedule();

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

    public function is_execution_enabled() {
        return ! empty( $this->get( 'execution_enabled', 1 ) );
    }

    public function log_retention_days() {
        return max( 0, absint( $this->get( 'log_retention_days', $this->defaults['log_retention_days'] ) ) );
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

    public function render_operations_section() {
        echo '<p>' . esc_html__( 'Control runner availability and log retention for operations.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_api_key_field() {
        $masked = $this->mask_api_key();

        if ( $this->has_constant_api_key() ) {
            echo '<p><strong>' . esc_html__( 'API key is being loaded from the FAT_OPENAI_API_KEY constant in wp-config.php.', 'fabled-ai-tools' ) . '</strong></p>';
            echo '<p>' . esc_html( $masked ) . '</p>';
            echo '<input type="password" class="regular-text" disabled value="' . esc_attr( $masked ) . '" />';
            echo '<p class="description">' . esc_html__( 'The saved option will be ignored while the constant is defined.', 'fabled-ai-tools' ) . '</p>';
            return;
        }

        if ( '' !== $masked ) {
            echo '<p><strong>' . esc_html__( 'Current saved key:', 'fabled-ai-tools' ) . '</strong> ' . esc_html( $masked ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'No API key is currently stored in plugin settings.', 'fabled-ai-tools' ) . '</p>';
        }

        echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_key]" value="" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Leave blank to keep the existing key. Enter a value to replace it.', 'fabled-ai-tools' ) . '</p>';
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[clear_api_key]" value="1" /> ' . esc_html__( 'Clear stored API key', 'fabled-ai-tools' ) . '</label>';
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

    public function render_execution_enabled_field() {
        $enabled = ! empty( $this->get( 'execution_enabled', 1 ) );
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[execution_enabled]" value="1" ' . checked( true, $enabled, false ) . ' /> ' . esc_html__( 'Enable tool runs and apply actions', 'fabled-ai-tools' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Disable this during incidents or budget freezes. Runner and apply requests will be blocked while disabled.', 'fabled-ai-tools' ) . '</p>';
    }

    public function render_log_retention_days_field() {
        $days = $this->log_retention_days();
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPTION_KEY ) . '[log_retention_days]" value="' . esc_attr( $days ) . '" />';
        echo '<p class="description">' . esc_html__( 'Logs older than this many days are removed during daily cleanup. Set 0 to disable automatic cleanup.', 'fabled-ai-tools' ) . '</p>';
    }

    public function ensure_log_cleanup_schedule() {
        if ( ! wp_next_scheduled( 'fat_daily_log_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fat_daily_log_cleanup' );
        }
    }

    public function handle_daily_log_cleanup() {
        $days = $this->log_retention_days();
        if ( $days <= 0 ) {
            return;
        }

        $runs_repo = new FAT_Runs_Repository();
        $runs_repo->delete_older_than_days( $days );
    }
}
