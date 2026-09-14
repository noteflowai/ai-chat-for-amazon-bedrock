# Security Policy

## Supported versions

Security fixes are provided for the latest WordPress.org release of AI Chat for Amazon Bedrock.

## Reporting a vulnerability

Do not open a public issue containing credentials, exploit details, private chat content, or personal data. Contact the plugin author through the WordPress.org profile or the repository's private security-reporting channel. Include the affected version, impact, reproduction steps, and a minimal proof of concept with secrets removed.

## Deployment guidance

- Use dedicated least-privilege AWS credentials and AWS Budgets alerts.
- Prefer `wp-config.php` credential constants over database storage.
- Keep guest chat and public MCP access disabled unless required.
- Register only MCP servers you operate or trust.
- Rotate AWS credentials after any WordPress administrator or database compromise.
- Keep WordPress, PHP, and this plugin on supported versions.
