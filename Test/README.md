# OpenAiService Tests

## Overview

This directory contains comprehensive tests for the `OpenAiService` class, covering both unit tests and integration tests with real API calls.

## Test Structure

```
Test/
├── Unit/                          # Unit tests (mock dependencies)
│   └── Model/Service/OpenAiServiceTest.php
├── Integration/                   # Integration tests (real API calls)
│   └── Model/Service/OpenAiServiceIntegrationTest.php
├── Helper/                        # Test utilities
│   └── ApiKeyHelper.php
├── Bootstrap.php                  # Test initialization
└── README.md                      # This file
```

## Running Tests

### Prerequisites

1. Composer dependencies installed:
   ```bash
   composer install
   ```

2. For integration tests: Set OpenAI API key
   ```bash
   export OPENAI_API_KEY=sk-proj-your-actual-api-key
   ```

### Unit Tests Only (No API Key Required)

Run all unit tests:
```bash
vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml
```

Run specific test class:
```bash
vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml Test/Unit/Model/Service/OpenAiServiceTest.php
```

Run specific test method:
```bash
vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml --filter testGetApiKeyFromEnvironmentVariable
```

### Integration Tests (Requires API Key)

```bash
# Set API key first
export OPENAI_API_KEY=sk-proj-your-actual-api-key

# Run integration tests
vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml --group=integration
```

### Run All Tests

```bash
export OPENAI_API_KEY=sk-proj-your-actual-api-key
vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml
```

## API Key Management

### ⚠️ SECURITY IMPORTANT

**NEVER**:
- Commit API keys to the repository
- Share API keys in conversations or messages
- Hardcode API keys in test files
- Log API keys to console or files

**Always**:
- Use environment variables to store API keys
- Use `ApiKeyHelper::maskApiKey()` when logging
- Rotate compromised keys immediately
- Use separate keys for development/testing

### Getting Your API Key

