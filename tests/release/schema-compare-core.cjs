'use strict';
// Compares structural database metadata only. Does not read application rows.
const pick = (x, ...keys) => keys.map(k => String(x?.[k] ?? '')).join('|');
const keyTable = x => String(x.name ?? '');
const keyColumn = x => pick(x,'table','name');
const keyForeignKey = x => pick(x,'table','column','references_table','references_column');
const keyIndex = x => pick(x,'table','index','column','position','non_unique');
const keyTrigger = x => pick(x,'name','table','timing','event');
const canonicalType = value => String(value ?? '').toLowerCase().replace(/\s+/g,' ').replace(/\binteger\b/g,'int').trim();
function compareCollection(installed, expected, keyFn) {
  if (!Array.isArray(installed) || !Array.isArray(expected)) throw Error('Schema metadata arrays missing');
  const found = new Map(installed.map(x => [keyFn(x), x]));
  const current = new Map(expected.map(x => [keyFn(x), x]));
  if (found.size !== installed.length || current.size !== expected.length) {
    throw Error('Duplicate structural identifiers; refusing ambiguous comparison');
  }
  return {
    installed_count: installed.length,
    expected_count: expected.length,
    absent_from_installed: [...current.keys()].filter(x => !found.has(x)).sort(),
    present_only_in_installed: [...found.keys()].filter(x => !current.has(x)).sort()
  };
}
function compareSchemas(installed, expected) {
  for (const schema of [installed,expected]) {
    if (!schema || typeof schema !== 'object' ||
        !Array.isArray(schema.tables) || !Array.isArray(schema.columns) ||
        !Array.isArray(schema.foreign_keys) || !Array.isArray(schema.indexes) ||
        !Array.isArray(schema.triggers)) {
      throw Error('Incomplete structural metadata: tables, columns, FKs, indexes, and triggers are required');
    }
  }
  const collections = {
    tables:compareCollection(installed.tables,expected.tables,keyTable),
    columns:compareCollection(installed.columns,expected.columns,keyColumn),
    foreign_keys:compareCollection(installed.foreign_keys,expected.foreign_keys,keyForeignKey),
    indexes:compareCollection(installed.indexes,expected.indexes,keyIndex),
    triggers:compareCollection(installed.triggers,expected.triggers,keyTrigger)
  };
  const expectedColumns = new Map(expected.columns.map(x => [keyColumn(x),x]));
  const discrepancies = [];
  for (const col of installed.columns) {
    const reference=expectedColumns.get(keyColumn(col));
    if(!reference)continue;
    const props=['type','nullable','key','extra'];
    const different=props.filter(prop=>
      (prop==='type'?canonicalType(col[prop]):String(col[prop] ?? '').toLowerCase()) !==
      (prop==='type'?canonicalType(reference[prop]):String(reference[prop] ?? '').toLowerCase())
    );
    if(different.length) {
      discrepancies.push({
        field:keyColumn(col),properties:different,
        installed: Object.fromEntries(different.map(k=>[k,String(col[k]??'')])),
        expected: Object.fromEntries(different.map(k=>[k,String(reference[k]??'')]))
      });
    }
  }
  discrepancies.sort((a,b)=>a.field.localeCompare(b.field));
  const missing = Object.values(collections).reduce((s,c)=>s+c.absent_from_installed.length,0);
  const extra = Object.values(collections).reduce((s,c)=>s+c.present_only_in_installed.length,0);
  const status = missing+extra+discrepancies.length ? 'DRIFT_REVIEW_REQUIRED' : 'STRUCTURAL_MATCH';
  return {
    status,
    missing_structure_items:missing,
    extra_structure_items:extra,
    changed_columns:discrepancies.length,
    comparison:collections,
    changed_column_definitions:discrepancies
  };
}
module.exports = {compareSchemas};
