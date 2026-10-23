<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\User;
$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function callApi($method,$url,$data=[],$token=null){global $app,$kernel;$app['auth']->forgetGuards();$headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'];if($token)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;$r=Illuminate\Http\Request::create('/api/'.$url,$method,[],[],[],$headers,json_encode($data));$res=$kernel->handle($r);return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
DB::beginTransaction();
try{
 $tag=bin2hex(random_bytes(5));$users=[];$tokens=[];
 foreach(['admin','moderador','aluno'] as $role){$u=User::create(['name'=>'QA Admin '.$role,'username'=>'qa_'.$tag.$role,'email'=>$tag.$role.'@example.test','password'=>'Testing!123456','role'=>$role]);$users[$role]=$u;$tokens[$role]=$u->createToken('qa-admin')->plainTextToken;}
 foreach(['users','posts','subjects'] as $kind){
  [$code]=callApi('GET','admin/'.$kind);check($code===401,'Anonymous blocked');
  foreach(['aluno','moderador'] as $role){[$code]=callApi('GET','admin/'.$kind,[],$tokens[$role]);check($code===403,'Non-admin blocked');}
  [$code,$list]=callApi('GET','admin/'.$kind,[],$tokens['admin']);check($code===200&&count($list['entries'])<=20,'Admin paginated list');
 }
 $student=$users['aluno'];$data=['name'=>$student->name,'email'=>$student->email,'role'=>'aluno','verified'=>false,'suspended'=>true];
 [$code]=callApi('PATCH','admin/users/'.$student->id,$data,$tokens['moderador']);check($code===403,'Moderator cannot edit users');
 [$code]=callApi('PATCH','admin/users/'.$student->id,$data,$tokens['admin']);check($code===200,'Suspend user');check($student->tokens()->count()===0,'Revoke sessions');
 [$code]=callApi('POST','auth/login',['email'=>$student->email,'password'=>'Testing!123456']);check($code===403,'Suspended login blocked');
 $data['suspended']=false;$data['password']='Changed!123456';
 [$code]=callApi('PATCH','admin/users/'.$student->id,$data,$tokens['admin']);check($code===200,'Reactivate and reset password');
 check(Illuminate\Support\Facades\Hash::check($data['password'],$student->fresh()->password),'Password hashed');
 [$code]=callApi('POST','auth/login',['email'=>$student->email,'password'=>$data['password']]);check($code===200,'New login works');
 $admin=$users['admin'];$self=['name'=>$admin->name,'email'=>$admin->email,'role'=>'aluno','verified'=>false,'suspended'=>false];
 [$code]=callApi('PATCH','admin/users/'.$admin->id,$self,$tokens['admin']);check($code===422,'Cannot demote self');$self['role']='admin';$self['suspended']=true;
 [$code]=callApi('PATCH','admin/users/'.$admin->id,$self,$tokens['admin']);check($code===422,'Cannot suspend self');
 [$code]=callApi('POST','admin/subjects',['name'=>'QA '.$tag],$tokens['admin']);check($code===200,'Create topic');$subject=App\Models\Subject::where('name','QA '.$tag)->firstOrFail();
 [$code]=callApi('PATCH','admin/subjects/'.$subject->id,['name'=>'QA renamed '.$tag],$tokens['admin']);check($code===200,'Rename topic');
 $post=App\Models\Post::create(['user_id'=>$student->id,'subject_id'=>$subject->id,'title'=>'QA','content'=>'Original','status'=>'aprovado']);
 $change=['title'=>'Edited','content'=>'Edited content','status'=>'reprovado'];
 [$code]=callApi('PATCH','admin/posts/'.$post->id,$change,$tokens['aluno']);check($code===401,'Revoked token rejected');
 [$code]=callApi('PATCH','admin/posts/'.$post->id,$change,$tokens['admin']);check($code===200&&$post->fresh()->content==='Edited content','Edit and hide post');
 [$code]=callApi('GET','moderation?type=post',[],$tokens['admin']);check($code===200,'Admin accesses reports');
 echo "PASS: $checks admin checks; all test records rolled back.\n";
}finally{DB::rollBack();}
