<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\ViolationService;

$pdo=new PDO('mysql:host=127.0.0.1;port=3338;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='violation_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$name}`");
(new ReflectionProperty(Database::class,'connection'))->setValue(null,$pdo);
$checks=0;
function checkViolation(bool $ok,string $label):void { global $checks; if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS {$label}\n"; }
function applySql(PDO $pdo,string $path):void { $text=preg_replace('/^\s*--.*$/m','',file_get_contents($path));foreach(explode(';',$text)as$sql)if(trim($sql)!=='')$pdo->exec($sql); }
try {
    applySql($pdo,BASE_PATH.'/database/schema.sql');
    applySql($pdo,BASE_PATH.'/database/seed.sql');
    applySql($pdo,BASE_PATH.'/database/migrations/20260908_disciplinary_violations.sql');
    applySql($pdo,BASE_PATH.'/database/migrations/20260908_disciplinary_violations.sql');
    $_SESSION['user_id']=1;
    $_SERVER['REQUEST_URI']='/admin/?page=compliance';
    checkViolation(Auth::usesAdminArea(),'administrator retains admin access');
    Auth::setPreviewRole(3);
    $_SERVER['REQUEST_URI']='/index/';
    checkViolation(Auth::user()['role_name']==='Student','student preview changes effective portal role');
    checkViolation(Auth::can('view_grades')&&!Auth::can('manage_users'),'preview uses selected role permissions');
    checkViolation(!Auth::usesAdminArea()&&count(Auth::viewableRoles())>=5,'student preview uses portal and preserves administrator return choices');
    Auth::setPreviewRole(4);
    $_SERVER['REQUEST_URI']='/admin/';
    checkViolation(Auth::user()['role_name']==='Staff'&&Auth::usesAdminArea(),'staff preview uses its management area');
    checkViolation(Auth::can('manage_compliance')&&!Auth::can('manage_users'),'staff management view uses staff permissions');
    Auth::setPreviewRole(1);
    checkViolation(Auth::previewRole()===null,'admin selection exits preview');

    $id=ViolationService::issue([
        'student_id'=>5,'violation_group'=>'Minor offenses','specific_violation'=>'Littering','classification'=>'Category C',
        'is_progressive'=>'1','previous_infractions'=>'2','current_occurrence'=>'3','incident_date'=>date('Y-m-d'),
        'incident_time'=>'9:35 AM','location'=>'Main hallway','incident_description'=>'The observed incident was documented by campus staff.','witnesses'=>'TCH-001',
    ],1);
    $row=$pdo->query("SELECT * FROM disciplinary_violations WHERE id={$id}")->fetch();
    checkViolation($row&&$row['specific_violation']==='Littering'&&$row['incident_time']==='09:35:00','violation persists normalized incident data');
    checkViolation((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='issue_violation' AND entity_id='{$id}'")->fetchColumn()===1,'violation creates audit entry');
    try {
        ViolationService::issue(['student_id'=>5,'violation_group'=>'Minor offenses','specific_violation'=>'Invented','classification'=>'Category C','incident_date'=>date('Y-m-d'),'incident_time'=>'09:00','location'=>'Hall','incident_description'=>'A sufficiently detailed incident description.'],1);
        checkViolation(false,'unknown catalog value rejected');
    } catch (DomainException) { checkViolation(true,'unknown catalog value rejected'); }
    echo "{$checks} integration checks passed.\n";
} finally {
    unset($_SESSION['admin_preview_role_id'],$_SESSION['user_id']);
    if(preg_match('/\Aviolation_test_[a-f0-9]{12}\z/',$name))$pdo->exec("DROP DATABASE `{$name}`");
}
