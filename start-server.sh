#!/bin/bash
set -euo pipefail

# Colors
RED='\033[0;31m'
YELLOW='\033[0;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Check for existing PID files
if [ -f "server.vscode.pid" ]; then
  pid=$(cat "server.vscode.pid")
  if ! kill -0 "$pid" 2>/dev/null; then
    echo -e "${YELLOW}Found stale server.vscode.pid (no process with PID $pid). Removing.${NC}"
    rm -f "server.vscode.pid"
  else
    echo -e "${YELLOW}Server already started in VSCode with PID $pid${NC}"
    exit 0
  fi
fi
if [ -f "server.pid" ]; then
  pid=$(cat server.pid)
  if kill -0 "$pid" 2>/dev/null; then
    if [ "${RUN_MODE:-}" = "vscode" ]; then
      echo -e "❌ ${YELLOW}Server already started in background with PID $pid, please stop it from there before starting in VSCode.${NC}"
      exit 1
    else
      echo -e "${YELLOW}Server already started in background with PID $pid${NC}"
      exit 0
    fi
  else
    echo -e "${YELLOW}No process with PID $pid found. Removing stale server.pid.${NC}"
    rm server.pid
  fi
fi

echo "Starting PHP built-in web server..."

# Set defaults
: "${API_HOST:=localhost}"
: "${API_PORT:=8000}"

if [ "${RUN_MODE:-}" = "vscode" ]; then
  # Run in foreground (for VSCode tasks)
  pid=$$
  echo "$pid" > server.vscode.pid
  echo -e "${GREEN}Server starting at http://${API_HOST}:${API_PORT}/ (PID: $pid)${NC}"
  exec php -S "${API_HOST}:${API_PORT}" -t public public/router.php
else
  # Run in background with multiple workers
  PHP_CLI_SERVER_WORKERS=4 php -S "${API_HOST}:${API_PORT}" -t public public/router.php > /dev/null 2>&1 &
  pid=$!
  sleep 0.2
  if ! kill -0 "$pid" 2>/dev/null; then
    echo -e "${RED}Failed to start server at http://${API_HOST}:${API_PORT}/.${NC}"
    rm -f "server.pid"
    exit 1
  fi
  echo "$pid" > "server.pid"
  echo -e "${GREEN}Server started at http://${API_HOST}:${API_PORT}/ (PID: $pid)${NC}"
fi
