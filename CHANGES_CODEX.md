# Local deployment and reliability fixes

This branch contains general fixes discovered while deploying and testing
WhatFUHaveDone locally on an Apple Silicon Mac with MAMP. It intentionally
contains no personal profile data, API keys, database contents, location data,
or personal skill files.

## Changes

- Add a PHP development-server router and a macOS/MAMP local launcher that bind
  the web app to `127.0.0.1`.
- Complete missing MySQL schema definitions for profiles, weather cache, BaZi
  analysis, AI skills, work-log notes, and person metadata.
- Prevent an undefined route segment warning on the home page.
- Make profile uploads return actionable errors, validate file types, locate
  `pdftotext`, and fall back to a Composer-installed PDF parser.
- Repair AI tool-call history so assistant `tool_calls` are not replayed without
  their required tool responses.
- Guard empty and repeated confirmation state in the browser.
- Add request cancellation, differentiated normal/deep-analysis timeouts, and
  bounded AI tool loops.
- Reduce prompt size by loading large profile/skill context only for relevant
  personal-analysis requests and truncating oversized context.
- Increase the default output budget and surface model truncation explicitly.
- Make long or structured assistant messages render immediately and add a
  watchdog for short typing animations.

## Privacy exclusions

The following local-only content is not included:

- database credentials or API keys
- `API key.md`
- `data/` contents and location information
- database files or exported personal profile/conversation data
- `skills/` contents
- Composer's generated `vendor/` directory

## Validation

- PHP syntax checks for modified PHP files
- JavaScript syntax check for the assistant client
- MySQL 8 schema creation and CRUD smoke tests
- Local HTTP checks for pages, static assets, JSON APIs, uploads, and weather
- Browser verification of page loading and assistant UI state
- DeepSeek smoke request after prompt-budget changes

Run `composer install` before using the pure-PHP PDF extraction fallback.
