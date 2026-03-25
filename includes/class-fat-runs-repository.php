<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Runs_Repository {
    protected $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fat_runs';
    }

    public function insert( $data ) {
        global $wpdb;

        $record = array(
            'tool_id'           => absint( FAT_Helpers::array_get( $data, 'tool_id', 0 ) ),
            'user_id'           => absint( FAT_Helpers::array_get( $data, 'user_id', 0 ) ),
            'status'            => sanitize_key( FAT_Helpers::array_get( $data, 'status', 'unknown' ) ),
            'request_preview'   => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'request_preview', '' ) ),
            'response_preview'  => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'response_preview', '' ) ),
            'request_payload'   => $this->maybe_json_encode( FAT_Helpers::array_get( $data, 'request_payload', null ) ),
            'response_payload'  => $this->maybe_json_encode( FAT_Helpers::array_get( $data, 'response_payload', null ) ),
            'error_message'     => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $data, 'error_message', '' ) ),
            'model_used'        => sanitize_text_field( FAT_Helpers::array_get( $data, 'model_used', '' ) ),
            'prompt_tokens'     => $this->nullable_absint( FAT_Helpers::array_get( $data, 'prompt_tokens', null ) ),
            'completion_tokens' => $this->nullable_absint( FAT_Helpers::array_get( $data, 'completion_tokens', null ) ),
            'total_tokens'      => $this->nullable_absint( FAT_Helpers::array_get( $data, 'total_tokens', null ) ),
            'latency_ms'        => $this->nullable_absint( FAT_Helpers::array_get( $data, 'latency_ms', null ) ),
            'openai_request_id' => sanitize_text_field( FAT_Helpers::array_get( $data, 'openai_request_id', '' ) ),
            'created_at'        => FAT_Helpers::current_datetime_mysql(),
        );

        $result = $wpdb->insert(
            $this->table,
            $record,
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function count_user_runs_for_day( $user_id, $tool_id, $start, $end ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d AND tool_id = %d AND created_at >= %s AND created_at <= %s",
                absint( $user_id ),
                absint( $tool_id ),
                $start,
                $end
            )
        );
    }

    public function get_runs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'   => '',
            'tool_id'  => 0,
            'page'     => 1,
            'per_page' => 50,
        );
        $args     = wp_parse_args( $args, $defaults );
        $where    = array( '1=1' );
        $params   = array();

        if ( '' !== $args['status'] ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_key( $args['status'] );
        }

        if ( ! empty( $args['tool_id'] ) ) {
            $where[]  = 'tool_id = %d';
            $params[] = absint( $args['tool_id'] );
        }

        $sql_where = implode( ' AND ', $where );
        $limit     = max( 1, absint( $args['per_page'] ) );
        $offset    = ( max( 1, absint( $args['page'] ) ) - 1 ) * $limit;

        $sql = "SELECT * FROM {$this->table} WHERE {$sql_where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare( $sql, $params );
        $rows     = $wpdb->get_results( $prepared, ARRAY_A );

        return array_map( array( $this, 'map_row' ), (array) $rows );
    }

    public function count_runs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'  => '',
            'tool_id' => 0,
        );
        $args     = wp_parse_args( $args, $defaults );
        $where    = array( '1=1' );
        $params   = array();

        if ( '' !== $args['status'] ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_key( $args['status'] );
        }

        if ( ! empty( $args['tool_id'] ) ) {
            $where[]  = 'tool_id = %d';
            $params[] = absint( $args['tool_id'] );
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where );

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return (int) $wpdb->get_var( $sql );
    }

    public function get_run( $id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", absint( $id ) ),
            ARRAY_A
        );

        return $row ? $this->map_row( $row ) : null;
    }


    public function delete_older_than_days( $days ) {
        global $wpdb;

        $days = absint( $days );
        if ( $days <= 0 ) {
            return 0;
        }

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < %s",
                $cutoff
            )
        );
    }

    public function delete_all() {
        global $wpdb;

        return (int) $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function count_runs_since( $status, $since_mysql ) {
        global $wpdb;

        $status = sanitize_key( $status );
        $since  = is_string( $since_mysql ) ? $since_mysql : '';
        if ( '' === $status || '' === $since ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = %s AND created_at >= %s",
                $status,
                $since
            )
        );
    }

    public function get_recent_failures( $limit = 5 ) {
        global $wpdb;

        $limit = max( 1, absint( $limit ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                'error',
                $limit
            ),
            ARRAY_A
        );

        return array_map( array( $this, 'map_row' ), (array) $rows );
    }
    protected function maybe_json_encode( $value ) {
        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( is_string( $value ) ) {
            return $value;
        }

        return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    protected function nullable_absint( $value ) {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return absint( $value );
    }

    protected function map_row( $row ) {
        if ( ! is_array( $row ) ) {
            return array();
        }

        $row['id']                = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $row['tool_id']           = isset( $row['tool_id'] ) ? (int) $row['tool_id'] : 0;
        $row['user_id']           = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
        $row['prompt_tokens']     = isset( $row['prompt_tokens'] ) ? (int) $row['prompt_tokens'] : null;
        $row['completion_tokens'] = isset( $row['completion_tokens'] ) ? (int) $row['completion_tokens'] : null;
        $row['total_tokens']      = isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : null;
        $row['latency_ms']        = isset( $row['latency_ms'] ) ? (int) $row['latency_ms'] : null;

        return $row;
    }
}
