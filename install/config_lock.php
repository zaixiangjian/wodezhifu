<?php
// Native installer/updater maintenance shares the runtime configuration mutex.
// This does not authorize online schema maintenance: stop application workers first.
function epay_maintenance_config_lock($pdo, $prefix){
    $s = $pdo->prepare("SELECT CONCAT(DATABASE(), ':', :prefix)");
    if(!$s || !$s->execute([':prefix'=>$prefix.'_'])) throw new RuntimeException('Configuration lock identity failed');
    $identity=$s->fetchColumn();
    if(!is_string($identity)) throw new RuntimeException('Configuration lock identity failed');
    $name=substr('epay:config:'.hash('sha256',$identity),0,64);
    $s=$pdo->prepare('SELECT GET_LOCK(:name, 10)');
    if(!$s || !$s->execute([':name'=>$name]) || (string)$s->fetchColumn()!=='1') throw new RuntimeException('Configuration lock unavailable');
    // Connection-scoped ownership is released on request termination, including errors.
}
