'use strict';
// Local synthetic Docker-only regression: simulate a legacy site that lacks
// mandatory migration 037 and verify the normal PHP bootstrap reapplies it.
// This script MUST NEVER be run against an existing deployment or real DB.
const fs=require('node:fs');
const mysql=require('mysql2/promise');
const path='/suite/output/migration037-rehearsal.json';
const phase=process.argv[2];
const M='037_checked_hash_period_guard';
const TRIGGER='protect_checked_hash_period_update';
const TX='synthetic-transaction-a';
const PERIOD='2026-01';
const TARGET='school:synthetic-school-a';
function assert(condition,message){if(!condition)throw Error(message);}
async function sql(db,query,params=[]){return (await db.execute(query,params))[0];}
async function inspect(db){
  const count=async table=> Number((await db.query('SELECT COUNT(*) AS n FROM '+table))[0][0].n);
  const [migrations]=await sql(db,'SELECT status FROM schema_migrations WHERE id=?',[M]);
  const [[tx]]=[await sql(db,'SELECT checked_snapshot_hash FROM transactions WHERE id=?',[TX])];
  const [period]=await sql(db,'SELECT state FROM transaction_periods WHERE period_month=? AND target_key=?',[PERIOD,TARGET]);
  const triggerRows=await sql(db,'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
  return {
    transaction_count: await count('transactions'),
    audit_count:await count('audit'),
    transaction_history_count:await count('transaction_history'),
    migration_count:await count('schema_migrations'),
    triggers:triggerRows.length,
    migration037_status:migrations?.status??'absent',
    guard_present:triggerRows.some(row=>row.name===TRIGGER),
    sample_has_checked_hash:/^[0-9a-f]{64}$/i.test(String(tx?.checked_snapshot_hash??'')),
    sample_hash:String(tx?.checked_snapshot_hash??''),
    sample_period_state:period?.state??'absent'
  };
}
async function main(){
 assert(['prepare','verify'].includes(phase),'Expected prepare or verify only.');
 assert(process.env.DB_HOST==='db'&&process.env.DB_NAME==='nonius_release_test',
   'Refusing anything other than the disposable local synthetic Docker database.');
 const db=await mysql.createConnection({
    host:'db',user:'nonius_test',password:'local-release-password-only',
    database:'nonius_release_test',connectTimeout:10000
 });
 try{
  if(phase==='prepare'){
   if(fs.existsSync(path))fs.unlinkSync(path);
   const before=await inspect(db);
   assert(before.transaction_count>=10003,'Fresh 10k synthetic dataset is not prepared.');
   assert(before.triggers===15&&before.guard_present&&before.migration037_status==='applied',
     'Required clean 15-trigger source baseline is not present.');
   assert(before.sample_has_checked_hash&&before.sample_period_state!=='absent',
     'Synthetic checked hash and period fixture not present.');
   // We only remove one trigger and its applied migration ID, on disposable DB.
   await db.query('DROP TRIGGER `protect_checked_hash_period_update`');
   await sql(db,'DELETE FROM schema_migrations WHERE id=?',[M]);
   const simulated=await inspect(db);
   assert(simulated.triggers===14&&!simulated.guard_present&&simulated.migration037_status==='absent',
     'Could not create the expected synthetic old 14-trigger schema.');
   assert(simulated.transaction_count===before.transaction_count&&
      simulated.audit_count===before.audit_count&&
      simulated.transaction_history_count===before.transaction_history_count&&
      simulated.sample_hash===before.sample_hash,
     'Fixture corruption while preparing simulated old schema.');
   const record={
      status:'prepared',type:'SYNTHETIC_LEGACY_MIGRATION_037_REHEARSAL',
      original_version:'0.4.56',real_existing_site_used:false,
      actual_history_sanitized:false,production_approval:'PENDING',
      initial:before,simulated_old_schema:simulated
   };
   fs.writeFileSync(path,JSON.stringify(record,null,2)+'\n');
   console.log('PASS: created disposable simulation with 14 triggers, migration 037 absent, records preserved.');
  } else {
   assert(fs.existsSync(path),'Missing same-run preparation record.');
   const record=JSON.parse(fs.readFileSync(path,'utf8'));
   assert(record.status==='prepared'&&record.simulated_old_schema?.triggers===14,
     'Invalid or stale migration 037 rehearsal state.');
   const after=await inspect(db);
   assert(after.guard_present&&after.triggers===15&&after.migration037_status==='applied',
     'Normal bootstrap did not restore required migration 037 and trigger.');
   assert(after.transaction_count===record.initial.transaction_count&&
      after.audit_count===record.initial.audit_count&&
      after.transaction_history_count===record.initial.transaction_history_count&&
      after.sample_hash===record.initial.sample_hash,
      'Synthetic historical transaction, audit or hash preservation failed.');
   // Demonstrate that the restored trigger blocks hash-only tampering in
   // a closed month, not merely that its name appears in SHOW TRIGGERS.
   await sql(db,"UPDATE transaction_periods SET state='closed' WHERE period_month=? AND target_key=?",[PERIOD,TARGET]);
   let denied=false;
   try{
      await sql(db,'UPDATE transactions SET checked_snapshot_hash=SHA2(?,256) WHERE id=?',
       ['forbidden-after-local-migration',TX]);
   }catch(e){
      denied=/period|closed|certificate hash/i.test(String(e.message));
   }
   assert(denied,'Closed-month checked hash modification was not refused by restored trigger.');
   const [verification]=await sql(db,'SELECT checked_snapshot_hash FROM transactions WHERE id=?',[TX]);
   assert(verification?.checked_snapshot_hash===record.initial.sample_hash,
       'Checked hash changed during rejected tamper attempt.');
   const result={...record,status:'passed',post_migration:after,
     checks:{trigger_restored:true,migration037_applied:true,
       transaction_count_preserved:true,audit_count_preserved:true,
       history_count_preserved:true,original_checked_hash_preserved:true,
       closed_month_hash_tamper_rejected:true},
     conclusion:'Simulated local migration 036 to 037 succeeded using fictional records only. Not evidence of live installed migration state or actual-data-safe upgrade.'};
   fs.writeFileSync(path,JSON.stringify(result,null,2)+'\n');
   console.log('PASS: disposable 14-trigger -> 15-trigger migration, records preserved, closed-month hash tamper blocked.');
  }
 }finally{await db.end();}
}
main().catch(e=>{console.error('FAIL synthetic migration rehearsal: '+e.message);process.exitCode=1;});
