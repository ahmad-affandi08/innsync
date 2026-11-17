#!/bin/sh
# Makes the schema dump load on any host and for any database user: no trigger DEFINER (loading one that is not the current user needs SUPER) and no collation that only MySQL 8 knows
# (utf8mb4_0900_ai_ci). Run it after every `php artisan schema:dump`; tests/Deploy/SchemaDumpIsPortableTest fails while the dump is not clean.
set -eu
file="${1:-database/schema/mysql-schema.sql}"
sed -i -e 's# /\*!50017 DEFINER=`[^`]*`@`[^`]*`\*/##' -e 's# COLLATE=utf8mb4_0900_ai_ci##' -e '/SET collation_connection  = utf8mb4_0900_ai_ci/d' "$file"
