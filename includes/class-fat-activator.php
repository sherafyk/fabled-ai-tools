<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Activator {
    public static function activate() {
        self::create_tables();
        self::add_capabilities();
        self::seed_example_tools();
        self::maybe_backfill_seeded_wp_integration();
        self::maybe_seed_missing_featured_image_generator();
        self::maybe_seed_missing_uploaded_image_processor();
        self::maybe_seed_missing_attachment_metadata_assistant();
        self::maybe_repair_corrupted_seeded_tools();
        self::ensure_scheduled_events();
        update_option( 'fat_db_version', FAT_DB_VERSION );
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'fat_daily_log_cleanup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'fat_daily_log_cleanup' );
        }
    }

    public static function maybe_upgrade() {
        // Keep missing built-ins backfilled even when schema version is unchanged.
        self::maybe_seed_missing_featured_image_generator();
        self::maybe_seed_missing_uploaded_image_processor();
        self::maybe_seed_missing_attachment_metadata_assistant();
        self::ensure_scheduled_events();

        $installed = get_option( 'fat_db_version', '' );
        if ( FAT_DB_VERSION !== $installed ) {
            self::create_tables();
            self::add_capabilities();
            self::maybe_backfill_seeded_wp_integration();
            self::maybe_seed_missing_featured_image_generator();
            self::maybe_seed_missing_uploaded_image_processor();
            self::maybe_seed_missing_attachment_metadata_assistant();
            self::maybe_repair_corrupted_seeded_tools();
            self::ensure_scheduled_events();
            update_option( 'fat_db_version', FAT_DB_VERSION );
        }
    }


    protected static function ensure_scheduled_events() {
        if ( ! wp_next_scheduled( 'fat_daily_log_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fat_daily_log_cleanup' );
        }
    }
    public static function add_capabilities() {
        $cap_map = array(
            'administrator' => array(
                'fat_run_ai_tools',
                'fat_manage_tools',
                'fat_view_ai_logs',
                'fat_manage_ai_settings',
            ),
            'editor'        => array(
                'fat_run_ai_tools',
            ),
            'author'        => array(
                'fat_run_ai_tools',
            ),
        );

        foreach ( $cap_map as $role_name => $caps ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }

            foreach ( $caps as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    protected static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tools_table     = $wpdb->prefix . 'fat_tools';
        $runs_table      = $wpdb->prefix . 'fat_runs';

        $sql_tools = "CREATE TABLE {$tools_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(190) NOT NULL,
            description text NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            allowed_roles longtext NULL,
            allowed_capabilities longtext NULL,
            model varchar(100) NULL,
            system_prompt longtext NULL,
            user_prompt_template longtext NULL,
            input_schema longtext NULL,
            output_schema longtext NULL,
            wp_integration longtext NULL,
            max_input_chars int(10) unsigned NOT NULL DEFAULT 20000,
            max_output_tokens int(10) unsigned NOT NULL DEFAULT 700,
            daily_run_limit int(10) unsigned NOT NULL DEFAULT 0,
            log_inputs tinyint(1) NOT NULL DEFAULT 0,
            log_outputs tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(10) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) {$charset_collate};";

        $sql_runs = "CREATE TABLE {$runs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tool_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL,
            request_preview text NULL,
            response_preview text NULL,
            request_payload longtext NULL,
            response_payload longtext NULL,
            error_message text NULL,
            model_used varchar(100) NULL,
            prompt_tokens int(10) unsigned NULL,
            completion_tokens int(10) unsigned NULL,
            total_tokens int(10) unsigned NULL,
            latency_ms int(10) unsigned NULL,
            openai_request_id varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY tool_id (tool_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_tools );
        dbDelta( $sql_runs );
    }

    protected static function seed_example_tools() {
        global $wpdb;

        $table = $wpdb->prefix . 'fat_tools';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $repo = new FAT_Tools_Repository();

        $common_roles = array( 'administrator', 'editor', 'author' );

        $repo->create(
            array(
                'name'                 => 'Featured Image Prompt',
                'slug'                 => 'featured-image-prompt',
                'description'          => 'Turns article body text into a single featured image prompt for an external image generation system.',
                'is_active'            => 1,
                'allowed_roles'        => $common_roles,
                'allowed_capabilities' => array(),
                'model'                => '',
                'system_prompt'        => "You create one high-quality featured image prompt from article content. The prompt must be visually specific, editorially relevant, publication-safe, and directly usable in an image generation system. Do not add markdown, labels, preamble, explanation, quotes, or multiple options.",
                'user_prompt_template' => "Create a single detailed featured image prompt based on the article below.\n\nArticle body:\n{{input.article_body}}\n\nRequirements:\n- Return only the prompt content.\n- Start immediately with the prompt.\n- Keep it visually concrete and publication-ready.",
                'input_schema'         => array(
                    array(
                        'key'         => 'article_body',
                        'label'       => 'Article Body',
                        'type'        => 'textarea',
                        'required'    => 1,
                        'help_text'   => 'Paste the full article body text.',
                        'placeholder' => 'Paste article content here...',
                        'max_length'  => 30000,
                    ),
                ),
                'output_schema'        => array(
                    array(
                        'key'      => 'image_prompt',
                        'label'    => 'Image Prompt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                ),
                'max_input_chars'      => 30000,
                'max_output_tokens'    => 450,
                'daily_run_limit'      => 25,
                'log_inputs'           => 0,
                'log_outputs'          => 1,
                'sort_order'           => 10,
            )
        );

        $repo->create(
            self::featured_image_generator_blueprint( $common_roles )
        );

        $repo->create(
            self::uploaded_image_processor_blueprint( $common_roles )
        );

        $repo->create(
            self::attachment_metadata_assistant_blueprint( $common_roles )
        );

        $repo->create(
            array(
                'name'                 => 'SEO Excerpt',
                'slug'                 => 'seo-excerpt',
                'description'          => 'Generates a plain-text SEO-friendly excerpt in roughly 75 to 80 words.',
                'is_active'            => 1,
                'allowed_roles'        => $common_roles,
                'allowed_capabilities' => array(),
                'model'                => '',
                'system_prompt'        => "You write SEO-friendly plain-text excerpts for article previews. Keep the excerpt approximately 75 to 80 words, readable, compelling, and factually grounded in the source article. Do not use markdown, bullets, labels, or quotation marks unless they are required by the source text.",
                'user_prompt_template' => "Write one SEO-friendly excerpt for the article below.\n\nArticle body:\n{{input.article_body}}\n\nRequirements:\n- Approximately 75 to 80 words.\n- Plain text only.\n- No preamble or explanation.",
                'input_schema'         => array(
                    array(
                        'key'         => 'article_body',
                        'label'       => 'Article Body',
                        'type'        => 'textarea',
                        'required'    => 1,
                        'help_text'   => 'Paste the full article body text.',
                        'placeholder' => 'Paste article content here...',
                        'max_length'  => 30000,
                    ),
                ),
                'output_schema'        => array(
                    array(
                        'key'      => 'excerpt',
                        'label'    => 'Excerpt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                ),
                'wp_integration'       => array(
                    'source' => array(
                        'type'             => 'post',
                        'allow_manual'     => 1,
                        'allow_draft'      => 1,
                        'allow_publish'    => 1,
                        'allow_attachment' => 0,
                    ),
                    'apply'  => array(
                        'target'   => 'post',
                        'mappings' => array(
                            array(
                                'output_key' => 'excerpt',
                                'wp_field'   => 'post_excerpt',
                                'label'      => 'Post Excerpt',
                            ),
                        ),
                    ),
                ),
                'max_input_chars'      => 30000,
                'max_output_tokens'    => 250,
                'daily_run_limit'      => 25,
                'log_inputs'           => 0,
                'log_outputs'          => 1,
                'sort_order'           => 20,
            )
        );

        $repo->create(
            array(
                'name'                 => 'Combined Publishing Tool',
                'slug'                 => 'combined-publishing-tool',
                'description'          => 'Creates a plain-text excerpt and a featured image prompt from common article inputs.',
                'is_active'            => 1,
                'allowed_roles'        => $common_roles,
                'allowed_capabilities' => array(),
                'model'                => '',
                'system_prompt'        => "You are an editorial production assistant. Produce concise, useful publishing outputs from the article data provided. Keep every output clean and directly usable with no surrounding commentary.",
                'user_prompt_template' => "Use the article data below to generate the requested outputs.\n\nTitle:\n{{input.title}}\n\nURL:\n{{input.url}}\n\nArticle body:\n{{input.article_body}}\n\nExtra instructions:\n{{input.extra_instructions}}\n\nRequirements:\n- excerpt: plain text only, approximately 75 to 80 words, SEO-friendly.\n- image_prompt: detailed, visually specific, editorially appropriate, directly usable in an image generation system.\n- No markdown, labels, or explanations inside field values.",
                'input_schema'         => array(
                    array(
                        'key'         => 'title',
                        'label'       => 'Title',
                        'type'        => 'text',
                        'required'    => 1,
                        'help_text'   => 'Article title.',
                        'placeholder' => 'Enter article title',
                        'max_length'  => 250,
                    ),
                    array(
                        'key'         => 'url',
                        'label'       => 'URL',
                        'type'        => 'url',
                        'required'    => 1,
                        'help_text'   => 'Canonical article URL.',
                        'placeholder' => 'https://example.com/article',
                        'max_length'  => 500,
                    ),
                    array(
                        'key'         => 'article_body',
                        'label'       => 'Article Body',
                        'type'        => 'textarea',
                        'required'    => 1,
                        'help_text'   => 'Main article body.',
                        'placeholder' => 'Paste article content here...',
                        'max_length'  => 30000,
                    ),
                    array(
                        'key'         => 'extra_instructions',
                        'label'       => 'Extra Instructions',
                        'type'        => 'textarea',
                        'required'    => 0,
                        'help_text'   => 'Optional editorial constraints or preferences.',
                        'placeholder' => 'Optional instructions',
                        'max_length'  => 4000,
                    ),
                ),
                'output_schema'        => array(
                    array(
                        'key'      => 'excerpt',
                        'label'    => 'Excerpt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                    array(
                        'key'      => 'image_prompt',
                        'label'    => 'Image Prompt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                ),
                'wp_integration'       => array(
                    'source' => array(
                        'type'             => 'post',
                        'allow_manual'     => 1,
                        'allow_draft'      => 1,
                        'allow_publish'    => 1,
                        'allow_attachment' => 0,
                    ),
                    'apply'  => array(
                        'target'   => 'post',
                        'mappings' => array(
                            array(
                                'output_key' => 'excerpt',
                                'wp_field'   => 'post_excerpt',
                                'label'      => 'Post Excerpt',
                            ),
                        ),
                    ),
                ),
                'max_input_chars'      => 36000,
                'max_output_tokens'    => 600,
                'daily_run_limit'      => 20,
                'log_inputs'           => 0,
                'log_outputs'          => 1,
                'sort_order'           => 30,
            )
        );
    }

    protected static function maybe_backfill_seeded_wp_integration() {
        $repo = new FAT_Tools_Repository();

        $default_wp_integration = array(
            'seo-excerpt'             => array(
                'source' => array(
                    'type'             => 'post',
                    'allow_manual'     => 1,
                    'allow_draft'      => 1,
                    'allow_publish'    => 1,
                    'allow_attachment' => 0,
                ),
                'apply'  => array(
                    'target'   => 'post',
                    'mappings' => array(
                        array(
                            'output_key' => 'excerpt',
                            'wp_field'   => 'post_excerpt',
                            'label'      => 'Post Excerpt',
                        ),
                    ),
                ),
            ),
            'combined-publishing-tool' => array(
                'source' => array(
                    'type'             => 'post',
                    'allow_manual'     => 1,
                    'allow_draft'      => 1,
                    'allow_publish'    => 1,
                    'allow_attachment' => 0,
                ),
                'apply'  => array(
                    'target'   => 'post',
                    'mappings' => array(
                        array(
                            'output_key' => 'excerpt',
                            'wp_field'   => 'post_excerpt',
                            'label'      => 'Post Excerpt',
                        ),
                    ),
                ),
            ),
        );

        foreach ( $default_wp_integration as $slug => $config ) {
            $tool = $repo->get_by_slug( $slug );
            if ( ! $tool ) {
                continue;
            }

            $existing = (array) FAT_Helpers::array_get( $tool, 'wp_integration', array() );
            if ( ! empty( $existing ) ) {
                continue;
            }

            $repo->update(
                (int) $tool['id'],
                array_merge(
                    $tool,
                    array(
                    'wp_integration' => $config,
                    )
                )
            );
        }
    }

    protected static function maybe_repair_corrupted_seeded_tools() {
        $repo  = new FAT_Tools_Repository();
        $tools = $repo->get_all();

        $known = self::seeded_tool_blueprints();
        $existing_by_slug = array();
        foreach ( $tools as $tool ) {
            $slug = sanitize_title( FAT_Helpers::array_get( $tool, 'slug', '' ) );
            if ( '' !== $slug ) {
                $existing_by_slug[ $slug ] = $tool;
            }
        }

        $corrupted = array();
        foreach ( $tools as $tool ) {
            $name = trim( (string) FAT_Helpers::array_get( $tool, 'name', '' ) );
            $slug = sanitize_title( FAT_Helpers::array_get( $tool, 'slug', '' ) );
            if ( '' === $name || '' === $slug ) {
                $corrupted[] = $tool;
            }
        }

        foreach ( $known as $slug => $blueprint ) {
            if ( isset( $existing_by_slug[ $slug ] ) ) {
                continue;
            }
            if ( empty( $corrupted ) ) {
                continue;
            }

            $target = array_shift( $corrupted );
            $repo->update(
                (int) FAT_Helpers::array_get( $target, 'id', 0 ),
                array_merge(
                    $target,
                    $blueprint
                )
            );
        }
    }

    public static function seeded_tool_blueprints() {
        return array(
            'featured-image-generator' => self::featured_image_generator_blueprint( array( 'administrator', 'editor', 'author' ) ),
            'uploaded-image-processor' => self::uploaded_image_processor_blueprint( array( 'administrator', 'editor', 'author' ) ),
            'attachment-metadata-assistant' => self::attachment_metadata_assistant_blueprint( array( 'administrator', 'editor', 'author' ) ),
            'seo-excerpt' => array(
                'name'                 => 'SEO Excerpt',
                'slug'                 => 'seo-excerpt',
                'description'          => 'Generates a plain-text SEO-friendly excerpt in roughly 75 to 80 words.',
                'is_active'            => 1,
                'allowed_roles'        => array( 'administrator', 'editor', 'author' ),
                'allowed_capabilities' => array(),
                'model'                => '',
                'system_prompt'        => "You write SEO-friendly plain-text excerpts for article previews. Keep the excerpt approximately 75 to 80 words, readable, compelling, and factually grounded in the source article. Do not use markdown, bullets, labels, or quotation marks unless they are required by the source text.",
                'user_prompt_template' => "Write one SEO-friendly excerpt for the article below.\n\nArticle body:\n{{input.article_body}}\n\nRequirements:\n- Approximately 75 to 80 words.\n- Plain text only.\n- No preamble or explanation.",
                'input_schema'         => array(
                    array(
                        'key'         => 'article_body',
                        'label'       => 'Article Body',
                        'type'        => 'textarea',
                        'required'    => 1,
                        'help_text'   => 'Paste the full article body text.',
                        'placeholder' => 'Paste article content here...',
                        'max_length'  => 30000,
                    ),
                ),
                'output_schema'        => array(
                    array(
                        'key'      => 'excerpt',
                        'label'    => 'Excerpt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                ),
                'wp_integration'       => array(
                    'source' => array(
                        'type'             => 'post',
                        'allow_manual'     => 1,
                        'allow_draft'      => 1,
                        'allow_publish'    => 1,
                        'allow_attachment' => 0,
                    ),
                    'apply'  => array(
                        'target'   => 'post',
                        'mappings' => array(
                            array(
                                'output_key' => 'excerpt',
                                'wp_field'   => 'post_excerpt',
                                'label'      => 'Post Excerpt',
                            ),
                        ),
                    ),
                ),
                'max_input_chars'      => 30000,
                'max_output_tokens'    => 250,
                'daily_run_limit'      => 25,
                'log_inputs'           => 0,
                'log_outputs'          => 1,
                'sort_order'           => 20,
            ),
            'combined-publishing-tool' => array(
                'name'                 => 'Combined Publishing Tool',
                'slug'                 => 'combined-publishing-tool',
                'description'          => 'Creates a plain-text excerpt and a featured image prompt from common article inputs.',
                'is_active'            => 1,
                'allowed_roles'        => array( 'administrator', 'editor', 'author' ),
                'allowed_capabilities' => array(),
                'model'                => '',
                'system_prompt'        => "You are an editorial production assistant. Produce concise, useful publishing outputs from the article data provided. Keep every output clean and directly usable with no surrounding commentary.",
                'user_prompt_template' => "Use the article data below to generate the requested outputs.\n\nTitle:\n{{input.title}}\n\nURL:\n{{input.url}}\n\nArticle body:\n{{input.article_body}}\n\nExtra instructions:\n{{input.extra_instructions}}\n\nRequirements:\n- excerpt: plain text only, approximately 75 to 80 words, SEO-friendly.\n- image_prompt: detailed, visually specific, editorially appropriate, directly usable in an image generation system.\n- No markdown, labels, or explanations inside field values.",
                'input_schema'         => array(
                    array(
                        'key'         => 'title',
                        'label'       => 'Title',
                        'type'        => 'text',
                        'required'    => 1,
                        'help_text'   => 'Article title.',
                        'placeholder' => 'Enter article title',
                        'max_length'  => 250,
                    ),
                    array(
                        'key'         => 'url',
                        'label'       => 'URL',
                        'type'        => 'url',
                        'required'    => 1,
                        'help_text'   => 'Canonical article URL.',
                        'placeholder' => 'https://example.com/article',
                        'max_length'  => 500,
                    ),
                    array(
                        'key'         => 'article_body',
                        'label'       => 'Article Body',
                        'type'        => 'textarea',
                        'required'    => 1,
                        'help_text'   => 'Main article body.',
                        'placeholder' => 'Paste article content here...',
                        'max_length'  => 30000,
                    ),
                    array(
                        'key'         => 'extra_instructions',
                        'label'       => 'Extra Instructions',
                        'type'        => 'textarea',
                        'required'    => 0,
                        'help_text'   => 'Optional editorial constraints or preferences.',
                        'placeholder' => 'Optional instructions',
                        'max_length'  => 4000,
                    ),
                ),
                'output_schema'        => array(
                    array(
                        'key'      => 'excerpt',
                        'label'    => 'Excerpt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                    array(
                        'key'      => 'image_prompt',
                        'label'    => 'Image Prompt',
                        'type'     => 'long_text',
                        'copyable' => 1,
                    ),
                ),
                'wp_integration'       => array(
                    'source' => array(
                        'type'             => 'post',
                        'allow_manual'     => 1,
                        'allow_draft'      => 1,
                        'allow_publish'    => 1,
                        'allow_attachment' => 0,
                    ),
                    'apply'  => array(
                        'target'   => 'post',
                        'mappings' => array(
                            array(
                                'output_key' => 'excerpt',
                                'wp_field'   => 'post_excerpt',
                                'label'      => 'Post Excerpt',
                            ),
                        ),
                    ),
                ),
                'max_input_chars'      => 36000,
                'max_output_tokens'    => 600,
                'daily_run_limit'      => 20,
                'log_inputs'           => 0,
                'log_outputs'          => 1,
                'sort_order'           => 30,
            ),
        );
    }

    protected static function maybe_seed_missing_featured_image_generator() {
        $repo = new FAT_Tools_Repository();
        $tool = $repo->get_by_slug( 'featured-image-generator' );
        if ( $tool ) {
            return;
        }

        $repo->create( self::featured_image_generator_blueprint( array( 'administrator', 'editor', 'author' ) ) );
    }


    protected static function maybe_seed_missing_uploaded_image_processor() {
        $repo = new FAT_Tools_Repository();
        $tool = $repo->get_by_slug( 'uploaded-image-processor' );
        if ( $tool ) {
            return;
        }

        $repo->create( self::uploaded_image_processor_blueprint( array( 'administrator', 'editor', 'author' ) ) );
    }

    protected static function maybe_seed_missing_attachment_metadata_assistant() {
        $repo = new FAT_Tools_Repository();
        $tool = $repo->get_by_slug( 'attachment-metadata-assistant' );
        if ( $tool ) {
            return;
        }

        $repo->create( self::attachment_metadata_assistant_blueprint( array( 'administrator', 'editor', 'author' ) ) );
    }

    protected static function uploaded_image_processor_blueprint( $roles ) {
        return array(
            'name'                 => 'Uploaded Image Processor',
            'slug'                 => 'uploaded-image-processor',
            'description'          => 'Processes an uploaded image into a 1200x675 WebP asset, stores it in Media Library, and generates attachment metadata.',
            'is_active'            => 1,
            'allowed_roles'        => $roles,
            'allowed_capabilities' => array(),
            'model'                => '',
            'system_prompt'        => 'This built-in workflow processes uploaded images server-side and generates attachment metadata. Prompt text fields are optional context only.',
            'user_prompt_template' => '{{input.prompt}}',
            'input_schema'         => array(
                array(
                    'key'         => 'prompt',
                    'label'       => 'Optional Context',
                    'type'        => 'textarea',
                    'required'    => 0,
                    'help_text'   => 'Optional context to guide metadata generation.',
                    'placeholder' => 'Optional context for title/alt/description',
                    'max_length'  => 1000,
                ),
            ),
            'output_schema'        => array(
                array(
                    'key'      => 'title',
                    'label'    => 'Generated Title',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'alt_text',
                    'label'    => 'Generated Alt Text',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'description',
                    'label'    => 'Generated Description',
                    'type'     => 'long_text',
                    'copyable' => 1,
                ),
            ),
            'wp_integration'       => array(
                'workflow' => 'uploaded_image_processor',
                'workflow_config' => array(
                    'target_size' => '1200x675',
                    'target_format' => 'webp',
                ),
                'source' => array(
                    'type'             => '',
                    'allow_manual'     => 1,
                    'allow_draft'      => 1,
                    'allow_publish'    => 1,
                    'allow_attachment' => 0,
                ),
                'apply' => array(
                    'target'   => 'post',
                    'mappings' => array(),
                ),
            ),
            'max_input_chars'      => 1500,
            'max_output_tokens'    => 250,
            'daily_run_limit'      => 20,
            'log_inputs'           => 1,
            'log_outputs'          => 1,
            'sort_order'           => 16,
        );
    }

    protected static function attachment_metadata_assistant_blueprint( $roles ) {
        return array(
            'name'                 => 'Attachment Metadata Assistant',
            'slug'                 => 'attachment-metadata-assistant',
            'description'          => 'Generates attachment title, alt text, and description from an existing Media Library image.',
            'is_active'            => 1,
            'allowed_roles'        => $roles,
            'allowed_capabilities' => array(),
            'model'                => '',
            'system_prompt'        => 'This built-in workflow generates metadata for an existing attachment. Prompt text is optional context only.',
            'user_prompt_template' => '{{input.prompt}}',
            'input_schema'         => array(
                array(
                    'key'         => 'prompt',
                    'label'       => 'Optional Context',
                    'type'        => 'textarea',
                    'required'    => 0,
                    'help_text'   => 'Optional editorial context for metadata generation.',
                    'placeholder' => 'Optional context for title/alt/description',
                    'max_length'  => 800,
                ),
            ),
            'output_schema'        => array(
                array(
                    'key'      => 'title',
                    'label'    => 'Generated Title',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'alt_text',
                    'label'    => 'Generated Alt Text',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'description',
                    'label'    => 'Generated Description',
                    'type'     => 'long_text',
                    'copyable' => 1,
                ),
            ),
            'wp_integration'       => array(
                'workflow' => 'attachment_metadata_assistant',
                'workflow_config' => array(),
                'source' => array(
                    'type'             => 'attachment',
                    'allow_manual'     => 0,
                    'allow_draft'      => 0,
                    'allow_publish'    => 0,
                    'allow_attachment' => 1,
                ),
                'apply' => array(
                    'target'   => 'attachment',
                    'mappings' => array(
                        array(
                            'output_key' => 'title',
                            'wp_field'   => 'post_title',
                            'label'      => 'Attachment Title',
                        ),
                        array(
                            'output_key' => 'alt_text',
                            'wp_field'   => 'alt_text',
                            'label'      => 'Alt Text',
                        ),
                        array(
                            'output_key' => 'description',
                            'wp_field'   => 'post_content',
                            'label'      => 'Description',
                        ),
                    ),
                ),
            ),
            'max_input_chars'      => 1200,
            'max_output_tokens'    => 250,
            'daily_run_limit'      => 30,
            'log_inputs'           => 1,
            'log_outputs'          => 1,
            'sort_order'           => 17,
        );
    }

    protected static function featured_image_generator_blueprint( $roles ) {
        return array(
            'name'                 => 'Featured Image Generator',
            'slug'                 => 'featured-image-generator',
            'description'          => 'Generates a featured image, stores it in Media Library, creates metadata, and prepares a 1200x675 PNG featured-image derivative.',
            'is_active'            => 1,
            'allowed_roles'        => $roles,
            'allowed_capabilities' => array(),
            'model'                => '',
            'system_prompt'        => 'This built-in workflow uses server-side image generation and metadata generation. Text prompt fields are kept for backward-compatible tool editing only.',
            'user_prompt_template' => '{{input.prompt}}',
            'input_schema'         => array(
                array(
                    'key'         => 'prompt',
                    'label'       => 'Image Prompt',
                    'type'        => 'textarea',
                    'required'    => 1,
                    'help_text'   => 'Describe the featured image to generate.',
                    'placeholder' => 'Describe the image you want to generate...',
                    'max_length'  => 2000,
                ),
            ),
            'output_schema'        => array(
                array(
                    'key'      => 'title',
                    'label'    => 'Generated Title',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'alt_text',
                    'label'    => 'Generated Alt Text',
                    'type'     => 'text',
                    'copyable' => 1,
                ),
                array(
                    'key'      => 'description',
                    'label'    => 'Generated Description',
                    'type'     => 'long_text',
                    'copyable' => 1,
                ),
            ),
            'wp_integration'       => array(
                'workflow' => 'featured_image_generator',
                'workflow_config' => array(
                    'image_model' => 'gpt-image-1-mini',
                    'image_quality' => 'low',
                    'source_size' => '1536x1024',
                    'featured_size' => '1200x675',
                    'featured_format' => 'png',
                ),
                'source' => array(
                    'type'             => '',
                    'allow_manual'     => 1,
                    'allow_draft'      => 1,
                    'allow_publish'    => 1,
                    'allow_attachment' => 0,
                ),
                'apply' => array(
                    'target'   => 'post',
                    'mappings' => array(),
                ),
            ),
            'max_input_chars'      => 3000,
            'max_output_tokens'    => 400,
            'daily_run_limit'      => 15,
            'log_inputs'           => 1,
            'log_outputs'          => 1,
            'sort_order'           => 15,
        );
    }
}
