<?php
// May be preloaded over SSH stdin without remote source files.
if (!class_exists('EwsCalendarOccurrenceId')) require_once __DIR__ . '/../ews/src/CalendarOccurrenceId.php';
if (!class_exists('EwsCalendarViewQuery')) require_once __DIR__ . '/../ews/src/CalendarViewQuery.php';
if (!class_exists('EwsCalendarOccurrenceResolver')) require_once __DIR__ . '/../ews/src/CalendarOccurrenceResolver.php';
const CR_CORE = 'urn:ietf:params:jmap:core';
const CR_CAL = 'urn:ietf:params:jmap:calendars';
function crCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function crId(string $rid = '2025-03-30T09:00:00', string $account = 'calendar'): string { return EwsCalendarOccurrenceId::encode($account,'base',$rid); }
final class CrFake {
    public array $session; public array $master; public array $events; public array $calls=[];
    public $hook = null; public bool $rights = true; public ?array $remoteSession = null; public int $invalidations = 0; public int $sessionReads = 0;
    public function __construct() {
        $this->session=['state'=>'session1','apiUrl'=>'https://calendar.example/jmap/api','capabilities'=>[CR_CORE=>['maxObjectsInGet'=>2],CR_CAL=>[]], 'primaryAccounts'=>[CR_CORE=>'mail',CR_CAL=>'calendar'], 'accounts'=>['calendar'=>['accountCapabilities'=>[CR_CAL=>['maxExpandedQueryDuration'=>'P365D']]]]];
        $this->master=['id'=>'base','uid'=>'uid','title'=>'private payload','start'=>'2025-03-23T09:00:00','duration'=>'PT1H','timeZone'=>'Europe/Amsterdam','calendarIds'=>['folder'=>true], 'recurrenceRule'=>['frequency'=>'weekly','count'=>3], 'recurrenceOverrides'=>['2025-03-30T09:00:00'=>['start'=>'2025-04-10T12:00:00']]];
        $e=$this->master; unset($e['recurrenceRule'],$e['recurrenceOverrides']);
        $e['id']='synthetic'; $e['baseEventId']='base'; $e['recurrenceId']='2025-03-30T09:00:00'; $e['start']='2025-04-10T12:00:00'; $e['utcStart']='2025-04-10T10:00:00Z';
        $this->events=[$e];
    }
    public function getSession(): array { ++$this->sessionReads; return $this->session; }
    public function invalidateSession(): void { ++$this->invalidations; if ($this->remoteSession !== null) $this->session=$this->remoteSession; }
    public function call(array $calls, array $using): array {
        crCheck(count($calls)===1 && $using===[CR_CORE,CR_CAL], 'capabilities');
        [$method,$args,$tag]=$calls[0]; $this->calls[]=[$method,$args,$tag];
        crCheck(($args['accountId']??null)==='calendar', 'calendar account');
        if ($method==='Calendar/get') {
            crCheck($tag==='cor' && $args['properties']===['id','myRights'] && count($args['ids'])===1, 'calendar tuple');
            $data=['accountId'=>'calendar','state'=>'c1','list'=>[['id'=>$args['ids'][0],'myRights'=>['mayReadItems'=>$this->rights]]],'notFound'=>[]];
        } elseif ($method==='CalendarEvent/get') {
            crCheck(in_array($tag,['cor','cv'],true), 'get tag'); $list=[]; $not=[];
            foreach ($args['ids'] as $id) {
                $event=$id==='base'?$this->master:null;
                if ($event===null) foreach ($this->events as $e) if ($e['id']===$id) { $event=$e; break; }
                if ($event===null) { $not[]=$id; continue; }
                if (isset($args['properties'])) $event=array_intersect_key($event,array_flip($args['properties']));
                elseif ($id!=='base') unset($event['baseEventId'],$event['recurrenceId'],$event['utcStart']);
                $list[]=$event;
            }
            if ($tag==='cor') crCheck($args['ids']===['base'] && !isset($args['properties']), 'default master get');
            $data=['accountId'=>'calendar','state'=>'e1','list'=>$list,'notFound'=>$not];
        } elseif ($method==='CalendarEvent/query') {
            crCheck($tag==='cv' && ($args['expandRecurrences']??null)===true && $args['timeZone']==='Etc/UTC' && $args['sort']===[['property'=>'start','isAscending'=>true]] && $args['calculateTotal']===true, 'query tuple');
            $a=new DateTimeImmutable($args['filter']['after'],new DateTimeZone('UTC')); $b=new DateTimeImmutable($args['filter']['before'],new DateTimeZone('UTC'));
            crCheck($b->getTimestamp()-$a->getTimestamp()===2, 'small window'); $ids=[];
            foreach ($this->events as $e) {
                $start=new DateTimeImmutable($e['utcStart']);
                if (($e['calendarIds'][$args['filter']['inCalendar']]??null)===true && $start->modify('+1 hour')>$a && $start<$b) $ids[]=$e['id'];
            }
            $data=['accountId'=>'calendar','queryState'=>'q1','position'=>$args['position'],'total'=>count($ids),'ids'=>array_slice($ids,$args['position'],$args['limit'])];
        } else throw new RuntimeException('write or unexpected method');
        $response=[[$method,$data,$tag]];
        return $this->hook ? ($this->hook)($method,$args,$response,$tag,$this) : $response;
    }
}
function crResolve(CrFake $f, ?string $id=null): array { return (new EwsCalendarOccurrenceResolver($f))->resolve($id ?? crId()); }
function crReject(CrFake $f, string $label, ?string $id=null): void {
    try { crResolve($f,$id); } catch (Throwable $e) { crCheck(get_class($e)===RuntimeException::class && $e->getMessage()==='Occurrence resolution failed' && $e->getPrevious()===null, $label.' sanitized generic error'); return; }
    throw new RuntimeException($label.' accepted');
}
function crMissing(CrFake $f, string $label): void {
    try { crResolve($f); } catch (Throwable $e) {
        crCheck(get_class($e)===EwsCalendarOccurrenceNotFound::class && $e->getMessage()==='Occurrence not found' && $e->getPrevious()===null, $label.' sanitized not found');
        crCheck($f->invalidations===1, $label.' refreshed session'); return;
    }
    throw new RuntimeException($label.' accepted');
}
$f=new CrFake(); $r=crResolve($f); crCheck($f->invalidations===1, 'fresh session required');
crCheck($r['master']===$f->master && $r['event']['id']==='synthetic' && $r['event']['recurrenceId']==='2025-03-30T09:00:00' && $r['calendar']['id']==='folder', 'moved identity');
$scans=array_values(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/query'));
crCheck(count($scans)===4 && $scans[0][1]['filter']['after']==='2025-03-30T06:59:59' && $scans[2][1]['filter']['after']==='2025-04-10T09:59:59', 'original and moved DST windows');
$f->events[0]['id']='new-synthetic'; crCheck(crResolve($f)['event']['id']==='new-synthetic', 'synthetic changes');
$f=new CrFake(); unset($f->master['recurrenceOverrides']); $f->events[0]['start']='2025-03-30T09:00:00'; $f->events[0]['utcStart']='2025-03-30T07:00:00Z'; crCheck(crResolve($f)['event']['id']==='synthetic','normal DST occurrence');
$f=new CrFake(); $f->master['start']='2025-10-19T09:00:00'; unset($f->master['recurrenceOverrides']); $f->events[0]['start']='2025-10-26T09:00:00'; $f->events[0]['recurrenceId']='2025-10-26T09:00:00'; $f->events[0]['utcStart']='2025-10-26T08:00:00Z'; crCheck(crResolve($f,crId('2025-10-26T09:00:00'))['event']['id']==='synthetic','autumn DST');
$f=new CrFake(); $f->master['recurrenceRule']=['frequency'=>'daily','interval'=>2,'count'=>5]; unset($f->master['recurrenceOverrides']); $f->events[0]['start']='2025-03-27T09:00:00'; $f->events[0]['recurrenceId']='2025-03-27T09:00:00'; $f->events[0]['utcStart']='2025-03-27T08:00:00Z'; crCheck(crResolve($f,crId('2025-03-27T09:00:00'))['event']['id']==='synthetic','daily interval');
$f=new CrFake(); $f->master['calendarIds']=['z'=>true,'a'=>true]; $f->events[0]['calendarIds']=$f->master['calendarIds']; crCheck(crResolve($f)['calendar']['id']==='a','multiple calendars deterministic');
$f=new CrFake(); $ids=json_decode('{"0":true}',true); $f->master['calendarIds']=$ids; $f->events[0]['calendarIds']=$ids; crCheck(crResolve($f)['calendar']['id']==='0','numeric calendar map');
$f=new CrFake(); $ids=json_decode('{"0":true}',true); $f->master['calendarIds']=$ids; $f->events[0]['calendarIds']=$ids; $f->rights=false; crReject($f,'numeric calendar permission');
$f=new CrFake(); $ids=json_decode('{"0":false}',true); $f->master['calendarIds']=$ids; crReject($f,'numeric map denied');
$f=new CrFake(); $ids=array_fill_keys(array_map(fn($i)=>'c'.$i,range(1,16)),true); $f->master['calendarIds']=$ids; $f->events[0]['calendarIds']=$ids; crCheck(crResolve($f)['calendar']['id']==='c1', 'maximum memberships with two windows');
$f=new CrFake(); $f->session['capabilities'][CR_CORE]['maxObjectsInGet']=1; crCheck(crResolve($f)['event']['id']==='synthetic','server get limit one');
$f=new CrFake(); $f->session['capabilities'][CR_CORE]['maxObjectsInGet']=0; crReject($f,'invalid server get limit');
$f=new CrFake(); $f->remoteSession=$f->session; $f->remoteSession['primaryAccounts'][CR_CAL]='calendar-b'; $f->remoteSession['accounts']['calendar-b']=$f->remoteSession['accounts']['calendar']; crReject($f,'primary account changed with old still accessible'); crCheck($f->invalidations===1 && isset($f->session['accounts']['calendar']), 'fresh session still has old account');
$f=new CrFake(); $f->remoteSession=$f->session; $f->remoteSession['apiUrl']='https://other.example/jmap/api'; crReject($f,'server binding changed'); crCheck($f->invalidations===1,'server binding refreshed');
$f=new CrFake(); unset($f->session['apiUrl']); crReject($f,'missing server binding');
$f=new CrFake(); crReject($f,'malformed','bad'); crCheck(!$f->calls,'malformed no calls');
$f=new CrFake(); crReject($f,'forged account',crId('2025-03-30T09:00:00','mail')); crCheck(!$f->calls,'forged no calls');
$f=new CrFake(); unset($f->session['primaryAccounts'][CR_CAL]); crReject($f,'no account');
$f=new CrFake(); $f->rights=false; crReject($f,'denied');
$f=new CrFake(); $f->events=[]; crMissing($f,'deleted occurrence');
crCheck(count(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/query'))===4 && count(array_filter($f->calls,fn($c)=>$c[0]==='Calendar/get'))===2 && count(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/get' && $c[2]==='cor'))===2,'absence requires both complete windows and rechecks');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; crMissing($f,'excluded');
crCheck(count(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/query'))===0 && count(array_filter($f->calls,fn($c)=>$c[0]==='Calendar/get'))===2 && count(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/get' && $c[2]==='cor'))===2, 'excluded revalidates without query');
$f=new CrFake(); $f->events=[]; $f->master['calendarIds']=['z'=>true,'a'=>true]; crMissing($f,'deleted multiple calendars');
crCheck(count(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/query'))===8 && count(array_filter($f->calls,fn($c)=>$c[0]==='Calendar/get'))===4, 'all memberships scanned and reauthorized');
$f=new CrFake(); unset($f->events[0]['baseEventId'],$f->events[0]['recurrenceId']); crReject($f,'identity omitted makes absence uncertain');
$f=new CrFake(); $f->events=[]; $f->rights=false; crReject($f,'missing but denied');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->rights=false; crReject($f,'excluded but denied');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->session['capabilities'][CR_CORE]['maxObjectsInGet']=0; crReject($f,'excluded with invalid server get limit');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->master['recurrenceOverrides']['2025-04-06T09:00:00']=['title'=>'private payload']; crReject($f,'excluded with unsupported override');
$f=new CrFake(); $f->events=[]; $f->remoteSession=$f->session; $f->remoteSession['primaryAccounts'][CR_CAL]='other'; $f->remoteSession['accounts']['other']=$f->remoteSession['accounts']['calendar']; crReject($f,'missing with changed session');
$f=new CrFake(); $f->events=[]; $f->remoteSession=$f->session; $f->remoteSession['state']='session2'; crReject($f,'missing with changed session state');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->remoteSession=$f->session; $f->remoteSession['apiUrl']='https://other.example/jmap/api'; crReject($f,'excluded with changed server');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->remoteSession=$f->session; $f->remoteSession['state']='session2'; crReject($f,'excluded with changed session state');
$f=new CrFake(); unset($f->session['state']); $f->events=[]; crReject($f,'missing session state');
$f=new CrFake(); $f->hook=function() { throw new EwsCalendarOccurrenceNotFound(); }; crReject($f,'untrusted client not-found exception');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['title'=>'x']; crReject($f,'complex override');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['start'=>'2025-03-30T02:30:00']; crReject($f,'DST gap');
$f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['start'=>'2025-10-26T02:30:00']; crReject($f,'DST overlap');
$f=new CrFake(); $f->events[0]['start']='2025-04-11T12:00:00'; crReject($f,'wrong start');
$f=new CrFake(); $f->events[0]['uid']='other'; crReject($f,'wrong uid');
$f=new CrFake(); $f->events[0]['baseEventId']='other'; crMissing($f,'wrong base not a match');
$f=new CrFake(); $f->events[0]['recurrenceId']='2025-04-06T09:00:00'; crMissing($f,'wrong original recurrence not a match');
$f=new CrFake(); $f->events[0]['calendarIds']['extra']=true; crReject($f,'membership mismatch');
$f=new CrFake(); $f->events[0]['utcStart']='2025-04-10T10:00:00.500000Z'; crReject($f,'inconsistent UTC');
$f=new CrFake(); $f->master['recurrenceRule']['count']=1; crReject($f,'outside count');
$f=new CrFake(); $f->master['calendarIds']=[]; crReject($f,'missing membership');
$f=new CrFake(); $f->master['calendarIds']=array_fill_keys(array_map(fn($i)=>'c'.$i,range(1,17)),true); crReject($f,'membership limit');
$f=new CrFake(); $f->session['accounts']['calendar']['accountCapabilities'][CR_CAL]['maxExpandedQueryDuration']='PT1S'; crReject($f,'server window limit');
$f=new CrFake(); $f->session['capabilities'][CR_CORE]['maxObjectsInGet']=1;
$ids=array_fill_keys(array_map(fn($i)=>'c'.$i,range(1,16)),true); $f->master['calendarIds']=$ids; $e=$f->events[0]; $e['calendarIds']=$ids; $f->events=[];
for ($i=0;$i<600;$i++) { $copy=$e; $copy['id']='large-'.$i; $f->events[]=$copy; }
crReject($f,'global operation limit');
crCheck(count($f->calls)+$f->sessionReads<=EwsCalendarOccurrenceResolverBudgetClient::MAX_OPERATIONS && count($f->calls)<300 && $f->invalidations===0,'global limit includes query calls and session reads');
$f=new CrFake(); $f->hook=function($m,$a,$r,$t) { if ($m==='CalendarEvent/get' && $t==='cor') $r[0][1]['list'][0]['title']=str_repeat('x',EwsCalendarOccurrenceResolverBudgetClient::MAX_BYTES); return $r; }; crReject($f,'total byte budget');
$f=new CrFake(); $f->master['recurrenceRule']['frequency']='monthly'; crReject($f,'unsupported rule');
$f=new CrFake(); crReject($f,'not rule member',crId('2025-03-31T09:00:00'));
$f=new CrFake(); $e=$f->events[0]; $e['id']='other'; $f->events[]=$e; crReject($f,'ambiguous');
$faults=[
 'missing master'=>function($m,$a,$r,$t) { if ($m==='CalendarEvent/get' && $t==='cor') { $r[0][1]['list']=[]; $r[0][1]['notFound']=['base']; } return $r; },
 'wrong account'=>function($m,$a,$r,$t) { if ($t==='cor') $r[0][1]['accountId']='mail'; return $r; },
 'secret error'=>function($m,$a,$r,$t) { throw new RuntimeException('private payload credentials'); },
 'bad tuple'=>function($m,$a,$r,$t) { $r[0][2]='bad'; return $r; },
 'changed state'=>function($m,$a,$r,$t) { if ($t==='cv' && $m==='CalendarEvent/get') $r[0][1]['state']='e2'; return $r; },
 'query denial'=>function($m,$a,$r,$t) { if ($m==='CalendarEvent/query') return [['error',['type'=>'forbidden','description'=>'private payload'],$t]]; return $r; },
 'calendar changed'=>function($m,$a,$r,$t,$f) { if ($m==='Calendar/get' && count(array_filter($f->calls,fn($x)=>$x[0]==='Calendar/get'))>1) $r[0][1]['state']='c2'; return $r; },
 'permission revoked'=>function($m,$a,$r,$t,$f) { if ($m==='Calendar/get' && count(array_filter($f->calls,fn($x)=>$x[0]==='Calendar/get'))>1) $r[0][1]['list'][0]['myRights']['mayReadItems']=false; return $r; },
 'master changed'=>function($m,$a,$r,$t,$f) { if ($m==='CalendarEvent/get' && $t==='cor' && count(array_filter($f->calls,fn($x)=>$x[0]==='CalendarEvent/get' && $x[2]==='cor'))>1) $r[0][1]['list'][0]['title']='changed'; return $r; },
 'master state changed'=>function($m,$a,$r,$t,$f) { if ($m==='CalendarEvent/get' && $t==='cor' && count(array_filter($f->calls,fn($x)=>$x[0]==='CalendarEvent/get' && $x[2]==='cor'))>1) $r[0][1]['state']='e2'; return $r; },
 'query state changed'=>function($m,$a,$r,$t,$f) { if ($m==='CalendarEvent/query' && count(array_filter($f->calls,fn($x)=>$x[0]==='CalendarEvent/query'))===2) $r[0][1]['queryState']='q2'; return $r; },
 'session changes during scan'=>function($m,$a,$r,$t,$f) { if ($m==='CalendarEvent/query') $f->session['apiUrl']='https://other.example/jmap/api'; return $r; },
];
foreach ($faults as $name=>$hook) { $f=new CrFake(); $f->hook=$hook; crReject($f,$name); }
foreach (['changed state','query denial','calendar changed','permission revoked','master changed','master state changed','query state changed'] as $name) {
    $f=new CrFake(); $f->events=[]; $f->hook=$faults[$name]; crReject($f,'missing with '.$name);
}
foreach (['calendar changed','permission revoked','master changed','master state changed'] as $name) {
    $f=new CrFake(); $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; $f->hook=$faults[$name]; crReject($f,'excluded with '.$name);
}
echo "calendar occurrence resolver tests passed\n";
