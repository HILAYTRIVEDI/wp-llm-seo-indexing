# Security Remediation & Best Practices

## Overview

This document outlines security measures, configuration requirements, and remediation procedures for the WP LLM SEO Indexing plugin.

## Environment Configuration

### Required Environment Variables

The plugin supports environment-based configuration for sensitive credentials. Configure these via `wp-config.php` or environment variables:

```php
// wp-config.php
define( 'WPLLMSEO_GEMINI_API_KEY', getenv('WPLLMSEO_GEMINI_API_KEY') ?: '' );
define( 'WPLLMSEO_OPENAI_API_KEY', getenv('WPLLMSEO_OPENAI_API_KEY') ?: '' );
define( 'WPLLMSEO_CLAUDE_API_KEY', getenv('WPLLMSEO_CLAUDE_API_KEY') ?: '' );
```

### Environment Setup (.env file)

```bash
# Google Gemini API
WPLLMSEO_GEMINI_API_KEY=your_gemini_api_key_here

# OpenAI API
WPLLMSEO_OPENAI_API_KEY=your_openai_api_key_here
WPLLMSEO_OPENAI_ORG_ID=your_org_id_here

# Anthropic Claude API
WPLLMSEO_CLAUDE_API_KEY=your_claude_api_key_here
```

**Important**: Never commit `.env` files to version control. Add to `.gitignore`:
```
.env
.env.local
.env.*.local
```

## Security Features

### 1. Authentication & Authorization

#### REST API Endpoints
All REST API endpoints require appropriate permissions:
- `permission_callback` implemented on all routes
- Capability checks: `manage_options`, `edit_posts`, `upload_files`
- Custom capabilities via WordPress roles system

#### AJAX Handlers
All AJAX handlers include:
- Nonce verification: `check_ajax_referer()`
- Capability checks: `current_user_can()`
- Input sanitization and validation

#### Admin Actions
- Form submissions use nonce fields
- `admin_init` hooks for form processing
- Capability checks before state modifications

### 2. Data Sanitization

#### Input Validation
- `sanitize_text_field()` for text inputs
- `absint()` for integer values
- `wp_kses_post()` for HTML content
- `esc_url()` for URLs

#### Output Escaping
- `esc_html()` for text output
- `esc_attr()` for attributes
- `esc_url()` for URLs
- `wp_json_encode()` for JSON data

### 3. SQL Injection Prevention

All database queries use prepared statements:
```php
// Good - Uses prepare()
$wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}table WHERE id = %d",
		$id
	)
);

// Schema changes (ALTER TABLE, SHOW COLUMNS) are exempt
// but include phpcs:ignore comments
```

### 4. Secure Logging

The plugin includes `WPLLMSEO_Security_Logger` that automatically redacts sensitive fields:

```php
// Sensitive fields are automatically redacted
WPLLMSEO_Security_Logger::log( 'API request', $request_data );
// Output: api_key, token, password, etc. replaced with [REDACTED]
```

**Redacted fields**:
- `api_key`, `apiKey`, `api-key`
- `token`, `access_token`, `accessToken`
- `password`
- `client_secret`, `clientSecret`
- `authorization`, `bearer`
- `private_key`, `privateKey`

### 5. File Upload Security

File uploads (if implemented) must follow WordPress best practices:
- Use `wp_check_filetype_and_ext()` for validation
- Sanitize filenames with `sanitize_file_name()`
- Restrict allowed MIME types
- Store uploads in WordPress uploads directory

```php
$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
if ( ! in_array( $filetype['type'], $allowed_types, true ) ) {
	return new WP_Error( 'invalid_file_type', 'File type not allowed' );
}
```

## API Key Rotation

### When to Rotate Keys

Rotate API keys if:
- Keys may have been exposed in logs or commits
- Team member with access leaves
- Suspicious activity detected
- As part of regular security maintenance (every 90 days)

### Rotation Procedure

1. **Generate New Keys**
   - Google Gemini: https://makersuite.google.com/app/apikey
   - OpenAI: https://platform.openai.com/api-keys
   - Anthropic Claude: https://console.anthropic.com/settings/keys

2. **Update Environment Variables**
   ```bash
   # Update .env file
   WPLLMSEO_GEMINI_API_KEY=new_gemini_key_here
   WPLLMSEO_OPENAI_API_KEY=new_openai_key_here
   WPLLMSEO_CLAUDE_API_KEY=new_claude_key_here
   ```

