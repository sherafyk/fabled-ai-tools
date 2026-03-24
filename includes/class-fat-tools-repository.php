<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Tools_Repository {
    protected $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fat_tools';
    }

    public function table() {
        return $this->table;
    }

    public function get_all( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'active_only' => false,
            'orderby'     => 'sort_order ASC, name ASC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        if ( ! empty( $args['active_only'] ) ) {
            $where .= ' AND is_active = 1';
        }

        $sql  = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$args['orderby']}";
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return array_map( array( $this, 'map_row' ), (array) $rows );
    }

    public function get_by_id( $id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", absint( $id ) ),
            ARRAY_A
        );

        return $row ? $this->map_row( $row ) : null;
    }

    public function get_by_slug( $slug ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE slug = %s", sanitize_title( $slug ) ),
            ARRAY_A
        );

        return $row ? $this->map_row( $row ) : null;
    }

    public function exists_slug( $slug, $exclude_id = 0 ) {
        global $wpdb;

        $slug       = sanitize_title( $slug );
        $exclude_id = absint( $exclude_id );

        if ( $exclude_id > 0 ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id != %d", $slug, $exclude_id )
            );
        } else {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s", $slug )
            );
        }

        return $count > 0;
    }

    public function create( $data ) {
        global $wpdb;

        $record = $this->prepare_record( $data, false );
        $result = $wpdb->insert( $this->table, $record, $this->get_formats( false ) );

        if ( false === $result ) {
            return new WP_Error( 'fat_tool_create_failed', __( 'Failed to create the tool.', 'fabled-ai-tools' ) );
        }

        return $this->get_by_id( $wpdb->insert_id );
    }

    public function update( $id, $data ) {
        global $wpdb;

        $id     = absint( $id );
        $record = $this->prepare_record( $data, true );
        $result = $wpdb->update(
            $this->table,
            $record,
            array( 'id' => $id ),
            $this->get_formats( true ),
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'fat_tool_update_failed', __( 'Failed to update the tool.', 'fabled-ai-tools' ) );
        }

        return $this->get_by_id( $id );
    }

    public function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    public function set_active( $id, $active ) {
        return $this->update(
            $id,
            array(
                'is_active' => $active ? 1 : 0,
            )
        );
    }

    public function duplicate( $id ) {
        $tool = $this->get_by_id( $id );
        if ( ! $tool ) {
            return new WP_Error( 'fat_tool_not_found', __( 'Tool not found.', 'fabled-ai-tools' ) );
        }

        unset( $tool['id'] );
        $tool['name']      = $tool['name'] . ' (Copy)';
        $tool['slug']      = $this->generate_duplicate_slug( $tool['slug'] );
        $tool['is_active'] = 0;

        return $this->create( $tool );
    }

    protected function generate_duplicate_slug( $base_slug ) {
        $base_slug = sanitize_title( $base_slug );
        $suffix    = 2;
        $new_slug  = $base_slug . '-copy';

        if ( ! $this->exists_slug( $new_slug ) ) {
            return $new_slug;
        }

        while ( $this->exists_slug( $base_slug . '-copy-' . $suffix ) ) {
            ++$suffix;
        }

        return $base_slug . '-copy-' . $suffix;
    }

    protected function prepare_record( $data, $is_update = false ) {
        $now    = FAT_Helpers::current_datetime_mysql();
        $record = array(
            'name'                 => sanitize_text_field( FAT_Helpers::array_get( $data, 'name', '' ) ),
            'slug'                 => FAT_Helpers::clean_slug( FAT_Helpers::array_get( $data, 'slug', '' ), FAT_Helpers::array_get( $data, 'name', '' ) ),
            'description'          => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'description', '' ) ),
            'is_active'            => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $data, 'is_active', 0 ) ),
            'allowed_roles'        => wp_json_encode( FAT_Helpers::sanitize_roles_array( FAT_Helpers::array_get( $data, 'allowed_roles', array() ) ) ),
            'allowed_capabilities' => wp_json_encode( FAT_Helpers::sanitize_capabilities_list( FAT_Helpers::array_get( $data, 'allowed_capabilities', array() ) ) ),
            'model'                => sanitize_text_field( FAT_Helpers::array_get( $data, 'model', '' ) ),
            'system_prompt'        => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'system_prompt', '' ) ),
            'user_prompt_template' => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'user_prompt_template', '' ) ),
            'input_schema'         => wp_json_encode( (array) FAT_Helpers::array_get( $data, 'input_schema', array() ) ),
            'output_schema'        => wp_json_encode( (array) FAT_Helpers::array_get( $data, 'output_schema', array() ) ),
            'wp_integration'       => wp_json_encode( (array) FAT_Helpers::array_get( $data, 'wp_integration', array() ) ),
            'max_input_chars'      => max( 1, absint( FAT_Helpers::array_get( $data, 'max_input_chars', 20000 ) ) ),
            'max_output_tokens'    => max( 1, absint( FAT_Helpers::array_get( $data, 'max_output_tokens', 700 ) ) ),
            'daily_run_limit'      => max( 0, absint( FAT_Helpers::array_get( $data, 'daily_run_limit', 0 ) ) ),
            'log_inputs'           => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $data, 'log_inputs', 0 ) ),
            'log_outputs'          => FAT_Helpers::to_bool_flag( FAT_Helpers::array_get( $data, 'log_outputs', 1 ) ),
            'sort_order'           => intval( FAT_Helpers::array_get( $data, 'sort_order', 0 ) ),
            'updated_at'           => $now,
        );

        if ( ! $is_update ) {
            $record['created_at'] = $now;
        }

        return $record;
    }

    protected function get_formats( $is_update = false ) {
        $formats = array(
            '%s', // name.
            '%s', // slug.
            '%s', // description.
            '%d', // is_active.
            '%s', // allowed_roles.
            '%s', // allowed_capabilities.
            '%s', // model.
            '%s', // system_prompt.
            '%s', // user_prompt_template.
            '%s', // input_schema.
            '%s', // output_schema.
            '%s', // wp_integration.
            '%d', // max_input_chars.
            '%d', // max_output_tokens.
            '%d', // daily_run_limit.
            '%d', // log_inputs.
            '%d', // log_outputs.
            '%d', // sort_order.
            '%s', // updated_at.
        );

        if ( ! $is_update ) {
            $formats[] = '%s'; // created_at.
        }

        return $formats;
    }

    protected function map_row( $row ) {
        if ( ! is_array( $row ) ) {
            return array();
        }

        $row['id']                   = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $row['is_active']            = ! empty( $row['is_active'] ) ? 1 : 0;
        $row['allowed_roles']        = FAT_Helpers::maybe_json_decode( FAT_Helpers::array_get( $row, 'allowed_roles', array() ), array() );
        $row['allowed_capabilities'] = FAT_Helpers::maybe_json_decode( FAT_Helpers::array_get( $row, 'allowed_capabilities', array() ), array() );
        $row['input_schema']         = FAT_Helpers::maybe_json_decode( FAT_Helpers::array_get( $row, 'input_schema', array() ), array() );
        $row['output_schema']        = FAT_Helpers::maybe_json_decode( FAT_Helpers::array_get( $row, 'output_schema', array() ), array() );
        $row['wp_integration']       = FAT_Helpers::maybe_json_decode( FAT_Helpers::array_get( $row, 'wp_integration', array() ), array() );
        $row['max_input_chars']      = isset( $row['max_input_chars'] ) ? (int) $row['max_input_chars'] : 0;
        $row['max_output_tokens']    = isset( $row['max_output_tokens'] ) ? (int) $row['max_output_tokens'] : 0;
        $row['daily_run_limit']      = isset( $row['daily_run_limit'] ) ? (int) $row['daily_run_limit'] : 0;
        $row['log_inputs']           = ! empty( $row['log_inputs'] ) ? 1 : 0;
        $row['log_outputs']          = ! empty( $row['log_outputs'] ) ? 1 : 0;
        $row['sort_order']           = isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0;

        return $row;
    }
}
