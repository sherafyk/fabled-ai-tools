<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Admin_Launch_Service {
    public function add_post_row_launch_link( $actions, $post, $can_run_tools ) {
        if ( ! $can_run_tools || ! ( $post instanceof WP_Post ) ) {
            return $actions;
        }

        if ( ! user_can( get_current_user_id(), 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $tool_slug = 'seo-excerpt';
        if ( post_type_supports( $post->post_type, 'thumbnail' ) ) {
            $tool_slug = 'featured-image-generator';
        }

        $actions['fat_open_runner'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url(
                $this->runner_launch_url(
                    array(
                        'fat_tool'          => $tool_slug,
                        'fat_source_post'   => (int) $post->ID,
                        'fat_source_status' => 'publish' === $post->post_status ? 'publish' : 'draft',
                        'fat_target_post'   => (int) $post->ID,
                    )
                )
            ),
            esc_html__( 'Open in Fabled AI Tools', 'fabled-ai-tools' )
        );

        return $actions;
    }

    public function add_media_row_launch_link( $actions, $post, $can_run_tools ) {
        if ( ! $can_run_tools || ! ( $post instanceof WP_Post ) ) {
            return $actions;
        }

        if ( 'attachment' !== $post->post_type || ! user_can( get_current_user_id(), 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $actions['fat_open_runner'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url(
                $this->runner_launch_url(
                    array(
                        'fat_tool'          => 'attachment-metadata-assistant',
                        'fat_attachment_id' => (int) $post->ID,
                    )
                )
            ),
            esc_html__( 'Open in Fabled AI Tools', 'fabled-ai-tools' )
        );

        return $actions;
    }

    public function render_gap_posts_table( $posts, $tool_slug, $launch_label ) {
        ?>
        <table class="widefat striped fat-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Title', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Launch', 'fabled-ai-tools' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $posts ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No items found.', 'fabled-ai-tools' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $posts as $post ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                                    <?php echo esc_html( get_the_title( $post->ID ) ? get_the_title( $post->ID ) : '#' . (int) $post->ID ); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $post_type_obj = get_post_type_object( $post->post_type );
                                echo esc_html( $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type );
                                ?>
                            </td>
                            <td><?php echo esc_html( $post->post_status ); ?></td>
                            <td><?php echo esc_html( get_the_modified_date( 'Y-m-d H:i', $post->ID ) ); ?></td>
                            <td>
                                <a class="button button-secondary" href="<?php echo esc_url( $this->runner_launch_url( array( 'fat_tool' => $tool_slug, 'fat_source_post' => (int) $post->ID, 'fat_source_status' => 'publish' === $post->post_status ? 'publish' : 'draft', 'fat_target_post' => (int) $post->ID ) ) ); ?>">
                                    <?php echo esc_html( $launch_label ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function render_gap_attachments_table( $attachments ) {
        ?>
        <table class="widefat striped fat-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Attachment', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Filename', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'fabled-ai-tools' ); ?></th>
                    <th><?php esc_html_e( 'Launch', 'fabled-ai-tools' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $attachments ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No items found.', 'fabled-ai-tools' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $attachments as $attachment ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $attachment->ID ) ); ?>">
                                    <?php echo esc_html( get_the_title( $attachment->ID ) ? get_the_title( $attachment->ID ) : '#' . (int) $attachment->ID ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( wp_basename( (string) get_attached_file( $attachment->ID ) ) ); ?></td>
                            <td><?php echo esc_html( get_the_modified_date( 'Y-m-d H:i', $attachment->ID ) ); ?></td>
                            <td>
                                <a class="button button-secondary" href="<?php echo esc_url( $this->runner_launch_url( array( 'fat_tool' => 'attachment-metadata-assistant', 'fat_attachment_id' => (int) $attachment->ID ) ) ); ?>">
                                    <?php esc_html_e( 'Generate Metadata', 'fabled-ai-tools' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function find_posts_missing_excerpt( $limit = 12 ) {
        return $this->find_gap_posts(
            function ( $post_id ) {
                return '' === trim( (string) get_post_field( 'post_excerpt', $post_id ) );
            },
            $limit
        );
    }

    public function find_posts_missing_featured_image( $limit = 12 ) {
        return $this->find_gap_posts(
            function ( $post_id ) {
                return ! has_post_thumbnail( $post_id );
            },
            $limit,
            array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_thumbnail_id',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'   => '_thumbnail_id',
                        'value' => '',
                    ),
                ),
            )
        );
    }

    public function find_attachments_missing_alt_text( $limit = 12 ) {
        $limit     = max( 1, absint( $limit ) );
        $per_page  = 20;
        $page      = 1;
        $max_pages = 5;
        $found     = array();

        while ( count( $found ) < $limit && $page <= $max_pages ) {
            $query = new WP_Query(
                array(
                    'post_type'              => 'attachment',
                    'post_status'            => 'inherit',
                    'post_mime_type'         => 'image',
                    'posts_per_page'         => $per_page,
                    'paged'                  => $page,
                    'orderby'                => 'modified',
                    'order'                  => 'DESC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                    'meta_query'             => array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_wp_attachment_image_alt',
                            'compare' => 'NOT EXISTS',
                        ),
                        array(
                            'key'   => '_wp_attachment_image_alt',
                            'value' => '',
                        ),
                    ),
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment ) {
                if ( ! user_can( get_current_user_id(), 'edit_post', $attachment->ID ) ) {
                    continue;
                }

                $alt = trim( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
                if ( '' !== $alt ) {
                    continue;
                }

                $found[] = $attachment;
                if ( count( $found ) >= $limit ) {
                    break;
                }
            }

            if ( count( $query->posts ) < $per_page ) {
                break;
            }
            ++$page;
        }

        return $found;
    }

    public function runner_launch_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => 'fabled-ai-tools' ), $args ), admin_url( 'admin.php' ) );
    }

    public function runner_launch_context_from_request( $current_page ) {
        if ( 'fabled-ai-tools' !== $current_page ) {
            return array();
        }

        return array(
            'toolId'        => isset( $_GET['fat_tool_id'] ) ? absint( $_GET['fat_tool_id'] ) : 0,
            'toolSlug'      => isset( $_GET['fat_tool'] ) ? sanitize_title( wp_unslash( $_GET['fat_tool'] ) ) : '',
            'sourcePostId'  => isset( $_GET['fat_source_post'] ) ? absint( $_GET['fat_source_post'] ) : 0,
            'sourceStatus'  => isset( $_GET['fat_source_status'] ) ? sanitize_key( wp_unslash( $_GET['fat_source_status'] ) ) : '',
            'targetPostId'  => isset( $_GET['fat_target_post'] ) ? absint( $_GET['fat_target_post'] ) : 0,
            'attachmentId'  => isset( $_GET['fat_attachment_id'] ) ? absint( $_GET['fat_attachment_id'] ) : 0,
        );
    }

    protected function find_gap_posts( $predicate, $limit = 12, $query_overrides = array() ) {
        $limit      = max( 1, absint( $limit ) );
        $per_page   = 20;
        $page       = 1;
        $max_pages  = 5;
        $found      = array();
        $post_types = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );

        while ( count( $found ) < $limit && $page <= $max_pages ) {
            $query_args = wp_parse_args(
                $query_overrides,
                array(
                    'post_type'              => $post_types,
                    'post_status'            => array( 'publish', 'draft' ),
                    'posts_per_page'         => $per_page,
                    'paged'                  => $page,
                    'orderby'                => 'modified',
                    'order'                  => 'DESC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );

            $query = new WP_Query( $query_args );
            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $post ) {
                if ( ! user_can( get_current_user_id(), 'edit_post', $post->ID ) ) {
                    continue;
                }

                if ( ! call_user_func( $predicate, $post->ID ) ) {
                    continue;
                }

                $found[] = $post;
                if ( count( $found ) >= $limit ) {
                    break;
                }
            }

            if ( count( $query->posts ) < $per_page ) {
                break;
            }
            ++$page;
        }

        return $found;
    }
}
