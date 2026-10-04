#!/bin/bash
cd /mnt/HC_Volume_103099143/corex-worktrees/at443-rentals-reports-2026-10-04
FAILCOUNT=0
TOTAL=0
> /tmp/lint_failures2.txt
for f in $(find app routes config database -name "*.php" -not -path "*/vendor/*"); do
  TOTAL=$((TOTAL+1))
  OUT=$(php8.2 -l "$f" 2>&1)
  if [[ "$OUT" != *"No syntax errors detected"* ]]; then
    echo "FAIL: $f" >> /tmp/lint_failures2.txt
    echo "$OUT" >> /tmp/lint_failures2.txt
    FAILCOUNT=$((FAILCOUNT+1))
  fi
done
echo "DONE. Total files: $TOTAL. Failures: $FAILCOUNT" >> /tmp/lint_failures2.txt
echo "DONE. Total files: $TOTAL. Failures: $FAILCOUNT"
