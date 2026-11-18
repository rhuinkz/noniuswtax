'use strict';
const assert = require('node:assert/strict');
const {compareSchemas} = require('./schema-compare-core.cjs');
const base={
  tables:[{name:'schools',kind:'BASE TABLE'},{name:'transactions',kind:'BASE TABLE'}],
  columns:[{table:'schools',name:'id',type:'varchar(64)',nullable:'NO',key:'PRI',extra:''},
           {table:'transactions',name:'gross',type:'bigint unsigned',nullable:'NO',key:'',extra:''}],
  foreign_keys:[],
  indexes:[{table:'schools',index:'PRIMARY',column:'id',position:1,non_unique:0}],
  triggers:[{name:'guard_tx',table:'transactions',timing:'BEFORE',event:'UPDATE'}]
};
const clone = x => JSON.parse(JSON.stringify(x));
assert.equal(compareSchemas(base,clone(base)).status,'STRUCTURAL_MATCH');
const older = clone(base);
older.triggers=[];
let diff=compareSchemas(older,base);
assert.equal(diff.status,'DRIFT_REVIEW_REQUIRED');
assert.deepEqual(diff.comparison.triggers.absent_from_installed,['guard_tx|transactions|BEFORE|UPDATE']);
assert.equal(diff.missing_structure_items,1);
const custom=clone(base);
custom.tables.push({name:'custom_table',kind:'BASE TABLE'});
diff=compareSchemas(custom,base);
assert.deepEqual(diff.comparison.tables.present_only_in_installed,['custom_table']);
const altered=clone(base);
altered.columns[1].type='int';
diff=compareSchemas(altered,base);
assert.equal(diff.changed_columns,1);
assert.equal(diff.changed_column_definitions[0].field,'transactions|gross');
assert.throws(()=>compareSchemas({tables:[]},base),/Incomplete structural/);
console.log('PASS: structural drift core catches missing triggers, custom tables, type changes and missing metadata.');
