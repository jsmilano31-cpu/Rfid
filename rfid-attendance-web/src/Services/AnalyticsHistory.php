<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

/** Point-in-time lookup. Loaded once per source, with binary search per identity. */
final class AnalyticsHistory
{
    private array $sources=[];
    private ?array $gradeIndex=null;
    public function __construct(private PDO $pdo,private ?string $gradeScope=null) {}
    private function load(string $table): void
    {
        if(isset($this->sources[$table]))return;
        if(!isset(AnalyticsSchema::SOURCES[$table]))throw new \DomainException('Unknown history source.');
        $this->sources[$table]=[];
        $sql='SELECT h.record_key,h.valid_from,h.operation,h.payload FROM analytics_history h WHERE h.source_table=?';
        if($table==='grades' && $this->gradeScope!==null)$sql.=' AND EXISTS(SELECT 1 FROM grades g WHERE CAST(g.id AS CHAR)=h.record_key AND ('.$this->gradeScope.'))';
        $q=$this->pdo->prepare($sql.' ORDER BY h.record_key,h.valid_from,h.id');$q->execute([$table]);
        while($r=$q->fetch()){$r['payload']=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);if(isset($r['payload']['_policy']) && is_string($r['payload']['_policy']))$r['payload']['_policy']=json_decode($r['payload']['_policy'],true,512,JSON_THROW_ON_ERROR);$this->sources[$table][$r['record_key']][]=$r;}
    }
    public function at(string $table,int|string $key,string $instant): ?array
    {
        $this->load($table);$rows=$this->sources[$table][(string)$key]??[];$lo=0;$hi=count($rows)-1;$found=-1;
        if(strlen($instant)===19)$instant.='.999999';
        while($lo<=$hi){$mid=intdiv($lo+$hi,2);if($rows[$mid]['valid_from']<=$instant){$found=$mid;$lo=$mid+1;}else$hi=$mid-1;}
        return $found<0 || $rows[$found]['operation']==='DELETE'?null:$rows[$found]['payload'];
    }
    public function all(string $table,string $instant): array
    {
        $this->load($table);$out=[];foreach(array_keys($this->sources[$table]) as $key){$row=$this->at($table,$key,$instant);if($row!==null)$out[(string)$key]=$row;}return $out;
    }
    public function during(string $table,string $from,string $through): array
    {
        $this->load($table);$out=[];
        foreach($this->sources[$table] as $versions)foreach($versions as $i=>$row){$end=$versions[$i+1]['valid_from']??'9999-12-31';if($row['operation']!=='DELETE' && $row['valid_from']<=$through && $end>$from)$out[]=$row['payload'];}return $out;
    }
    public function finalizedGrade(int $offering,int $student,string $instant): ?array
    {
        $this->load('grades');
        if($this->gradeIndex===null){$this->gradeIndex=[];foreach($this->sources['grades'] as $rows)foreach($rows as $r){$p=$r['payload'];if(!in_array($r['operation'],['BASELINE','DELETE'],true) && $p['status']==='finalized')$this->gradeIndex[$p['class_offering_id'].'/'.$p['student_id'].'/'.$p['finalized_at']]=$p;}}
        return $this->gradeIndex[$offering.'/'.$student.'/'.$instant]??null;
    }
}
