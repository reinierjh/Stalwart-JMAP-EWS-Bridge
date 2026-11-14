<?php

// Can also be preloaded over SSH stdin, without remote source files.
if (!class_exists('EwsSoap')) require_once __DIR__ . '/../ews/src/Soap.php';
if (!class_exists('EwsConverter')) require_once __DIR__ . '/../ews/src/Converter.php';
if (!class_exists('EwsCalendarOccurrenceId')) require_once __DIR__ . '/../ews/src/CalendarOccurrenceId.php';
if (!class_exists('EwsCalendarViewQuery')) require_once __DIR__ . '/../ews/src/CalendarViewQuery.php';
if (!class_exists('EwsCalendarViewOperation')) require_once __DIR__ . '/../ews/src/CalendarViewOperation.php';
if (!class_exists('EwsOperations')) require_once __DIR__ . '/../ews/src/Operations.php';
const CVO_CORE = 'urn:ietf:params:jmap:core';
const CVO_CAL = 'urn:ietf:params:jmap:calendars';
function cvoCheck(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function cvoRequest(string $shape = 'Default', string $max = '2', string $traversal = 'Shallow', string $id = 'cal_folder', string $extra = ''): DOMElement {
    $xml = '<m:FindItem xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '" Traversal="' . $traversal . '"><m:ItemShape><t:BaseShape>' . $shape . '</t:BaseShape></m:ItemShape><m:CalendarView StartDate="2025-03-20T01:00:00+01:00" EndDate="2025-04-09T20:00:00-04:00" MaxEntriesReturned="' . $max . '"/><m:ParentFolderIds><t:FolderId Id="' . $id . '"/></m:ParentFolderIds>' . $extra . '</m:FindItem>';
    $doc = new DOMDocument(); cvoCheck($doc->loadXML($xml), 'fixture XML'); return $doc->documentElement;
}
function cvoXpath(string $xml): DOMXPath {
    $doc = new DOMDocument(); cvoCheck($doc->loadXML($xml), 'response XML');
    $xp = new DOMXPath($doc); $xp->registerNamespace('m', EwsSoap::NS_M); $xp->registerNamespace('t', EwsSoap::NS_T); return $xp;
}
function cvoResponse(CvoFake $fake, ?DOMElement $request = null): DOMXPath { return cvoXpath((new EwsCalendarViewOperation($fake))->findItem($request ?? cvoRequest())); }
function cvoCode(DOMXPath $x): string { return $x->evaluate('string(//m:ResponseCode)'); }
function cvoFailure(CvoFake $fake, DOMElement $request, string $code): void {
    $x = cvoResponse($fake, $request); cvoCheck(cvoCode($x) === $code && $x->query('//m:RootFolder')->length === 0, 'expected error ' . $code);
    cvoCheck(!str_contains($x->document->saveXML(), 'private payload'), 'no private error content');
}

final class CvoFake {
    public array $session; public array $events; public array $masters; public array $calls = [];
    public $hook = null; public bool $rights = true; public bool $missingFolder = false; public bool $defaultIncludesMetadata = false;
    public function __construct() {
        $this->session = ['capabilities'=>[CVO_CORE=>['maxObjectsInGet'=>2], CVO_CAL=>[]], 'primaryAccounts'=>[CVO_CORE=>'mail-account', CVO_CAL=>'calendar-account'], 'accounts'=>['calendar-account'=>['accountCapabilities'=>[CVO_CAL=>['maxExpandedQueryDuration'=>'P52W1D']]]]];
        $master = ['id'=>'base', 'uid'=>'uid', 'title'=>'Series', 'description'=>'private payload', 'start'=>'2025-03-23T09:00:00', 'duration'=>'PT1H', 'timeZone'=>'Europe/Amsterdam', 'privacy'=>'private', 'calendarIds'=>['folder'=>true], 'recurrenceRule'=>['frequency'=>'weekly','count'=>3], 'recurrenceOverrides'=>['2025-03-30T09:00:00'=>['start'=>'2025-03-30T12:00:00'], '2025-04-06T09:00:00'=>['excluded'=>true]]];
        $normal = $master; unset($normal['recurrenceRule'], $normal['recurrenceOverrides']);
        $normal['id']='opaque-a'; $normal['baseEventId']='base'; $normal['recurrenceId']='2025-03-23T09:00:00'; $normal['utcStart']='2025-03-23T08:00:00Z';
        $moved = $normal; $moved['id']='opaque-b'; $moved['recurrenceId']='2025-03-30T09:00:00'; $moved['start']='2025-03-30T12:00:00'; $moved['utcStart']='2025-03-30T10:00:00Z';
        $single = ['id'=>'single','uid'=>'single-uid','title'=>'Single','description'=>'private payload','start'=>'2025-04-02T09:00:00','duration'=>'PT1H','timeZone'=>'Etc/UTC','calendarIds'=>['folder'=>true],'baseEventId'=>null,'utcStart'=>'2025-04-02T09:00:00Z'];
        $this->events=[$normal,$moved,$single]; $this->masters=['base'=>$master];
    }
    public function getSession(): array { return $this->session; }
    public function call(array $calls, array $using): array {
        cvoCheck(count($calls) === 1 && $using === [CVO_CORE,CVO_CAL], 'capabilities');
        [$method,$args,$tag] = $calls[0]; $this->calls[] = [$method,$args,$tag];
        cvoCheck(($args['accountId']??null)==='calendar-account', 'no mail fallback');
        if ($method === 'Calendar/get') {
            cvoCheck($args['ids'] === ['folder'] && $args['properties'] === ['id','myRights'], 'folder authorization request');
            $data=['accountId'=>'calendar-account','state'=>'cs1','list'=>$this->missingFolder?[]:[['id'=>'folder','myRights'=>['mayReadItems'=>$this->rights]]],'notFound'=>$this->missingFolder?['folder']:[]];
        } elseif ($method === 'CalendarEvent/query') {
            cvoCheck(($args['filter']['inCalendar']??null)==='folder' && ($args['timeZone']??null)==='Etc/UTC', 'expanded folder query');
            $after=new DateTimeImmutable($args['filter']['after'],new DateTimeZone('UTC'));
            $before=new DateTimeImmutable($args['filter']['before'],new DateTimeZone('UTC'));
            $filtered=[];
            foreach ($this->events as $event) {
                $start=new DateTimeImmutable($event['start'],new DateTimeZone($event['timeZone']));
                $end=$start->add(new DateInterval($event['duration']));
                if ($end > $after && $start < $before) $filtered[]=$event['id'];
            }
            $data=['accountId'=>'calendar-account','queryState'=>'q1','position'=>$args['position'],'total'=>count($filtered),'ids'=>array_slice($filtered,$args['position'],$args['limit'])];
        } elseif ($method === 'CalendarEvent/get') {
            cvoCheck(count($args['ids']) <= 2, 'bounded batches'); $list=[]; $missing=[];
            foreach ($args['ids'] as $id) {
                cvoCheck(is_string($id), 'JMAP IDs must be strings');
                $item=$this->masters[$id]??null;
                if ($item===null) foreach ($this->events as $event) if ($event['id']===$id) { $item=$event; break; }
                if ($item===null) { $missing[]=$id; continue; }
                if (isset($args['properties'])) $item=array_intersect_key($item,array_flip($args['properties']));
                elseif (!isset($this->masters[$id]) && !$this->defaultIncludesMetadata) unset($item['baseEventId'],$item['recurrenceId'],$item['utcStart']);
                $list[]=$item;
            }
            $data=['accountId'=>'calendar-account','state'=>'s1','list'=>array_reverse($list),'notFound'=>$missing];
        } else throw new RuntimeException('Unexpected write');
        $response=[[$method,$data,$tag]];
        return $this->hook ? ($this->hook)($method,$args,$response,$tag,$this) : $response;
    }
}

// Exercise the actual existing GetItem implementation with the same mocked
// JMAP client, rather than assuming how it constructs a ChangeKey.
class JmapClient {
    const CAP_CORE=CVO_CORE, CAP_CALENDARS=CVO_CAL, CAP_CONTACTS='urn:ietf:params:jmap:contacts';
    public function __construct(private CvoFake $fake) {}
    public function getAccountId(): string { return 'mail-account'; }
    public function getCapabilityAccountId(string $cap): string { return $cap === self::CAP_CALENDARS ? 'calendar-account' : 'contacts-account'; }
    public function getSession(): array { return $this->fake->getSession(); }
    public function call(array $calls, array $using = [self::CAP_CORE]): array { return $this->fake->call($calls,$using); }
}

$f=new CvoFake(); $x=cvoResponse($f);
cvoCheck(cvoCode($x)==='NoError', 'success');
cvoCheck($x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='3' && $x->evaluate('string(//m:RootFolder/@IncludesLastItemInRange)')==='false', 'true total and truncation');
cvoCheck($x->query('//m:RootFolder/t:Items/t:CalendarItem')->length===2 && $x->query('//m:RootFolder/m:Items')->length===0 && $x->query('//m:RootFolder/@IndexedPagingOffset')->length===0, 'items namespace and no fake offset');
cvoCheck($x->evaluate('string((//t:CalendarItem/t:CalendarItemType)[1])')==='Occurrence' && $x->evaluate('string((//t:CalendarItem/t:CalendarItemType)[2])')==='Exception', 'occurrence and exception');
cvoCheck($x->evaluate('string((//t:CalendarItem/t:Start)[1])')==='2025-03-23T08:00:00Z' && $x->evaluate('string((//t:CalendarItem/t:Start)[2])')==='2025-03-30T10:00:00Z', 'DST moved order');
$id1=$x->evaluate('string((//t:CalendarItem/t:ItemId)[1]/@Id)'); $id2=$x->evaluate('string((//t:CalendarItem/t:ItemId)[2]/@Id)');
cvoCheck(EwsCalendarOccurrenceId::decodeForAccount($id1,'calendar-account')['recurrenceId']==='2025-03-23T09:00:00' && EwsCalendarOccurrenceId::decodeForAccount($id2,'calendar-account')['recurrenceId']==='2025-03-30T09:00:00', 'original IDs');
cvoCheck($x->evaluate('string((//t:CalendarItem/t:ItemId)[1]/@ChangeKey)')===EwsConverter::itemChangeKey($f->masters['base']), 'master changekey');
cvoCheck($x->query('//t:Body | //t:Attachments')->length===0, 'FindItem strips body and attachments');
$gets=array_values(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/get' && $c[1]['ids']===['base']));
cvoCheck(count($gets)===1 && !isset($gets[0][1]['properties']), 'shared master fetched once and complete');
$f->events[0]['id']='different-synthetic'; $again=cvoResponse($f); cvoCheck($again->evaluate('string((//t:CalendarItem/t:ItemId)[1]/@Id)')===$id1, 'ID stable when synthetic ID changes');
$x=cvoResponse(new CvoFake(),cvoRequest('IdOnly','3'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@IncludesLastItemInRange)')==='true' && $x->query('//t:Items/t:CalendarItem')->length===3, 'all results id-only');
cvoCheck($x->query('//t:CalendarItem/*[not(self::t:ItemId)]')->length===0 && $x->query('//t:CalendarItem/t:ItemId[@Id and @ChangeKey]')->length===3, 'IdOnly fields');
cvoCheck($x->evaluate('string((//t:CalendarItem/t:ItemId)[3]/@Id)')==='cal_single', 'single uses normal calendar ID');
$singleDefault=(new CvoFake())->events[2]; unset($singleDefault['baseEventId'],$singleDefault['recurrenceId'],$singleDefault['utcStart']);
cvoCheck($x->evaluate('string((//t:CalendarItem/t:ItemId)[3]/@ChangeKey)')===EwsConverter::itemChangeKey($singleDefault), 'single changekey equals default GetItem projection');
$f=new CvoFake(); $f->defaultIncludesMetadata=true; $f->events=[$f->events[2]]; $x=cvoResponse($f,cvoRequest('IdOnly','3'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//t:ItemId/@ChangeKey)')===EwsConverter::itemChangeKey($f->events[0]), 'explicit default metadata preserved in singleton changekey');
foreach ([false,true] as $withMetadata) {
    $f=new CvoFake(); $f->defaultIncludesMetadata=$withMetadata; $f->events=[$f->events[2]]; $client=new JmapClient($f);
    $find=cvoXpath((new EwsCalendarViewOperation($client))->findItem(cvoRequest('IdOnly','3')));
    $doc=new DOMDocument(); $doc->loadXML('<m:GetItem xmlns:m="'.EwsSoap::NS_M.'" xmlns:t="'.EwsSoap::NS_T.'"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape><m:ItemIds><t:ItemId Id="cal_single"/></m:ItemIds></m:GetItem>');
    $get=cvoXpath((new EwsOperations($client))->getItem($doc->documentElement));
    cvoCheck(cvoCode($find)==='NoError' && cvoCode($get)==='NoError' && $find->evaluate('string(//t:ItemId/@ChangeKey)')===$get->evaluate('string(//t:ItemId/@ChangeKey)'), 'FindItem ChangeKey matches real GetItem');
}
$f=new CvoFake(); $f->events=[]; $x=cvoResponse($f); cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='0' && $x->evaluate('string(//m:RootFolder/@IncludesLastItemInRange)')==='true' && $x->query('//t:Items')->length===1, 'empty');
$x=cvoResponse(new CvoFake(),cvoRequest('AllProperties','3')); cvoCheck(cvoCode($x)==='NoError' && $x->query('//t:Body | //t:Attachments')->length===0, 'all properties strips bodies');
$x=cvoResponse(new CvoFake(),cvoRequest('IdOnly','+00002')); cvoCheck(cvoCode($x)==='NoError' && $x->query('//t:CalendarItem')->length===2, 'XML integer lexical form');
$req=cvoRequest(); $req->getElementsByTagNameNS(EwsSoap::NS_M,'CalendarView')->item(0)->removeAttribute('MaxEntriesReturned'); $x=cvoResponse(new CvoFake(),$req); cvoCheck(cvoCode($x)==='NoError' && $x->query('//t:CalendarItem')->length===3, 'default max entries');
$f=new CvoFake(); $template=$f->events[0]; $master=$f->masters['base']; $f->events=[]; $f->masters=[];
for ($i=0;$i<5;$i++) { $m=$master; $m['id']='base'.$i; $m['uid']='uid'.$i; $f->masters[$m['id']]=$m; $e=$template; $e['id']='synthetic'.$i; $e['baseEventId']=$m['id']; $e['uid']=$m['uid']; $f->events[]=$e; }
$x=cvoResponse($f,cvoRequest('IdOnly','1'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='5' && $x->query('//t:CalendarItem')->length===1, 'multi-master total and limit');
$batches=array_values(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/get' && $c[2]==='cvo' && $c[1]['ids']!==[]));
cvoCheck(array_map(fn($c)=>count($c[1]['ids']),$batches)===[2,2,1], 'distinct master batches bounded by advertised get limit');

foreach ([cvoRequest('Default','0'),cvoRequest('Default','1001'),cvoRequest('Default','-1'),cvoRequest('Default','2','Deep'),cvoRequest('Default','2','Shallow','mail_folder'),cvoRequest('Default','2','Shallow','cal_'),cvoRequest('Bogus'),cvoRequest('Default','2','Shallow','cal_folder','<m:Restriction/>'),cvoRequest('Default','2','Shallow','cal_folder','<m:CalendarView/>')] as $bad) {
    $f=new CvoFake(); $x=cvoResponse($f,$bad); cvoCheck(cvoCode($x)!=='NoError' && $f->calls===[], 'reject unsupported input before JMAP');
}
$bad=cvoRequest(); $bad->getElementsByTagNameNS(EwsSoap::NS_M,'ItemShape')->item(0)->appendChild($bad->ownerDocument->createElementNS(EwsSoap::NS_T,'t:AdditionalProperties')); cvoFailure(new CvoFake(),$bad,'ErrorInvalidPropertyRequest');
$bad=cvoRequest(); $bad->getElementsByTagNameNS(EwsSoap::NS_M,'ItemShape')->item(0)->setAttribute('IncludeMimeContent','true'); cvoFailure(new CvoFake(),$bad,'ErrorInvalidRequest');
$bad=cvoRequest(); $bad->getElementsByTagNameNS(EwsSoap::NS_M,'ParentFolderIds')->item(0)->appendChild($bad->ownerDocument->createElementNS(EwsSoap::NS_T,'t:FolderId')); cvoFailure(new CvoFake(),$bad,'ErrorInvalidRequest');
$bad=cvoRequest(); $bad->getElementsByTagNameNS(EwsSoap::NS_M,'CalendarView')->item(0)->setAttribute('StartDate','2025-03-20T00:00:00'); cvoFailure(new CvoFake(),$bad,'ErrorInvalidRequest');
$bad=cvoRequest(); $bad->getElementsByTagNameNS(EwsSoap::NS_M,'CalendarView')->item(0)->setAttribute('EndDate','2027-03-20T00:00:00Z'); cvoFailure(new CvoFake(),$bad,'ErrorInvalidRequest');
$f=new CvoFake(); $f->rights=false; cvoFailure($f,cvoRequest(),'ErrorAccessDenied');
$f=new CvoFake(); $f->missingFolder=true; cvoFailure($f,cvoRequest(),'ErrorFolderNotFound');
$f=new CvoFake(); unset($f->session['primaryAccounts'][CVO_CAL]); cvoFailure($f,cvoRequest(),'ErrorInternalServerError'); cvoCheck($f->calls===[], 'no account fallback');
$faults = [
    'query permission loss' => function($m,$a,$r,$tag) { return $m==='CalendarEvent/query' ? [['error',['type'=>'forbidden','description'=>'private payload'],$tag]] : $r; },
    'folder error' => function($m,$a,$r,$tag) { return $m==='Calendar/get' ? [['error',['type'=>'forbidden','description'=>'private payload'],$tag]] : $r; },
    'master deleted' => function($m,$a,$r) { if($m==='CalendarEvent/get'&&$a['ids']===['base']) { $r[0][1]['list']=[]; $r[0][1]['notFound']=['base']; } return $r; },
    'master changed state' => function($m,$a,$r) { if($m==='CalendarEvent/get'&&$a['ids']===['base']) $r[0][1]['state']='s2'; return $r; },
    'wrong master account' => function($m,$a,$r) { if($m==='CalendarEvent/get'&&$a['ids']===['base']) $r[0][1]['accountId']='mail-account'; return $r; },
    'bad tuple' => function($m,$a,$r) { if($m==='Calendar/get') $r[0][2]='wrong'; return $r; },
    'late event state' => function($m,$a,$r,$tag,$f) { if($m==='CalendarEvent/get'&&$tag==='cvo'&&$a['ids']===[]) $r[0][1]['state']='s2'; return $r; },
    'calendar state change' => function($m,$a,$r,$tag,$f) { if($m==='Calendar/get'&&count(array_filter($f->calls,fn($c)=>$c[0]==='Calendar/get'))>1) $r[0][1]['state']='cs2'; return $r; },
    'permission revoked' => function($m,$a,$r,$tag,$f) { if($m==='Calendar/get'&&count(array_filter($f->calls,fn($c)=>$c[0]==='Calendar/get'))>1) $r[0][1]['list'][0]['myRights']['mayReadItems']=false; return $r; },
];
foreach($faults as $name=>$hook) { $f=new CvoFake(); $f->hook=$hook; $x=cvoResponse($f); cvoCheck(cvoCode($x)!=='NoError' && $x->query('//m:RootFolder')->length===0 && !str_contains($x->document->saveXML(),'private payload'), $name); }
$f=new CvoFake(); $f->events[0]['calendarIds']=['other'=>true]; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->masters['base']['calendarIds']=['other'=>true]; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->events[0]['privacy']='public'; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->events=[$f->events[2]]; $f->events[0]['utcStart']='2025-04-02T10:00:00Z'; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->masters['base']['recurrenceOverrides']['2025-03-23T09:00:00']=['excluded'=>true]; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->masters['base']['recurrenceRules']=[$f->masters['base']['recurrenceRule']]; unset($f->masters['base']['recurrenceRule']); cvoFailure($f,cvoRequest(),'ErrorInternalServerError'); // Converter rejects this unsupported fixture; not a claim about the server response.
$f=new CvoFake(); $f->events[2]['timeZone']='Europe/Paris'; cvoFailure($f,cvoRequest('IdOnly','1'),'ErrorInternalServerError'); // Also validate items beyond truncation.
$f=new CvoFake(); unset($f->events[0]['recurrenceId']); cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $duplicate=$f->events[0]; $duplicate['id']='other-synthetic-same-occurrence'; $f->events=[$f->events[0],$duplicate]; cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
$f=new CvoFake(); $f->events=[$f->events[2]]; $x=cvoResponse($f); cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//t:CalendarItem/t:CalendarItemType)')==='Single', 'non-recurring single');
$f=new CvoFake(); $m=$f->masters['base']; $m['id']='123'; $f->masters=['123'=>$m]; $f->events[0]['baseEventId']='123'; $f->events[1]['baseEventId']='123';
$x=cvoResponse($f,cvoRequest('IdOnly','3')); cvoCheck(cvoCode($x)==='NoError', 'numeric base id');
$batches=array_values(array_filter($f->calls,fn($c)=>$c[0]==='CalendarEvent/get' && $c[2]==='cvo' && $c[1]['ids']!==[]));
cvoCheck(count($batches)===1 && $batches[0][1]['ids']===['123'], 'numeric base ID remains a string');

// The fake enforces JMAP's end > after and start < before. EWS instead
// includes end == StartDate while still excluding start == EndDate.
$f=new CvoFake(); $f->masters=[]; $f->events=[];
foreach ([['early','07:00:00','PT1H'],['end-at-start','08:00:00','PT1H'],['cross','08:30:00','PT1H'],['at-start','09:00:00','PT1H'],['at-end','10:00:00','PT1H']] as [$id,$time,$duration]) {
    $f->events[]=['id'=>$id,'uid'=>$id,'title'=>'Boundary','start'=>'2025-03-20T'.$time,'duration'=>$duration,'timeZone'=>'Etc/UTC','calendarIds'=>['folder'=>true],'baseEventId'=>null,'utcStart'=>'2025-03-20T'.$time.'Z'];
}
$range=function(string $start,string $end,string $max='10'): DOMElement { $r=cvoRequest('IdOnly',$max); $v=$r->getElementsByTagNameNS(EwsSoap::NS_M,'CalendarView')->item(0); $v->setAttribute('StartDate',$start); $v->setAttribute('EndDate',$end); return $r; };
$x=cvoResponse($f,$range('2025-03-20T10:00:00+01:00','2025-03-20T06:00:00-04:00'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='3', 'inclusive end and exclusive start boundaries');
cvoCheck($x->query('//t:ItemId[@Id="cal_end-at-start"]')->length===1 && $x->query('//t:ItemId[@Id="cal_at-end" or @Id="cal_early"]')->length===0, 'boundary membership');
$x=cvoResponse($f,$range('2025-03-20T09:00:00Z','2025-03-20T09:00:00Z'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='2' && $x->query('//t:ItemId[@Id="cal_end-at-start"]')->length===1 && $x->query('//t:ItemId[@Id="cal_at-start"]')->length===0, 'equal range');
$x=cvoResponse($f,$range('2025-03-20T09:00:00.500000Z','2025-03-20T10:00:00Z','1'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='2' && $x->evaluate('string(//m:RootFolder/@IncludesLastItemInRange)')==='false' && $x->query('//t:ItemId[@Id="cal_end-at-start"]')->length===0, 'fractional edge and truncation');
$x=cvoResponse($f,$range('2025-03-20T09:00:00.500000Z','2025-03-20T09:00:00.500000Z'));
cvoCheck(cvoCode($x)==='NoError' && $x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='2' && $x->query('//t:ItemId[@Id="cal_at-start"]')->length===1 && $x->query('//t:ItemId[@Id="cal_end-at-start"]')->length===0, 'equal fractional range');
$x=cvoResponse($f,$range('2025-03-20T09:00:00Z','2025-03-20T10:00:00.500000Z'));
cvoCheck(cvoCode($x)==='NoError' && $x->query('//t:ItemId[@Id="cal_at-end"]')->length===1, 'fractional exclusive upper boundary');
$f=new CvoFake(); $f->session['accounts']['calendar-account']['accountCapabilities'][CVO_CAL]['maxExpandedQueryDuration']='P21D';
cvoCheck(cvoCode(cvoResponse($f,cvoRequest()))==='NoError','exact advertised maximum does not grow due to boundary scan');
$f=new CvoFake(); $f->session['accounts']['calendar-account']['accountCapabilities'][CVO_CAL]['maxExpandedQueryDuration']='P20D'; cvoFailure($f,cvoRequest(),'ErrorInvalidRequest');
$count=0; $f=new CvoFake(); $f->hook=function($m,$a,$r,$tag) use (&$count) { if ($m==='CalendarEvent/get' && $tag==='cv' && !$a['ids']) { $count++; if ($count>=4) $r[0][1]['state']='s2'; } else if ($m==='CalendarEvent/get' && $tag==='cv' && $count>=4) $r[0][1]['state']='s2'; return $r; };
cvoFailure($f,cvoRequest(),'ErrorInternalServerError');
echo "calendar view operation tests passed\n";
