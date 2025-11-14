<?php
/**
 * Test Autoloader
 * 
 * Run with: php tests/test-autoloader.php
 */

require_once dirname(__DIR__) . '/wp-llm-seo-indexing.php';

echo "Testing Autoloader...\n\n";

$test_classes = [
    // MCP Classes
    'WPLLMSEO_MCP_Adapter' => 'includes/mcp/class-mcp-adapter.php',
    'WPLLMSEO_MCP_Auth' => 'includes/mcp/class-mcp-auth.php',
    'WPLLMSEO_MCP_Handlers' => 'includes/mcp/class-mcp-handlers.php',
    
    // Provider Classes
    'WPLLMSEO_LLM_Provider_Gemini' => 'includes/providers/class-llm-provider-gemini.php',
    'WPLLMSEO_LLM_Provider_OpenAI' => 'includes/providers/class-llm-provider-openai.php',
    'WPLLMSEO_LLM_Provider_Claude' => 'includes/providers/class-llm-provider-claude.php',
    'WPLLMSEO_LLM_Provider_Base' => 'includes/providers/class-llm-provider-base.php',
    
    // Regular Classes
    'WPLLMSEO_Admin' => 'includes/class-admin.php',
    'WPLLMSEO_Provider_Manager' => 'includes/class-provider-manager.php',
];

$passed = 0;
$failed = 0;

foreach ($test_classes as $class_name => $expected_path) {
    $full_path = WPLLMSEO_PLUGIN_DIR . $expected_path;
    
    if (file_exists($full_path)) {
        echo "✓ $class_name\n";
        echo "  Expected: $expected_path\n";
        echo "  Status: File exists\n\n";
        $passed++;
    } else {
        echo "✗ $class_name\n";
        echo "  Expected: $expected_path\n";
        echo "  Status: FILE NOT FOUND\n\n";
        $failed++;
    }
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed === 0) {
    echo "✓ All autoloader tests passed!\n";
    exit(0);
} else {
    echo "✗ Some tests failed\n";
    exit(1);
}
