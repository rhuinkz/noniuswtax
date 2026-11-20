<?php
declare(strict_types=1);
// Disposable database fixtures for the real PHP/MariaDB concurrent supplier test.
// Only run against the empty database supplied by GitHub Actions.
require dirname(__DIR__) . '/lib/bootstrap.php';
ensure_database();
$pdo=db();
$now=now_iso();
$pass=getenv('NONIUS_TEST_PASSWORD') ?: 'workshop-only-strong-password';
$pdo->prepare('INSERT INTO division (id,name,data,version) VALUES (?,?,?,1)')->execute([
    'division','Fixture Division',json_encode_safe(['tin'=>'12345678900000','address'=>'Test Street','zip'=>'6300','representative'=>'Test Officer','designation'=>'Test','representative_tin'=>'12345678900000'])
]);
foreach (['a','b'] as $letter) {
    $id='test-school-'.$letter;
    $pdo->prepare('INSERT INTO schools (id,name,data,active,version) VALUES (?,?,?,1,1)')->execute([$id,'Test School '.strtoupper($letter),json_encode_safe(['code'=>'T00'.$letter,'address'=>'Fixture City','representative'=>'Test Principal','position'=>'Principal'])]);
    $pdo->prepare('INSERT INTO members (id,username,email,user_id,password_hash,role,school_id,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
        'test-member-'.$letter,'test_school_'.$letter,null,'test-member-'.$letter,password_hash($pass,PASSWORD_DEFAULT),'school_user',$id,1,$now,$now
    ]);
}
foreach ([['test-vat','VAT'],['test-ewt','EWT']] as [$id,$tax]) {
    $pdo->prepare('INSERT INTO atcs (id,name,data,active,version) VALUES (?,?,?,1,1)')->execute([
        $id,strtoupper($id),json_encode_safe(['tax_type'=>$tax,'business_type'=>'Both','transaction_type'=>'All','rate'=>1.0,'effective_from'=>'2020-01-01'])
    ]);
}
$pdo->prepare('INSERT INTO suppliers (id,name,data,tin,active,version) VALUES (?,?,?,?,0,1)')->execute([
    'test-inactive','Inactive Test Supplier',json_encode_safe(['address'=>'Fixture City','zip'=>'6300','business_type'=>'Corporate','vat_status'=>'VAT Registered']),'66611122200000'
]);
$index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suppliers' AND INDEX_NAME='supplier_tin' AND NON_UNIQUE=0")->fetchColumn();
if ((int)$index<1) throw new RuntimeException('Missing unique supplier_tin index');
// Expand race window only in the throwaway GitHub Actions DB.
$pdo->exec('CREATE TRIGGER pr15_supplier_delay BEFORE INSERT ON suppliers FOR EACH ROW DO SLEEP(0.4)');
echo "PASS: fresh schema, unique supplier_tin constraint, fixture school users and race trigger\n";
