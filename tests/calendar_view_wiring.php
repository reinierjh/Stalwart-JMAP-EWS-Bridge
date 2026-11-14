<?php
// Real bootstrap and operations; the only transport is the fake JMAP client.
require __DIR__ . '/../ews/bootstrap.php';

function wiringCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function wiringRequest(string $body): DOMElement {
    $doc = new DOMDocument();
    wiringCheck($doc->loadXML('<root xmlns:m="' . EwsSoap::NS_M . '" xmlns:t="' . EwsSoap::NS_T . '">' . $body . '</root>'), 'request XML');
    return $doc->documentElement->firstElementChild;
}
function wiringXpath(string $xml): DOMXPath {
    $doc = new DOMDocument(); wiringCheck($doc->loadXML($xml), 'response XML');
    $x = new DOMXPath($doc); $x->registerNamespace('m', EwsSoap::NS_M); $x->registerNamespace('t', EwsSoap::NS_T); return $x;
}
function wiringFind(string $shape = 'Default', string $extra = ''): DOMElement {
    return wiringRequest('<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>' . $shape . '</t:BaseShape></m:ItemShape><m:CalendarView StartDate="2025-03-20T00:00:00Z" EndDate="2025-04-15T00:00:00Z"/><m:ParentFolderIds><t:FolderId Id="cal_folder"/></m:ParentFolderIds>' . $extra . '</m:FindItem>');
}
function wiringGet(array $ids, string $shape = 'Default'): DOMElement {
    $items = ''; foreach ($ids as $id) $items .= '<t:ItemId Id="' . htmlspecialchars($id, ENT_QUOTES | ENT_XML1) . '"/>';
    return wiringRequest('<m:GetItem><m:ItemShape><t:BaseShape>' . $shape . '</t:BaseShape></m:ItemShape><m:ItemIds>' . $items . '</m:ItemIds></m:GetItem>');
}

