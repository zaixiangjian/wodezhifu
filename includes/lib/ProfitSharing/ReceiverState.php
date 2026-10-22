<?php
namespace lib\ProfitSharing;

/** Exact receiver-set contract shared by the two multi-receiver providers. */
class ReceiverState
{
    public static function expected($amount, $info){
        $out=[];
        foreach($info as $r){
            $money=round(floor($amount*$r['rate'])/100,2);
            $account=(string)$r['account'];
            if($account==='' || isset($out[$account]))throw new \RuntimeException('接收方为空或重复');
            $out[$account]=\lib\Finance::cents(number_format($money,2,'.',''));
        }
        if(!$out)throw new \RuntimeException('接收方为空');
        return $out;
    }

    public static function aggregate($expected,$rows,$accountField,$minor,$success,$failed,$stateField){
        if(!$expected || !is_array($rows) || count($rows)!==count($expected))return ['code'=>0,'status'=>0,'reason'=>'接收方结果不完整，待核对'];
        $seen=[];$ok=0;$fail=0;
        foreach($rows as $r){
            $a=(string)($r[$accountField]??'');
            if(!array_key_exists($a,$expected) || isset($seen[$a]))return ['code'=>0,'status'=>0,'reason'=>'接收方身份不符，待核对'];
            $seen[$a]=true;
            $raw=$r['amount']??null;
            try{
                if($minor){if(!is_int($raw) || $raw<0)throw new \RuntimeException('金额格式');$n=$raw;}
                else $n=\lib\Finance::cents($raw);
            }catch(\Throwable $e){return ['code'=>0,'status'=>0,'reason'=>'接收方金额无效'];}
            if($n!==$expected[$a])return ['code'=>0,'status'=>0,'reason'=>'接收方金额不符，待核对'];
            if(($r[$stateField]??'')===$success)$ok++;
            elseif(($r[$stateField]??'')===$failed)$fail++;
        }
        if($ok===count($expected))return ['code'=>0,'status'=>1];
        if($fail===count($expected))return ['code'=>0,'status'=>2,'reason'=>'全部接收方分账失败'];
        return ['code'=>0,'status'=>0,'reason'=>'部分成功或处理中，待核对（禁止全额确认/重付）'];
    }

    // Separate keys leave the immutable submission snapshot unchanged.
    public static function save($key,$value){
        global $DB;
        return \lib\Finance::transaction(function()use($DB,$key,$value){
            $old=\lib\Finance::row('SELECT payload FROM pre_funds_snapshot WHERE event_key=:k FOR UPDATE',[':k'=>$key]);
            $data=['payload'=>json_encode($value,JSON_THROW_ON_ERROR)];
            if($old)\lib\Finance::checked($DB->update('funds_snapshot',$data,['event_key'=>$key]));
            else \lib\Finance::checked($DB->insert('funds_snapshot',$data+['event_key'=>$key,'addtime'=>'NOW()']));
        });
    }
}
