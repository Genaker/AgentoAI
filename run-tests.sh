#!/bin/bash

# OpenAiService Test Runner
# Usage: bash run-tests.sh [unit|integration|all]

TEST_TYPE=${1:-unit}
PHPUNIT_BIN="vendor/bin/phpunit"
TEST_CONFIG="app/code/Genaker/MagentoMcpAi/phpunit.xml"
TEST_DIR="app/code/Genaker/MagentoMcpAi/Test"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== OpenAiService Test Suite ===${NC}\n"

# Check if PHPUnit is installed
if [ ! -f "$PHPUNIT_BIN" ]; then
    echo -e "${RED}Error: PHPUnit not found at $PHPUNIT_BIN${NC}"
    echo "Please run: composer install"
    exit 1
fi

# Function to run unit tests
run_unit_tests() {
    echo -e "${BLUE}Running Unit Tests...${NC}\n"
    
    $PHPUNIT_BIN \
        --configuration "$TEST_CONFIG" \
        --testdox \
        --exclude-group integration \
        "$TEST_DIR/Unit/Model/Service/OpenAiServiceTest.php"
}

# Function to run integration tests
run_integration_tests() {
    if [ -z "$OPENAI_API_KEY" ]; then
        echo -e "${YELLOW}Warning: OPENAI_API_KEY environment variable not set${NC}"
        echo "Integration tests will be skipped"
        echo "To run integration tests, set: export OPENAI_API_KEY=sk-proj-your-key"
        return 0
    fi

    echo -e "${BLUE}Running Integration Tests...${NC}\n"
    echo -e "${GREEN}Using API key: ${OPENAI_API_KEY:0:4}...${OPENAI_API_KEY: -4}${NC}\n"
    
    $PHPUNIT_BIN \
        --configuration "$TEST_CONFIG" \
        --testdox \
        --group=integration \
        "$TEST_DIR/Integration/Model/Service/OpenAiServiceIntegrationTest.php"
}

# Run tests based on parameter
case $TEST_TYPE in
    unit)
        run_unit_tests
        ;;
    integration)
        run_integration_tests
        ;;
    all)
        run_unit_tests
        echo ""
        run_integration_tests
        ;;
    *)
        echo "Usage: $0 [unit|integration|all]"
        echo ""
        echo "Examples:"
        echo "  $0 unit          # Run unit tests only"
        echo "  $0 integration   # Run integration tests (requires OPENAI_API_KEY)"
        echo "  $0 all           # Run all tests"
        exit 1
        ;;
esac

exit $?
