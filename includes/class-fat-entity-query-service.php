<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Entity_Query_Service {
    const DEFAULT_RESULT_LIMIT = 40;
    const MAX_QUERY_PAGES      = 5;
    const DEFAULT_PAGE_SIZE    = 20;
    const MAX_PAGE_SIZE        = 50;

    public function get_editable_posts_for_runner( $status, $user, $limit = self::DEFAULT_RESULT_LIMIT ) {
        $result = $this->search_editable_posts_for_runner(
            $user,
            array(
                'status'   => $status,
                'per_page' => $limit,
                'page'     => 1,
            )
        );

        return (array) FAT_Helpers::array_get( $result, 'items', array() );
    }

    public function get_editable_attachments_for_runner( $user, $limit = self::DEFAULT_RESULT_LIMIT ) {
        $result = $this->search_editable_attachments_for_runner(
            $user,
            array(
                'per_page' => $limit,
                'page'     => 1,
            )
        );

        return (array) FAT_Helpers::array_get( $result, 'items', array() );
    }


    public function get_featured_image_supported_post_types() {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        $types   = array();

        foreach ( (array) $objects as $post_type => $object ) {
            if ( 'attachment' === $post_type ) {
                continue;
            }
            if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
                continue;
            }
            $types[] = sanitize_key( $post_type );
        }

        if ( empty( $types ) ) {
            $types[] = 'post';
        }

        return array_values( array_unique( $types ) );
    }

    public function search_editable_posts_for_runner( $user, $args = array() ) {
        $user  = $this->normalize_user( $user );
        $status = sanitize_key( FAT_Helpers::array_get( $args, 'status', '' ) );
        $search = sanitize_text_field( FAT_Helpers::array_get( $args, 'search', '' ) );
        $requested_post_types = (array) FAT_Helpers::array_get( $args, 'post_types', array( 'post' ) );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', $requested_post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post' );
        }
        $page   = max( 1, absint( FAT_Helpers::array_get( $args, 'page', 1 ) ) );
        $per_page = min( self::MAX_PAGE_SIZE, max( 1, absint( FAT_Helpers::array_get( $args, 'per_page', self::DEFAULT_PAGE_SIZE ) ) ) );

        if ( ! $user || ! $user->exists() ) {
            return array(
                'items'     => array(),
                'has_more'  => false,
                'next_page' => null,
            );
        }

        if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
            $status = 'publish';
        }

        $raw_posts = $this->query_ids_with_capability_filter(
            array(
                'post_type'              => $post_types,
                'post_status'            => $status,
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                's'                      => $search,
            ),
            $user
        );

        $items = array();
        foreach ( $raw_posts['ids'] as $post_id ) {
            $post_type_obj = get_post_type_object( get_post_type( $post_id ) );
            $items[] = array(
                'id'    => (int) $post_id,
                'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                'post_type' => sanitize_key( (string) get_post_type( $post_id ) ),
                'post_type_label' => $post_type_obj ? (string) $post_type_obj->labels->singular_name : '',
            );
        }

        return array(
            'items'     => $items,
            'has_more'  => ! empty( $raw_posts['has_more'] ),
            'next_page' => ! empty( $raw_posts['has_more'] ) ? ( $page + 1 ) : null,
        );
    }

    public function search_editable_attachments_for_runner( $user, $args = array() ) {
        $user    = $this->normalize_user( $user );
        $search  = sanitize_text_field( FAT_Helpers::array_get( $args, 'search', '' ) );
        $page    = max( 1, absint( FAT_Helpers::array_get( $args, 'page', 1 ) ) );
        $per_page = min( self::MAX_PAGE_SIZE, max( 1, absint( FAT_Helpers::array_get( $args, 'per_page', self::DEFAULT_PAGE_SIZE ) ) ) );

        if ( ! $user || ! $user->exists() ) {
            return array(
                'items'     => array(),
                'has_more'  => false,
                'next_page' => null,
            );
        }

        $raw_attachments = $this->query_ids_with_capability_filter(
            array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'post_mime_type'         => 'image',
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                's'                      => $search,
            ),
            $user
        );

        $items = array();
        foreach ( $raw_attachments['ids'] as $attachment_id ) {
            $attached_file = get_attached_file( $attachment_id );
            $items[]       = array(
                'id'       => (int) $attachment_id,
                'title'    => html_entity_decode( get_the_title( $attachment_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                'filename' => $attached_file ? wp_basename( $attached_file ) : '',
            );
        }

        return array(
            'items'     => $items,
            'has_more'  => ! empty( $raw_attachments['has_more'] ),
            'next_page' => ! empty( $raw_attachments['has_more'] ) ? ( $page + 1 ) : null,
        );
    }

    protected function query_ids_with_capability_filter( $query_args, $user ) {
        $page     = max( 1, absint( FAT_Helpers::array_get( $query_args, 'paged', 1 ) ) );
        $per_page = max( 1, absint( FAT_Helpers::array_get( $query_args, 'posts_per_page', self::DEFAULT_PAGE_SIZE ) ) );
        $results  = array();
        $cycles   = 0;

        while ( count( $results ) < $per_page && $cycles < self::MAX_QUERY_PAGES ) {
            $query_args['paged']          = $page;
            $query_args['posts_per_page'] = $per_page;
            $query_args['fields']         = 'ids';
            $query_args['no_found_rows']  = true;

            $query = new WP_Query( $query_args );
            if ( empty( $query->posts ) ) {
                return array(
                    'ids'      => $results,
                    'has_more' => false,
                );
            }

            foreach ( $query->posts as $post_id ) {
                $post_id = (int) $post_id;
                if ( ! user_can( $user, 'edit_post', $post_id ) ) {
                    continue;
                }

                $results[] = $post_id;
                if ( count( $results ) >= $per_page ) {
                    break;
                }
            }

            if ( count( $query->posts ) < $per_page ) {
                return array(
                    'ids'      => $results,
                    'has_more' => false,
                );
            }

            ++$page;
            ++$cycles;
        }

        return array(
            'ids'      => array_slice( $results, 0, $per_page ),
            'has_more' => true,
        );
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