final class WiringClient extends JmapClient {
    public array $session, $master, $events, $calls = [];
    public bool $rights = true, $fail = false;
    public array $failMethods = [];
    public int $refreshes = 0;
    public bool $denyOnRecheck = false;
    public int $calendarReads = 0;
    public function __construct() {
        $this->session = ['apiUrl'=>'https://example.invalid/jmap', 'state'=>'session1', 'capabilities'=>[self::CAP_CORE=>['maxObjectsInGet'=>10], self::CAP_MAIL=>[], self::CAP_CONTACTS=>[], self::CAP_CALENDARS=>[]], 'primaryAccounts'=>[self::CAP_MAIL=>'mail',self::CAP_CONTACTS=>'contacts',self::CAP_CALENDARS=>'calendar'], 'accounts'=>['calendar'=>['accountCapabilities'=>[self::CAP_CALENDARS=>['maxExpandedQueryDuration'=>'P365D']]]]];
        $this->master = ['id'=>'base','uid'=>'uid','title'=>'Series','description'=>'private payload','start'=>'2025-03-23T09:00:00','duration'=>'PT1H','timeZone'=>'Europe/Amsterdam','calendarIds'=>['folder'=>true], 'locations'=>['other'=>['name'=>'Wrong'], 'main'=>['name'=>'Right']], 'mainLocationId'=>'main', 'recurrenceRule'=>['frequency'=>'weekly','count'=>3], 'recurrenceOverrides'=>['2025-03-30T09:00:00'=>['start'=>'2025-04-10T12:00:00']]];
        $event = $this->master; unset($event['recurrenceRule'], $event['recurrenceOverrides']);
        $event['id']='synthetic'; $event['baseEventId']='base'; $event['recurrenceId']='2025-03-30T09:00:00'; $event['start']='2025-04-10T12:00:00'; $event['utcStart']='2025-04-10T10:00:00Z';
        $normal = $event; $normal['id']='normal-synthetic'; $normal['recurrenceId']='2025-03-23T09:00:00'; $normal['start']='2025-03-23T09:00:00'; $normal['utcStart']='2025-03-23T08:00:00Z';
        $single = ['id'=>'single','uid'=>'single-uid','title'=>'Single','start'=>'2025-04-02T09:00:00','duration'=>'PT1H','timeZone'=>'Etc/UTC','calendarIds'=>['folder'=>true], 'utcStart'=>'2025-04-02T09:00:00Z'];
        $this->events = [$normal, $event, $single];
    }
    public function getSession(): array { return $this->session; }
    public function invalidateSession(): void { ++$this->refreshes; }
    public function getAccountId(): string { return 'mail'; }
    public function getCapabilityAccountId(string $capability): string { return $this->session['primaryAccounts'][$capability]; }
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        $this->calls[] = [$methodCalls, $using];
        if ($this->fail) throw new RuntimeException('private payload credentials');
        if (count($methodCalls) === 2 && $methodCalls[0][0] === 'Email/query') return [['Email/query',['ids'=>['mail1']],'q'], ['Email/get',['list'=>[['id'=>'mail1','subject'=>'mail']]],'g']];
        wiringCheck(count($methodCalls) === 1, 'single JMAP call');
        [$method,$args,$tag] = $methodCalls[0];
        if (in_array($method, $this->failMethods, true)) return [['error',['type'=>'serverFail','description'=>'private payload credentials'],$tag]];
        if ($method === 'Calendar/get') {
            wiringCheck($args['accountId'] === 'calendar', 'calendar account');
            ++$this->calendarReads;
            $data=['accountId'=>'calendar','state'=>'c1','list'=>[['id'=>'folder','myRights'=>['mayReadItems'=>$this->rights && !($this->denyOnRecheck && $this->calendarReads > 1)]]],'notFound'=>[]];
        } elseif ($method === 'CalendarEvent/query') {
            $after = new DateTimeImmutable($args['filter']['after']); $before = new DateTimeImmutable($args['filter']['before']); $ids=[];
            foreach ($this->events as $e) { $start = new DateTimeImmutable($e['utcStart']); if ($start->modify('+1 hour') > $after && $start < $before) $ids[]=$e['id']; }
            $data=['accountId'=>'calendar','queryState'=>'q1','position'=>$args['position'],'total'=>count($ids),'ids'=>array_slice($ids,$args['position'],$args['limit'])];
        } elseif ($method === 'CalendarEvent/get') {
            wiringCheck($args['accountId'] === 'calendar', 'calendar event account'); $list=[]; $not=[];
            foreach ($args['ids'] as $id) {
                $item=$id==='base' ? $this->master : null;
                foreach ($this->events as $event) if ($event['id']===$id) $item=$event;
                if (!$item) { $not[]=$id; continue; }
                if (isset($args['properties'])) $item=array_intersect_key($item,array_flip($args['properties']));
                elseif ($id!=='base') unset($item['baseEventId'],$item['recurrenceId'],$item['utcStart']);
                $list[]=$item;
            }
            $data=['accountId'=>'calendar','state'=>'e1','list'=>array_reverse($list),'notFound'=>$not];
        } elseif ($method === 'Email/get' || $method === 'ContactCard/get') {
            foreach ($args['ids'] as $id) wiringCheck(!str_starts_with($id,'ewsocc_'), 'occurrence leaked to mail/contact');
            $object=$method==='Email/get' ? ['id'=>'mail1','subject'=>'mail','mailboxIds'=>['inbox'=>true]] : ['id'=>'contact1','addressBookIds'=>['book'=>true]];
            $data=['list'=>in_array($object['id'],$args['ids'],true)?[$object]:[]];
        } else throw new RuntimeException('Unexpected JMAP method or write: ' . $method);
        return [[$method,$data,$tag]];
    }
}

