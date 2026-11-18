'use strict';
// Compare the real installed site's STRUCTURE-ONLY report with the freshly
// installed source schema under Docker + Playwright. No real data is imported.
const fs = require('fs');
const mysql = require('mysql2/promise');
const { compareSchemas } = require('./schema-compare-core.cjs');
const INPUT = '/suite/existing-schema-report.json';
const OUTPUT = '/suite/output/existing-schema-comparison.json';
const expectedFormat = 'NONIUS_SCHEMA_PREFLIGHT_V1';
async function run(){
  const document = JSON.parse(fs.readFileSync(INPUT,'utf8'));
  if(document.report_type!=='SCHEMA_ONLY_NO_PRODUCTION_RECORDS' ||
     document.source_data_rows_inspected!==false ||
     document.source_data_sanitized!==false ||
     document.real_installed_upgrade_tested!==false ||
     document.production_approval!=='PENDING' ||
     document.schema?.format!==expectedFormat){
    throw Error('Source file is not the approved structure-only NONIUSWTAX preflight report');
  }
  const d = document.schema;
  if(!Array.isArray(d.tables)||d.tables.length<6||
     !Array.isArray(d.columns)||d.columns.length<15||
     !Array.isArray(d.triggers)){
    throw Error('Source structural metadata is missing required object inventory');
  }
  const conn=await mysql.createConnection({
    host:process.env.DB_HOST||'db',
    user:process.env.DB_USER||'nonius_test',
    password:process.env.DB_PASSWORD||'local-release-password-only',
    database:process.env.DB_NAME||'nonius_release_test',
    connectTimeout:10000
  });
  let current;
  try{
    const expectedDatabase=process.env.DB_NAME||'nonius_release_test';
    const get=async(sql)=> (await conn.execute(sql,[expectedDatabase]))[0];
    current={
      tables:await get('SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=?'),
      columns:await get('SELECT TABLE_NAME AS `table`, COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS `key`, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=?'),
      foreign_keys:await get('SELECT TABLE_NAME AS `table`, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS references_table, REFERENCED_COLUMN_NAME AS references_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL'),
      indexes:await get('SELECT TABLE_NAME AS `table`, INDEX_NAME AS `index`, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS position, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=?'),
      triggers:await get('SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS `table`, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?')
    };
    current.foreign_keys=current.foreign_keys.map(({column_name,...row})=>({...row,column:column_name}));
    current.indexes=current.indexes.map(({column_name,...row})=>({...row,column:column_name}));
  }finally{await conn.end()}
  // No record SELECT was executed; expected schema came from the fresh
  // synthetic Playwright database and actual structure from a zero-row export.
  const comparison=compareSchemas(d,current);
  const result={
    format:'NONIUS_EXISTING_SCHEMA_COMPARISON_V1',
    generated_utc:new Date().toISOString(),
    source_sql_digest:document.source_export_sha256,
    installed_code_version:document.installed_code_version_from_optional_local_snapshot??null,
    source_schema_counts:{
      tables:d.tables.length,columns:d.columns.length,triggers:d.triggers.length,
      foreign_keys:d.foreign_keys.length,indexes:d.indexes.length
    },
    expected_current_source_counts:{
      tables:current.tables.length,columns:current.columns.length,triggers:current.triggers.length,
      foreign_keys:current.foreign_keys.length,indexes:current.indexes.length
    },
    ...comparison,
    source_data_rows_imported:false,
    real_installation_data_sanitized:false,
    historical_migration_records_verified:false,
    checked_transaction_hashes_verified_against_historical_data:false,
    real_installed_upgrade_rollback_tested:false,
    production_approval:'PENDING',
    interpretation:'Differences can reflect older migrations or site customizations. The report is structural only, NOT a safe upgrade certification. Review missing triggers and constraints before cloning historical records.'
  };
  fs.mkdirSync('/suite/output',{recursive:true});
  fs.writeFileSync(OUTPUT,JSON.stringify(result,null,2)+'\n');
  console.log('Existing schema: '+d.tables.length+' tables, '+d.columns.length+' columns, '+d.triggers.length+' triggers');
  console.log('Fresh current schema: '+current.tables.length+' tables, '+current.columns.length+' columns, '+current.triggers.length+' triggers');
  console.log('Structural result: '+result.status+'; missing='+result.missing_structure_items+'; extra='+result.extra_structure_items+'; changed_columns='+result.changed_columns);
  for(const k of ['tables','columns','foreign_keys','indexes','triggers']){
    const c=result.comparison[k];
    if(c.absent_from_installed.length||c.present_only_in_installed.length){
      console.log(k+': absent from installed '+JSON.stringify(c.absent_from_installed.slice(0,20))+
                  '; only installed '+JSON.stringify(c.present_only_in_installed.slice(0,20)));
    }
  }
  console.log('Saved local metadata-only comparison '+OUTPUT);
  console.log('Actual historical data sanitization, upgrade/rollback, and production authorization: PENDING.');
}
run().catch(err=>{console.error('FAIL schema comparison: '+err.message);process.exitCode=1});
