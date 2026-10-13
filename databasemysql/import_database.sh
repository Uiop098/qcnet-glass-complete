#!/usr/bin/env bash
# =============================================================================
# QCNet Universal Business Platform - Automated Database Importer
# File: import_database.sh
# =============================================================================

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB_NAME="qcnet_db"
DB_USER="${DB_USER:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_PASS="${DB_PASS:-}"
SOCKET_PATH="/data/data/com.termux/files/usr/var/run/mysqld.sock"

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}============================================================${NC}"
echo -e "${BLUE}🚀 QCNet Universal Database Provisioning & Importer${NC}"
echo -e "${BLUE}============================================================${NC}"

# Detect client binary
if command -v mariadb &> /dev/null; then
    CLI="mariadb"
elif command -v mysql &> /dev/null; then
    CLI="mysql"
else
    echo -e "${RED}❌ Error: Neither 'mariadb' nor 'mysql' client command found.${NC}"
    exit 1
fi

# Build connection parameters
CLI_ARGS=("-u" "$DB_USER")
if [ -n "$DB_PASS" ]; then
    CLI_ARGS+=("-p$DB_PASS")
fi

if [ -S "$SOCKET_PATH" ]; then
    echo -e "${GREEN}🔌 Detected local socket at ${SOCKET_PATH}${NC}"
    CLI_ARGS+=("-S" "$SOCKET_PATH")
else
    CLI_ARGS+=("-h" "$DB_HOST" "-P" "$DB_PORT")
fi

echo -e "${YELLOW}Testing database connectivity...${NC}"
if ! $CLI "${CLI_ARGS[@]}" -e "SELECT 1;" &> /dev/null; then
    echo -e "${RED}❌ Cannot connect to MariaDB/MySQL server.${NC}"
    echo -e "   Please verify server is running and credentials are valid."
    exit 1
fi
echo -e "${GREEN}✅ Database connection verified.${NC}"

# Execute Migration Files
FILES=(
    "00_init_database.sql"
    "01_quickchat_core.sql"
    "02_universal_business_platform.sql"
    "03_caching_queues_and_scaling.sql"
    "04_security_center_and_audit.sql"
    "05_seed_and_demo_data.sql"
)

for file in "${FILES[@]}"; do
    FILEPATH="$DIR/$file"
    if [ -f "$FILEPATH" ]; then
        echo -e "${YELLOW}▶ Importing:${NC} $file..."
        $CLI "${CLI_ARGS[@]}" < "$FILEPATH"
        echo -e "${GREEN}  ✓ Done.${NC}"
    else
        echo -e "${RED}❌ Missing migration file: $FILEPATH${NC}"
        exit 1
    fi
done

echo -e "${BLUE}============================================================${NC}"
echo -e "${GREEN}🎉 All migrations and seed datasets successfully applied!${NC}"
echo -e "${BLUE}============================================================${NC}"

# Print Table Summary
echo -e "${YELLOW}Installed Tables in \`${DB_NAME}\`:${NC}"
$CLI "${CLI_ARGS[@]}" -e "USE \`${DB_NAME}\`; SHOW TABLES;"

echo -e "\n${GREEN}Database ready for production!${NC}"
