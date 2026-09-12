<?php
/** Synthetic architecture specification. NEVER load production configuration. */
declare(strict_types=1);
function db(): PDO {
    $dsn = getenv('ARCH_DB_DSN') ?: '';
    if (getenv('ARCH_DB_ALLOW_SYNTHETIC') !== '1' || $dsn !== 'mysql:host=127.0.0.1;port=33079;dbname=p02f_architecture;charset=utf8mb4') {
        throw new RuntimeException('Requires explicit isolated localhost:33079/p02f_architecture opt-in');
    }
    return new PDO($dsn, getenv('ARCH_DB_USER') ?: 'root', getenv('ARCH_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function money(string $s): int {
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D', $s, $m)) throw new RuntimeException('INVALID_DECIMAL');
    return (int)$m[1]*10000+(int)str_pad($m[2]??'',4,'0');
}
function decimal(int $n): string { return intdiv($n,10000).'.'.str_pad((string)($n%10000),4,'0',STR_PAD_LEFT); }
function query(PDO $p,string $sql,array $args=[]): PDOStatement { $q=$p->prepare($sql);$q->execute($args);return $q; }
function tx(PDO $p,callable $f) { $p->beginTransaction();try {$v=$f();$p->commit();return $v;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;} }
function lockPeriod(PDO $p): void {
    if(query($p,'SELECT status FROM proto_periods WHERE id=1 FOR UPDATE')->fetchColumn()!=='OPEN')throw new RuntimeException('PERIOD_CLOSED');
    if(getenv('ARCH_WORKER_HOLD')==='1')usleep(350000);
}
/** Lock order for ALL prototype writers: period -> transfer -> sorted accounts -> entry. */
function publish(PDO $p,string $key,array $lines,?int $original=null): int {
    $hash=hash('sha256',json_encode([$lines,$original],JSON_THROW_ON_ERROR));
    $old=query($p,'SELECT id,request_hash FROM proto_entries WHERE client_key=? FOR UPDATE',[$key])->fetch(PDO::FETCH_ASSOC);
    if($old){if($old['request_hash']!==$hash)throw new RuntimeException('IDEMPOTENCY_CONFLICT');return (int)$old['id'];}
    if(count($lines)<2)throw new RuntimeException('TOO_FEW_LINES');
    $debit=0;$credit=0;$byUnit=[];$ids=[];
    foreach($lines as [$account,$d,$c]){
        $di=money($d);$ci=money($c);if(($di>0)==($ci>0))throw new RuntimeException('INVALID_LINE');
        $ids[]=$account;$debit+=$di;$credit+=$ci;
    }
    if($debit!==$credit)throw new RuntimeException('UNBALANCED');
    sort($ids,SORT_NUMERIC);
    foreach(array_unique($ids) as $id){$unit=query($p,'SELECT unit_id FROM proto_accounts WHERE id=? FOR UPDATE',[$id])->fetchColumn();if($unit===false)throw new RuntimeException('ACCOUNT_MISSING');}
    foreach($lines as [$account,$d,$c]){$unit=query($p,'SELECT unit_id FROM proto_accounts WHERE id=?',[$account])->fetchColumn();$byUnit[$unit]=($byUnit[$unit]??0)+money($d)-money($c);}
    if(array_filter($byUnit))throw new RuntimeException('UNIT_UNBALANCED');
    query($p,'INSERT INTO proto_entries(client_key,request_hash,status,reversal_of) VALUES(?,?,?,?)',[$key,$hash,'DRAFT',$original]);$id=(int)$p->lastInsertId();
    foreach($lines as [$a,$d,$c])query($p,'INSERT INTO proto_lines(entry_id,account_id,debit,credit) VALUES(?,?,?,?)',[$id,$a,$d,$c]);
    query($p,"UPDATE proto_entries SET status='POSTED' WHERE id=?",[$id]);return $id;
}

function setPeriodStatus(PDO $p,string $target): void {tx($p,function()use($p,$target){$current=query($p,'SELECT status FROM proto_periods WHERE id=1 FOR UPDATE')->fetchColumn();if($current==='CLOSED'&&$target!=='CLOSED')throw new RuntimeException('IMMUTABLE_CLOSED_PERIOD');if(!in_array($target,['OPEN','CLOSED'],true))throw new RuntimeException('INVALID_PERIOD_STATUS');query($p,'UPDATE proto_periods SET status=? WHERE id=1',[$target]);});}

function entry(PDO $p,string $key,array $lines):int {return tx($p,function()use($p,$key,$lines){lockPeriod($p);return publish($p,$key,$lines);});}
function stage(PDO $p,int $transfer,string $stage,string $key): int {
    return tx($p,function()use($p,$transfer,$stage,$key){
        lockPeriod($p);$t=query($p,'SELECT * FROM proto_transfers WHERE id=? FOR UPDATE',[$transfer])->fetch(PDO::FETCH_ASSOC);
        if(!$t)throw new RuntimeException('TRANSFER_MISSING');
        $old=query($p,'SELECT entry_id FROM proto_postings WHERE transfer_id=? AND posting_stage=?',[$transfer,$stage])->fetchColumn();if($old!==false)return (int)$old;
        $a=(int)$t['origin_unit']*10;$b=(int)$t['destination_unit']*10;$v=$t['amount'];
        if($stage==='SEND') {if($t['status']!=='PENDING')throw new RuntimeException('INVALID_STAGE');$lines=[[$a+2,$v,'0'],[$a+1,'0',$v]];$status='SENT';}
        elseif($stage==='RECEIVE') {if($t['status']!=='SENT')throw new RuntimeException('INVALID_STAGE');$lines=[[$b+1,$v,'0'],[$b+4,'0',$v],[$a+3,$v,'0'],[$a+2,'0',$v]];$status='RECEIVED';}
        else throw new RuntimeException('INVALID_STAGE');
        $id=publish($p,$key,$lines);query($p,'INSERT INTO proto_postings VALUES(?,?,?)',[$transfer,$stage,$id]);query($p,'UPDATE proto_transfers SET status=? WHERE id=?',[$status,$transfer]);return $id;
    });
}
function cancelSend(PDO $p,int $transfer,string $key): int {
    return tx($p,function()use($p,$transfer,$key){lockPeriod($p);$t=query($p,'SELECT * FROM proto_transfers WHERE id=? FOR UPDATE',[$transfer])->fetch(PDO::FETCH_ASSOC);
        if($t['status']!=='SENT')throw new RuntimeException('CANNOT_CANCEL_RECEIVED');
        $original=(int)query($p,"SELECT entry_id FROM proto_postings WHERE transfer_id=? AND posting_stage='SEND'",[$transfer])->fetchColumn();
        $rows=query($p,'SELECT account_id,credit,debit FROM proto_lines WHERE entry_id=? ORDER BY id',[$original])->fetchAll(PDO::FETCH_NUM);
        $rows=array_map(fn($r)=>[(int)$r[0],$r[1],$r[2]],$rows);$id=publish($p,$key,$rows,$original);
        query($p,"INSERT INTO proto_postings VALUES(?,'REVERSE_SEND',?)",[$transfer,$id]);query($p,"UPDATE proto_transfers SET status='CANCELLED' WHERE id=?",[$transfer]);return $id;
    });
}
function allocate(PDO $p,string $amount): void {tx($p,function()use($p,$amount){lockPeriod($p);$owed=money(query($p,'SELECT amount FROM proto_debts WHERE id=1 FOR UPDATE')->fetchColumn());$paid=money(query($p,'SELECT COALESCE(SUM(amount),0) FROM proto_allocations WHERE debt_id=1')->fetchColumn());if($paid+money($amount)>$owed)throw new RuntimeException('OVERALLOCATION');query($p,'INSERT INTO proto_allocations(debt_id,amount) VALUES(1,?)',[$amount]);});}
function expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function reject(callable $f,string $message):void{try{$f();}catch(Throwable $e){expect(strpos($e->getMessage(),$message)!==false,'Unexpected rejection '.$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$message);}
function parallelStage(int $id,string $stage):array {
    $dir=sys_get_temp_dir().'/mepa-p02f-barrier-'.bin2hex(random_bytes(8));mkdir($dir);$workers=[];
    try {
        foreach([1,2] as $n){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id,$stage,$dir,(string)$n],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('WORKER_START_FAILED');fclose($pipes[0]);$workers[]=[$proc,$pipes];}
        $deadline=microtime(true)+15;while(!file_exists($dir.'/ready1')||!file_exists($dir.'/ready2')){if(microtime(true)>$deadline)throw new RuntimeException('BARRIER_TIMEOUT');usleep(10000);}file_put_contents($dir.'/go','go');$results=[];
        foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);expect($code===0,'Worker error '.$err.$out);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
        expect($results[0]['pid']!==$results[1]['pid'],'Independent processes required');if($stage==='PUBLISH')expect($results[0]['entry_id']!==$results[1]['entry_id'],'Independent publications');else expect($results[0]['entry_id']===$results[1]['entry_id'],'Concurrent duplicate');expect(max(array_column($results,'elapsed_ms'))>=600,'Second transaction must wait for lock');return $results;
    } finally {foreach(['ready1','ready2','go']as$f)if(file_exists($dir.'/'.$f))unlink($dir.'/'.$f);rmdir($dir);}
}
if(($argv[1]??'')==='worker'){
    try{putenv('ARCH_WORKER_HOLD=1');$p=db();$dir=$argv[4];file_put_contents($dir.'/ready'.$argv[5],(string)getmypid());$deadline=microtime(true)+15;while(!file_exists($dir.'/go')){if(microtime(true)>$deadline)throw new RuntimeException('BARRIER_TIMEOUT');usleep(10000);}$start=microtime(true);$id=$argv[3]==='PUBLISH' ? entry($p,'parallel-entry-'.$argv[5],[[11,'1','0'],[15,'0','1']]) : stage($p,(int)$argv[2],$argv[3],'parallel-'.$argv[2].'-'.$argv[3].'-'.$argv[5]);echo json_encode(['pid'=>getmypid(),'entry_id'=>$id,'elapsed_ms'=>round((microtime(true)-$start)*1000,2)]);exit(0);}catch(Throwable$e){fwrite(STDERR,$e->getMessage());exit(1);}
}
$tests=[];$concurrency=[];
function test(string $name,callable $f):void{global $tests;try{$f();$tests[]=['name'=>$name,'status'=>'PASS'];}catch(Throwable$e){$tests[]=['name'=>$name,'status'=>'FAIL','error'=>$e->getMessage()];}}
try {
    $p=db();query($p,"SET SESSION sql_mode='STRICT_ALL_TABLES'");
    // Fresh fixtures only. The DSN guard excludes all business databases.
    foreach(['proto_allocations','proto_debts','proto_postings','proto_lines','proto_entries','proto_transfers','proto_accounts','proto_periods','proto_units','proto_custody','proto_children','proto_sessions','proto_users']as$t)query($p,'DROP TABLE IF EXISTS '.$t);
    $ddl=[
    "CREATE TABLE proto_periods(id BIGINT PRIMARY KEY,status VARCHAR(16) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE proto_accounts(id BIGINT PRIMARY KEY,unit_id BIGINT NOT NULL,kind VARCHAR(20) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE proto_transfers(id BIGINT PRIMARY KEY,origin_unit BIGINT NOT NULL,destination_unit BIGINT NOT NULL,amount DECIMAL(19,4) NOT NULL,status VARCHAR(20) NOT NULL,CHECK(amount>0),CHECK(origin_unit<>destination_unit)) ENGINE=InnoDB",
    "CREATE TABLE proto_entries(id BIGINT AUTO_INCREMENT PRIMARY KEY,client_key VARCHAR(100) NOT NULL UNIQUE,request_hash CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,reversal_of BIGINT NULL,FOREIGN KEY(reversal_of) REFERENCES proto_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE proto_lines(id BIGINT AUTO_INCREMENT PRIMARY KEY,entry_id BIGINT NOT NULL,account_id BIGINT NOT NULL,debit DECIMAL(19,4) NOT NULL,credit DECIMAL(19,4) NOT NULL,CHECK(debit>=0 AND credit>=0 AND ((debit>0 AND credit=0) OR (credit>0 AND debit=0))),FOREIGN KEY(entry_id) REFERENCES proto_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT,FOREIGN KEY(account_id) REFERENCES proto_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE proto_postings(transfer_id BIGINT NOT NULL,posting_stage VARCHAR(20) NOT NULL,entry_id BIGINT NOT NULL,PRIMARY KEY(transfer_id,posting_stage),UNIQUE(entry_id),FOREIGN KEY(transfer_id) REFERENCES proto_transfers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,FOREIGN KEY(entry_id) REFERENCES proto_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE proto_debts(id BIGINT PRIMARY KEY,amount DECIMAL(19,4) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE proto_allocations(id BIGINT AUTO_INCREMENT PRIMARY KEY,debt_id BIGINT NOT NULL,amount DECIMAL(19,4) NOT NULL,CHECK(amount>0),FOREIGN KEY(debt_id) REFERENCES proto_debts(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE proto_units(id BIGINT PRIMARY KEY,parent_id BIGINT NULL,unit_type_code VARCHAR(32) NOT NULL,status VARCHAR(16) NOT NULL,general_center_key BIGINT GENERATED ALWAYS AS (CASE WHEN unit_type_code='GENERAL_CENTER' AND status<>'CLOSED' THEN parent_id ELSE NULL END) STORED,UNIQUE(general_center_key),FOREIGN KEY(parent_id) REFERENCES proto_units(id) ON DELETE RESTRICT ON UPDATE RESTRICT,CHECK(unit_type_code<>'GENERAL_CENTER' OR parent_id IS NOT NULL)) ENGINE=InnoDB",
    "CREATE TABLE proto_children(id BIGINT PRIMARY KEY) ENGINE=InnoDB",
    "CREATE TABLE proto_custody(id BIGINT AUTO_INCREMENT PRIMARY KEY,child_id BIGINT NOT NULL,session_id BIGINT NOT NULL,checkout_at DATETIME NULL,FOREIGN KEY(child_id) REFERENCES proto_children(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE proto_users(id BIGINT PRIMARY KEY,archived_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE proto_sessions(id BIGINT PRIMARY KEY,user_id BIGINT NOT NULL,revoked_at DATETIME NULL,FOREIGN KEY(user_id) REFERENCES proto_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB"
    ];foreach($ddl as$sql)query($p,$sql);
    query($p,"INSERT INTO proto_periods VALUES(1,'OPEN')");foreach([1,2,3]as$u)foreach([1=>'CASH',2=>'TRANSIT',3=>'DUE_FROM',4=>'DUE_TO',5=>'REVENUE']as$k=>$kind)query($p,'INSERT INTO proto_accounts VALUES(?,?,?)',[$u*10+$k,$u,$kind]);
    query($p,"INSERT INTO proto_transfers VALUES(1,1,2,40000,'PENDING'),(2,2,3,40000,'PENDING'),(3,1,2,10000,'PENDING')");query($p,'INSERT INTO proto_debts VALUES(1,1000)');
    test('T01 balanced external receipt',function()use($p){entry($p,'external-100000',[[11,'100000','0'],[15,'0','100000']]);expect((int)query($p,'SELECT COUNT(*) FROM proto_entries')->fetchColumn()===1,'One publication');});
    test('T02 unbalanced rejected atomically',function()use($p){reject(fn()=>entry($p,'bad',[[11,'10','0'],[15,'0','9']]),'UNBALANCED');expect((int)query($p,"SELECT COUNT(*) FROM proto_entries WHERE client_key='bad'")->fetchColumn()===0,'No residual entry');});
    test('T03 concurrent SEND exactly once',function()use($p){global$concurrency;$concurrency['SEND']=parallelStage(1,'SEND');expect((int)query($p,"SELECT COUNT(*) FROM proto_postings WHERE transfer_id=1 AND posting_stage='SEND'")->fetchColumn()===1,'One SEND');});
    test('T04 concurrent RECEIVE exactly once',function()use($p){global$concurrency;$concurrency['RECEIVE']=parallelStage(1,'RECEIVE');expect((int)query($p,"SELECT COUNT(*) FROM proto_postings WHERE transfer_id=1 AND posting_stage='RECEIVE'")->fetchColumn()===1,'One RECEIVE');});
    test('T05 replay after commit and duplicate receive',function()use($p){$a=stage($p,1,'SEND','lost-response');$b=stage($p,1,'SEND','lost-response');expect($a===$b,'Replay ID');expect(stage($p,1,'RECEIVE','receive-again')===stage($p,1,'RECEIVE','receive-again'),'Receive replay');$x=entry($p,'external-100000',[[11,'100000','0'],[15,'0','100000']]);expect($x===1,'Entry replay');reject(fn()=>entry($p,'external-100000',[[11,'1','0'],[15,'0','1']]),'IDEMPOTENCY_CONFLICT');});
    test('T06 internal movement and national elimination',function()use($p){stage($p,2,'SEND','second-send');stage($p,2,'RECEIVE','second-receive');$revenue=query($p,"SELECT SUM(l.credit-l.debit) FROM proto_lines l JOIN proto_accounts a ON a.id=l.account_id JOIN proto_entries e ON e.id=l.entry_id WHERE a.kind='REVENUE' AND e.status='POSTED'")->fetchColumn();expect(money($revenue)===money('100000'),'Revenue remains 100000');$cash=query($p,"SELECT SUM(l.debit-l.credit) FROM proto_lines l JOIN proto_accounts a ON a.id=l.account_id WHERE a.kind='CASH'")->fetchColumn();expect(money($cash)===money('100000'),'Cash conserved');$pairs=query($p,"SELECT SUM(CASE WHEN a.kind='DUE_FROM' THEN l.debit-l.credit WHEN a.kind='DUE_TO' THEN l.debit-l.credit ELSE 0 END) FROM proto_lines l JOIN proto_accounts a ON a.id=l.account_id")->fetchColumn();expect((string)$pairs==='0.0000','Interunit pairs net zero'); $pairRows=query($p,"SELECT t.transfer_id,SUM(CASE WHEN a.kind='DUE_FROM' THEN l.debit-l.credit ELSE 0 END) asset,SUM(CASE WHEN a.kind='DUE_TO' THEN l.credit-l.debit ELSE 0 END) liability FROM proto_postings t JOIN proto_lines l ON l.entry_id=t.entry_id JOIN proto_accounts a ON a.id=l.account_id WHERE t.posting_stage='RECEIVE' GROUP BY t.transfer_id")->fetchAll(PDO::FETCH_ASSOC);expect(count($pairRows)===2,'Two distinct transfer elimination pairs');foreach($pairRows as$r)expect($r['asset']==='40000.0000'&&$r['liability']==='40000.0000','Elimination matched by transfer');expect(query($p,"SELECT SUM(l.debit-l.credit) FROM proto_lines l JOIN proto_accounts a ON a.id=l.account_id WHERE a.kind='TRANSIT'")->fetchColumn()==='0.0000','Transit cleared');});
    test('T07 injected rollback preserves lines and header',function()use($p){$before=query($p,'SELECT COUNT(*) FROM proto_lines')->fetchColumn();reject(function()use($p){tx($p,function()use($p){lockPeriod($p);publish($p,'rollback',[[11,'7','0'],[15,'0','7']]);throw new RuntimeException('INJECTED_FAILURE');});},'INJECTED_FAILURE');expect(query($p,'SELECT COUNT(*) FROM proto_lines')->fetchColumn()===$before,'Rollback line count');expect((int)query($p,"SELECT COUNT(*) FROM proto_entries WHERE client_key='rollback'")->fetchColumn()===0,'Rollback header');});
    test('T08 confirmed send cancellation references original',function()use($p){$original=stage($p,3,'SEND','cancel-send');$reverse=cancelSend($p,3,'cancel-reversal');expect((int)query($p,'SELECT reversal_of FROM proto_entries WHERE id=?',[$reverse])->fetchColumn()===$original,'Reversal link');expect((int)query($p,'SELECT COUNT(*) FROM (SELECT account_id FROM proto_lines WHERE entry_id IN (?,?) GROUP BY account_id HAVING SUM(debit-credit)<>0) x',[$original,$reverse])->fetchColumn()===0,'Every original account reversed exactly');reject(fn()=>cancelSend($p,1,'bad-cancel'),'CANNOT_CANCEL_RECEIVED');});
    test('T09 partial payment preserves balance',function()use($p){allocate($p,'300');allocate($p,'250');expect(query($p,'SELECT d.amount-COALESCE(SUM(a.amount),0) FROM proto_debts d LEFT JOIN proto_allocations a ON a.debt_id=d.id WHERE d.id=1 GROUP BY d.amount')->fetchColumn()==='450.0000','Remaining 450');reject(fn()=>allocate($p,'451'),'OVERALLOCATION');});
    test('T10 closed period rejects publication',function()use($p){setPeriodStatus($p,'CLOSED');reject(fn()=>entry($p,'closed',[[11,'1','0'],[15,'0','1']]),'PERIOD_CLOSED');reject(fn()=>setPeriodStatus($p,'OPEN'),'IMMUTABLE_CLOSED_PERIOD'); /* fixture reset for independent later cases, not a service transition */ query($p,"UPDATE proto_periods SET status='OPEN' WHERE id=1");});
    test('T11 engine CHECK FK UNIQUE enforcement',function()use($p){reject(fn()=>query($p,"INSERT INTO proto_transfers VALUES(9,1,2,-1,'PENDING')"),'23000');reject(fn()=>query($p,"INSERT INTO proto_lines(entry_id,account_id,debit,credit) VALUES(99999,11,1,0)"),'23000');reject(fn()=>query($p,"INSERT INTO proto_lines(entry_id,account_id,debit,credit) VALUES(1,11,1,1)"),'23000');reject(fn()=>query($p,"INSERT INTO proto_postings VALUES(1,'SEND',1)"),'23000');});
    test('T12 portable generated key general center maximum',function()use($p){query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(1,NULL,'MUNICIPAL_DIRECTION','DRAFT'),(2,1,'GENERAL_CENTER','ACTIVE')");reject(fn()=>query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(3,1,'GENERAL_CENTER','ACTIVE')"),'23000');query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(4,1,'CENTER','ACTIVE'),(5,1,'CENTER','ACTIVE')");query($p,"UPDATE proto_units SET status='CLOSED' WHERE id=2");query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(3,1,'GENERAL_CENTER','ACTIVE')");});
    test('T13 child anchor rejects second open visit',function()use($p){query($p,'INSERT INTO proto_children VALUES(1)');$open=function()use($p){tx($p,function()use($p){query($p,'SELECT id FROM proto_children WHERE id=1 FOR UPDATE');if((int)query($p,'SELECT COUNT(*) FROM proto_custody WHERE child_id=1 AND session_id=1 AND checkout_at IS NULL')->fetchColumn()>0)throw new RuntimeException('OPEN_VISIT_EXISTS');query($p,'INSERT INTO proto_custody(child_id,session_id) VALUES(1,1)');});};$open();reject($open,'OPEN_VISIT_EXISTS');});
    test('T14 archive revokes sessions and request checks actor',function()use($p){query($p,'INSERT INTO proto_users VALUES(1,NULL)');query($p,'INSERT INTO proto_sessions VALUES(1,1,NULL),(2,1,NULL)');tx($p,function()use($p){query($p,'SELECT id FROM proto_users WHERE id=1 FOR UPDATE');query($p,'UPDATE proto_users SET archived_at=UTC_TIMESTAMP() WHERE id=1');query($p,'UPDATE proto_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=1 AND revoked_at IS NULL');});expect((int)query($p,'SELECT COUNT(*) FROM proto_sessions s JOIN proto_users u ON u.id=s.user_id WHERE u.archived_at IS NULL AND s.revoked_at IS NULL')->fetchColumn()===0,'No live request');});

    test('T15 concurrent independent publication same period accounts',function()use($p){global$concurrency;$concurrency['PUBLISH']=parallelStage(0,'PUBLISH');expect((int)query($p,"SELECT COUNT(*) FROM proto_entries WHERE client_key LIKE 'parallel-entry-%' AND status='POSTED'")->fetchColumn()===2,'Both committed once');expect((int)query($p,'SELECT COUNT(*) FROM (SELECT entry_id FROM proto_lines GROUP BY entry_id HAVING SUM(debit)<>SUM(credit)) x')->fetchColumn()===0,'All balanced'); foreach($concurrency['PUBLISH']as$r)tx($p,function()use($p,$r){lockPeriod($p);publish($p,'reverse-parallel-'.$r['entry_id'],[[11,'0','1'],[15,'1','0']],$r['entry_id']);});});
    test('T16 canonical pairs minimum and cycle protocol',function()use($p){
        $manifest=json_decode(file_get_contents(__DIR__.'/../../docs/database/unit_parent_rules.json'),true,512,JSON_THROW_ON_ERROR);
        $validate=function()use($p,$manifest){
            $rows=query($p,'SELECT * FROM proto_units')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
            foreach($rows as$id=>$u){$seen=[$id=>true];$next=$u['parent_id'];while($next!==null){if(isset($seen[$next]))throw new RuntimeException('TREE_CYCLE');$seen[$next]=true;if(!isset($rows[$next]))throw new RuntimeException('PARENT_MISSING');$next=$rows[$next]['parent_id'];}}
            foreach($rows as$id=>$u){
                $parent=$u['parent_id'];$seen=[$id=>true];$next=$parent;
                while($next!==null){if(isset($seen[$next]))throw new RuntimeException('TREE_CYCLE');$seen[$next]=true;if(!isset($rows[$next]))throw new RuntimeException('PARENT_MISSING');$next=$rows[$next]['parent_id'];}
                if($parent===null){if($u['unit_type_code']!==$manifest['root_type'])throw new RuntimeException('NON_ROOT_WITHOUT_PARENT');}
                elseif(!in_array([$rows[$parent]['unit_type_code'],$u['unit_type_code']],$manifest['allowed_parent_child_pairs'],true))throw new RuntimeException('INVALID_PAIR');
                if($u['unit_type_code']==='MUNICIPAL_DIRECTION'&&$u['status']==='ACTIVE'){
                    $centers=0;$general=null;foreach($rows as$cid=>$c)if($c['unit_type_code']==='GENERAL_CENTER'&&$c['parent_id']==$id&&$c['status']!=='CLOSED')$general=$cid;
                    foreach($rows as$c)if($c['unit_type_code']==='CENTER'&&$c['status']==='ACTIVE'&&($c['parent_id']==$id||($general!==null&&$c['parent_id']==$general)))$centers++;
                    if($centers<1)throw new RuntimeException('MUNICIPAL_MINIMUM');
                    foreach($rows as$c)if($c['unit_type_code']==='CENTER'&&$c['status']==='ACTIVE'&&$general!==null&&$c['parent_id']==$id)throw new RuntimeException('GENERAL_CENTER_DEPENDENCY');
                }
            }
        };
        // fixture ancestor chain uses the period row as a synthetic singleton lock;
        // production uses organizational_structure_lock.NATIONAL_TREE, never the period row.
        query($p,"DELETE FROM proto_units WHERE id IN (4,5,3,2)");query($p,'DELETE FROM proto_units WHERE id=1');
        foreach([[10,null,'GENERAL_DIRECTION'],[20,10,'REGIONAL_DIRECTION'],[30,20,'PROVINCIAL_DIRECTION'],[40,30,'MUNICIPAL_DIRECTION']]as[$id,$parent,$kind])query($p,'INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(?,?,?,?)',[$id,$parent,$kind,'DRAFT']);
        $validate();
        reject(function()use($p,$validate){tx($p,function()use($p,$validate){lockPeriod($p);query($p,"UPDATE proto_units SET status='ACTIVE' WHERE id=40");$validate();});},'MUNICIPAL_MINIMUM');
        query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(50,40,'CENTER','ACTIVE'),(60,50,'CONGREGATION','ACTIVE')");
        tx($p,function()use($p,$validate){lockPeriod($p);query($p,"UPDATE proto_units SET status='ACTIVE' WHERE id=40");$validate();});
        reject(function()use($p,$validate){tx($p,function()use($p,$validate){lockPeriod($p);query($p,'UPDATE proto_units SET parent_id=40 WHERE id=60');$validate();});},'INVALID_PAIR');
        reject(function()use($p,$validate){tx($p,function()use($p,$validate){lockPeriod($p);query($p,'UPDATE proto_units SET parent_id=60 WHERE id=50');$validate();});},'TREE_CYCLE');
        reject(function()use($p,$validate){tx($p,function()use($p,$validate){lockPeriod($p);query($p,"UPDATE proto_units SET status='CLOSED' WHERE id=50");$validate();});},'MUNICIPAL_MINIMUM');
        tx($p,function()use($p,$validate){lockPeriod($p);query($p,"INSERT INTO proto_units(id,parent_id,unit_type_code,status) VALUES(45,40,'GENERAL_CENTER','ACTIVE')");query($p,'UPDATE proto_units SET parent_id=45 WHERE id=50');$validate();});
        expect((int)query($p,'SELECT parent_id FROM proto_units WHERE id=50')->fetchColumn()===45,'Atomic reparent');
    });

    $fail=count(array_filter($tests,fn($t)=>$t['status']==='FAIL'));$report=['scope'=>'SYNTHETIC_ARCHITECTURE_ONLY','engine'=>query($p,'SELECT VERSION()')->fetchColumn(),'production_qualification'=>false,'tests'=>$tests,'concurrency'=>$concurrency,'passed'=>count($tests)-$fail,'failed'=>$fail,'national_external_revenue_AOA'=>query($p,"SELECT SUM(l.credit-l.debit) FROM proto_lines l JOIN proto_accounts a ON a.id=l.account_id JOIN proto_entries e ON e.id=l.entry_id WHERE a.kind='REVENUE' AND e.status='POSTED'")->fetchColumn()];
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($fail?1:0);
} catch(Throwable$e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(2);}