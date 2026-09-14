# AI Chat for Amazon Bedrock 1.1.0

A production-hardened WordPress plugin for Amazon Bedrock chat and optional Model Context Protocol (MCP) tools.

## Requirements

- WordPress 6.4 or later; tested metadata targets WordPress 7.1
- PHP 7.4 or later with Sodium
- An AWS identity allowed to call `bedrock:InvokeModel` for the selected model

## Safe defaults

- Chat requires a signed-in user unless guest access is explicitly enabled.
- Each visitor is rate limited (default: five requests per minute).
- MCP tools are available to authenticated chat users only.
- Built-in WordPress MCP REST routes require authentication by default.
- External MCP servers must use a public HTTPS URL and pass WordPress safe-URL validation.
- Debug logs contain operational metadata only.
- Chat history remains in the browser and is not stored permanently by the plugin.

## Installation

1. Install and activate the plugin.
2. Open **AI Chat Bedrock > Settings**.
3. Configure credentials, region, and model.
4. Test while signed in.
5. Add `[ai_chat_bedrock]` to a page.
6. Enable guest access only after configuring AWS Budgets and reviewing the rate limit.

## Credentials

For production, prefer constants in `wp-config.php`:

```php
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'replace-me' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'replace-me' );
// Required only for temporary credentials.
define( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN', 'replace-me' );
```

Values saved through the settings page are encrypted with Sodium using a key derived from WordPress authentication salts. They are never rendered back into the form. Changing WordPress salts invalidates stored ciphertext, so rotate or re-enter credentials after a salt change.

Use a dedicated IAM identity with least privilege. Do not reuse WordPress.org, SVN, or unrelated application credentials.

## MCP

External MCP servers are treated as untrusted. The client rejects non-HTTPS, private, loopback, link-local, credential-bearing, redirecting, oversized, and invalid JSON responses. Tool definitions and inputs are bounded and sanitized. Tool results are processed on the server and are explicitly framed as untrusted data before being sent back to the model.

The built-in WordPress MCP server is read-only and returns published posts and public site metadata. Authentication is required unless an administrator explicitly enables public access.

## Upgrade notes from 1.0.7

- Guest chat becomes disabled.
- Stored AWS credentials are encrypted when an administrator opens the dashboard.
- Legacy EventSource streaming is removed because it duplicated paid model requests and exposed messages in URLs/logs.
- External MCP URLs must be public HTTPS endpoints.
- Client-submitted MCP tool results are no longer accepted.
- The unused chat-history database table is no longer created and is removed on uninstall.

## Development validation

```bash
composer test
# Equivalent without Composer:
php tests/security-regression.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/js/ai-chat-bedrock-public.js
node --check admin/js/ai-chat-bedrock-admin.js
node --check admin/js/ai-chat-bedrock-mcp.js
```

## License

GPL-2.0-or-later.
