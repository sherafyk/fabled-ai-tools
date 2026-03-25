<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Builtin_Tools {
    protected $tools_repo;

    public function __construct( FAT_Tools_Repository $tools_repo ) {
        $this->tools_repo = $tools_repo;
    }

    public function get_blueprints() {
        $blueprints = FAT_Activator::seeded_tool_blueprints();

        return (array) apply_filters( 'fat_builtin_tool_blueprints', $blueprints );
    }

    public function get_blueprint_by_slug( $slug ) {
        $slug       = sanitize_title( $slug );
        $blueprints = $this->get_blueprints();

        if ( isset( $blueprints[ $slug ] ) && is_array( $blueprints[ $slug ] ) ) {
            return $blueprints[ $slug ];
        }

        return null;
    }

    public function is_builtin_slug( $slug ) {
        return null !== $this->get_blueprint_by_slug( $slug );
    }

    public function is_builtin_tool( $tool ) {
        $slug = sanitize_title( FAT_Helpers::array_get( $tool, 'slug', '' ) );

        return '' !== $slug && $this->is_builtin_slug( $slug );
    }

    public function reset_tool_to_defaults( $tool_id ) {
        $tool_id = absint( $tool_id );
        if ( $tool_id <= 0 ) {
            return new WP_Error( 'fat_tool_not_found', __( 'Tool not found.', 'fabled-ai-tools' ) );
        }

        $existing = $this->tools_repo->get_by_id( $tool_id );
        if ( ! $existing ) {
            return new WP_Error( 'fat_tool_not_found', __( 'Tool not found.', 'fabled-ai-tools' ) );
        }

        $slug      = sanitize_title( FAT_Helpers::array_get( $existing, 'slug', '' ) );
        $blueprint = $this->get_blueprint_by_slug( $slug );
        if ( ! $blueprint ) {
            return new WP_Error( 'fat_not_builtin_tool', __( 'Only built-in tools can be reset to defaults.', 'fabled-ai-tools' ) );
        }

        return $this->tools_repo->update(
            $tool_id,
            array_merge(
                $existing,
                $blueprint,
                array(
                    'id'   => $tool_id,
                    'slug' => $slug,
                )
            )
        );
    }
}
