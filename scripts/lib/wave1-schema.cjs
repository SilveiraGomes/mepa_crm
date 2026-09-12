'use strict';

// Parse the DDL emitted by executing Laravel up()/down() against a recorder.
// This is static validation, never a substitute for information_schema.
function parseCreate(sql) {
  const match = /^CREATE TABLE `([a-z_]+)` \(\n([\s\S]+)\n\) ENGINE=(\w+) DEFAULT CHARACTER SET (\w+) COLLATE (\w+)$/.exec(sql);
  if (!match) throw new Error('Unsupported emitted CREATE TABLE syntax');
  const [, name, body, engine, charset, collation] = match;
  const table = { name, engine, charset, collation, columns: [], primary: [], indexes: [], foreign_keys: [], checks: [] };
  const columns = s => s.split(',').map(c => {
    if (!/^`\w+`$/.test(c)) throw new Error('Invalid identifier list'); return c.slice(1,-1);
  });
  for (let line of body.split('\n')) {
    line = line.trim().replace(/,$/,'');
    let m;
    if ((m = /^`(\w+)` ([A-Z]+(?:\(\d+(?:,\d+)?\))?(?: UNSIGNED)?)(?: CHARACTER SET (\w+) COLLATE (\w+))? (NOT NULL|NULL)( AUTO_INCREMENT)?(?: DEFAULT (NULL|0))?$/.exec(line))) {
      table.columns.push({ name:m[1], type:m[2].toLowerCase(), charset:m[3]||null, collation:m[4]||null,
        nullable:m[5]==='NULL', auto_increment:!!m[6], default:m[7]==='0'?'0':null });
    } else if ((m = /^PRIMARY KEY \((.+)\)$/.exec(line))) table.primary = columns(m[1]);
    else if ((m = /^(UNIQUE KEY|KEY) `(\w+)` \((.+)\)$/.exec(line))) table.indexes.push({name:m[2],columns:columns(m[3]),unique:m[1]==='UNIQUE KEY'});
    else if ((m = /^CONSTRAINT `(\w+)` FOREIGN KEY \((.+)\) REFERENCES `(\w+)` \((.+)\) ON DELETE (RESTRICT) ON UPDATE (RESTRICT)$/.exec(line)))
      table.foreign_keys.push({name:m[1],columns:columns(m[2]),target_table:m[3],target_columns:columns(m[4]),on_delete:m[5],on_update:m[6]});
    else if ((m = /^CONSTRAINT `(\w+)` CHECK \((.+)\)$/.exec(line))) table.checks.push({name:m[1],expression:m[2]});
    else throw new Error(`Unsupported emitted DDL clause: ${line}`);
  }
  return table;
}

// AST comparison preserves boolean grouping while ignoring MySQL's extra parentheses.
function expressionAst(expression) {
  const input = expression.replace(/\\'([A-Z0-9_]+)\\'/gi, "'$1'").replace(/_(?:utf8mb4|ascii)(?=')/gi,'').replaceAll('`','');
  const tokens = input.match(/'(?:''|[^'])*'|<>|>=|<=|=|>|<|[(),-]|\d+(?:\.\d+)?|[A-Za-z_]\w*/g) || [];
  if (tokens.join('').toLowerCase() !== input.replace(/\s+(?=(?:[^']*'[^']*')*[^']*$)/g,'').toLowerCase()) throw new Error('Unsupported CHECK tokens');
  let pos = 0;
  const peek = () => tokens[pos]?.toUpperCase();
  const eat = x => { if (peek()!==x) throw new Error(`Expected ${x} in CHECK`); pos++; };
  function atom() {
    if (peek()==='(') { pos++; const value = or(); eat(')'); return value; }
    if (peek()==='-') { pos++; return ['negative',atom()]; }
    const t=tokens[pos++]; if (!t) throw new Error('Missing CHECK operand');
    return t.startsWith("'") ? ['literal',t] : /^\d/.test(t) ? ['number',t] : ['column',t.toLowerCase()];
  }
  function comparison() {
    const left=atom(), op=peek();
    if (op==='IS') { pos++; const not=peek()==='NOT'; if(not)pos++; eat('NULL'); return [not?'is_not_null':'is_null',left]; }
    if (op==='IN') { pos++; eat('('); const values=[atom()]; while(peek()===','){pos++;values.push(atom());}eat(')');return ['in',left,...values]; }
    if (op==='BETWEEN') {pos++;const low=atom();eat('AND');return ['between',left,low,atom()];}
    if (['=','<>','>=','<=','>','<'].includes(op)) {pos++;return [op,left,atom()];}
    return left;
  }
  function and() { let left=comparison();while(peek()==='AND'){pos++;left=['and',left,comparison()];}return left; }
  function or() { let left=and();while(peek()==='OR'){pos++;left=['or',left,and()];}return left; }
  const ast=or(); if(pos!==tokens.length)throw new Error('Trailing CHECK expression');return ast;
}
const same = (a,b) => JSON.stringify(a)===JSON.stringify(b);
function compare(expected, actual) {
  const errors=[];
  const fail = (where, a, b) => errors.push({path:where,expected:a,actual:b??null});
  for(const e of expected) {
    const a=actual.find(t=>t.name===e.name);
    if(!a){fail(e.name,'table exists','missing');continue;}
    for(const k of ['engine','charset','collation','primary'])if(!same(e[k],a[k]))fail(e.name+'.'+k,e[k],a[k]);
    for(const c of e.columns) {
      const ac=a.columns.find(x=>x.name===c.name);
      if(!ac){fail(e.name+'.'+c.name,'column exists','missing');continue;}
      for(const k of ['type','nullable','default','auto_increment','charset','collation'])if(!same(c[k],ac[k]))fail(e.name+'.'+c.name+'.'+k,c[k],ac[k]);
    }
    for(const c of a.columns)if(!e.columns.some(x=>x.name===c.name))fail(e.name+'.'+c.name,'no extra column','present');
    for(const k of ['indexes','foreign_keys','checks']) {
      for(const item of e[k]) {
        const ai=a[k].find(x=>x.name===item.name);
        if(!ai){fail(e.name+'.'+item.name,item,'missing');continue;}
        const fields=k==='indexes'?['columns','unique']:k==='foreign_keys'?['columns','target_table','target_columns','on_delete','on_update']:[];
        for(const f of fields)if(!same(item[f],ai[f]))fail(e.name+'.'+item.name+'.'+f,item[f],ai[f]);
        if(k==='indexes'&&ai.prefix_length)fail(e.name+'.'+item.name,'full index','prefix index');
        if(k==='checks') {
          if(ai.enforced===false)fail(e.name+'.'+item.name,'ENFORCED',false);
          try {if(!same(expressionAst(item.expression),expressionAst(ai.expression)))fail(e.name+'.'+item.name,item.expression,ai.expression);}
          catch(err){fail(e.name+'.'+item.name,'parseable CHECK',{error:err.message,expression:ai.expression});}
        }
      }
      for(const ai of a[k])if(!e[k].some(x=>x.name===ai.name))fail(e.name+'.'+ai.name,'no undocumented constraint/index','present');
    }
  }
  for(const a of actual)if(!expected.some(e=>e.name===a.name))fail(a.name,'Wave 1 only','extra table');
  return errors;
}
module.exports={parseCreate,expressionAst,compare};