3. **Update WordPress Configuration**
   - Navigate to WP Admin → LLM SEO → API Providers
   - Enter new API keys
   - Click "Discover Models" to test
   - Save Provider Settings

4. **Revoke Old Keys**
   - Revoke old keys from provider dashboards
   - Verify old keys no longer work

5. **Clear Caches**
   ```bash
   wp cache flush
   wp transient delete --all
   ```

## Git History Cleanup (Optional)

**⚠️ WARNING**: Only perform if secrets were committed to git history.

### Using git-filter-repo

```bash
# Install git-filter-repo
pip3 install git-filter-repo

# Backup repository
git clone --mirror https://github.com/yourusername/repo.git repo-backup.git

# Create expressions file
cat > /tmp/expressions.txt << EOF
api_key==>api_key=[REDACTED]
token==>token=[REDACTED]
password==>password=[REDACTED]
EOF

# Run filter (DRY RUN first)
git filter-repo --replace-text /tmp/expressions.txt --dry-run

# If dry run looks good, run for real
git filter-repo --replace-text /tmp/expressions.txt --force

# Force push (requires admin access)
git push origin --force --all
git push origin --force --tags
```

### Post-Cleanup Steps

1. **Notify all developers** to re-clone the repository
2. **Rotate ALL exposed keys** immediately
3. **Update CI/CD secrets** in GitHub/GitLab settings
4. **Audit access logs** for unauthorized usage

## Pre-Commit Hooks

### Installation

```bash
# Make hooks directory
mkdir -p .githooks

# Set git to use .githooks
git config core.hooksPath .githooks

# Make hook executable
chmod +x .githooks/pre-commit
```

### Hook Script

See `.githooks/pre-commit` for:
- Secret pattern detection
- PHPCS linting
- ESLint validation
- Prevents commits with secrets

## Continuous Integration

### GitHub Actions Workflow

The plugin includes CI checks for:
- **PHP CodeSniffer (PHPCS)**: WordPress coding standards
- **PHPStan**: Static analysis (level 7)
- **PHPUnit**: Automated tests
- **ESLint**: JavaScript linting
- **npm audit**: Dependency vulnerabilities

### Running Locally

```bash
# PHP checks
composer install
vendor/bin/phpcs --standard=WordPress
vendor/bin/phpstan analyse includes --level=7
vendor/bin/phpunit

# JavaScript checks
npm ci
npx eslint admin/assets/js
npm audit --audit-level=moderate
```

## Security Checklist

Before deploying to production:

- [ ] All API keys stored in environment variables (not hard-coded)
- [ ] Logging enabled only in development/staging
- [ ] Sensitive fields redacted in logs
- [ ] All REST routes have `permission_callback`
- [ ] All AJAX handlers have nonce + capability checks
- [ ] File uploads validated (if applicable)
- [ ] SQL queries use prepared statements
- [ ] CI/CD pipeline passing all checks
- [ ] Pre-commit hooks installed
- [ ] Dependencies audited (no critical vulnerabilities)
- [ ] API rate limiting configured
- [ ] MCP authentication tokens rotated

## Vulnerability Reporting

To report security vulnerabilities:

1. **Do NOT** open a public GitHub issue
2. Email: security@yourplugin.com
3. Include:
   - Description of vulnerability
   - Steps to reproduce
   - Potential impact assessment
   - Suggested fix (optional)

Response time: Within 48 hours

## Compliance

### Data Protection

The plugin processes content for embedding generation:
- No personal data collected by plugin
- API providers (Google, OpenAI, Anthropic) process content
- Review each provider's privacy policy
- Configure data retention policies per provider

### GDPR Considerations

- Content embeddings may be considered personal data if content includes PII
- Implement data export functionality
- Provide data deletion on user request
- Document data flows in privacy policy

## References

- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Google Gemini API Security](https://ai.google.dev/docs/api_security)
- [OpenAI API Best Practices](https://platform.openai.com/docs/guides/safety-best-practices)
- [Anthropic Claude Security](https://www.anthropic.com/security)

## License

This security documentation is part of the WP LLM SEO Indexing plugin and is released under the same GPL-2.0+ license.

---

**Last Updated**: 2025-11-15  
**Version**: 1.2.0
