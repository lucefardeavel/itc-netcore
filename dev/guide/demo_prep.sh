#!/usr/bin/env bash
# Booklet demo copy (isolated lab only, synthetic data): display names, demo settings, then the UI flows.
set -euo pipefail
G="$(cd "$(dirname "$0")" && pwd)"
WORK="${GUIDE_WORK:-/tmp/itc-guide}"; mkdir -p "$WORK"
# the throwaway database credentials written by dev/wp9/t1a_run.sh up (its STATE file)
T1A_ENV="${T1A_ENV:-$(sed -n 's/^STATE=//p' "$G/../wp9/t1a_run.sh")}"
set -a; . "$T1A_ENV"; set +a
D=$(python3 -c "import json;print(json.load(open('/srv/phpnuxbill/pwalab/pwalab-ready.json'))['dir'])")
docker exec nuxbill-reseller-test-pwalab rm -f $D/system/plugin/wp2_probe.php
docker exec nuxbill-reseller-test-pwalab sh -c "printf '%s' 'مرحباً بكم في شبكة النور للإنترنت.<br>باقات جديدة بسرعات أعلى متاحة الآن لدى الوكلاء المعتمدين.' > $D/pages/Announcement.html && printf '%s' 'عزيزنا المشترك: يمكنك متابعة باقتك وتاريخ انتهائها من هذه الصفحة.<br>للتجديد تواصل مع وكيلك المعتمد.' > $D/pages/Announcement_Customer.html"
cd /srv/phpnuxbill/dev/dev/test
q() { MYSQL_PWD="$WP1_DB_ROOT_PASSWORD" docker compose exec -T -e MYSQL_PWD mariadb mariadb -uroot "$@" 2>&1 | grep -v Container || true; }
DB=$(q -N -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'wp1_h_%'" | tail -1)
echo "$DB" > "$WORK/labdb"
q "$DB" < "$G/demo_names.sql"
q "$DB" < "$G/demo_usernames.sql"
q "$DB" -e "SET NAMES utf8mb4; DELETE FROM tbl_plans WHERE device='Probe'; DELETE FROM tbl_user_recharges WHERE namebp='wp2_plan';
UPDATE tbl_customers SET phonenumber = CONCAT('0770', LPAD(id*7919 % 10000000, 7, '0')) WHERE CHAR_LENGTH(phonenumber) < 11;
DELETE FROM tbl_bandwidth WHERE name_bw='wp2_bw'; UPDATE tbl_bandwidth SET name_bw='سرعة 4 ميغا / 1 ميغا' WHERE name_bw='bw 4M/1M';
UPDATE tbl_bandwidth SET name_bw='سرعة 10 ميغا / 2 ميغا' WHERE name_bw='bw 10M/2M';
UPDATE tbl_appconfig SET value='yes' WHERE setting IN ('enable_balance','enable_coupons');
INSERT INTO tbl_appconfig (setting, value) SELECT 'enable_balance','yes' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting='enable_balance');
INSERT INTO tbl_appconfig (setting, value) SELECT 'enable_coupons','yes' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting='enable_coupons');"
python3 - <<EOF
import json
d=json.load(open('/srv/phpnuxbill/pwalab/pwalab-ready.json'))
m={'sa':'gm','ad':'ali.admin','A':'rafidain','B':'karrada','A1':'haider','rp':'reports'}
json.dump({m[k]:v for k,v in d['staff'].items()}, open('$WORK/staff.json','w'))
EOF
echo "db=$DB prepared"
docker exec nuxbill-reseller-test-pwalab rm -f $D/system/devices/Probe.php
q "$DB" -e "SET NAMES utf8mb4; DELETE FROM tbl_transactions WHERE method LIKE '%seed%' OR method LIKE 'Voucher - X%'; UPDATE tbl_transactions SET plan_name='هوتسبوت 4 ميغا - 30 يوم' WHERE plan_name LIKE 'Hotspot 4M%';
UPDATE tbl_routers SET name='MikroTik-Karrada', description='راوتر فرع الكرادة' WHERE name='wp2_router'; UPDATE tbl_plans SET routers='MikroTik-Karrada' WHERE routers='wp2_router'; UPDATE tbl_transactions SET routers='MikroTik-Karrada' WHERE routers='wp2_router';"
echo cleanup-done
docker exec nuxbill-reseller-test-pwalab sh -c "printf '%s' 'قبل التسجيل: احصل على قسيمة من أحد وكلائنا المعتمدين، ثم أدخل رمزها مع بياناتك.' > $D/pages/Registration_Info.html && printf '%s' 'يمكنك شراء قسائم الإنترنت من الوكلاء المعتمدين لدى شبكة النور.' > $D/pages/Order_Voucher.html"
