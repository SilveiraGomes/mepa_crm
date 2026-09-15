SELECT 'tables',COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mepa_wave4_test_independent_physical';
SELECT 'columns',COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='mepa_wave4_test_independent_physical';
SELECT 'foreign_keys',COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='mepa_wave4_test_independent_physical';
SELECT 'checks',COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='mepa_wave4_test_independent_physical' AND CONSTRAINT_TYPE='CHECK';
SELECT 'unique',COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='mepa_wave4_test_independent_physical' AND CONSTRAINT_TYPE='UNIQUE';
SELECT 'cascade_count',COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='mepa_wave4_test_independent_physical' AND (DELETE_RULE='CASCADE' OR UPDATE_RULE='CASCADE');