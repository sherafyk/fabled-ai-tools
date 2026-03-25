<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Entity_Query_Service {
    const DEFAULT_RESULT_LIMIT = 40;
    const MAX_QUERY_PAGES      = 5;

    public function get_editable_posts_for_runner( $status, $user, $limit = self::DEFAULT_RESULT_LIMIT ) {
        $status = sanitize_key( $status );
        $user   = $this->normalize_user( $user );
        $limit  = max( 1, absint( $limit ) );

        if ( ! $user || ! $user->exists() ) {
            return array();
        }

        if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
            return array();
        }

        $results   = array();
        $page      = 1;
        $page_size = min( 100, max( $limit, 40 ) );

        while ( count( $results ) < $limit && $page <= self::MAX_QUERY_PAGES ) {
            $query = new WP_Query(
                array(
                    'post_type'              => 'post',
                    'post_status'            => $status,
                    'posts_per_page'         => $page_size,
                    'paged'                  => $page,
                    'orderby'                => 'modified',
                    'order'                  => 'DESC',
                    'ignore_sticky_posts'    => true,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'fields'                 => 'ids',
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $post_id ) {
                $post_id = (int) $post_id;
                if ( ! user_can( $user, 'edit_post', $post_id ) ) {
                    continue;
                }

                $results[] = array(
                    'id'    => $post_id,
                    'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                );

                if ( count( $results ) >= $limit ) {
                    break;
                }
            }

            if ( count( $query->posts ) < $page_size ) {
                break;
            }

            ++$page;
        }

        return $results;
    }

    public function get_editable_attachments_for_runner( $user, $limit = self::DEFAULT_RESULT_LIMIT ) {
        $user  = $this->normalize_user( $user );
        $limit = max( 1, absint( $limit ) );

        if ( ! $user || ! $user->exists() ) {
            return array();
        }

        $results   = array();
        $page      = 1;
        $page_size = min( 100, max( $limit, 40 ) );

        while ( count( $results ) < $limit && $page <= self::MAX_QUERY_PAGES ) {
            $query = new WP_Query(
                array(
                    'post_type'              => 'attachment',
                    'post_status'            => 'inherit',
                    'post_mime_type'         => 'image',
                    'posts_per_page'         => $page_size,
                    'paged'                  => $page,
                    'orderby'                => 'modified',
                    'order'                  => 'DESC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'fields'                 => 'ids',
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment_id ) {
                $attachment_id = (int) $attachment_id;
                if ( ! user_can( $user, 'edit_post', $attachment_id ) ) {
                    continue;
                }

                $attached_file = get_attached_file( $attachment_id );
                $results[]     = array(
                    'id'       => $attachment_id,
                    'title'    => html_entity_decode( get_the_title( $attachment_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                    'filename' => $attached_file ? wp_basename( $attached_file ) : '',
                );

                if ( count( $results ) >= $limit ) {
                    break;
                }
            }

            if ( count( $query->posts ) < $page_size ) {
                break;
            }

            ++$page;
        }

        return $results;
    }

    protected function normalize_user( $user ) {
        if ( $user instanceof WP_User ) {
            return $user;
        }

        if ( is_numeric( $user ) && $user > 0 ) {
            return get_userdata( absint( $user ) );
        }

        return wp_get_current_user();
    }
}