1. Go to [https://platform.openai.com/account/api-keys](https://platform.openai.com/account/api-keys)
2. Create a new API key
3. Copy it and set it as environment variable

### Using ApiKeyHelper

```php
use Genaker\MagentoMcpAi\Test\Helper\ApiKeyHelper;

// Get API key
$apiKey = ApiKeyHelper::getApiKey();

// Check if available
if (ApiKeyHelper::isApiKeyAvailable()) {
    // Run integration tests
}

// Mask for logging
$masked = ApiKeyHelper::maskApiKey($apiKey);
echo "Using key: {$masked}\n";  // Output: Using key: sk-p...rkey

// Get status
$status = ApiKeyHelper::getStatus();
// Output: ['is_available' => true, 'is_valid_format' => true, 'masked_key' => 'sk-p...rkey']
```

## Test Coverage

### Unit Tests (10 tests)

1. ✅ `testGetApiKeyFromEnvironmentVariable` - API key retrieval from env var
2. ✅ `testGetApiKeyThrowsExceptionWhenNotConfigured` - Error handling
3. ✅ `testGetApiDomainReturnsDefault` - Default domain
4. ✅ `testGetApiDomainReturnsCustom` - Custom domain from config
5. ✅ `testGetApiDomainStripsTrailingSlash` - Domain format validation
6. ✅ `testTokenPropertiesSetAndRetrieve` - Token tracking
7. ✅ `testConstructorInitializesDependencies` - Dependency injection
8. ✅ `testApiEndpointConstantsAreDefined` - Endpoint constants
9. ✅ `testGoogleApiEndpointConstantsAreDefined` - Google API constants
10. ✅ `testMultipleServiceInstancesAreIndependent` - Instance isolation

### Integration Tests (10 tests)

1. ⏳ `testChatCompletionWithRealApi` - Chat completion endpoint
2. ⏳ `testEmbeddingsWithRealApi` - Embeddings endpoint
3. ✅ `testTokenUsageTracking` - Token counting
4. ✅ `testApiKeyIsValid` - API key validation
5. ✅ `testApiDomainConfiguration` - Domain configuration
6. ⏳ `testErrorHandlingForInvalidApiKey` - Error scenarios
7. ⏳ `testTimeoutHandling` - Timeout handling
8. ⏳ `testRateLimitingHandling` - Rate limit handling
9. ⏳ `testConcurrentRequests` - Concurrent request handling
10. ⏳ `testResponseCaching` - Response caching

**Legend**: ✅ = Ready, ⏳ = Needs Implementation

## Example Test Run

```bash
$ export OPENAI_API_KEY=sk-proj-...
$ vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml

PHPUnit 9.5.x by Sebastian Bergmann and contributors.

✓ OpenAI API key found (masked: sk-p...key)

Unit Tests:
  testGetApiKeyFromEnvironmentVariable ............................ PASS
  testGetApiKeyThrowsExceptionWhenNotConfigured ................... PASS
  testGetApiDomainReturnsDefault ................................ PASS
  testGetApiDomainReturnsCustom ................................. PASS
  testGetApiDomainStripsTrailingSlash ........................... PASS
  testTokenPropertiesSetAndRetrieve .............................. PASS
  testConstructorInitializesDependencies ......................... PASS
  testApiEndpointConstantsAreDefined ............................ PASS
  testGoogleApiEndpointConstantsAreDefined ..................... PASS
  testMultipleServiceInstancesAreIndependent ................... PASS

Integration Tests:
  testTokenUsageTracking ......................................... PASS
  testApiKeyIsValid .............................................. PASS
  testApiDomainConfiguration .................................... PASS

Time: 0.234s, Memory: 12.5MB

OK (13 passed, 7 incomplete)
```

## Writing New Tests

### Unit Test Template

```php
<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\OpenAiService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class MyNewTest extends TestCase
{
    private $openAiService;
    private $mockDependency;

    protected function setUp(): void
    {
        $this->mockDependency = $this->createMock(SomeDependency::class);
        $this->openAiService = new OpenAiService($this->mockDependency);
    }

    public function testMyNewFeature(): void
    {
        // Arrange
        $expected = 'some value';

        // Act
        $result = $this->openAiService->myMethod();

        // Assert
        $this->assertEquals($expected, $result);
    }
}
```

### Integration Test Template

```php
<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Integration\Model\Service;

use Genaker\MagentoMcpAi\Test\Helper\ApiKeyHelper;
use PHPUnit\Framework\TestCase;

class MyNewIntegrationTest extends TestCase
{
    /**
     * @group integration
     * @group openai-api
     */
    public function testRealApiCall(): void
    {
        if (!ApiKeyHelper::isApiKeyAvailable()) {
            $this->markTestSkipped('API key not available');
        }

        $apiKey = ApiKeyHelper::getApiKey();
        // Make real API call
    }
}
```

## Troubleshooting

### "OPENAI_API_KEY environment variable not set"

```bash
# Set the API key
export OPENAI_API_KEY=sk-proj-your-key

# Verify it's set
echo $OPENAI_API_KEY
```

### "Class not found" errors

```bash
# Ensure autoloader is loaded
composer install

# Regenerate autoloader
composer dump-autoload
```

### Test failures

1. Check if API key is valid at https://platform.openai.com/account/api-keys
2. Ensure your OpenAI account has sufficient credits
3. Check API rate limits
4. Review error messages carefully

## Best Practices

1. **Always use mocks in unit tests** - Don't call real APIs
2. **Use environment variables for secrets** - Never hardcode keys
3. **Mask sensitive data in logs** - Use ApiKeyHelper
4. **Test error cases** - Invalid keys, timeouts, rate limits
5. **Keep tests isolated** - Each test should be independent
6. **Use descriptive names** - Test names should describe what they test
7. **Test one thing per test** - Single responsibility principle
8. **Use groups for organization** - @group integration, @group openai-api

## CI/CD Integration

For continuous integration pipelines:

```bash
# .github/workflows/tests.yml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - run: composer install
      - run: vendor/bin/phpunit -c app/code/Genaker/MagentoMcpAi/phpunit.xml
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
```

## Resources

- [PHPUnit Documentation](https://phpunit.de)
- [OpenAI API Documentation](https://platform.openai.com/docs)
- [PHP Testing Best Practices](https://phpunit.de/manual/current/en/appendixes.best-practices.html)
- [OpenAI API Rate Limits](https://platform.openai.com/docs/guides/rate-limits)

## Support

For test-related issues:
1. Check the troubleshooting section above
2. Review test output for specific error messages
3. Consult PHPUnit documentation
4. Check OpenAI API status at https://status.openai.com

---

**Last Updated:** 2024-02-19  
**Maintainer:** Your Organization
