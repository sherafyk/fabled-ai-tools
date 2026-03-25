<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FAT_Plugin {
    protected static $instance = null;

    protected $settings;
    protected $tools_repo;
    protected $runs_repo;
    protected $validator;
    protected $prompt_engine;
    protected $client;
    protected $media_service;
    protected $entity_query_service;
    protected $builtin_tools;
    protected $usage_limiter;
    protected $featured_image_generator;
    protected $uploaded_image_processor;
    protected $attachment_metadata_assistant;
    protected $tool_runner;
    protected $rest_controller;
    protected $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function __construct() {
        FAT_Activator::maybe_upgrade();

        $this->settings        = new FAT_Settings();
        $this->tools_repo      = new FAT_Tools_Repository();
        $this->runs_repo       = new FAT_Runs_Repository();
        $this->validator       = new FAT_Tool_Validator();
        $this->prompt_engine   = new FAT_Prompt_Engine();
        $this->client          = new FAT_OpenAI_Client( $this->settings );
        $this->entity_query_service = new FAT_Entity_Query_Service();
        $this->media_service  = new FAT_Media_Service();
        $this->builtin_tools = new FAT_Builtin_Tools( $this->tools_repo );
        $this->usage_limiter   = new FAT_Usage_Limiter( $this->runs_repo, $this->settings );
        $this->featured_image_generator = new FAT_Featured_Image_Generator( $this->client, $this->media_service );
        $this->uploaded_image_processor = new FAT_Uploaded_Image_Processor( $this->client, $this->media_service );
        $this->attachment_metadata_assistant = new FAT_Attachment_Metadata_Assistant( $this->client, $this->media_service );
        $this->tool_runner     = new FAT_Tool_Runner( $this->tools_repo, $this->runs_repo, $this->settings, $this->prompt_engine, $this->client, $this->usage_limiter, $this->featured_image_generator, $this->uploaded_image_processor, $this->attachment_metadata_assistant );
        $this->rest_controller = new FAT_REST_Controller( $this->tool_runner );
        $this->admin           = new FAT_Admin( $this->settings, $this->tools_repo, $this->runs_repo, $this->validator, $this->prompt_engine, $this->tool_runner, $this->client, $this->entity_query_service, $this->builtin_tools );

        $this->settings->hooks();
        $this->rest_controller->hooks();
        $this->admin->hooks();
    }
}
