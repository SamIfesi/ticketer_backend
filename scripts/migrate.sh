#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

run_sql() { docker exec -i ticketer-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root ticketer_db'; }
query()   { docker exec -i ticketer-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -N -u root ticketer_db'; }

echo "CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" | run_sql

pending=()
for f in database/migrations/*.sql; do
  [ -e "$f" ] || continue
  name=$(basename "$f")
  count=$(echo "SELECT COUNT(*) FROM schema_migrations WHERE filename='$name';" | query)
  if [ "$count" = "0" ]; then pending+=("$f"); fi
done

if [ ${#pending[@]} -eq 0 ]; then
  echo "Nothing to apply."
  exit 0
fi

echo "Pending: ${pending[*]}"
mkdir -p ~/backups
docker exec ticketer-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --routines --triggers ticketer_db' | gzip > ~/backups/pre_migration_$(date +%F_%H%M).sql.gz
echo "Backup saved."

for f in "${pending[@]}"; do
  echo "Applying $f"
  run_sql < "$f"
  echo "INSERT INTO schema_migrations (filename) VALUES ('$(basename "$f")');" | run_sql
done
echo "Done."