# Wave 1 ? relat?rio f?sico executado

Fonte: MySQL 8.4.11, INFORMATION_SCHEMA e SHOW CREATE TABLE, base de teste sint?tica. 31 tabelas, 267 colunas, 31 PKs, 50 FKs, 25 CHECKs enforced, 28 UNIQUEs e 52 INDEX. W1-F01 continua aberto; este relat?rio n?o aprova paridade estrita.

[Metadados integrais](wave1_physical_inspection.json) ? [SQL parcial sem dados](wave1_partial_schema.sql) ? [FKs executadas](wave1_executed_foreign_keys.json)

DATETIME(6) usa UTC; DATE representa data civil. Todas as tabelas usam InnoDB, utf8mb4/utf8mb4_unicode_ci; collation espec?fica de coluna consta abaixo.

## person_statuses

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_person_statuses_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_person_statuses_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## sex_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_sex_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_sex_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## civil_status_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_civil_status_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_civil_status_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## people

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| full_name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| birth_date | date | True | None | False | None/None |
| birth_precision | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| birth_year | smallint unsigned | True | None | False | None/None |
| sex_type_id | bigint unsigned | True | None | False | None/None |
| civil_status_type_id | bigint unsigned | True | None | False | None/None |
| status_id | bigint unsigned | False | None | False | None/None |
| merged_into_id | bigint unsigned | True | None | False | None/None |
| archived_at | datetime(6) | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_people_birth_date_id",
    "unique": false,
    "columns": [
      "birth_date",
      "id"
    ]
  },
  {
    "name": "ix_people_civil_status_type_id",
    "unique": false,
    "columns": [
      "civil_status_type_id"
    ]
  },
  {
    "name": "ix_people_full_name_id",
    "unique": false,
    "columns": [
      "full_name",
      "id"
    ]
  },
  {
    "name": "ix_people_merged_into_id",
    "unique": false,
    "columns": [
      "merged_into_id"
    ]
  },
  {
    "name": "ix_people_sex_type_id",
    "unique": false,
    "columns": [
      "sex_type_id"
    ]
  },
  {
    "name": "ix_people_status_id",
    "unique": false,
    "columns": [
      "status_id"
    ]
  },
  {
    "name": "uq_people_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_people_civil_status_type_id",
    "columns": [
      "civil_status_type_id"
    ],
    "target_table": "civil_status_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_people_merged_into_id",
    "columns": [
      "merged_into_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_people_sex_type_id",
    "columns": [
      "sex_type_id"
    ],
    "target_table": "sex_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_people_status_id",
    "columns": [
      "status_id"
    ],
    "target_table": "person_statuses",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_people_birth",
    "expression": "(((`birth_precision` = _utf8mb4\\'EXACT\\') and (`birth_date` is not null) and (`birth_year` is null)) or ((`birth_precision` = _utf8mb4\\'YEAR_ONLY\\') and (`birth_date` is null) and (`birth_year` is not null)) or ((`birth_precision` = _utf8mb4\\'UNKNOWN\\') and (`birth_date` is null) and (`birth_year` is null)))",
    "enforced": true
  }
]
```

## identity_document_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_identity_document_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_identity_document_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## contact_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_contact_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_contact_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## person_contacts

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| person_id | bigint unsigned | False | None | False | None/None |
| contact_type_id | bigint unsigned | False | None | False | None/None |
| value_ciphertext | varbinary(2048) | False | None | False | None/None |
| value_blind_index | binary(32) | False | None | False | None/None |
| key_version | smallint unsigned | False | None | False | None/None |
| is_primary | tinyint unsigned | False | None | False | None/None |
| verified_at | datetime(6) | True | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_person_contacts_contact_type_id",
    "unique": false,
    "columns": [
      "contact_type_id"
    ]
  },
  {
    "name": "ix_person_contacts_person_id_contact_type_id",
    "unique": false,
    "columns": [
      "person_id",
      "contact_type_id"
    ]
  },
  {
    "name": "ix_person_contacts_value_blind_index",
    "unique": false,
    "columns": [
      "value_blind_index"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_person_contacts_contact_type_id",
    "columns": [
      "contact_type_id"
    ],
    "target_table": "contact_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_contacts_person_id",
    "columns": [
      "person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_person_contacts_is_primary",
    "expression": "(`is_primary` in (0,1))",
    "enforced": true
  }
]
```