foreach (['EwsCalendarOccurrenceNotFound','EwsCalendarViewOperationException','EwsCalendarOccurrenceResolverBudgetClient','EwsCalendarViewQuery','EwsCalendarOccurrenceId','EwsCalendarOccurrenceResolver','EwsCalendarViewOperation','EwsCalendarSafetyException'] as $class) wiringCheck(class_exists($class), 'autoload ' . $class);
$client=new WiringClient(); $ops=new EwsOperations($client);
$find=wiringXpath($ops->findItem(wiringFind()));
wiringCheck($find->evaluate('string(//m:ResponseCode)')==='NoError' && $find->query('//m:RootFolder/t:Items/t:CalendarItem')->length===3, 'FindItem view');
$ids=[]; foreach ($find->query('//t:CalendarItem/t:ItemId') as $node) $ids[]=$node->getAttribute('Id');
wiringCheck(str_starts_with($ids[0],'ewsocc_') && $ids[1]==='cal_single' && str_starts_with($ids[2],'ewsocc_'), 'FindItem identities and order');
$moved=$ids[2]; $get=wiringXpath($ops->getItem(wiringGet([$moved])));
wiringCheck($get->evaluate('string(//m:ResponseCode)')==='NoError' && $get->evaluate('string(//t:ItemId/@Id)')===$moved && $get->evaluate('string(//t:CalendarItemType)')==='Exception', 'GetItem moved');
wiringCheck($get->evaluate('string(//t:Start)')==='2025-04-10T10:00:00Z' && $get->evaluate('string(//t:Location)')==='Right' && $get->evaluate('string(//t:ParentFolderId/@Id)')==='cal_folder', 'time zone, location and folder');
wiringCheck($client->refreshes>0, 'resolver refreshes session');
$viewOnly=wiringXpath($ops->findItem(wiringFind('IdOnly')));
wiringCheck($viewOnly->query('//t:CalendarItem')->length===3 && $viewOnly->query('//t:CalendarItem/*[not(self::t:ItemId)]')->length===0, 'FindItem IdOnly');
$client->events[1]['id']='new-synthetic';
$get=wiringXpath($ops->getItem(wiringGet([$ids[0], 'mail1', 'ab_contact1', 'cal_single', $moved])));
$nodes=$get->query('//m:GetItemResponseMessage'); wiringCheck($nodes->length===5, 'mixed count');
foreach ([$ids[0], 'mail1', 'ab_contact1', 'cal_single', $moved] as $i=>$id) wiringCheck($get->evaluate('string(.//t:ItemId/@Id)', $nodes->item($i))===$id && $get->evaluate('string(m:ResponseCode)', $nodes->item($i))==='NoError', 'mixed order ' . $i);
$only=wiringXpath($ops->getItem(wiringGet([$moved], 'IdOnly')));
wiringCheck($only->query('//t:CalendarItem/*[not(self::t:ItemId)]')->length===0 && $only->evaluate('string(//t:ItemId/@ChangeKey)')===EwsConverter::itemChangeKey($client->master), 'IdOnly');
$single=wiringXpath($ops->getItem(wiringGet(['cal_single']))); wiringCheck($single->evaluate('string(//m:ResponseCode)')==='NoError', 'singleton GetItem');
$empty=new WiringClient(); $empty->events=[]; $x=wiringXpath((new EwsOperations($empty))->findItem(wiringFind('IdOnly'))); wiringCheck($x->evaluate('string(//m:RootFolder/@TotalItemsInView)')==='0', 'empty view');
$failed=new WiringClient(); $failed->fail=true; $x=wiringXpath((new EwsOperations($failed))->findItem(wiringFind())); wiringCheck($x->evaluate('string(//m:ResponseCode)')==='ErrorInternalServerError' && !str_contains($x->document->saveXML(),'private payload'), 'view JMAP error sanitized');
$x=wiringXpath($ops->findItem(wiringFind('Default','<m:Restriction/>'))); wiringCheck($x->evaluate('string(//m:ResponseCode)')!=='NoError', 'unsupported view option');
$x=wiringXpath($ops->findItem(wiringRequest('<m:FindItem><m:ParentFolderIds><t:FolderId Id="cal_folder"/></m:ParentFolderIds></m:FindItem>'))); wiringCheck($x->evaluate('string(//m:ResponseCode)')==='ErrorInvalidOperation', 'unsupported calendar search');
$x=wiringXpath($ops->findItem(wiringRequest('<m:FindItem><t:CalendarView/></m:FindItem>'))); wiringCheck($x->evaluate('string(//m:ResponseCode)')==='ErrorInvalidOperation', 'wrong namespace view');
$mail=wiringXpath($ops->findItem(wiringRequest('<m:FindItem><t:QueryString>mail</t:QueryString></m:FindItem>'))); wiringCheck($mail->evaluate('string(//m:ResponseCode)')==='NoError' && $mail->query('//t:Message')->length===1, 'regular mail search');
foreach (['malformed'=>'ewsocc_bad!', 'account'=>EwsCalendarOccurrenceId::encode('other','base','2025-03-30T09:00:00'), 'removed'=>$moved, 'excluded'=>$moved, 'denied'=>$moved, 'recheck-denied'=>$moved, 'jmap'=>$moved, 'query-error'=>$moved] as $case=>$id) {
    $f=new WiringClient(); if ($case==='removed') $f->events=[]; if ($case==='excluded') $f->master['recurrenceOverrides']['2025-03-30T09:00:00']=['excluded'=>true]; if ($case==='denied') $f->rights=false; if ($case==='recheck-denied') { $f->events=[]; $f->denyOnRecheck=true; } if ($case==='jmap') $f->fail=true; if ($case==='query-error') $f->failMethods=['CalendarEvent/query'];
    $x=wiringXpath((new EwsOperations($f))->getItem(wiringGet([$id])));
    $expected=$case==='malformed'?'ErrorInvalidIdMalformed':(in_array($case,['removed','excluded'],true)?'ErrorItemNotFound':'ErrorInternalServerError');
    wiringCheck($x->evaluate('string(//m:ResponseCode)')===$expected && $x->query('//m:Items | //t:ItemId')->length===0 && !str_contains($x->document->saveXML(),$id) && !str_contains($x->document->saveXML(),'private payload'), 'safe error ' . $case);
    if (in_array($case,['removed','excluded'],true)) wiringCheck($f->calendarReads===2 && $f->refreshes>0, 'absence revalidated ' . $case);
}
$f=new WiringClient(); $f->events=[];
$x=wiringXpath((new EwsOperations($f))->getItem(wiringGet(['mail1',$moved,'ab_contact1']))); $nodes=$x->query('//m:GetItemResponseMessage');
wiringCheck($nodes->length===3 && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(0))==='mail1' && $x->evaluate('string(m:ResponseCode)',$nodes->item(1))==='ErrorItemNotFound' && $x->query('.//t:ItemId',$nodes->item(1))->length===0 && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(2))==='ab_contact1', 'mixed not-found and request order');
$f=new WiringClient(); $bad=EwsCalendarOccurrenceId::encode('other','base','2025-03-30T09:00:00');
$x=wiringXpath((new EwsOperations($f))->getItem(wiringGet(['mail1',$bad,$moved,'ab_contact1'])));
$nodes=$x->query('//m:GetItemResponseMessage');
wiringCheck($nodes->length===4 && $x->evaluate('string(m:ResponseCode)',$nodes->item(1))==='ErrorInternalServerError' && $x->query('.//t:ItemId',$nodes->item(1))->length===0 && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(2))===$moved && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(3))==='ab_contact1', 'mixed error and request order');
$f=new WiringClient(); $f->failMethods=['Email/get'];
$x=wiringXpath((new EwsOperations($f))->getItem(wiringGet(['mail1', $moved, 'ab_contact1']))); $nodes=$x->query('//m:GetItemResponseMessage');
wiringCheck($nodes->length===3 && $x->evaluate('string(m:ResponseCode)',$nodes->item(0))==='ErrorInternalServerError' && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(1))===$moved && $x->evaluate('string(.//t:ItemId/@Id)',$nodes->item(2))==='ab_contact1' && !str_contains($x->document->saveXML(),'credentials'), 'mixed JMAP failure isolated');
$f=new WiringClient(); $o=new EwsOperations($f);
$x=wiringXpath($o->getItem(wiringRequest('<m:GetItem><m:ItemIds><t:OccurrenceItemId RecurringMasterId="base" InstanceIndex="1"/></m:ItemIds></m:GetItem>'))); wiringCheck($x->evaluate('string(//m:ResponseCode)')==='ErrorInvalidOperation' && !$f->calls, 'OccurrenceItemId unsupported');
foreach (['UpdateItem'=>'<m:ItemChanges><t:ItemChange><t:ItemId Id="'.$moved.'"/><t:Updates/></t:ItemChange></m:ItemChanges>', 'DeleteItem'=>'<m:ItemIds><t:ItemId Id="'.$moved.'"/></m:ItemIds>', 'SendItem'=>'<m:ItemIds><t:ItemId Id="'.$moved.'"/></m:ItemIds>'] as $op=>$body) {
    $method=lcfirst($op); $x=wiringXpath($o->$method(wiringRequest('<m:'.$op.'>'.$body.'</m:'.$op.'>'))); wiringCheck($x->evaluate('string(//m:ResponseCode)')!=='NoError' && !$f->calls, 'no occurrence writes '.$op);
}
foreach (['cal_single', 'ab_contact1'] as $nonMailId) {
    $client = new WiringClient(); $operation = new EwsOperations($client);
    $request = wiringRequest('<m:SendItem><m:ItemIds><t:ItemId Id="mail1"/><t:ItemId Id="'.$nonMailId.'"/></m:ItemIds></m:SendItem>');
    $response = wiringXpath($operation->sendItem($request));
    wiringCheck($response->evaluate('string(//m:ResponseCode)') === 'ErrorInvalidOperation' && !$client->calls, 'reject non-mail before sending batch');
}
echo "calendar_view_wiring: OK\n";
