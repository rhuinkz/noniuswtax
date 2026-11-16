<?php
declare(strict_types=1);
require __DIR__.'/../../lib/calculation.php';
$file=__DIR__.'/tax-golden.json';
$data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(($data['review_status']??'')!=='PENDING_FINANCE_SIGNOFF')throw new RuntimeException('Do not treat tax fixtures as legally approved.');
$count=0;
foreach($data['cases'] as $case){
    $result=calculate_tax(
        amount_to_cents((string)$case['gross']),
        (bool)$case['vat'],(float)$case['business_percent'],(float)$case['ewt_percent']
    );
    foreach($case['expected'] as $field=>$expected){
        if(($result[$field]??null)!==(int)$expected){
            fwrite(STDERR,"FAIL tax fixture ".$case['name']." ".$field.": expected ".$expected.", got ".($result[$field]??'missing')."\n");
            exit(1);
        }
    }
    $count++;
}
if($count<10)throw new RuntimeException('Insufficient tax fixtures.');
foreach(['0','-1','1.234','1000000000.01'] as $invalid){
    try{amount_to_cents($invalid);fwrite(STDERR,"FAIL invalid amount ".$invalid." accepted\n");exit(1);}
    catch(RuntimeException $expected){}
}
echo "PASS: $count independent decimal mechanical golden cases; BIR/legal sign-off PENDING\n";
