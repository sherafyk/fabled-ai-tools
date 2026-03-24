<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Usage_Limiter {
    protected $runs_repo;
    protected $settings;

    public function __construct( FAT_Runs_Repository $runs_repo, FAT_Settings $settings ) {
        $this->runs_repo = $runs_repo;
        $this->settings  = $settings;
    }

    public function get_effective_limit( $tool ) {
        $tool_limit = absint( FAT_Helpers::array_get( $tool, 'daily_run_limit', 0 ) );
        if ( $tool_limit > 0 ) {
            return $tool_limit;
        }

        return absint( $this->settings->get( 'default_daily_limit', 0 ) );
    }

    public function get_usage_for_today( $user_id, $tool_id ) {
        $bounds = FAT_Helpers::day_bounds();

        return $this->runs_repo->count_user_runs_for_day( $user_id, $tool_id, $bounds['start'], $bounds['end'] );
    }

    public function assert_can_run( $tool, $user_id ) {
        $limit = $this->get_effective_limit( $tool );
        if ( $limit < 1 ) {
            return true;
        }

        $used = $this->get_usage_for_today( $user_id, FAT_Helpers::array_get( $tool, 'id', 0 ) );
        if ( $used >= $limit ) {
            return new WP_Error(
                'fat_daily_limit_exceeded',
                sprintf(
                    __( 'Daily run limit reached for this tool (%1$d/%2$d used today).', 'fabled-ai-tools' ),
                    $used,
                    $limit
                ),
                array(
                    'used'  => $used,
                    'limit' => $limit,
                )
            );
        }

        return true;
    }
}
