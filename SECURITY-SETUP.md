# Security Setup Guide

This guide will help you set up the security features and best practices for the WP LLM SEO Indexing plugin.

## Quick Start

### 1. Install Pre-Commit Hooks

```bash
cd /path/to/wp-llm-seo-indexing
git config core.hooksPath .githooks
chmod +x .githooks/pre-commit
```

### 2. Configure Environment Variables

Create a `.env` file (never commit this):

```bash
# Copy the template
cp .env.example .env

# Edit with your keys
nano .env
```

Add to your `wp-config.php`:

```php
// Load environment variables (if using .env file with vlucas/phpdotenv)
if ( file_exists( __DIR__ . '/.env' ) ) {
    $dotenv = Dotenv\Dotenv::createImmutable( __DIR__ );
    $dotenv->load();
}

// Configure API keys from environment
define( 'WPLLMSEO_GEMINI_API_KEY', getenv('WPLLMSEO_GEMINI_API_KEY') ?: '' );
define( 'WPLLMSEO_OPENAI_API_KEY', getenv('WPLLMSEO_OPENAI_API_KEY') ?: '' );
define( 'WPLLMSEO_CLAUDE_API_KEY', getenv('WPLLMSEO_CLAUDE_API_KEY') ?: '' );
```

### 3. Enable Secure Logging

Update your WordPress plugin settings:

```php
// Use the secure logger instead of wpllmseo_log()
WPLLMSEO_Security_Logger::log( 'API request', $request_data );

// Sensitive fields are automatically redacted:
// api_key, token, password, etc. → [REDACTED]
```

### 4. Run Security Checks Locally

```bash
# Install dependencies
composer install
npm install

# Run all checks
composer test
npm run lint
npm audit

# Or run individually
vendor/bin/phpcs --standard=WordPress
vendor/bin/phpstan analyse includes --level=7
vendor/bin/phpunit
```

## CI/CD Integration

### GitHub Actions

The repository includes `.github/workflows/security-quality.yml` which runs:

- ✅ Secret detection
- ✅ PHP syntax check (PHP 7.4, 8.0, 8.1, 8.2)
- ✅ WordPress coding standards (PHPCS)
- ✅ Static analysis (PHPStan)
- ✅ Unit tests (PHPUnit)
- ✅ JavaScript linting (ESLint)
- ✅ Dependency security audit

### Required GitHub Secrets

Add these to your repository settings (`Settings → Secrets and variables → Actions`):

- `WPLLMSEO_GEMINI_API_KEY` - For integration tests (optional)
- `WPLLMSEO_OPENAI_API_KEY` - For integration tests (optional)
- `WPLLMSEO_CLAUDE_API_KEY` - For integration tests (optional)

## Security Features

### 1. Automatic Secret Redaction

All logging automatically redacts:
- API keys (`api_key`, `apiKey`, `api-key`)
- Tokens (`token`, `access_token`, `bearer`)
- Passwords (`password`)
- Client secrets (`client_secret`, `clientSecret`)
- Private keys (`private_key`, `privateKey`)
- Authorization headers (`authorization`)

```php
// Before
error_log( 'Request: ' . print_r( $request, true ) );
// Logs: api_key=sk-abc123xyz...

// After
WPLLMSEO_Security_Logger::log( 'Request', $request );
// Logs: api_key=[REDACTED]
```

### 2. Pre-Commit Validation

Blocks commits containing:
- API key patterns (Google, OpenAI, Anthropic)
- Generic secrets (tokens, passwords)
- Debugging statements (`var_dump`, `console.log`)
- Coding standard violations

### 3. REST API Security

All endpoints include:
- `permission_callback` - Authorization check
- `args` validation - Input sanitization
- Nonce verification (for mutations)

```php
register_rest_route( 'wpllmseo/v1', '/endpoint', [
    'methods'             => 'POST',
    'callback'            => 'callback_function',
    'permission_callback' => function() {
        return current_user_can( 'manage_options' );
    },
    'args'                => [
        'post_id' => [
            'required'          => true,
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ],
    ],
] );
```

### 4. AJAX Handler Security

All AJAX handlers include:
- Nonce verification (`check_ajax_referer`)
- Capability checks (`current_user_can`)
- Input sanitization

```php
public static function ajax_handler() {
    check_ajax_referer( 'wpllmseo_admin_action', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    
    $input = sanitize_text_field( wp_unslash( $_POST['input'] ) );
    // Process...
}
```

## Troubleshooting

### Pre-commit hook not running

```bash
# Check hook location
git config core.hooksPath
# Should output: .githooks

# Reset if needed
git config core.hooksPath .githooks

# Make executable
chmod +x .githooks/pre-commit
```

### PHPCS/PHPStan not found

```bash
# Install via Composer
composer install

# Or install globally
composer global require squizlabs/php_codesniffer
composer global require phpstan/phpstan
```

### CI failing on secret detection

If CI detects secrets:

1. **Never commit the secret** - It's already in git history
2. **Rotate the exposed key** immediately
3. **Clean git history** (see SECURITY-REMEDIATION.md)
4. **Update secrets** in environment variables

## Best Practices

### Development

- ✅ Never hard-code API keys or tokens
- ✅ Always use `WPLLMSEO_Security_Logger` for logging
- ✅ Test locally before pushing (`composer test`, `npm run lint`)
- ✅ Review pre-commit warnings
- ✅ Keep dependencies updated (`composer update`, `npm update`)

### Production

- ✅ Use environment variables for all secrets
- ✅ Enable logging only in staging (disable in production)
- ✅ Rotate API keys every 90 days
- ✅ Monitor error logs for security issues
- ✅ Keep WordPress and plugins updated

### Code Review

- ✅ Check for permission callbacks on new REST routes
- ✅ Verify nonce checks in AJAX handlers
- ✅ Ensure SQL uses `$wpdb->prepare()`
- ✅ Validate all user inputs
- ✅ Escape all outputs

## Additional Resources

- [SECURITY-REMEDIATION.md](./SECURITY-REMEDIATION.md) - Complete security guide
- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Snyk Vulnerability Database](https://snyk.io/vuln/)

## Support

For security issues:
- **DO NOT** open public issues
- Email: security@yourplugin.com
- Response time: 48 hours

---

**Version**: 1.2.0  
**Last Updated**: 2025-11-15
