#!/bin/bash

# OpenAI API Test Script
# Uses environment variable OPENAI_API_KEY
# Tests with gpt-3.5-turbo (cheapest model)
# 
# Usage:
#   export OPENAI_API_KEY=sk-proj-your-key
#   bash test-openai.sh "Your question here"

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuration
API_KEY="${OPENAI_API_KEY}"
MODEL="gpt-3.5-turbo"  # Cheapest model
API_DOMAIN="${OPENAI_API_DOMAIN:-https://api.openai.com}"
QUERY="${1:-What is 2+2?}"
VERBOSE="${VERBOSE:-false}"

echo -e "${BLUE}=== OpenAI API Test ===${NC}\n"

# Check API key
if [ -z "$API_KEY" ]; then
    echo -e "${RED}Error: OPENAI_API_KEY environment variable not set${NC}"
    echo "Set it with: export OPENAI_API_KEY=sk-proj-your-key"
    exit 1
fi

echo -e "${GREEN}✓ API Key found${NC}"
echo -e "${YELLOW}Masked: ${API_KEY:0:4}...${API_KEY: -4}${NC}\n"

# Display test parameters
echo -e "${BLUE}Test Parameters:${NC}"
echo "  Model: $MODEL"
echo "  Query: $QUERY"
echo "  Domain: $API_DOMAIN"
echo ""

# Make API request
echo -e "${BLUE}Making API request...${NC}\n"

START_TIME=$(date +%s%N | cut -b1-13)

RESPONSE=$(curl -s -X POST "${API_DOMAIN}/v1/chat/completions" \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "'${MODEL}'",
    "messages": [
      {
        "role": "system",
        "content": "You are a helpful assistant. Provide concise, accurate answers."
      },
      {
        "role": "user",
        "content": "'${QUERY}'"
      }
    ],
    "temperature": 0.7,
    "max_tokens": 500
  }')

END_TIME=$(date +%s%N | cut -b1-13)
DURATION=$((END_TIME - START_TIME))

# Check for errors
if echo "$RESPONSE" | grep -q "error"; then
    echo -e "${RED}API Error:${NC}"
    echo "$RESPONSE" | jq '.error'
    exit 1
fi

# Extract response content using grep and sed
CONTENT=$(echo "$RESPONSE" | grep -o '"content":"[^"]*"' | head -1 | sed 's/"content":"//' | sed 's/"$//')
PROMPT_TOKENS=$(echo "$RESPONSE" | grep -o '"prompt_tokens":[0-9]*' | sed 's/"prompt_tokens"://')
COMPLETION_TOKENS=$(echo "$RESPONSE" | grep -o '"completion_tokens":[0-9]*' | sed 's/"completion_tokens"://')
TOTAL_TOKENS=$(echo "$RESPONSE" | grep -o '"total_tokens":[0-9]*' | sed 's/"total_tokens"://')

# Display response
echo -e "${GREEN}Response:${NC}"
echo "$CONTENT"
echo ""

# Display token usage
echo -e "${BLUE}Token Usage:${NC}"
echo "  Prompt tokens: $PROMPT_TOKENS"
echo "  Completion tokens: $COMPLETION_TOKENS"
echo "  Total tokens: $TOTAL_TOKENS"
echo ""

# Calculate costs (gpt-3.5-turbo pricing as of 2024)
# Input: $0.50 per 1M tokens
# Output: $1.50 per 1M tokens
PROMPT_COST=$(awk "BEGIN {printf \"%.6f\", $PROMPT_TOKENS * 0.50 / 1000000}")
COMPLETION_COST=$(awk "BEGIN {printf \"%.6f\", $COMPLETION_TOKENS * 1.50 / 1000000}")
TOTAL_COST=$(awk "BEGIN {printf \"%.6f\", $PROMPT_COST + $COMPLETION_COST}")

echo -e "${BLUE}Cost Estimation (gpt-3.5-turbo):${NC}"
echo "  Prompt cost: \$$PROMPT_COST"
echo "  Completion cost: \$$COMPLETION_COST"
echo -e "  Total cost: ${GREEN}\$$TOTAL_COST${NC}"
echo ""

echo -e "${BLUE}Performance:${NC}"
echo "  Response time: ${DURATION}ms"
echo ""

echo -e "${GREEN}✓ Test completed successfully!${NC}"

# Display raw response if verbose
if [ "$VERBOSE" = "true" ]; then
    echo ""
    echo -e "${BLUE}Raw Response:${NC}"
    echo "$RESPONSE" | grep -o '.'
fi
