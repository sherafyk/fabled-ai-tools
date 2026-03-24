# Fabled AI Tools

A modular WordPress plugin for creating and running structured AI tools using the OpenAI Responses API.

## Features

- Admin-defined AI tools
- Structured inputs and outputs
- Server-side OpenAI calls
- Usage logging
- Role-based access control
- Per-user limits

## Installation

1. Upload to `/wp-content/plugins/`
2. Activate in WordPress
3. Configure API key under Settings

## Development

This plugin is designed to be extended incrementally.

Key areas:
- Tool runner: `class-fat-tool-runner.php`
- OpenAI client: `class-fat-openai-client.php`
- Admin UI: `class-fat-admin.php` + `/assets/admin.js`

## Next Planned Features

- Post/draft selector input
- Better search UI for content selection
- Tool export/import