## household_role_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_household_role_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_household_role_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## relationship_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_relationship_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_relationship_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## territorial_area_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_territorial_area_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_territorial_area_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## territorial_areas

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| parent_id | bigint unsigned | True | None | False | None/None |
| area_type_id | bigint unsigned | False | None | False | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_territorial_areas_area_type_id",
    "unique": false,
    "columns": [
      "area_type_id"
    ]
  },
  {
    "name": "ix_territorial_areas_parent_id_area_type_id",
    "unique": false,
    "columns": [
      "parent_id",
      "area_type_id"
    ]
  },
  {
    "name": "uq_territorial_areas_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_territorial_areas_area_type_id",
    "columns": [
      "area_type_id"
    ],
    "target_table": "territorial_area_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_territorial_areas_parent_id",
    "columns": [
      "parent_id"
    ],
    "target_table": "territorial_areas",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## addresses

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| country_code | char(3) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| province_id | bigint unsigned | True | None | False | None/None |
| municipality_id | bigint unsigned | True | None | False | None/None |
| line1_ciphertext | varbinary(2048) | False | None | False | None/None |
| locality | varchar(191) | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| key_version | smallint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_addresses_municipality_id",
    "unique": false,
    "columns": [
      "municipality_id"
    ]
  },
  {
    "name": "ix_addresses_province_id",
    "unique": false,
    "columns": [
      "province_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_addresses_municipality_id",
    "columns": [
      "municipality_id"
    ],
    "target_table": "territorial_areas",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_addresses_province_id",
    "columns": [
      "province_id"
    ],
    "target_table": "territorial_areas",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## households

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| address_id | bigint unsigned | True | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_households_address_id",
    "unique": false,
    "columns": [
      "address_id"
    ]
  },
  {
    "name": "uq_households_code",
    "unique": true,
    "columns": [
      "code"
    ]
  },
  {
    "name": "uq_households_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_households_address_id",
    "columns": [
      "address_id"
    ],
    "target_table": "addresses",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## organizational_unit_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_organizational_unit_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_organizational_unit_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## unit_parent_rules

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| child_type_id | bigint unsigned | False | None | False | None/None |
| parent_type_id | bigint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_unit_parent_rules_parent_type_id",
    "unique": false,
    "columns": [
      "parent_type_id"
    ]
  },
  {
    "name": "uq_unit_parent_rules_child_type_id_parent_type_id",
    "unique": true,
    "columns": [
      "child_type_id",
      "parent_type_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_unit_parent_rules_child_type_id",
    "columns": [
      "child_type_id"
    ],
    "target_table": "organizational_unit_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_parent_rules_parent_type_id",
    "columns": [
      "parent_type_id"
    ],
    "target_table": "organizational_unit_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## organizational_units

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| parent_id | bigint unsigned | True | None | False | None/None |
| unit_type_id | bigint unsigned | False | None | False | None/None |
| municipality_id | bigint unsigned | True | None | False | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| opened_on | date | True | None | False | None/None |
| closed_on | date | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_organizational_units_municipality_id_unit_type_id",
    "unique": false,
    "columns": [
      "municipality_id",
      "unit_type_id"
    ]
  },
  {
    "name": "ix_organizational_units_parent_id_unit_type_id_status",
    "unique": false,
    "columns": [
      "parent_id",
      "unit_type_id",
      "status"
    ]
  },
  {
    "name": "ix_organizational_units_unit_type_id",
    "unique": false,
    "columns": [
      "unit_type_id"
    ]
  },
  {
    "name": "uq_organizational_units_code",
    "unique": true,
    "columns": [
      "code"
    ]
  },
  {
    "name": "uq_organizational_units_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_organizational_units_municipality_id",
    "columns": [
      "municipality_id"
    ],
    "target_table": "territorial_areas",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_organizational_units_parent_id",
    "columns": [
      "parent_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_organizational_units_unit_type_id",
    "columns": [
      "unit_type_id"
    ],
    "target_table": "organizational_unit_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_organizational_units_status",
    "expression": "(`status` in (_utf8mb4\\'DRAFT\\',_utf8mb4\\'ACTIVE\\',_utf8mb4\\'CLOSED\\'))",
    "enforced": true
  }
]
```

## organizational_structure_lock

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_organizational_structure_lock_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[]
```

## files

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| owner_unit_id | bigint unsigned | False | None | False | None/None |
| owner_department_id | bigint unsigned | True | None | False | None/None |
| created_by | bigint unsigned | True | None | False | None/None |
| classification | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| disk | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| storage_key | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| original_name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| mime_type | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| size_bytes | bigint unsigned | False | None | False | None/None |
| checksum | binary(32) | False | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| deleted_at | datetime(6) | True | None | False | None/None |
| purged_at | datetime(6) | True | None | False | None/None |
| key_version | smallint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_files_created_by",
    "unique": false,
    "columns": [
      "created_by"
    ]
  },
  {
    "name": "ix_files_owner_department_id",
    "unique": false,
    "columns": [
      "owner_department_id"
    ]
  },
  {
    "name": "ix_files_owner_unit_id_classification_status",
    "unique": false,
    "columns": [
      "owner_unit_id",
      "classification",
      "status"
    ]
  },
  {
    "name": "uq_files_disk_storage_key",
    "unique": true,
    "columns": [
      "disk",
      "storage_key"
    ]
  },
  {
    "name": "uq_files_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_files_created_by",
    "columns": [
      "created_by"
    ],
    "target_table": "users",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_files_owner_unit_id",
    "columns": [
      "owner_unit_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_files_status",
    "expression": "(`status` in (_utf8mb4\\'QUARANTINED\\',_utf8mb4\\'AVAILABLE\\',_utf8mb4\\'TOMBSTONE\\',_utf8mb4\\'PURGED\\'))",
    "enforced": true
  }
]
```

## person_documents

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| person_id | bigint unsigned | False | None | False | None/None |
| document_type_id | bigint unsigned | False | None | False | None/None |
| issuer_country | char(3) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| number_ciphertext | varbinary(2048) | False | None | False | None/None |
| number_blind_index | binary(32) | False | None | False | None/None |
| key_version | smallint unsigned | False | None | False | None/None |
| issued_on | date | True | None | False | None/None |
| expires_on | date | True | None | False | None/None |
| file_id | bigint unsigned | True | None | False | None/None |
| verification_status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_person_docs_type_country_blind",
    "unique": false,
    "columns": [
      "document_type_id",
      "issuer_country",
      "number_blind_index"
    ]
  },
  {
    "name": "ix_person_documents_file_id",
    "unique": false,
    "columns": [
      "file_id"
    ]
  },
  {
    "name": "ix_person_documents_person_id",
    "unique": false,
    "columns": [
      "person_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_person_documents_document_type_id",
    "columns": [
      "document_type_id"
    ],
    "target_table": "identity_document_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_documents_file_id",
    "columns": [
      "file_id"
    ],
    "target_table": "files",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_documents_person_id",
    "columns": [
      "person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## person_files

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| person_id | bigint unsigned | False | None | False | None/None |
| file_id | bigint unsigned | False | None | False | None/None |
| purpose | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_person_files_file_id",
    "unique": false,
    "columns": [
      "file_id"
    ]
  },
  {
    "name": "uq_person_files_person_id_file_id_purpose",
    "unique": true,
    "columns": [
      "person_id",
      "file_id",
      "purpose"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_person_files_file_id",
    "columns": [
      "file_id"
    ],
    "target_table": "files",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_files_person_id",
    "columns": [
      "person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## legal_document_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_legal_document_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_legal_document_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## legal_documents

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| document_type_id | bigint unsigned | False | None | False | None/None |
| owner_unit_id | bigint unsigned | False | None | False | None/None |
| reference | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| title | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_legal_documents_document_type_id",
    "unique": false,
    "columns": [
      "document_type_id"
    ]
  },
  {
    "name": "ix_legal_documents_owner_unit_id_reference",
    "unique": false,
    "columns": [
      "owner_unit_id",
      "reference"
    ]
  },
  {
    "name": "uq_legal_documents_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_legal_documents_document_type_id",
    "columns": [
      "document_type_id"
    ],
    "target_table": "legal_document_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_legal_documents_owner_unit_id",
    "columns": [
      "owner_unit_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## person_addresses

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| person_id | bigint unsigned | False | None | False | None/None |
| address_id | bigint unsigned | False | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| starts_at | datetime(6) | False | None | False | None/None |
| ends_at | datetime(6) | True | None | False | None/None |
| reason | text | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| source_document_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_person_addresses_address_id",
    "unique": false,
    "columns": [
      "address_id"
    ]
  },
  {
    "name": "ix_person_addresses_person_id_starts_at",
    "unique": false,
    "columns": [
      "person_id",
      "starts_at"
    ]
  },
  {
    "name": "ix_person_addresses_source_document_id",
    "unique": false,
    "columns": [
      "source_document_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_person_addresses_address_id",
    "columns": [
      "address_id"
    ],
    "target_table": "addresses",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_addresses_person_id",
    "columns": [
      "person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_addresses_source_document_id",
    "columns": [
      "source_document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_person_addresses_period",
    "expression": "((`ends_at` is null) or (`ends_at` > `starts_at`))",
    "enforced": true
  }
]
```

## household_members

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| household_id | bigint unsigned | False | None | False | None/None |
| person_id | bigint unsigned | False | None | False | None/None |
| role_type_id | bigint unsigned | False | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| starts_at | datetime(6) | False | None | False | None/None |
| ends_at | datetime(6) | True | None | False | None/None |
| reason | text | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| source_document_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_household_members_household_id_starts_at",
    "unique": false,
    "columns": [
      "household_id",
      "starts_at"
    ]
  },
  {
    "name": "ix_household_members_person_id_starts_at",
    "unique": false,
    "columns": [
      "person_id",
      "starts_at"
    ]
  },
  {
    "name": "ix_household_members_role_type_id",
    "unique": false,
    "columns": [
      "role_type_id"
    ]
  },
  {
    "name": "ix_household_members_source_document_id",
    "unique": false,
    "columns": [
      "source_document_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_household_members_household_id",
    "columns": [
      "household_id"
    ],
    "target_table": "households",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_household_members_person_id",
    "columns": [
      "person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_household_members_role_type_id",
    "columns": [
      "role_type_id"
    ],
    "target_table": "household_role_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_household_members_source_document_id",
    "columns": [
      "source_document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_household_members_period",
    "expression": "((`ends_at` is null) or (`ends_at` > `starts_at`))",
    "enforced": true
  }
]
```

## person_relationships

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| subject_person_id | bigint unsigned | False | None | False | None/None |
| related_person_id | bigint unsigned | False | None | False | None/None |
| relationship_type_id | bigint unsigned | False | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| starts_at | datetime(6) | False | None | False | None/None |
| ends_at | datetime(6) | True | None | False | None/None |
| reason | text | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| source_document_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_person_relationships_related_person_id_relationship_type_id",
    "unique": false,
    "columns": [
      "related_person_id",
      "relationship_type_id"
    ]
  },
  {
    "name": "ix_person_relationships_relationship_type_id",
    "unique": false,
    "columns": [
      "relationship_type_id"
    ]
  },
  {
    "name": "ix_person_relationships_source_document_id",
    "unique": false,
    "columns": [
      "source_document_id"
    ]
  },
  {
    "name": "ix_person_relationships_subject_person_id_relationship_type_id",
    "unique": false,
    "columns": [
      "subject_person_id",
      "relationship_type_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_person_relationships_related_person_id",
    "columns": [
      "related_person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_relationships_relationship_type_id",
    "columns": [
      "relationship_type_id"
    ],
    "target_table": "relationship_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_relationships_source_document_id",
    "columns": [
      "source_document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_person_relationships_subject_person_id",
    "columns": [
      "subject_person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_person_relationships_period",
    "expression": "((`ends_at` is null) or (`ends_at` > `starts_at`))",
    "enforced": true
  },
  {
    "name": "ck_person_relationships_not_reflexive",
    "expression": "(`subject_person_id` <> `related_person_id`)",
    "enforced": true
  }
]
```

## unit_parent_periods

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| unit_id | bigint unsigned | False | None | False | None/None |
| parent_unit_id | bigint unsigned | True | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| starts_at | datetime(6) | False | None | False | None/None |
| ends_at | datetime(6) | True | None | False | None/None |
| reason | text | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| source_document_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_unit_parent_periods_parent_unit_id",
    "unique": false,
    "columns": [
      "parent_unit_id"
    ]
  },
  {
    "name": "ix_unit_parent_periods_source_document_id",
    "unique": false,
    "columns": [
      "source_document_id"
    ]
  },
  {
    "name": "ix_unit_parent_periods_unit_id_starts_at",
    "unique": false,
    "columns": [
      "unit_id",
      "starts_at"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_unit_parent_periods_parent_unit_id",
    "columns": [
      "parent_unit_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_parent_periods_source_document_id",
    "columns": [
      "source_document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_parent_periods_unit_id",
    "columns": [
      "unit_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_unit_parent_periods_period",
    "expression": "((`ends_at` is null) or (`ends_at` > `starts_at`))",
    "enforced": true
  }
]
```

## document_versions

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| document_id | bigint unsigned | False | None | False | None/None |
| version | int unsigned | False | None | False | None/None |
| file_id | bigint unsigned | False | None | False | None/None |
| issued_on | date | True | None | False | None/None |
| supersedes_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_document_versions_file_id",
    "unique": false,
    "columns": [
      "file_id"
    ]
  },
  {
    "name": "ix_document_versions_supersedes_id",
    "unique": false,
    "columns": [
      "supersedes_id"
    ]
  },
  {
    "name": "uq_document_versions_document_id_version",
    "unique": true,
    "columns": [
      "document_id",
      "version"
    ]
  },
  {
    "name": "uq_document_versions_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_document_versions_document_id",
    "columns": [
      "document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_document_versions_file_id",
    "columns": [
      "file_id"
    ],
    "target_table": "files",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_document_versions_supersedes_id",
    "columns": [
      "supersedes_id"
    ],
    "target_table": "document_versions",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_document_versions_version",
    "expression": "(`version` >= 1)",
    "enforced": true
  }
]
```

## physical_locations

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| address_id | bigint unsigned | False | None | False | None/None |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| latitude | decimal(9,6) | True | None | False | None/None |
| longitude | decimal(10,6) | True | None | False | None/None |
| geocode_accuracy | varchar(64) | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| public_visibility | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_physical_locations_address_id",
    "unique": false,
    "columns": [
      "address_id"
    ]
  },
  {
    "name": "ix_physical_locations_latitude_longitude_id",
    "unique": false,
    "columns": [
      "latitude",
      "longitude",
      "id"
    ]
  },
  {
    "name": "uq_physical_locations_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_physical_locations_address_id",
    "columns": [
      "address_id"
    ],
    "target_table": "addresses",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_physical_locations_coordinates",
    "expression": "(((`latitude` is null) and (`longitude` is null)) or ((`latitude` is not null) and (`longitude` is not null) and (`latitude` between -(90) and 90) and (`longitude` between -(180) and 180)))",
    "enforced": true
  },
  {
    "name": "ck_physical_locations_visibility",
    "expression": "(`public_visibility` in (_utf8mb4\\'PRIVATE\\',_utf8mb4\\'APPROVED_PUBLIC\\'))",
    "enforced": true
  }
]
```

## properties

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| public_id | char(26) | False | None | False | ascii/ascii_bin |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| location_id | bigint unsigned | False | None | False | None/None |
| owner_person_id | bigint unsigned | True | None | False | None/None |
| owner_name_external | varchar(191) | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| ownership_status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_properties_location_id",
    "unique": false,
    "columns": [
      "location_id"
    ]
  },
  {
    "name": "ix_properties_owner_person_id",
    "unique": false,
    "columns": [
      "owner_person_id"
    ]
  },
  {
    "name": "uq_properties_code",
    "unique": true,
    "columns": [
      "code"
    ]
  },
  {
    "name": "uq_properties_public_id",
    "unique": true,
    "columns": [
      "public_id"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_properties_location_id",
    "columns": [
      "location_id"
    ],
    "target_table": "physical_locations",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_properties_owner_person_id",
    "columns": [
      "owner_person_id"
    ],
    "target_table": "people",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[]
```

## occupation_types

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| code | varchar(64) | False | None | False | utf8mb4/utf8mb4_bin |
| name | varchar(191) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| is_active | tinyint unsigned | False | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "uq_occupation_types_code",
    "unique": true,
    "columns": [
      "code"
    ]
  }
]
```

### foreign_keys

```json
[]
```

### checks

```json
[
  {
    "name": "ck_occupation_types_is_active",
    "expression": "(`is_active` in (0,1))",
    "enforced": true
  }
]
```

## unit_location_links

PK: id

| Coluna | Tipo f?sico | Nullable | Default | Auto increment | Charset/collation |
|---|---|---|---|---|---|
| id | bigint unsigned | False | None | True | None/None |
| unit_id | bigint unsigned | False | None | False | None/None |
| location_id | bigint unsigned | False | None | False | None/None |
| property_id | bigint unsigned | True | None | False | None/None |
| occupation_type_id | bigint unsigned | False | None | False | None/None |
| is_primary | tinyint unsigned | False | None | False | None/None |
| status | varchar(64) | False | None | False | utf8mb4/utf8mb4_unicode_ci |
| starts_at | datetime(6) | False | None | False | None/None |
| ends_at | datetime(6) | True | None | False | None/None |
| reason | text | True | None | False | utf8mb4/utf8mb4_unicode_ci |
| source_document_id | bigint unsigned | True | None | False | None/None |
| created_at | datetime(6) | False | None | False | None/None |
| lock_version | int unsigned | False | 0 | False | None/None |

### indexes

```json
[
  {
    "name": "ix_unit_location_links_location_id_starts_at",
    "unique": false,
    "columns": [
      "location_id",
      "starts_at"
    ]
  },
  {
    "name": "ix_unit_location_links_occupation_type_id",
    "unique": false,
    "columns": [
      "occupation_type_id"
    ]
  },
  {
    "name": "ix_unit_location_links_property_id",
    "unique": false,
    "columns": [
      "property_id"
    ]
  },
  {
    "name": "ix_unit_location_links_source_document_id",
    "unique": false,
    "columns": [
      "source_document_id"
    ]
  },
  {
    "name": "ix_unit_location_links_unit_id_starts_at",
    "unique": false,
    "columns": [
      "unit_id",
      "starts_at"
    ]
  }
]
```

### foreign_keys

```json
[
  {
    "name": "fk_unit_location_links_location_id",
    "columns": [
      "location_id"
    ],
    "target_table": "physical_locations",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_location_links_occupation_type_id",
    "columns": [
      "occupation_type_id"
    ],
    "target_table": "occupation_types",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_location_links_property_id",
    "columns": [
      "property_id"
    ],
    "target_table": "properties",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_location_links_source_document_id",
    "columns": [
      "source_document_id"
    ],
    "target_table": "legal_documents",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  },
  {
    "name": "fk_unit_location_links_unit_id",
    "columns": [
      "unit_id"
    ],
    "target_table": "organizational_units",
    "target_columns": [
      "id"
    ],
    "on_delete": "RESTRICT",
    "on_update": "RESTRICT"
  }
]
```

### checks

```json
[
  {
    "name": "ck_unit_location_links_is_primary",
    "expression": "(`is_primary` in (0,1))",
    "enforced": true
  },
  {
    "name": "ck_unit_location_links_period",
    "expression": "((`ends_at` is null) or (`ends_at` > `starts_at`))",
    "enforced": true
  }
]
```
