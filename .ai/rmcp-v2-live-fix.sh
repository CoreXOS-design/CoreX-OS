#!/usr/bin/env bash
# RMCP v2 repair for HFC (LIVE). Replaces the cloned OLD-text v2 draft with the approved AT-506 text.
# Run as root on the live host. Nothing is dropped: the wrong draft is renumbered to 99 and soft-deleted (reversible).
set -euo pipefail
SLUG=hfc-coastal
BK=/mnt/HC_Volume_103099143/backups          # CHECK: df -h -> must be the data volume on the live box, not /
cd /corex                                     # CHECK: the live checkout path

# 0. Checkout must be on origin/Prod and carry the loader + the approved text
git rev-parse --abbrev-ref HEAD; git rev-parse --short HEAD
test -f database/data/rmcp/v2.md || { echo "STOP: database/data/rmcp/v2.md missing"; exit 1; }
test -f app/Console/Commands/Compliance/RmcpImportVersion.php || { echo "STOP: loader missing"; exit 1; }
[ "$(grep -c 'five years' database/data/rmcp/v2.md)" = 0 ] || { echo "STOP: v2.md has the OLD wording"; exit 1; }
[ "$(grep -c 'Directive 12' database/data/rmcp/v2.md)" -ge 1 ] || { echo "STOP: v2.md lacks Directive 12"; exit 1; }
su -s /bin/bash www-data -c "HOME=/tmp php8.2 artisan list" | grep -q 'rmcp:import-version' || { echo "STOP: command not registered (clear caches?)"; exit 1; }

U=$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"'); P=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'); D=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
q(){ MYSQL_PWD="$P" mysql -h127.0.0.1 -u"$U" -N -B "$D" -e "$1"; }

# 1. Backup the RMCP tables first
MYSQL_PWD="$P" mysqldump -h127.0.0.1 -u"$U" --single-transaction --no-tablespaces "$D" \
  rmcp_versions rmcp_sections rmcp_variables rmcp_acknowledgements rmcp_section_acknowledgements \
  | gzip > "$BK/rmcp-hfc-before-v2-fix-$(date +%F-%H%M).sql.gz"
gzip -t "$BK"/rmcp-hfc-before-v2-fix-*.sql.gz && echo "backup OK"

AG=$(q "select id from agencies where slug='$SLUG' and deleted_at is null")
[ -n "$AG" ] || { echo "STOP: no agency $SLUG"; exit 1; }
echo "agency id $AG"; q "select id,version_number,status,deleted_at,left(change_notes,70) from rmcp_versions where agency_id=$AG order by id"

# 2. Identify the wrong draft: v2, draft, not trashed, NOT loaded by the AT-506 loader.
BAD=$(q "select id from rmcp_versions where agency_id=$AG and version_number=2 and status='draft' and deleted_at is null and change_notes not like 'Loaded from database/data/rmcp/v2.md%'")
if [ -z "$BAD" ]; then
  echo "No wrong v2 draft found (either no v2, or v2 is already the loader's). Skipping step 3."
else
  [ "$(echo "$BAD" | wc -l)" = 1 ] || { echo "STOP: more than one candidate"; exit 1; }
  ACKS=$(q "select count(*) from rmcp_acknowledgements where rmcp_version_id=$BAD")
  [ "$ACKS" = 0 ] || { echo "STOP: draft $BAD has $ACKS acknowledgements"; exit 1; }
  echo "Discarding draft id $BAD (v2, 'Cloned' / old text)."
  # 3. Free the v2 slot: renumber to 99 (unique key agency_id+version_number) AND soft-delete. Reversible.
  q "update rmcp_sections set deleted_at=NOW() where rmcp_version_id=$BAD and deleted_at is null;
     update rmcp_versions set version_number=99, deleted_at=NOW(), updated_at=NOW(),
            change_notes=concat('DISCARDED 27 Oct 2026 (old text; replaced by AT-506 v2). Was v2. ', coalesce(change_notes,''))
      where id=$BAD and agency_id=$AG and status='draft';"
fi

# 4. Dry run -> read the BLANK / UNKNOWN warnings -> then --apply
run(){ su -s /bin/bash www-data -c "HOME=/tmp php8.2 artisan rmcp:import-version $SLUG $1 \
  --var='agency.area_of_operation=KZN South Coast and North Coast' \
  --var='agency.branches_statement=one office, Shelly Beach, to which all agents are attached; no branches or subsidiaries in or outside South Africa' \
  --var='agency.branches_and_responsible=Shelly Beach: {{compliance_officer.full_name}} (Compliance Officer)' \
  --var='profile.active_leases=55' \
  --var='profile.avg_monthly_rent=R 9,384' \
  --var='agency.legal_name=Johan and Elize Properties t/a Home Finders Coastal'"; }
run ""
read -r -p "Dry run read and OK? type APPLY: " ANS; [ "$ANS" = APPLY ] || { echo aborted; exit 1; }
run "--apply"

# 5. Verify
NEW=$(q "select id from rmcp_versions where agency_id=$AG and version_number=2 and deleted_at is null")
q "select id,version_number,status,left(change_notes,90) from rmcp_versions where id=$NEW"
echo "sections: $(q "select count(*) from rmcp_sections where rmcp_version_id=$NEW and deleted_at is null")  (expect 39)"
echo "five years: $(q "select count(*) from rmcp_sections where rmcp_version_id=$NEW and body_html like '%five years%'")  (expect 0)"
echo "Directive 12: $(q "select count(*) from rmcp_sections where rmcp_version_id=$NEW and body_html like '%Directive 12%'")  (expect >=1)"
echo "v1 still active: $(q "select status from rmcp_versions where agency_id=$AG and version_number=1")"
echo "Open: /corex/compliance/rmcp-manager/$NEW   PDF: /corex/compliance/rmcp-manager/$NEW/pdf"
echo "In the downloaded PDF run:  pdftotext file.pdf - | grep -c 'five years'   (expect 0)"

# Rollback: restore the backup file, or clear deleted_at on the discarded row and set its version_number back to 2.
