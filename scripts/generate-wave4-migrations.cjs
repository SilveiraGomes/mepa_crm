'use strict';
const fs=require('node:fs');
const crypto=require('node:crypto');
const w=require('./lib/wave4-catalog.cjs');
const migrations=[];
for(const [i,t] of w.orderedTables().entries()) {
 const file=`2026_09_16_${String(i+1).padStart(6,'0')}_wave4_create_${t.name}.php`;
 const sql=w.createSql(w.model(t));
 const body=`<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\n// P0.3.4 approved catalogue. Durable-data rollback: ADR 0013.\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Qualified MySQL required');\n        DB::statement(<<<'SQL'\n${sql}\nSQL\n        );\n    }\n\n    public function down(): void\n    {\n        // The first migration rolled back checks ALL Wave 4 tables before any DROP.\n        foreach (${phpNames(w.names)} as $table) {\n            if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->exists()) {\n                throw new RuntimeException('WAVE4_DURABLE_DATA_ROLLBACK_BLOCKED');\n            }\n        }\n        DB::statement('DROP TABLE \`${t.name}\`');\n    }\n};\n`;
 fs.writeFileSync(w.root+'/apps/api/database/migrations/'+file,body); migrations.push(file);
}
function phpNames(names){return '['+names.map(n=>"'"+n+"'").join(', ')+']';}
const models=w.orderedTables().map(w.model);
const manifest={phase:'P0.3.4',catalog_sha256:crypto.createHash('sha256').update(fs.readFileSync(w.root+'/docs/database/model_catalog.json')).digest('hex'),tables:models.map(t=>t.name),business_tables:w.names,support_tables:[],migrations,rollback_policy:'Refuse rollback of any Wave 4 table while any Wave 4 table contains durable data. Empty rollback drops only Wave 4.'};
fs.writeFileSync(w.root+'/docs/database/physical/wave4_manifest.json',JSON.stringify(manifest,null,2)+'\n');
const stats={migrations:migrations.length,tables:models.length,columns:models.reduce((n,t)=>n+t.columns.length,0),foreign_keys:models.reduce((n,t)=>n+t.foreign_keys.length,0),checks:models.reduce((n,t)=>n+t.checks.length,0),unique:models.reduce((n,t)=>n+t.indexes.filter(i=>i.unique).length,0),public_id:models.filter(t=>t.columns.some(c=>c.name==='public_id')).length,cascade:models.reduce((n,t)=>n+t.foreign_keys.filter(f=>f.on_delete==='CASCADE'||f.on_update==='CASCADE').length,0)};
fs.writeFileSync(w.root+'/docs/database/physical/wave4_planned_stats.json',JSON.stringify(stats,null,2)+'\n');
console.log(stats);
