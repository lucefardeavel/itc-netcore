SET NAMES utf8mb4;
UPDATE tbl_users SET fullname = CASE username
 WHEN 'sa' THEN 'المدير العام' WHEN 'ad' THEN 'علي حسن' WHEN 'rp' THEN 'قسم التقارير'
 WHEN 'A' THEN 'وكالة الرافدين' WHEN 'B' THEN 'موزع الكرادة' WHEN 'C' THEN 'وكالة دجلة'
 WHEN 'A1' THEN 'حيدر كاظم' WHEN 'A2' THEN 'زينب علي' WHEN 'C1' THEN 'مصطفى جاسم'
 WHEN 'D' THEN 'وكالة الفرات' WHEN 'F' THEN 'موزع الحلة' WHEN 'E' THEN 'وكالة بابل' WHEN 'E1' THEN 'سجاد رحيم'
 ELSE fullname END;
UPDATE tbl_customers SET fullname = CASE username
 WHEN 'cA1' THEN 'أحمد جاسم محمد' WHEN 'cA2' THEN 'زينب كريم' WHEN 'cA0' THEN 'حسين عباس' WHEN 'cB' THEN 'نور صباح'
 WHEN 'cC1' THEN 'علي مهدي' WHEN 'cF' THEN 'سارة حميد' WHEN 'cNULL' THEN 'عمر فاضل' WHEN 'cNULL2' THEN 'مريم طارق'
 WHEN 'legacy.user' THEN 'ياسر ناصر' ELSE fullname END,
 address = 'بغداد';
UPDATE tbl_customers SET username = CASE username
 WHEN 'cA1' THEN 'ahmed.j' WHEN 'cA2' THEN 'zainab.k' WHEN 'cA0' THEN 'hussein.a' WHEN 'cB' THEN 'noor.s'
 WHEN 'cC1' THEN 'ali.m' WHEN 'cF' THEN 'sara.h' WHEN 'cNULL' THEN 'omar.f' WHEN 'cNULL2' THEN 'maryam.t'
 WHEN 'legacy.user' THEN 'yasser.n' ELSE username END;
UPDATE tbl_plans SET name_plan = 'هوتسبوت 4 ميغا - 30 يوم' WHERE name_plan = 'Hotspot 4M - 30 يوم';
UPDATE tbl_plans SET name_plan = 'PPPoE منزلي 10 ميغا - 30 يوم' WHERE name_plan = 'PPPoE Home 10M';
UPDATE tbl_appconfig SET value = '$' WHERE setting = 'currency_code';
UPDATE tbl_appconfig SET value = 'arabic' WHERE setting = 'language';
UPDATE tbl_appconfig SET value = 'Asia/Baghdad' WHERE setting = 'timezone';
UPDATE tbl_appconfig SET value = '.' WHERE setting = 'dec_point';
UPDATE tbl_appconfig SET value = ',' WHERE setting = 'thousands_sep';
