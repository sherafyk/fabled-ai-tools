<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Admin {
    protected $settings;
    protected $tools_repo;
    protected $runs_repo;
    protected $validator;
    protected $prompt_engine;
    protected $tool_runner;
    protected $client;
    protected $entity_query_service;
    protected $builtin_tools;
    protected $launch_service;

    public function __construct( FAT_Settings $settings, FAT_Tools_Repository $tools_repo, FAT_Runs_Repository $runs_repo, FAT_Tool_Validator $validator, FAT_Prompt_Engine $prompt_engine, FAT_Tool_Runner $tool_runner, FAT_OpenAI_Client $client, FAT_Entity_Query_Service $entity_query_service, FAT_Builtin_Tools $builtin_tools ) {
        $this->settings      = $settings;
        $this->tools_repo    = $tools_repo;
        $this->runs_repo     = $runs_repo;
        $this->validator     = $validator;
        $this->prompt_engine = $prompt_engine;
        $this->tool_runner   = $tool_runner;
        $this->client        = $client;
        $this->entity_query_service = $entity_query_service;
        $this->builtin_tools = $builtin_tools;
        $this->launch_service = new FAT_Admin_Launch_Service();
    }

    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
        add_action( 'admin_post_fat_save_tool', array( $this, 'handle_save_tool' ) );
        add_action( 'admin_post_fat_tool_action', array( $this, 'handle_tool_action' ) );
        add_action( 'admin_post_fat_test_openai_connection', array( $this, 'handle_test_openai_connection' ) );
        add_action( 'admin_post_fat_purge_logs', array( $this, 'handle_purge_logs' ) );
        add_action( 'admin_post_fat_import_tool', array( $this, 'handle_import_tool' ) );
        add_action( 'wp_ajax_fat_runner_posts', array( $this, 'handle_runner_posts_lookup' ) );
        add_action( 'wp_ajax_fat_runner_attachments', array( $this, 'handle_runner_attachments_lookup' ) );
        add_filter( 'post_row_actions', array( $this, 'add_post_row_launch_link' ), 10, 2 );
        add_filter( 'page_row_actions', array( $this, 'add_post_row_launch_link' ), 10, 2 );
        add_filter( 'media_row_actions', array( $this, 'add_media_row_launch_link' ), 10, 2 );
    }

    public function register_menus() {
        add_menu_page(
            __( 'Fabled AI Tools', 'fabled-ai-tools' ),
            __( 'Fabled AI Tools', 'fabled-ai-tools' ),
            'fat_run_ai_tools',
            'fabled-ai-tools',
            array( $this, 'render_runner_page' ),
            'dashicons-admin-tools',
            56
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'Run Tools', 'fabled-ai-tools' ),
            __( 'Runner', 'fabled-ai-tools' ),
            'fat_run_ai_tools',
            'fabled-ai-tools',
            array( $this, 'render_runner_page' )
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'Manage Tools', 'fabled-ai-tools' ),
            __( 'Tools', 'fabled-ai-tools' ),
            'fat_manage_tools',
            'fat-tools',
            array( $this, 'render_tools_page' )
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'Add Tool', 'fabled-ai-tools' ),
            __( 'Add Tool', 'fabled-ai-tools' ),
            'fat_manage_tools',
            'fat-tool-edit',
            array( $this, 'render_tool_edit_page' )
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'Needs Attention', 'fabled-ai-tools' ),
            __( 'Needs Attention', 'fabled-ai-tools' ),
            'fat_run_ai_tools',
            'fat-needs-attention',
            array( $this, 'render_needs_attention_page' )
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'Run Logs', 'fabled-ai-tools' ),
            __( 'Logs', 'fabled-ai-tools' ),
            'fat_view_ai_logs',
            'fat-logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'fabled-ai-tools',
            __( 'AI Settings', 'fabled-ai-tools' ),
            __( 'Settings', 'fabled-ai-tools' ),
            'fat_manage_ai_settings',
            'fat-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function enqueue_assets() {
        if ( ! $this->is_plugin_page() ) {
            return;
        }

        wp_enqueue_style( 'fat-admin', FAT_PLUGIN_URL . 'assets/admin.css', array(), FAT_VERSION );
        wp_enqueue_script( 'fat-admin', FAT_PLUGIN_URL . 'assets/admin.js', array(), FAT_VERSION, true );

        $public_tools = array();
        if ( $this->current_user_can_run_tools() ) {
            $tools = $this->tool_runner->get_accessible_tools_for_user( wp_get_current_user() );
            foreach ( $tools as $tool ) {
                $public_tools[] = $this->prompt_engine->public_tool_definition( $tool );
            }
        }

        wp_localize_script(
            'fat-admin',
            'FAT_Admin_Data',
            array(
                'page'    => $this->current_page(),
                'restUrl' => esc_url_raw( rest_url( 'fabled-ai-tools/v1/run' ) ),
                'applyUrl' => esc_url_raw( rest_url( 'fabled-ai-tools/v1/apply' ) ),
                'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'postsNonce' => wp_create_nonce( 'fat_runner_posts' ),
                'launchContext' => $this->launch_service->runner_launch_context_from_request( $this->current_page() ),
                'tools'   => $public_tools,
                'strings' => array(
                    'runTool'        => __( 'Run Tool', 'fabled-ai-tools' ),
                    'running'        => __( 'Running…', 'fabled-ai-tools' ),
                    'copy'           => __( 'Copy', 'fabled-ai-tools' ),
                    'copied'         => __( 'Copied', 'fabled-ai-tools' ),
                    'runError'       => __( 'The tool could not be run.', 'fabled-ai-tools' ),
                    'selectTool'     => __( 'Select a tool', 'fabled-ai-tools' ),
                    'noTools'        => __( 'No tools are available for your account.', 'fabled-ai-tools' ),
                    'required'       => __( 'Required', 'fabled-ai-tools' ),
                    'optional'       => __( 'Optional', 'fabled-ai-tools' ),
                    'contentSource'  => __( 'Content Source', 'fabled-ai-tools' ),
                    'pasteContent'   => __( 'Paste Content', 'fabled-ai-tools' ),
                    'selectDraft'    => __( 'Select Draft', 'fabled-ai-tools' ),
                    'selectPublished'=> __( 'Select Published Content', 'fabled-ai-tools' ),
                    'loadingPosts'   => __( 'Loading content…', 'fabled-ai-tools' ),
                    'choosePost'     => __( 'Choose a content item', 'fabled-ai-tools' ),
                    'searchPosts'    => __( 'Search content by title…', 'fabled-ai-tools' ),
                    'noPostsFound'   => __( 'No content found for this status.', 'fabled-ai-tools' ),
                    'loadPostsError' => __( 'Unable to load content for this source.', 'fabled-ai-tools' ),
                    'postSelectionRequired' => __( 'Please select a content item for the chosen source.', 'fabled-ai-tools' ),
                    'bodyFilledFromPost' => __( 'Article body will be pulled from the selected content item.', 'fabled-ai-tools' ),
                    'mediaSource'   => __( 'Media Source', 'fabled-ai-tools' ),
                    'selectMedia'   => __( 'Select Media Attachment', 'fabled-ai-tools' ),
                    'loadingMedia'  => __( 'Loading media…', 'fabled-ai-tools' ),
                    'chooseMedia'   => __( 'Choose an attachment', 'fabled-ai-tools' ),
                    'searchMedia'   => __( 'Search media by title or filename…', 'fabled-ai-tools' ),
                    'noMediaFound'  => __( 'No attachments found.', 'fabled-ai-tools' ),
                    'loadMediaError'=> __( 'Unable to load attachments.', 'fabled-ai-tools' ),
                    'mediaSelectionRequired' => __( 'Please select an attachment.', 'fabled-ai-tools' ),
                    'generate'      => __( 'Generate', 'fabled-ai-tools' ),
                    'uploadAndProcess' => __( 'Upload and Process', 'fabled-ai-tools' ),
                    'generateApply' => __( 'Generate + Apply', 'fabled-ai-tools' ),
                    'uploadImage'   => __( 'Upload Image', 'fabled-ai-tools' ),
                    'uploadImageHelp' => __( 'Upload one image. The tool will create a 1200x675 WebP derivative.', 'fabled-ai-tools' ),
                    'uploadImageRequired' => __( 'Please upload an image to process.', 'fabled-ai-tools' ),
                    'uploadingImage' => __( 'Uploading image…', 'fabled-ai-tools' ),
                    'processingImage' => __( 'Processing image…', 'fabled-ai-tools' ),
                    'generatingMetadata' => __( 'Generating metadata…', 'fabled-ai-tools' ),
                    'applyingFeaturedImage' => __( 'Applying featured image…', 'fabled-ai-tools' ),
                    'processedImageTitle' => __( 'Processed Image', 'fabled-ai-tools' ),
                    'uploadReference' => __( 'Attachment ID', 'fabled-ai-tools' ),
                    'uploadFormat'  => __( 'Format', 'fabled-ai-tools' ),
                    'uploadSize'    => __( 'Size', 'fabled-ai-tools' ),
                    'applySelected' => __( 'Apply Selected Fields', 'fabled-ai-tools' ),
                    'applyPanelTitle' => __( 'Apply to WordPress', 'fabled-ai-tools' ),
                    'applyTarget'   => __( 'Target Content', 'fabled-ai-tools' ),
                    'applyTargetSelected' => __( 'Selected target', 'fabled-ai-tools' ),
                    'applyTargetNone' => __( 'No target selected.', 'fabled-ai-tools' ),
                    'applyFields'   => __( 'Fields to apply', 'fabled-ai-tools' ),
                    'searchButton'  => __( 'Search', 'fabled-ai-tools' ),
                    'loadMore'      => __( 'Load more', 'fabled-ai-tools' ),
                    'applySuccess'  => __( 'Selected fields were applied successfully.', 'fabled-ai-tools' ),
                    'applyError'    => __( 'Unable to apply selected outputs.', 'fabled-ai-tools' ),
                    'applyNoFields' => __( 'Select at least one field to apply.', 'fabled-ai-tools' ),
                    'applyTargetRequired' => __( 'Please choose a target before applying.', 'fabled-ai-tools' ),
                    'applyUnavailable' => __( 'No apply mappings are available for the generated outputs.', 'fabled-ai-tools' ),
                    'imagePromptRequired' => __( 'Please enter an image prompt.', 'fabled-ai-tools' ),
                    'applyFeaturedImage' => __( 'Apply as Featured Image', 'fabled-ai-tools' ),
                    'applyFeaturedSuccess' => __( 'Featured image was applied successfully.', 'fabled-ai-tools' ),
                    'addInputField'  => __( 'Add Input Field', 'fabled-ai-tools' ),
                    'addOutputField' => __( 'Add Output Field', 'fabled-ai-tools' ),
                ),
            )
        );
    }

    public function render_admin_notices() {
        if ( ! $this->is_plugin_page() ) {
            return;
        }

        $map = array(
            'fat_notice'  => 'notice notice-success is-dismissible',
            'fat_error'   => 'notice notice-error',
            'fat_warning' => 'notice notice-warning is-dismissible',
        );

        foreach ( $map as $query_key => $classes ) {
            if ( empty( $_GET[ $query_key ] ) ) {
                continue;
            }

            $message = sanitize_text_field( wp_unslash( $_GET[ $query_key ] ) );
            if ( '' === $message ) {
                continue;
            }

            echo '<div class="' . esc_attr( $classes ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    public function handle_save_tool() {
        if ( ! $this->current_user_can_manage_tools() ) {
            wp_die( esc_html__( 'You are not allowed to manage tools.', 'fabled-ai-tools' ) );
        }

        check_admin_referer( 'fat_save_tool' );

        $tool_id = isset( $_POST['tool_id'] ) ? absint( $_POST['tool_id'] ) : 0;
        $data    = $this->build_tool_data_from_request();

        $validation = $this->validator->validate_tool( $data, $this->tools_repo, $tool_id );
        if ( ! empty( $validation['errors'] ) ) {
            $this->store_form_state( $tool_id, $data, $validation );
            wp_safe_redirect(
                FAT_Helpers::admin_url_with_notice(
                    'fat-tool-edit',
                    array(
                        'id'          => $tool_id,
                        'fat_restore' => 1,
                        'fat_error'   => implode( ' ', $validation['errors'] ),
                    )
                )
            );
            exit;
        }

        if ( $tool_id > 0 ) {
            $result = $this->tools_repo->update( $tool_id, $data );
            $notice = __( 'Tool updated.', 'fabled-ai-tools' );
        } else {
            $result = $this->tools_repo->create( $data );
            $notice = __( 'Tool created.', 'fabled-ai-tools' );
        }

        if ( is_wp_error( $result ) ) {
            $this->store_form_state( $tool_id, $data, $validation );
            wp_safe_redirect(
                FAT_Helpers::admin_url_with_notice(
                    'fat-tool-edit',
                    array(
                        'id'          => $tool_id,
                        'fat_restore' => 1,
                        'fat_error'   => $result->get_error_message(),
                    )
                )
            );
            exit;
        }

        $redirect_args = array(
            'id'         => (int) $result['id'],
            'fat_notice' => $notice,
        );
        if ( ! empty( $validation['warnings'] ) ) {
            $redirect_args['fat_warning'] = implode( ' ', $validation['warnings'] );
        }

        wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tool-edit', $redirect_args ) );
        exit;
    }

    public function handle_tool_action() {
        if ( ! $this->current_user_can_manage_tools() ) {
            wp_die( esc_html__( 'You are not allowed to manage tools.', 'fabled-ai-tools' ) );
        }

        $tool_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $action  = isset( $_GET['tool_action'] ) ? sanitize_key( wp_unslash( $_GET['tool_action'] ) ) : '';

        check_admin_referer( 'fat_tool_action_' . $action . '_' . $tool_id );

        $redirect = FAT_Helpers::admin_url_with_notice( 'fat-tools' );
        $tool     = $this->tools_repo->get_by_id( $tool_id );
        if ( ! $tool ) {
            wp_safe_redirect( add_query_arg( 'fat_error', rawurlencode( __( 'Tool not found.', 'fabled-ai-tools' ) ), $redirect ) );
            exit;
        }

        switch ( $action ) {
            case 'duplicate':
                $result = $this->tools_repo->duplicate( $tool_id );
                if ( is_wp_error( $result ) ) {
                    $redirect = add_query_arg( 'fat_error', rawurlencode( $result->get_error_message() ), $redirect );
                } else {
                    $redirect = FAT_Helpers::admin_url_with_notice(
                        'fat-tool-edit',
                        array(
                            'id'         => (int) $result['id'],
                            'fat_notice' => __( 'Tool duplicated.', 'fabled-ai-tools' ),
                        )
                    );
                }
                break;

            case 'toggle':
                $result = $this->tools_repo->set_active( $tool_id, empty( $tool['is_active'] ) ? 1 : 0 );
                if ( is_wp_error( $result ) ) {
                    $redirect = add_query_arg( 'fat_error', rawurlencode( $result->get_error_message() ), $redirect );
                } else {
                    $redirect = add_query_arg( 'fat_notice', rawurlencode( ! empty( $result['is_active'] ) ? __( 'Tool activated.', 'fabled-ai-tools' ) : __( 'Tool deactivated.', 'fabled-ai-tools' ) ), $redirect );
                }
                break;

            case 'delete':
                if ( $this->builtin_tools->is_builtin_tool( $tool ) ) {
                    $redirect = add_query_arg( 'fat_error', rawurlencode( __( 'Built-in tools cannot be deleted. Use reset instead.', 'fabled-ai-tools' ) ), $redirect );
                    break;
                }

                $this->tools_repo->delete( $tool_id );
                $redirect = add_query_arg( 'fat_notice', rawurlencode( __( 'Tool deleted.', 'fabled-ai-tools' ) ), $redirect );
                break;

            case 'reset_default':
                $result = $this->builtin_tools->reset_tool_to_defaults( $tool_id );
                if ( is_wp_error( $result ) ) {
                    $redirect = add_query_arg( 'fat_error', rawurlencode( $result->get_error_message() ), $redirect );
                } else {
                    $redirect = FAT_Helpers::admin_url_with_notice(
                        'fat-tool-edit',
                        array(
                            'id'         => $tool_id,
                            'fat_notice' => __( 'Built-in tool reset to defaults.', 'fabled-ai-tools' ),
                        )
                    );
                }
                break;

            case 'export':
                $this->download_tool_export( $tool );
                exit;

            default:
                $redirect = add_query_arg( 'fat_error', rawurlencode( __( 'Unknown tool action.', 'fabled-ai-tools' ) ), $redirect );
                break;
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    public function render_runner_page() {
        if ( ! $this->current_user_can_run_tools() ) {
            wp_die( esc_html__( 'You are not allowed to run tools.', 'fabled-ai-tools' ) );
        }

        $tools = $this->tool_runner->get_accessible_tools_for_user( wp_get_current_user() );
        ?>
        <div class="wrap fat-wrap">
            <h1><?php esc_html_e( 'Fabled AI Tools', 'fabled-ai-tools' ); ?></h1>
            <p><?php esc_html_e( 'Select a tool, fill in the required inputs, and run it. Prompts and API keys stay server-side.', 'fabled-ai-tools' ); ?></p>

            <?php if ( ! $this->settings->is_execution_enabled() ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'Runner execution is currently paused in Settings.', 'fabled-ai-tools' ); ?></p></div>
            <?php endif; ?>

            <?php if ( ! $this->settings->has_api_key() ) : ?>
                <div class="notice notice-warning"><p><?php echo wp_kses_post( sprintf( __( 'No OpenAI API key is configured yet. Set one on the <a href="%s">Settings</a> page.', 'fabled-ai-tools' ), esc_url( admin_url( 'admin.php?page=fat-settings' ) ) ) ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $tools ) ) : ?>
                <div class="fat-card">
                    <p><?php esc_html_e( 'No active tools are available for your account right now.', 'fabled-ai-tools' ); ?></p>
                </div>
            <?php else : ?>
                <div id="fat-runner-app" class="fat-card">
                    <div class="fat-form-row fat-runner-toolbar">
                        <div>
                            <label for="fat-tool-select"><strong><?php esc_html_e( 'Tool', 'fabled-ai-tools' ); ?></strong></label>
                            <select id="fat-tool-select">
                                <?php foreach ( $tools as $index => $tool ) : ?>
                                    <option value="<?php echo esc_attr( $tool['id'] ); ?>" <?php selected( 0 === $index ); ?>><?php echo esc_html( $tool['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="fat-tool-meta" class="fat-tool-meta"></div>

                    <form id="fat-runner-form">
                        <div id="fat-input-fields"></div>
                        <p>
                            <button type="submit" class="button button-primary" id="fat-runner-submit"><?php esc_html_e( 'Generate', 'fabled-ai-tools' ); ?></button>
                        </p>
                    </form>

                    <div id="fat-runner-status" class="fat-runner-status" aria-live="polite"></div>
                    <div id="fat-output-fields" class="fat-output-grid"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_tools_page() {
        if ( ! $this->current_user_can_manage_tools() ) {
            wp_die( esc_html__( 'You are not allowed to manage tools.', 'fabled-ai-tools' ) );
        }

        $tools = $this->tools_repo->get_all();
        ?>
        <div class="wrap fat-wrap">
            <div class="fat-header-row">
                <h1><?php esc_html_e( 'Manage AI Tools', 'fabled-ai-tools' ); ?></h1>
                <a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=fat-tool-edit' ) ); ?>"><?php esc_html_e( 'Add New', 'fabled-ai-tools' ); ?></a>
            </div>

            <div class="fat-card">
                <h2><?php esc_html_e( 'Import Tool', 'fabled-ai-tools' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="fat_import_tool" />
                    <?php wp_nonce_field( 'fat_import_tool' ); ?>
                    <p>
                        <textarea name="tool_export_json" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'Paste exported tool JSON here.', 'fabled-ai-tools' ); ?>"></textarea>
                    </p>
                    <?php submit_button( __( 'Import Tool', 'fabled-ai-tools' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="fat-card fat-table-card">
                <table class="widefat striped fat-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Slug', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Access', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Model', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Daily Limit', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Logging', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'fabled-ai-tools' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $tools ) ) : ?>
                        <tr><td colspan="8"><?php esc_html_e( 'No tools found.', 'fabled-ai-tools' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $tools as $tool ) : ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=fat-tool-edit&id=' . (int) $tool['id'] ) ); ?>"><?php echo esc_html( $tool['name'] ); ?></a></strong>
                                    <?php if ( $this->builtin_tools->is_builtin_tool( $tool ) ) : ?>
                                        <span class="fat-pill"><?php esc_html_e( 'Built-in', 'fabled-ai-tools' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $tool['description'] ) ) : ?>
                                        <div class="fat-muted"><?php echo esc_html( $tool['description'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $tool['slug'] ); ?></code></td>
                                <td><?php echo ! empty( $tool['is_active'] ) ? '<span class="fat-pill fat-pill-success">' . esc_html__( 'Active', 'fabled-ai-tools' ) . '</span>' : '<span class="fat-pill">' . esc_html__( 'Inactive', 'fabled-ai-tools' ) . '</span>'; ?></td>
                                <td>
                                    <?php
                                    $access = array();
                                    if ( ! empty( $tool['allowed_roles'] ) ) {
                                        $access[] = 'Roles: ' . implode( ', ', (array) $tool['allowed_roles'] );
                                    }
                                    if ( ! empty( $tool['allowed_capabilities'] ) ) {
                                        $access[] = 'Caps: ' . implode( ', ', (array) $tool['allowed_capabilities'] );
                                    }
                                    echo ! empty( $access ) ? esc_html( implode( ' | ', $access ) ) : esc_html__( 'Any user with fat_run_ai_tools', 'fabled-ai-tools' );
                                    ?>
                                </td>
                                <td><?php echo '' !== $tool['model'] ? esc_html( $tool['model'] ) : '<span class="fat-muted">' . esc_html__( 'Default', 'fabled-ai-tools' ) . '</span>'; ?></td>
                                <td><?php echo ! empty( $tool['daily_run_limit'] ) ? esc_html( $tool['daily_run_limit'] ) : '<span class="fat-muted">' . esc_html__( 'Default', 'fabled-ai-tools' ) . '</span>'; ?></td>
                                <td>
                                    <?php
                                    $logging = array();
                                    $logging[] = ! empty( $tool['log_inputs'] ) ? __( 'Inputs', 'fabled-ai-tools' ) : __( 'No inputs', 'fabled-ai-tools' );
                                    $logging[] = ! empty( $tool['log_outputs'] ) ? __( 'Outputs', 'fabled-ai-tools' ) : __( 'No outputs', 'fabled-ai-tools' );
                                    echo esc_html( implode( ' / ', $logging ) );
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fat-tool-edit&id=' . (int) $tool['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'fabled-ai-tools' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( $this->runner_launch_url( array( 'fat_tool' => $tool['slug'] ) ) ); ?>"><?php esc_html_e( 'Run', 'fabled-ai-tools' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( $this->tool_action_url( 'export', $tool['id'] ) ); ?>"><?php esc_html_e( 'Export', 'fabled-ai-tools' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( $this->tool_action_url( 'duplicate', $tool['id'] ) ); ?>"><?php esc_html_e( 'Duplicate', 'fabled-ai-tools' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( $this->tool_action_url( 'toggle', $tool['id'] ) ); ?>"><?php echo ! empty( $tool['is_active'] ) ? esc_html__( 'Deactivate', 'fabled-ai-tools' ) : esc_html__( 'Activate', 'fabled-ai-tools' ); ?></a>
                                    <?php if ( $this->builtin_tools->is_builtin_tool( $tool ) ) : ?>
                                        |
                                        <a href="<?php echo esc_url( $this->tool_action_url( 'reset_default', $tool['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reset this built-in tool to its default configuration?', 'fabled-ai-tools' ) ); ?>');"><?php esc_html_e( 'Reset', 'fabled-ai-tools' ); ?></a>
                                    <?php else : ?>
                                        |
                                        <a href="<?php echo esc_url( $this->tool_action_url( 'delete', $tool['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this tool?', 'fabled-ai-tools' ) ); ?>');"><?php esc_html_e( 'Delete', 'fabled-ai-tools' ); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_tool_edit_page() {
        if ( ! $this->current_user_can_manage_tools() ) {
            wp_die( esc_html__( 'You are not allowed to manage tools.', 'fabled-ai-tools' ) );
        }

        $tool_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $tool    = $tool_id ? $this->tools_repo->get_by_id( $tool_id ) : $this->default_tool();
        $is_builtin_tool = $tool && $this->builtin_tools->is_builtin_tool( $tool );

        if ( $tool_id && ! $tool ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Tool not found.', 'fabled-ai-tools' ) . '</p></div></div>';
            return;
        }

        $stored_state = $this->consume_form_state();
        if ( ! empty( $stored_state['data'] ) ) {
            $tool = wp_parse_args( $stored_state['data'], $tool );
        }

        if ( empty( $tool['input_schema'] ) ) {
            $tool['input_schema'] = array( $this->default_input_row() );
        }
        if ( empty( $tool['output_schema'] ) ) {
            $tool['output_schema'] = array( $this->default_output_row() );
        }
        $wp_integration_form = $this->wp_integration_form_state( FAT_Helpers::array_get( $tool, 'wp_integration', array() ) );
        $workflow_fields     = $this->builtin_workflow_form_fields( $wp_integration_form['workflow'] );
        if ( ! empty( $wp_integration_form['enabled'] ) && empty( $wp_integration_form['mappings'] ) ) {
            $wp_integration_form['mappings'] = array( $this->default_wp_mapping_row() );
        }

        $roles = wp_roles()->roles;
        ?>
        <div class="wrap fat-wrap">
            <h1><?php echo $tool_id ? esc_html__( 'Edit Tool', 'fabled-ai-tools' ) : esc_html__( 'Add Tool', 'fabled-ai-tools' ); ?></h1>

            <?php if ( $is_builtin_tool ) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <strong><?php esc_html_e( 'Built-in workflow tool.', 'fabled-ai-tools' ); ?></strong>
                        <?php esc_html_e( 'This tool is seeded by the plugin and can be reset to its default settings.', 'fabled-ai-tools' ); ?>
                        <?php esc_html_e( 'Reset restores prompts, schema, WordPress integration, and workflow configuration.', 'fabled-ai-tools' ); ?>
                        <a href="<?php echo esc_url( $this->tool_action_url( 'reset_default', $tool['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reset this built-in tool to its default configuration?', 'fabled-ai-tools' ) ); ?>');"><?php esc_html_e( 'Reset now', 'fabled-ai-tools' ); ?></a>
                        |
                        <a href="<?php echo esc_url( $this->tool_action_url( 'export', $tool['id'] ) ); ?>"><?php esc_html_e( 'Export JSON', 'fabled-ai-tools' ); ?></a>
                    </p>
                </div>
            <?php elseif ( $tool_id ) : ?>
                <p><a href="<?php echo esc_url( $this->tool_action_url( 'export', $tool['id'] ) ); ?>"><?php esc_html_e( 'Export this tool as JSON', 'fabled-ai-tools' ); ?></a></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="fat_save_tool" />
                <input type="hidden" name="tool_id" value="<?php echo esc_attr( $tool_id ); ?>" />
                <?php wp_nonce_field( 'fat_save_tool' ); ?>

                <div class="fat-card">
                    <h2><?php esc_html_e( 'Basic Settings', 'fabled-ai-tools' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="fat-name"><?php esc_html_e( 'Name', 'fabled-ai-tools' ); ?></label></th>
                            <td><input id="fat-name" name="name" type="text" class="regular-text" value="<?php echo esc_attr( $tool['name'] ); ?>" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-slug"><?php esc_html_e( 'Slug', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <input id="fat-slug" name="slug" type="text" class="regular-text" value="<?php echo esc_attr( $tool['slug'] ); ?>" required />
                                <p class="description"><?php esc_html_e( 'Unique key used internally and in JSON schema names.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-description"><?php esc_html_e( 'Description', 'fabled-ai-tools' ); ?></label></th>
                            <td><textarea id="fat-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $tool['description'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Active', 'fabled-ai-tools' ); ?></th>
                            <td><label><input type="checkbox" name="is_active" value="1" <?php checked( ! empty( $tool['is_active'] ) ); ?> /> <?php esc_html_e( 'Tool is available in the runner', 'fabled-ai-tools' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Allowed Roles', 'fabled-ai-tools' ); ?></th>
                            <td>
                                <fieldset>
                                    <?php foreach ( $roles as $role_key => $role_data ) : ?>
                                        <label class="fat-inline-check"><input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $tool['allowed_roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description"><?php esc_html_e( 'Leave empty to allow any user who has the fat_run_ai_tools capability.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-allowed-caps"><?php esc_html_e( 'Allowed Capabilities', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <textarea id="fat-allowed-caps" name="allowed_capabilities" rows="3" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $tool['allowed_capabilities'] ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Optional. One capability per line or comma-separated.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-model"><?php esc_html_e( 'Model Override', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <input id="fat-model" name="model" type="text" class="regular-text" value="<?php echo esc_attr( $tool['model'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Leave blank to use the plugin default model.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-max-input"><?php esc_html_e( 'Max Input Chars', 'fabled-ai-tools' ); ?></label></th>
                            <td><input id="fat-max-input" name="max_input_chars" type="number" min="1" step="1" value="<?php echo esc_attr( (int) $tool['max_input_chars'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-max-output-tokens"><?php esc_html_e( 'Max Output Tokens', 'fabled-ai-tools' ); ?></label></th>
                            <td><input id="fat-max-output-tokens" name="max_output_tokens" type="number" min="1" step="1" value="<?php echo esc_attr( (int) $tool['max_output_tokens'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-daily-run-limit"><?php esc_html_e( 'Per-user Daily Run Limit', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <input id="fat-daily-run-limit" name="daily_run_limit" type="number" min="0" step="1" value="<?php echo esc_attr( (int) $tool['daily_run_limit'] ); ?>" />
                                <p class="description"><?php esc_html_e( '0 uses the global default daily limit.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Logging', 'fabled-ai-tools' ); ?></th>
                            <td>
                                <label class="fat-inline-check"><input type="checkbox" name="log_inputs" value="1" <?php checked( ! empty( $tool['log_inputs'] ) ); ?> /> <?php esc_html_e( 'Store full request payloads', 'fabled-ai-tools' ); ?></label>
                                <label class="fat-inline-check"><input type="checkbox" name="log_outputs" value="1" <?php checked( ! empty( $tool['log_outputs'] ) ); ?> /> <?php esc_html_e( 'Store full response payloads', 'fabled-ai-tools' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Preview snippets are always logged. Use full logging only when you need deeper troubleshooting.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-sort-order"><?php esc_html_e( 'Sort Order', 'fabled-ai-tools' ); ?></label></th>
                            <td><input id="fat-sort-order" name="sort_order" type="number" step="1" value="<?php echo esc_attr( (int) $tool['sort_order'] ); ?>" /></td>
                        </tr>
                    </table>
                </div>

                <div class="fat-card">
                    <h2><?php esc_html_e( 'Prompts', 'fabled-ai-tools' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="fat-system-prompt"><?php esc_html_e( 'System Prompt', 'fabled-ai-tools' ); ?></label></th>
                            <td><textarea id="fat-system-prompt" name="system_prompt" rows="8" class="large-text code" required><?php echo esc_textarea( $tool['system_prompt'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-user-prompt-template"><?php esc_html_e( 'User Prompt Template', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <textarea id="fat-user-prompt-template" name="user_prompt_template" rows="12" class="large-text code" required><?php echo esc_textarea( $tool['user_prompt_template'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Use placeholders like {{input.article_body}} or {{input.title}}. Template references are validated against the input schema keys below.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="fat-card">
                    <div class="fat-header-row">
                        <h2><?php esc_html_e( 'Input Schema', 'fabled-ai-tools' ); ?></h2>
                        <button type="button" class="button fat-add-row" data-target="input"><?php esc_html_e( 'Add Input Field', 'fabled-ai-tools' ); ?></button>
                    </div>
                    <table class="widefat striped fat-schema-table" id="fat-input-schema-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Key', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Label', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Required', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Help Text', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Placeholder', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Max Length', 'fabled-ai-tools' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_values( $tool['input_schema'] ) as $index => $field ) : ?>
                                <?php $this->render_input_schema_row( $index, $field ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="fat-card">
                    <div class="fat-header-row">
                        <h2><?php esc_html_e( 'Output Schema', 'fabled-ai-tools' ); ?></h2>
                        <button type="button" class="button fat-add-row" data-target="output"><?php esc_html_e( 'Add Output Field', 'fabled-ai-tools' ); ?></button>
                    </div>
                    <table class="widefat striped fat-schema-table" id="fat-output-schema-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Key', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Label', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Copyable', 'fabled-ai-tools' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_values( $tool['output_schema'] ) as $index => $field ) : ?>
                                <?php $this->render_output_schema_row( $index, $field ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="fat-card">
                    <h2><?php esc_html_e( 'WordPress Apply Integration', 'fabled-ai-tools' ); ?></h2>
                    <?php if ( ! empty( $wp_integration_form['workflow'] ) ) : ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="fat-workflow-type"><?php esc_html_e( 'Workflow Type', 'fabled-ai-tools' ); ?></label></th>
                                <td>
                                    <input id="fat-workflow-type" type="text" class="regular-text" value="<?php echo esc_attr( $wp_integration_form['workflow'] ); ?>" readonly />
                                    <input type="hidden" name="wp_integration_workflow" value="<?php echo esc_attr( $wp_integration_form['workflow'] ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Built-in workflow identity is fixed and preserved across saves.', 'fabled-ai-tools' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    <?php endif; ?>

                    <?php if ( ! empty( $workflow_fields ) ) : ?>
                        <h3><?php esc_html_e( 'Built-in Workflow Configuration', 'fabled-ai-tools' ); ?></h3>
                        <table class="form-table" role="presentation">
                            <?php foreach ( $workflow_fields as $workflow_key => $field ) : ?>
                                <?php
                                $field_id    = 'fat-workflow-' . sanitize_html_class( $workflow_key );
                                $field_value = (string) FAT_Helpers::array_get( $wp_integration_form['workflow_config'], $workflow_key, FAT_Helpers::array_get( $field, 'default', '' ) );
                                $is_readonly = ! empty( $field['readonly'] );
                                ?>
                                <tr>
                                    <th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( FAT_Helpers::array_get( $field, 'label', $workflow_key ) ); ?></label></th>
                                    <td>
                                        <?php if ( 'select' === FAT_Helpers::array_get( $field, 'type', 'text' ) ) : ?>
                                            <select id="<?php echo esc_attr( $field_id ); ?>" name="wp_integration_workflow_config[<?php echo esc_attr( $workflow_key ); ?>]" <?php disabled( $is_readonly ); ?>>
                                                <?php foreach ( (array) FAT_Helpers::array_get( $field, 'options', array() ) as $option_value => $option_label ) : ?>
                                                    <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $field_value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ( $is_readonly ) : ?>
                                                <input type="hidden" name="wp_integration_workflow_config[<?php echo esc_attr( $workflow_key ); ?>]" value="<?php echo esc_attr( $field_value ); ?>" />
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <input id="<?php echo esc_attr( $field_id ); ?>" name="wp_integration_workflow_config[<?php echo esc_attr( $workflow_key ); ?>]" type="text" class="regular-text" value="<?php echo esc_attr( $field_value ); ?>" <?php echo $is_readonly ? 'readonly' : ''; ?> />
                                        <?php endif; ?>
                                        <?php if ( ! empty( $field['description'] ) ) : ?>
                                            <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Integration', 'fabled-ai-tools' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="wp_integration_enabled" value="1" <?php checked( ! empty( $wp_integration_form['enabled'] ) ); ?> /> <?php esc_html_e( 'Allow generated outputs to be applied to WordPress fields.', 'fabled-ai-tools' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Leave disabled to keep this tool in generate-only mode.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fat-wp-target"><?php esc_html_e( 'Apply Target Type', 'fabled-ai-tools' ); ?></label></th>
                            <td>
                                <select id="fat-wp-target" name="wp_integration_apply_target">
                                    <option value=""><?php esc_html_e( 'None', 'fabled-ai-tools' ); ?></option>
                                    <option value="post" <?php selected( 'post', $wp_integration_form['apply_target'] ); ?>><?php esc_html_e( 'Post', 'fabled-ai-tools' ); ?></option>
                                    <option value="media" <?php selected( 'media', $wp_integration_form['apply_target'] ); ?>><?php esc_html_e( 'Media', 'fabled-ai-tools' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Post supports title/excerpt/content. Media supports title/alt text/caption/description.', 'fabled-ai-tools' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="fat-header-row">
                        <h3><?php esc_html_e( 'Output to WordPress Field Mappings', 'fabled-ai-tools' ); ?></h3>
                        <button type="button" class="button fat-add-row" data-target="wp-mapping"><?php esc_html_e( 'Add Mapping', 'fabled-ai-tools' ); ?></button>
                    </div>
                    <table class="widefat striped fat-schema-table" id="fat-wp-mapping-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Output Key', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'WordPress Field', 'fabled-ai-tools' ); ?></th>
                                <th><?php esc_html_e( 'Label', 'fabled-ai-tools' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_values( (array) $wp_integration_form['mappings'] ) as $index => $mapping ) : ?>
                                <?php $this->render_wp_mapping_row( $index, $mapping, $tool['output_schema'] ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e( 'Map each output key once. Duplicate mappings or unsupported fields are rejected during save.', 'fabled-ai-tools' ); ?></p>
                </div>

                <?php submit_button( $tool_id ? __( 'Update Tool', 'fabled-ai-tools' ) : __( 'Create Tool', 'fabled-ai-tools' ) ); ?>
            </form>

            <script type="text/template" id="fat-input-row-template"><?php ob_start(); $this->render_input_schema_row( '__INDEX__', $this->default_input_row() ); echo trim( ob_get_clean() ); ?></script>
            <script type="text/template" id="fat-output-row-template"><?php ob_start(); $this->render_output_schema_row( '__INDEX__', $this->default_output_row() ); echo trim( ob_get_clean() ); ?></script>
            <script type="text/template" id="fat-wp-mapping-row-template"><?php ob_start(); $this->render_wp_mapping_row( '__INDEX__', $this->default_wp_mapping_row(), $tool['output_schema'] ); echo trim( ob_get_clean() ); ?></script>
        </div>
        <?php
    }

    public function render_needs_attention_page() {
        if ( ! $this->current_user_can_run_tools() ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'fabled-ai-tools' ) );
        }

        $excerpt_items        = $this->find_posts_missing_excerpt( 12 );
        $featured_image_items = $this->find_posts_missing_featured_image( 12 );
        $attachment_items     = $this->find_attachments_missing_alt_text( 12 );
        $recent_errors_24h    = $this->runs_repo->count_runs_since( 'error', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
        $recent_errors_7d     = $this->runs_repo->count_runs_since( 'error', gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * 7 ) ) );
        $latest_failures      = $this->runs_repo->get_recent_failures( 6 );

        $tool_map = array();
        foreach ( $this->tools_repo->get_all() as $tool ) {
            $tool_map[ (int) $tool['id'] ] = $tool;
        }
        ?>
        <div class="wrap fat-wrap">
            <h1><?php esc_html_e( 'Needs Attention', 'fabled-ai-tools' ); ?></h1>
            <p><?php esc_html_e( 'Use these lightweight queues to spot common editorial gaps and launch directly into the right workflow.', 'fabled-ai-tools' ); ?></p>

            <div class="fat-grid-2">
                <div class="fat-card">
                    <h2><?php esc_html_e( 'Recent Health', 'fabled-ai-tools' ); ?></h2>
                    <ul class="fat-health-list">
                        <li><strong><?php echo esc_html( number_format_i18n( $recent_errors_24h ) ); ?></strong> <?php esc_html_e( 'errors in the last 24 hours', 'fabled-ai-tools' ); ?></li>
                        <li><strong><?php echo esc_html( number_format_i18n( $recent_errors_7d ) ); ?></strong> <?php esc_html_e( 'errors in the last 7 days', 'fabled-ai-tools' ); ?></li>
                    </ul>
                    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fat-logs&status=error' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View Error Logs', 'fabled-ai-tools' ); ?></a></p>
                </div>

                <div class="fat-card">
                    <h2><?php esc_html_e( 'Latest Failed Runs', 'fabled-ai-tools' ); ?></h2>
                    <?php if ( empty( $latest_failures ) ) : ?>
                        <p class="fat-muted"><?php esc_html_e( 'No recent failures found.', 'fabled-ai-tools' ); ?></p>
                    <?php else : ?>
                        <ul class="fat-failure-list">
                            <?php foreach ( $latest_failures as $run ) : ?>
                                <?php $tool = FAT_Helpers::array_get( $tool_map, (int) $run['tool_id'], array() ); ?>
                                <li>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fat-logs', 'run_id' => (int) $run['id'], 'status' => 'error' ), admin_url( 'admin.php' ) ) ); ?>">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                '#%1$d — %2$s',
                                                (int) $run['id'],
                                                FAT_Helpers::array_get( $tool, 'name', '#' . (int) $run['tool_id'] )
                                            )
                                        );
                                        ?>
                                    </a>
                                    <span class="fat-muted">· <?php echo esc_html( $run['created_at'] ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="fat-card fat-table-card">
                <h2><?php esc_html_e( 'Posts Missing Excerpts', 'fabled-ai-tools' ); ?></h2>
                <?php $this->render_gap_posts_table( $excerpt_items, 'seo-excerpt', __( 'Generate Excerpt', 'fabled-ai-tools' ) ); ?>
            </div>

            <div class="fat-card fat-table-card">
                <h2><?php esc_html_e( 'Posts Missing Featured Images', 'fabled-ai-tools' ); ?></h2>
                <?php $this->render_gap_posts_table( $featured_image_items, 'featured-image-generator', __( 'Generate Featured Image', 'fabled-ai-tools' ) ); ?>
            </div>

            <div class="fat-card fat-table-card">
                <h2><?php esc_html_e( 'Image Attachments Missing Alt Text', 'fabled-ai-tools' ); ?></h2>
                <?php $this->render_gap_attachments_table( $attachment_items ); ?>
            </div>
        </div>
        <?php
    }

    public function render_logs_page() {
        if ( ! $this->current_user_can_view_logs() ) {
            wp_die( esc_html__( 'You are not allowed to view logs.', 'fabled-ai-tools' ) );
        }

        $status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $tool_id  = isset( $_GET['tool_id'] ) ? absint( $_GET['tool_id'] ) : 0;
        $page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 30;

        $runs       = $this->runs_repo->get_runs(
            array(
                'status'   => $status,
                'tool_id'  => $tool_id,
                'page'     => $page_num,
                'per_page' => $per_page,
            )
        );
        $total_runs = $this->runs_repo->count_runs(
            array(
                'status'  => $status,
                'tool_id' => $tool_id,
            )
        );
        $tools      = $this->tools_repo->get_all();
        $tool_map   = array();
        foreach ( $tools as $tool ) {
            $tool_map[ $tool['id'] ] = $tool['name'];
        }

        $selected_run = null;
        if ( ! empty( $_GET['run_id'] ) ) {
            $selected_run = $this->runs_repo->get_run( absint( $_GET['run_id'] ) );
        }

        ?>
        <div class="wrap fat-wrap">
            <h1><?php esc_html_e( 'Tool Run Logs', 'fabled-ai-tools' ); ?></h1>

            <form method="get" class="fat-filter-bar">
                <input type="hidden" name="page" value="fat-logs" />
                <select name="status">
                    <option value=""><?php esc_html_e( 'All statuses', 'fabled-ai-tools' ); ?></option>
                    <option value="success" <?php selected( 'success', $status ); ?>><?php esc_html_e( 'Success', 'fabled-ai-tools' ); ?></option>
                    <option value="error" <?php selected( 'error', $status ); ?>><?php esc_html_e( 'Error', 'fabled-ai-tools' ); ?></option>
                </select>
                <select name="tool_id">
                    <option value="0"><?php esc_html_e( 'All tools', 'fabled-ai-tools' ); ?></option>
                    <?php foreach ( $tools as $tool ) : ?>
                        <option value="<?php echo esc_attr( $tool['id'] ); ?>" <?php selected( $tool_id, $tool['id'] ); ?>><?php echo esc_html( $tool['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'fabled-ai-tools' ); ?></button>
            </form>

            <?php if ( $selected_run ) : ?>
                <div class="fat-card fat-log-detail">
                    <h2><?php esc_html_e( 'Run Details', 'fabled-ai-tools' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e( 'Created', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( $selected_run['created_at'] ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Tool', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( FAT_Helpers::array_get( $tool_map, $selected_run['tool_id'], '#' . $selected_run['tool_id'] ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'User', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( FAT_Helpers::format_user_label( $selected_run['user_id'] ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Status', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( $selected_run['status'] ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Model', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( $selected_run['model_used'] ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Latency', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( (string) $selected_run['latency_ms'] ); ?> ms</td></tr>
                        <tr><th><?php esc_html_e( 'Tokens', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( sprintf( 'in: %s / out: %s / total: %s', (string) $selected_run['prompt_tokens'], (string) $selected_run['completion_tokens'], (string) $selected_run['total_tokens'] ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'OpenAI Request ID', 'fabled-ai-tools' ); ?></th><td><?php echo esc_html( (string) FAT_Helpers::array_get( $selected_run, 'openai_request_id', '' ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Request Preview', 'fabled-ai-tools' ); ?></th><td><pre><?php echo esc_html( $selected_run['request_preview'] ); ?></pre></td></tr>
                        <tr><th><?php esc_html_e( 'Response Preview', 'fabled-ai-tools' ); ?></th><td><pre><?php echo esc_html( $selected_run['response_preview'] ); ?></pre></td></tr>
                        <?php if ( ! empty( $selected_run['error_message'] ) ) : ?>
                            <tr><th><?php esc_html_e( 'Error', 'fabled-ai-tools' ); ?></th><td><pre><?php echo esc_html( $selected_run['error_message'] ); ?></pre></td></tr>
                        <?php endif; ?>
                        <?php if ( ! empty( $selected_run['request_payload'] ) ) : ?>
                            <tr><th><?php esc_html_e( 'Request Payload', 'fabled-ai-tools' ); ?></th><td><pre><?php echo esc_html( $selected_run['request_payload'] ); ?></pre></td></tr>
                        <?php endif; ?>
                        <?php if ( ! empty( $selected_run['response_payload'] ) ) : ?>
                            <tr><th><?php esc_html_e( 'Response Payload', 'fabled-ai-tools' ); ?></th><td><pre><?php echo esc_html( $selected_run['response_payload'] ); ?></pre></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>

            <div class="fat-card fat-table-card">
                <table class="widefat striped fat-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Tool', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'User', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Model', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Latency', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Tokens', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'Preview', 'fabled-ai-tools' ); ?></th>
                            <th><?php esc_html_e( 'View', 'fabled-ai-tools' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $runs ) ) : ?>
                        <tr><td colspan="9"><?php esc_html_e( 'No logs found.', 'fabled-ai-tools' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $runs as $run ) : ?>
                            <tr>
                                <td><?php echo esc_html( $run['created_at'] ); ?></td>
                                <td><?php echo esc_html( FAT_Helpers::array_get( $tool_map, $run['tool_id'], '#' . $run['tool_id'] ) ); ?></td>
                                <td><?php echo esc_html( FAT_Helpers::format_user_label( $run['user_id'] ) ); ?></td>
                                <td><?php echo esc_html( $run['status'] ); ?></td>
                                <td><?php echo esc_html( $run['model_used'] ); ?></td>
                                <td><?php echo esc_html( (string) $run['latency_ms'] ); ?> ms</td>
                                <td><?php echo esc_html( (string) $run['total_tokens'] ); ?></td>
                                <td>
                                    <div class="fat-log-preview"><strong><?php esc_html_e( 'Request:', 'fabled-ai-tools' ); ?></strong> <?php echo esc_html( FAT_Helpers::truncate( $run['request_preview'], 120 ) ); ?></div>
                                    <div class="fat-log-preview"><strong><?php esc_html_e( 'Response:', 'fabled-ai-tools' ); ?></strong> <?php echo esc_html( FAT_Helpers::truncate( $run['response_preview'], 120 ) ); ?></div>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fat-logs', 'run_id' => $run['id'], 'status' => $status, 'tool_id' => $tool_id, 'paged' => $page_num ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'fabled-ai-tools' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php
                $pagination = paginate_links(
                    array(
                        'base'      => add_query_arg( array( 'page' => 'fat-logs', 'status' => $status, 'tool_id' => $tool_id, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
                        'format'    => '',
                        'prev_text' => __( '&laquo;', 'fabled-ai-tools' ),
                        'next_text' => __( '&raquo;', 'fabled-ai-tools' ),
                        'total'     => max( 1, (int) ceil( $total_runs / $per_page ) ),
                        'current'   => $page_num,
                    )
                );
                if ( $pagination ) {
                    echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $pagination ) . '</div></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        if ( ! $this->current_user_can_manage_settings() ) {
            wp_die( esc_html__( 'You are not allowed to manage settings.', 'fabled-ai-tools' ) );
        }

        ?>
        <div class="wrap fat-wrap">
            <h1><?php esc_html_e( 'Fabled AI Tools Settings', 'fabled-ai-tools' ); ?></h1>

            <div class="fat-card">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'fat_settings_group' );
                    do_settings_sections( 'fat-settings' );
                    submit_button();
                    ?>
                </form>
            </div>

            <div class="fat-card">
                <h2><?php esc_html_e( 'Connection Test', 'fabled-ai-tools' ); ?></h2>
                <p><?php esc_html_e( 'Run a quick authenticated OpenAI check using your current model and timeout settings.', 'fabled-ai-tools' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'fat_test_openai_connection' ); ?>
                    <input type="hidden" name="action" value="fat_test_openai_connection" />
                    <?php submit_button( __( 'Test OpenAI Connection', 'fabled-ai-tools' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="fat-card">
                <h2><?php esc_html_e( 'Log Maintenance', 'fabled-ai-tools' ); ?></h2>
                <p><?php esc_html_e( 'Use this to manually clear all run logs. Tool definitions remain unchanged.', 'fabled-ai-tools' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete all run logs? This cannot be undone.', 'fabled-ai-tools' ) ); ?>');">
                    <?php wp_nonce_field( 'fat_purge_logs' ); ?>
                    <input type="hidden" name="action" value="fat_purge_logs" />
                    <?php submit_button( __( 'Purge Run Logs', 'fabled-ai-tools' ), 'delete', 'submit', false ); ?>
                </form>
            </div>

            <div class="fat-card">
                <h2><?php esc_html_e( 'Capability Summary', 'fabled-ai-tools' ); ?></h2>
                <ul>
                    <li><code>fat_run_ai_tools</code> — <?php esc_html_e( 'Can access the runner page and run allowed tools.', 'fabled-ai-tools' ); ?></li>
                    <li><code>fat_manage_tools</code> — <?php esc_html_e( 'Can create, edit, duplicate, activate, and delete tools.', 'fabled-ai-tools' ); ?></li>
                    <li><code>fat_view_ai_logs</code> — <?php esc_html_e( 'Can view tool run logs.', 'fabled-ai-tools' ); ?></li>
                    <li><code>fat_manage_ai_settings</code> — <?php esc_html_e( 'Can configure API key and defaults.', 'fabled-ai-tools' ); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function handle_test_openai_connection() {
        if ( ! $this->current_user_can_manage_settings() ) {
            wp_die( esc_html__( 'You are not allowed to manage settings.', 'fabled-ai-tools' ) );
        }

        check_admin_referer( 'fat_test_openai_connection' );

        $result = $this->client->test_connection(
            array(
                'model'   => $this->settings->get( 'default_model', 'gpt-5.4-mini' ),
                'timeout' => $this->settings->get( 'default_timeout', 45 ),
            )
        );

        if ( is_wp_error( $result ) ) {
            $message = sprintf(
                /* translators: %s: OpenAI error message */
                __( 'Connection test failed: %s', 'fabled-ai-tools' ),
                $result->get_error_message()
            );

            $request_id = (string) FAT_Helpers::array_get( $result->get_error_data(), 'request_id', '' );
            if ( '' !== $request_id ) {
                $message .= ' ' . sprintf(
                    /* translators: %s: request id */
                    __( 'Request ID: %s', 'fabled-ai-tools' ),
                    $request_id
                );
            }

            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-settings', 'fat_error', $message ) );
            exit;
        }

        $message = sprintf(
            /* translators: 1: model 2: request id 3: latency in milliseconds */
            __( 'Connection OK. Model: %1$s | Request ID: %2$s | Latency: %3$dms', 'fabled-ai-tools' ),
            (string) FAT_Helpers::array_get( $result, 'model', '' ),
            (string) FAT_Helpers::array_get( $result, 'request_id', '' ),
            (int) FAT_Helpers::array_get( $result, 'latency_ms', 0 )
        );

        wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-settings', 'fat_notice', $message ) );
        exit;
    }

    public function handle_purge_logs() {
        if ( ! $this->current_user_can_manage_settings() ) {
            wp_die( esc_html__( 'You are not allowed to manage settings.', 'fabled-ai-tools' ) );
        }

        check_admin_referer( 'fat_purge_logs' );

        $deleted = $this->runs_repo->delete_all();
        $message = sprintf(
            /* translators: %d: number of logs removed */
            __( 'Run logs purged. Deleted rows: %d', 'fabled-ai-tools' ),
            absint( $deleted )
        );

        wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-settings', 'fat_notice', $message ) );
        exit;
    }

    public function handle_runner_posts_lookup() {
        if ( ! $this->current_user_can_run_tools() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You are not allowed to load posts.', 'fabled-ai-tools' ),
                ),
                403
            );
        }

        check_ajax_referer( 'fat_runner_posts', 'nonce' );

        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid post status requested.', 'fabled-ai-tools' ),
                ),
                400
            );
        }

        $search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $page     = isset( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? min( 50, max( 1, absint( $_GET['per_page'] ) ) ) : 20;

        $post_types = $this->entity_query_service->get_generic_content_supported_post_types();
        $featured_support = isset( $_GET['featured_support'] ) ? FAT_Helpers::to_bool_flag( wp_unslash( $_GET['featured_support'] ) ) : 0;
        if ( ! empty( $featured_support ) ) {
            $post_types = $this->entity_query_service->get_featured_image_supported_post_types();
        }

        $result   = $this->entity_query_service->search_editable_posts_for_runner(
            wp_get_current_user(),
            array(
                'status'   => $status,
                'search'   => $search,
                'page'     => $page,
                'per_page' => $per_page,
                'post_types' => $post_types,
            )
        );

        wp_send_json_success(
            array(
                'posts'     => FAT_Helpers::array_get( $result, 'items', array() ),
                'has_more'  => (bool) FAT_Helpers::array_get( $result, 'has_more', false ),
                'next_page' => FAT_Helpers::array_get( $result, 'next_page', null ),
            )
        );
    }

    public function handle_runner_attachments_lookup() {
        if ( ! $this->current_user_can_run_tools() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You are not allowed to load attachments.', 'fabled-ai-tools' ),
                ),
                403
            );
        }

        check_ajax_referer( 'fat_runner_posts', 'nonce' );

        $search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $page     = isset( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? min( 50, max( 1, absint( $_GET['per_page'] ) ) ) : 20;
        $result   = $this->entity_query_service->search_editable_attachments_for_runner(
            wp_get_current_user(),
            array(
                'search'   => $search,
                'page'     => $page,
                'per_page' => $per_page,
            )
        );

        wp_send_json_success(
            array(
                'attachments' => FAT_Helpers::array_get( $result, 'items', array() ),
                'has_more'    => (bool) FAT_Helpers::array_get( $result, 'has_more', false ),
                'next_page'   => FAT_Helpers::array_get( $result, 'next_page', null ),
            )
        );
    }


    public function handle_import_tool() {
        if ( ! $this->current_user_can_manage_tools() ) {
            wp_die( esc_html__( 'You are not allowed to manage tools.', 'fabled-ai-tools' ) );
        }

        check_admin_referer( 'fat_import_tool' );

        $raw = isset( $_POST['tool_export_json'] ) ? wp_unslash( $_POST['tool_export_json'] ) : '';
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw ) {
            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tools', array( 'fat_error' => __( 'Import JSON is required.', 'fabled-ai-tools' ) ) ) );
            exit;
        }

        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tools', array( 'fat_error' => __( 'Invalid JSON payload.', 'fabled-ai-tools' ) ) ) );
            exit;
        }

        $tool_data = $this->tool_data_from_export_payload( $payload );
        if ( is_wp_error( $tool_data ) ) {
            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tools', array( 'fat_error' => $tool_data->get_error_message() ) ) );
            exit;
        }

        $validation = $this->validator->validate_tool( $tool_data, $this->tools_repo, 0 );
        if ( ! empty( $validation['errors'] ) ) {
            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tools', array( 'fat_error' => implode( ' ', $validation['errors'] ) ) ) );
            exit;
        }

        $slug = FAT_Helpers::array_get( $tool_data, 'slug', '' );
        if ( $this->tools_repo->exists_slug( $slug ) ) {
            $tool_data['slug'] = $slug . '-import-' . wp_generate_password( 4, false, false );
        }

        $created = $this->tools_repo->create( $tool_data );
        if ( is_wp_error( $created ) ) {
            wp_safe_redirect( FAT_Helpers::admin_url_with_notice( 'fat-tools', array( 'fat_error' => $created->get_error_message() ) ) );
            exit;
        }

        wp_safe_redirect(
            FAT_Helpers::admin_url_with_notice(
                'fat-tool-edit',
                array(
                    'id'         => (int) $created['id'],
                    'fat_notice' => __( 'Tool imported successfully.', 'fabled-ai-tools' ),
                )
            )
        );
        exit;
    }

    protected function download_tool_export( $tool ) {
        $payload = array(
            'exported_at' => gmdate( 'c' ),
            'plugin'      => 'fabled-ai-tools',
            'version'     => FAT_VERSION,
            'tool'        => $this->normalize_tool_for_export( $tool ),
        );

        $filename = sanitize_title( FAT_Helpers::array_get( $tool, 'slug', 'tool' ) ) . '-fat-tool.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    protected function normalize_tool_for_export( $tool ) {
        $tool = is_array( $tool ) ? $tool : array();
        unset( $tool['id'], $tool['created_at'], $tool['updated_at'] );

        return array(
            'name'                 => sanitize_text_field( FAT_Helpers::array_get( $tool, 'name', '' ) ),
            'slug'                 => FAT_Helpers::clean_slug( FAT_Helpers::array_get( $tool, 'slug', '' ) ),
            'description'          => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $tool, 'description', '' ) ),
            'is_active'            => ! empty( $tool['is_active'] ) ? 1 : 0,
            'allowed_roles'        => FAT_Helpers::sanitize_roles_array( FAT_Helpers::array_get( $tool, 'allowed_roles', array() ) ),
            'allowed_capabilities' => FAT_Helpers::sanitize_capabilities_list( FAT_Helpers::array_get( $tool, 'allowed_capabilities', array() ) ),
            'model'                => sanitize_text_field( FAT_Helpers::array_get( $tool, 'model', '' ) ),
            'system_prompt'        => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $tool, 'system_prompt', '' ) ),
            'user_prompt_template' => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $tool, 'user_prompt_template', '' ) ),
            'input_schema'         => $this->validator->normalize_input_schema( FAT_Helpers::array_get( $tool, 'input_schema', array() ) ),
            'output_schema'        => $this->validator->normalize_output_schema( FAT_Helpers::array_get( $tool, 'output_schema', array() ) ),
            'wp_integration'       => $this->validator->normalize_wp_integration( FAT_Helpers::array_get( $tool, 'wp_integration', array() ) ),
            'max_input_chars'      => max( 1, absint( FAT_Helpers::array_get( $tool, 'max_input_chars', 20000 ) ) ),
            'max_output_tokens'    => max( 1, absint( FAT_Helpers::array_get( $tool, 'max_output_tokens', 700 ) ) ),
            'daily_run_limit'      => max( 0, absint( FAT_Helpers::array_get( $tool, 'daily_run_limit', 0 ) ) ),
            'log_inputs'           => ! empty( $tool['log_inputs'] ) ? 1 : 0,
            'log_outputs'          => ! empty( $tool['log_outputs'] ) ? 1 : 0,
            'sort_order'           => intval( FAT_Helpers::array_get( $tool, 'sort_order', 0 ) ),
        );
    }

    protected function tool_data_from_export_payload( $payload ) {
        if ( isset( $payload['tool'] ) && is_array( $payload['tool'] ) ) {
            return $this->normalize_tool_for_export( $payload['tool'] );
        }

        if ( isset( $payload['name'] ) ) {
            return $this->normalize_tool_for_export( $payload );
        }

        return new WP_Error( 'fat_invalid_tool_import', __( 'Import payload is missing a tool definition.', 'fabled-ai-tools' ) );
    }

    protected function build_tool_data_from_request() {
        $input_rows  = isset( $_POST['input_fields'] ) ? wp_unslash( $_POST['input_fields'] ) : array();
        $output_rows = isset( $_POST['output_fields'] ) ? wp_unslash( $_POST['output_fields'] ) : array();
        $mapping_rows = isset( $_POST['wp_integration_mappings'] ) ? wp_unslash( $_POST['wp_integration_mappings'] ) : array();

        $wp_integration_raw = array();
        $workflow           = sanitize_key( FAT_Helpers::array_get( $_POST, 'wp_integration_workflow', '' ) );
        $workflow_config    = FAT_Helpers::array_get( $_POST, 'wp_integration_workflow_config', array() );
        if ( ! empty( $_POST['wp_integration_enabled'] ) ) {
            $target = sanitize_key( FAT_Helpers::array_get( $_POST, 'wp_integration_apply_target', '' ) );
            if ( 'media' === $target ) {
                $target = 'attachment';
            }

            $wp_integration_raw = array(
                'workflow' => $workflow,
                'workflow_config' => is_array( $workflow_config ) ? wp_unslash( $workflow_config ) : array(),
                'source' => array(
                    'type'             => '',
                    'allow_manual'     => 1,
                    'allow_draft'      => 1,
                    'allow_publish'    => 1,
                    'allow_attachment' => 0,
                ),
                'apply'  => array(
                    'target'   => $target,
                    'mappings' => is_array( $mapping_rows ) ? $mapping_rows : array(),
                ),
            );
        } elseif ( '' !== $workflow ) {
            $wp_integration_raw = array(
                'workflow' => $workflow,
                'workflow_config' => is_array( $workflow_config ) ? wp_unslash( $workflow_config ) : array(),
            );
        }

        return array(
            'name'                 => sanitize_text_field( FAT_Helpers::array_get( $_POST, 'name', '' ) ),
            'slug'                 => FAT_Helpers::clean_slug( FAT_Helpers::array_get( $_POST, 'slug', '' ), FAT_Helpers::array_get( $_POST, 'name', '' ) ),
            'description'          => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $_POST, 'description', '' ) ),
            'is_active'            => ! empty( $_POST['is_active'] ) ? 1 : 0,
            'allowed_roles'        => FAT_Helpers::sanitize_roles_array( isset( $_POST['allowed_roles'] ) ? (array) wp_unslash( $_POST['allowed_roles'] ) : array() ),
            'allowed_capabilities' => FAT_Helpers::sanitize_capabilities_list( FAT_Helpers::array_get( $_POST, 'allowed_capabilities', '' ) ),
            'model'                => sanitize_text_field( FAT_Helpers::array_get( $_POST, 'model', '' ) ),
            'system_prompt'        => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $_POST, 'system_prompt', '' ) ),
            'user_prompt_template' => FAT_Helpers::sanitize_textarea_preserve_newlines( FAT_Helpers::array_get( $_POST, 'user_prompt_template', '' ) ),
            'input_schema'         => $this->validator->normalize_input_schema( $input_rows ),
            'output_schema'        => $this->validator->normalize_output_schema( $output_rows ),
            'wp_integration'       => $this->validator->normalize_wp_integration( $wp_integration_raw ),
            'max_input_chars'      => max( 1, absint( FAT_Helpers::array_get( $_POST, 'max_input_chars', 20000 ) ) ),
            'max_output_tokens'    => max( 1, absint( FAT_Helpers::array_get( $_POST, 'max_output_tokens', 700 ) ) ),
            'daily_run_limit'      => max( 0, absint( FAT_Helpers::array_get( $_POST, 'daily_run_limit', 0 ) ) ),
            'log_inputs'           => ! empty( $_POST['log_inputs'] ) ? 1 : 0,
            'log_outputs'          => ! empty( $_POST['log_outputs'] ) ? 1 : 0,
            'sort_order'           => intval( FAT_Helpers::array_get( $_POST, 'sort_order', 0 ) ),
        );
    }

    protected function render_wp_mapping_row( $index, $mapping, $output_schema ) {
        $mapping     = wp_parse_args( $mapping, $this->default_wp_mapping_row() );
        $output_keys = array();

        foreach ( (array) $output_schema as $field ) {
            $key = FAT_Helpers::sanitize_keyish( FAT_Helpers::array_get( $field, 'key', '' ) );
            if ( '' !== $key ) {
                $output_keys[ $key ] = FAT_Helpers::array_get( $field, 'label', $key );
            }
        }

        $wp_field_options = array(
            ''             => __( 'Select field', 'fabled-ai-tools' ),
            'post_title'   => __( 'Title', 'fabled-ai-tools' ),
            'post_excerpt' => __( 'Excerpt / Caption', 'fabled-ai-tools' ),
            'post_content' => __( 'Content / Description', 'fabled-ai-tools' ),
            'alt_text'     => __( 'Alt Text (media only)', 'fabled-ai-tools' ),
        );
        ?>
        <tr>
            <td>
                <select name="wp_integration_mappings[<?php echo esc_attr( $index ); ?>][output_key]">
                    <option value=""><?php esc_html_e( 'Select output key', 'fabled-ai-tools' ); ?></option>
                    <?php foreach ( $output_keys as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, FAT_Helpers::array_get( $mapping, 'output_key', '' ) ); ?>><?php echo esc_html( $key . ' — ' . $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="wp_integration_mappings[<?php echo esc_attr( $index ); ?>][wp_field]">
                    <?php foreach ( $wp_field_options as $field_key => $field_label ) : ?>
                        <option value="<?php echo esc_attr( $field_key ); ?>" <?php selected( $field_key, FAT_Helpers::array_get( $mapping, 'wp_field', '' ) ); ?>><?php echo esc_html( $field_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="wp_integration_mappings[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( FAT_Helpers::array_get( $mapping, 'label', '' ) ); ?>" /></td>
            <td><button type="button" class="button-link-delete fat-remove-row"><?php esc_html_e( 'Remove', 'fabled-ai-tools' ); ?></button></td>
        </tr>
        <?php
    }

    protected function render_input_schema_row( $index, $field ) {
        $field = wp_parse_args(
            $field,
            $this->default_input_row()
        );
        ?>
        <tr>
            <td><input type="text" name="input_fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" /></td>
            <td><input type="text" name="input_fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" /></td>
            <td>
                <select name="input_fields[<?php echo esc_attr( $index ); ?>][type]">
                    <option value="text" <?php selected( 'text', $field['type'] ); ?>>text</option>
                    <option value="textarea" <?php selected( 'textarea', $field['type'] ); ?>>textarea</option>
                    <option value="url" <?php selected( 'url', $field['type'] ); ?>>url</option>
                </select>
            </td>
            <td><label><input type="checkbox" name="input_fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> /></label></td>
            <td><input type="text" name="input_fields[<?php echo esc_attr( $index ); ?>][help_text]" value="<?php echo esc_attr( $field['help_text'] ); ?>" /></td>
            <td><input type="text" name="input_fields[<?php echo esc_attr( $index ); ?>][placeholder]" value="<?php echo esc_attr( $field['placeholder'] ); ?>" /></td>
            <td><input type="number" min="0" step="1" name="input_fields[<?php echo esc_attr( $index ); ?>][max_length]" value="<?php echo esc_attr( (int) $field['max_length'] ); ?>" /></td>
            <td><button type="button" class="button-link-delete fat-remove-row"><?php esc_html_e( 'Remove', 'fabled-ai-tools' ); ?></button></td>
        </tr>
        <?php
    }

    protected function render_output_schema_row( $index, $field ) {
        $field = wp_parse_args(
            $field,
            $this->default_output_row()
        );
        ?>
        <tr>
            <td><input type="text" name="output_fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" /></td>
            <td><input type="text" name="output_fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" /></td>
            <td>
                <select name="output_fields[<?php echo esc_attr( $index ); ?>][type]">
                    <option value="text" <?php selected( 'text', $field['type'] ); ?>>text</option>
                    <option value="long_text" <?php selected( 'long_text', $field['type'] ); ?>>long_text</option>
                </select>
            </td>
            <td><label><input type="checkbox" name="output_fields[<?php echo esc_attr( $index ); ?>][copyable]" value="1" <?php checked( ! empty( $field['copyable'] ) ); ?> /></label></td>
            <td><button type="button" class="button-link-delete fat-remove-row"><?php esc_html_e( 'Remove', 'fabled-ai-tools' ); ?></button></td>
        </tr>
        <?php
    }

    protected function default_tool() {
        return array(
            'id'                   => 0,
            'name'                 => '',
            'slug'                 => '',
            'description'          => '',
            'is_active'            => 1,
            'allowed_roles'        => array(),
            'allowed_capabilities' => array(),
            'model'                => '',
            'system_prompt'        => '',
            'user_prompt_template' => '',
            'input_schema'         => array(),
            'output_schema'        => array(),
            'wp_integration'       => array(),
            'max_input_chars'      => 20000,
            'max_output_tokens'    => 700,
            'daily_run_limit'      => 0,
            'log_inputs'           => 0,
            'log_outputs'          => 1,
            'sort_order'           => 0,
        );
    }

    protected function default_input_row() {
        return array(
            'key'         => '',
            'label'       => '',
            'type'        => 'text',
            'required'    => 0,
            'help_text'   => '',
            'placeholder' => '',
            'max_length'  => 0,
        );
    }

    protected function default_output_row() {
        return array(
            'key'      => '',
            'label'    => '',
            'type'     => 'text',
            'copyable' => 1,
        );
    }

    protected function default_wp_mapping_row() {
        return array(
            'output_key' => '',
            'wp_field'   => '',
            'label'      => '',
        );
    }

    protected function wp_integration_form_state( $config ) {
        $config       = is_array( $config ) ? $config : array();
        $apply        = (array) FAT_Helpers::array_get( $config, 'apply', array() );
        $apply_target = sanitize_key( FAT_Helpers::array_get( $apply, 'target', '' ) );
        if ( 'attachment' === $apply_target ) {
            $apply_target = 'media';
        }

        return array(
            'enabled'      => ! empty( $config ),
            'workflow'     => sanitize_key( FAT_Helpers::array_get( $config, 'workflow', '' ) ),
            'workflow_config' => (array) FAT_Helpers::array_get( $config, 'workflow_config', array() ),
            'apply_target' => $apply_target,
            'mappings'     => (array) FAT_Helpers::array_get( $apply, 'mappings', array() ),
        );
    }

    protected function builtin_workflow_form_fields( $workflow ) {
        $workflow = sanitize_key( $workflow );

        if ( 'featured_image_generator' === $workflow ) {
            return array(
                'image_model' => array(
                    'label'       => __( 'Image Model', 'fabled-ai-tools' ),
                    'type'        => 'text',
                    'default'     => 'gpt-image-1-mini',
                    'readonly'    => 1,
                    'description' => __( 'Managed by the built-in workflow. Reset the tool to restore default model behavior.', 'fabled-ai-tools' ),
                ),
                'image_quality' => array(
                    'label'       => __( 'Image Quality', 'fabled-ai-tools' ),
                    'type'        => 'text',
                    'default'     => 'low',
                    'readonly'    => 1,
                    'description' => __( 'Managed by the built-in workflow to keep generation cost and latency predictable.', 'fabled-ai-tools' ),
                ),
                'source_size' => array(
                    'label'       => __( 'Generated Source Size', 'fabled-ai-tools' ),
                    'type'        => 'text',
                    'default'     => '1536x1024',
                    'description' => __( 'Editable. Use WIDTHxHEIGHT (for example 1536x1024).', 'fabled-ai-tools' ),
                ),
                'featured_size' => array(
                    'label'       => __( 'Featured Derivative Size', 'fabled-ai-tools' ),
                    'type'        => 'text',
                    'default'     => '1200x675',
                    'description' => __( 'Editable. Use WIDTHxHEIGHT (for example 1200x675).', 'fabled-ai-tools' ),
                ),
                'featured_format' => array(
                    'label'       => __( 'Featured Derivative Format', 'fabled-ai-tools' ),
                    'type'        => 'select',
                    'default'     => 'png',
                    'options'     => array(
                        'png'  => __( 'PNG', 'fabled-ai-tools' ),
                        'webp' => __( 'WebP', 'fabled-ai-tools' ),
                    ),
                    'description' => __( 'Editable. Controls how the featured-image derivative is saved.', 'fabled-ai-tools' ),
                ),
            );
        }

        if ( 'uploaded_image_processor' === $workflow ) {
            return array(
                'target_size' => array(
                    'label'       => __( 'Processed Image Size', 'fabled-ai-tools' ),
                    'type'        => 'text',
                    'default'     => '1200x675',
                    'description' => __( 'Editable. Use WIDTHxHEIGHT (for example 1200x675).', 'fabled-ai-tools' ),
                ),
                'target_format' => array(
                    'label'       => __( 'Processed Image Format', 'fabled-ai-tools' ),
                    'type'        => 'select',
                    'default'     => 'webp',
                    'options'     => array(
                        'webp' => __( 'WebP', 'fabled-ai-tools' ),
                        'png'  => __( 'PNG', 'fabled-ai-tools' ),
                    ),
                    'description' => __( 'Editable. Controls the derivative file format for uploaded images.', 'fabled-ai-tools' ),
                ),
            );
        }

        return array();
    }

    protected function store_form_state( $tool_id, $data, $validation ) {
        set_transient(
            $this->form_state_key(),
            array(
                'tool_id'    => absint( $tool_id ),
                'data'       => $data,
                'validation' => $validation,
            ),
            MINUTE_IN_SECONDS
        );
    }

    protected function consume_form_state() {
        if ( empty( $_GET['fat_restore'] ) ) {
            return array();
        }

        $state = get_transient( $this->form_state_key() );
        delete_transient( $this->form_state_key() );

        return is_array( $state ) ? $state : array();
    }

    protected function form_state_key() {
        return 'fat_tool_form_' . get_current_user_id();
    }

    protected function tool_action_url( $action, $tool_id ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'      => 'fat_tool_action',
                    'tool_action' => $action,
                    'id'          => absint( $tool_id ),
                ),
                admin_url( 'admin-post.php' )
            ),
            'fat_tool_action_' . $action . '_' . absint( $tool_id )
        );
    }

    public function add_post_row_launch_link( $actions, $post ) {
        return $this->launch_service->add_post_row_launch_link( $actions, $post, $this->current_user_can_run_tools() );
    }


    public function add_media_row_launch_link( $actions, $post ) {
        return $this->launch_service->add_media_row_launch_link( $actions, $post, $this->current_user_can_run_tools() );
    }


    protected function render_gap_posts_table( $posts, $tool_slug, $launch_label ) {
        $this->launch_service->render_gap_posts_table( $posts, $tool_slug, $launch_label );
    }


    protected function render_gap_attachments_table( $attachments ) {
        $this->launch_service->render_gap_attachments_table( $attachments );
    }


    protected function find_posts_missing_excerpt( $limit = 12 ) {
        return $this->launch_service->find_posts_missing_excerpt( $limit );
    }


    protected function find_posts_missing_featured_image( $limit = 12 ) {
        return $this->launch_service->find_posts_missing_featured_image( $limit );
    }


    protected function find_attachments_missing_alt_text( $limit = 12 ) {
        return $this->launch_service->find_attachments_missing_alt_text( $limit );
    }


    protected function runner_launch_url( $args = array() ) {
        $base_args = array_filter(
            array(
                'page' => 'fabled-ai-tools',
            )
        );

        return add_query_arg( array_merge( $base_args, $args ), admin_url( 'admin.php' ) );
    }

    protected function runner_launch_context_from_request() {
        return $this->launch_service->runner_launch_context_from_request( $this->current_page() );
    }


    protected function current_page() {
        return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    }

    protected function is_plugin_page() {
        return in_array( $this->current_page(), array( 'fabled-ai-tools', 'fat-needs-attention', 'fat-tools', 'fat-tool-edit', 'fat-logs', 'fat-settings' ), true );
    }

    protected function current_user_can_manage_tools() {
        return current_user_can( 'fat_manage_tools' ) || current_user_can( 'manage_options' );
    }

    protected function current_user_can_run_tools() {
        return current_user_can( 'fat_run_ai_tools' ) || current_user_can( 'fat_manage_tools' ) || current_user_can( 'manage_options' );
    }

    protected function current_user_can_view_logs() {
        return current_user_can( 'fat_view_ai_logs' ) || current_user_can( 'manage_options' );
    }

    protected function current_user_can_manage_settings() {
        return current_user_can( 'fat_manage_ai_settings' ) || current_user_can( 'manage_options' );
    }
}
