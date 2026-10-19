<?php
/** Explicit read projections. No existing API dispatch, session, LLM or sync calls. */
require_once __DIR__ . '/external_auth.php';
require_once __DIR__ . '/helpers.php';

final class ExternalReader {
    public function __construct(private PDO $db, private int $uid, private array $scopes) {}

    private function specs(): array {
        $s = static fn($scope, $from, $owner, $title, $date, $body, $fields = '', $where = '1=1') => compact('scope','from','owner','title','date','body','fields','where');
        return [
            'task' => $s('work','tasks r','r.user_id','r.name','r.updated_at','r.description','r.stage,r.priority,r.importance,r.necessity,r.deadline,r.location,r.archived,r.task_type,r.meeting_auto_from'),
            'worklog' => $s('work','work_logs r JOIN tasks t ON t.id=r.task_id','t.user_id','t.name','r.log_date','r.duration',"CONCAT('task:',r.task_id) AS task_ref,r.duration"),
            'note' => $s('work','worklog_notes r JOIN work_logs w ON w.id=r.worklog_id JOIN tasks t ON t.id=w.task_id','t.user_id','t.name','w.log_date','r.content',"CONCAT('task:',w.task_id) AS task_ref,CONCAT('worklog:',r.worklog_id) AS worklog_ref,r.created_at"),
            'plan' => $s('work','plans r JOIN tasks t ON t.id=r.task_id','t.user_id','t.name','r.planned_date',"''","CONCAT('task:',r.task_id) AS task_ref,r.plan_time,r.plan_end_time,r.is_completed,r.completed_at"),
            'result' => $s('work','results r','r.user_id','r.name','r.updated_at',"''",'r.quantity,r.level,r.archived'),
            'result_log' => $s('work','result_logs r JOIN tasks t ON t.id=r.task_id JOIN results z ON z.id=r.result_id AND z.user_id=t.user_id','t.user_id','z.name','r.log_date',"''","CONCAT('task:',r.task_id) AS task_ref,CONCAT('result:',r.result_id) AS result_ref"),
            'tag' => $s('work','tags r','r.user_id','r.name','r.updated_at',"''",'r.archived'),
            'person' => $s('contacts','people r','r.user_id','r.name','r.updated_at','r.bio','r.relationship,r.importance,r.usefulness,r.closeness,r.is_me,r.archived'),
            'mail' => $s('mail',"mail_messages r JOIN mail_folders f ON f.id=r.folder_id AND f.user_id=r.user_id LEFT JOIN mail_analysis a ON a.message_id=r.id AND a.user_id=r.user_id AND a.status='ok'",'r.user_id',"COALESCE(NULLIF(a.brief_title,''),r.subject)","IF(r.is_seen=0,CONCAT(CURDATE(),' 00:00:00'),COALESCE(r.read_at,r.msg_date))",'r.body_text',
                'r.subject,r.from_name,r.from_email,r.msg_date AS sent_at,r.read_at,r.is_seen,r.has_attachments,f.kind AS folder_kind,a.priority,a.relevance,a.deadline_hint,a.needs_reply,a.status AS analysis_status,a.analyzed_at,a.user_edited', 'r.is_deleted=0'),
            'report' => $s('reports','reports r','r.user_id','r.title','r.period_start','r.content_md','r.period_type,r.period_start,r.period_end,r.updated_at'),
            'profile_document' => $s('profile','profile_documents r','r.user_id',"COALESCE(NULLIF(r.title,''),r.file_name)",'r.updated_at','r.extracted_text','r.file_name,r.is_primary'),
            'profile' => $s('profile','user_profile r','r.user_id','r.name','r.updated_at',"CONCAT_WS('\n',r.resume,r.goals)"),
            'impression' => $s('profile','profile_impressions r','r.user_id','r.field','r.updated_at','r.value','r.confidence,r.source,r.evidence'),
            'profile_snapshot' => $s('profile','profile_impression_snapshots r','r.user_id','r.kind','r.created_at','r.content_md','r.kind'),
            'conversation' => $s('conversations','ai_conversations r','r.user_id','r.title','r.updated_at','r.messages_json','r.summary_upto'),
            'attachment' => $s('attachments','attachments r','r.user_id','r.file_name','r.created_at','r.extracted_text','r.entity_type,r.entity_id,r.mime,r.size', $this->attachmentWhere()),
        ];
    }

    private function attachmentWhere(): string {
        $clauses = [];
        if ($this->allowed('work')) {
            $clauses[] = "(r.entity_type='task' AND EXISTS(SELECT 1 FROM tasks p WHERE p.id=r.entity_id AND p.user_id=r.user_id))";
            $clauses[] = "(r.entity_type='result' AND EXISTS(SELECT 1 FROM results p WHERE p.id=r.entity_id AND p.user_id=r.user_id))";
            $clauses[] = "(r.entity_type='worklog_note' AND EXISTS(SELECT 1 FROM worklog_notes n JOIN work_logs w ON w.id=n.worklog_id JOIN tasks p ON p.id=w.task_id WHERE n.id=r.entity_id AND p.user_id=r.user_id))";
        }
        if ($this->allowed('reports')) $clauses[] = "(r.entity_type='report' AND EXISTS(SELECT 1 FROM reports p WHERE p.id=r.entity_id AND p.user_id=r.user_id))";
        if ($this->allowed('mail')) $clauses[] = "(r.entity_type='mail_message' AND EXISTS(SELECT 1 FROM mail_messages p WHERE p.id=r.entity_id AND p.user_id=r.user_id AND p.is_deleted=0))";
        return '(' . ($clauses ? implode(' OR ', $clauses) : '0=1') . ')';
    }

    public function allowed(string $scope): bool { return in_array($scope, $this->scopes, true); }
    public function kinds(): array {
        return array_keys(array_filter($this->specs(), fn($s) => $this->allowed($s['scope'])));
    }
    private function spec(string $kind): array {
        $s = $this->specs()[$kind] ?? null;
        if (!$s) throw new ExternalApiError('Unknown kind. Use: ' . implode(',', $this->kinds()));
        if (!$this->allowed($s['scope'])) throw new ExternalApiError('Scope required: ' . $s['scope'], 403);
        return $s;
    }
    private function query(string $sql, array $params): array {
        $st = $this->db->prepare($sql); $st->execute($params); return $st->fetchAll();
    }
    private function number(array $a, string $key, int $default, int $min, int $max): int {
        if (!array_key_exists($key, $a)) return $default;
        $n = filter_var($a[$key], FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) throw new ExternalApiError("$key must be $min..$max");
        return $n;
    }
    private function dates(array $a): array {
        $from = (string)($a['date_from'] ?? ''); $to = (string)($a['date_to'] ?? '');
        if (($from !== '' && !validate_date($from)) || ($to !== '' && !validate_date($to)) || ($from && $to && $from > $to)) throw new ExternalApiError('Invalid date range; use YYYY-MM-DD');
        return [$from,$to];
    }
    private function row(string $kind, array $r): array {
        $r['ref'] = $kind . ':' . $r['id']; unset($r['id']);
        $r['title'] = mb_substr((string)$r['title'], 0, 255);
        if ($kind === 'profile_document') $r['authority'] = 'user_uploaded';
        if (in_array($kind, ['impression','profile_snapshot'], true)) $r['authority'] = 'secondary_impression';
        $pages = ['mail'=>'/mail?open=','task'=>'/tasks','report'=>'/reports','profile_document'=>'/profile','profile_snapshot'=>'/profile'];
        if (isset($pages[$kind])) $r['url'] = app_url($pages[$kind] . ($kind === 'mail' ? substr($r['ref'], 5) : ''));
        return $r;
    }

    /** Keyset pagination by stable id, no N+1 detail fetching. */
    public function search(array $a, bool $sample = false, string $extra = '1=1', string $order = 'r.id DESC'): array {
        $kind = (string)($a['kind'] ?? 'task'); $s = $this->spec($kind);
        $limit = $this->number($a,'limit',12,1,30);
        $before = $this->number($a,'before_id',0,0,PHP_INT_MAX);
        [$from,$to] = $this->dates($a);
        $q = trim((string)($a['query'] ?? ''));
        if (mb_strlen($q) > 200) throw new ExternalApiError('query exceeds 200 characters');
        $where = ["{$s['owner']}=?", $s['where'], $extra]; $p = [$this->uid];
        if ($before) { $where[] = 'r.id<?'; $p[] = $before; }
        if ($from) { $where[] = "{$s['date']}>=?"; $p[] = $from; }
        if ($to) { $where[] = "{$s['date']}<DATE_ADD(?, INTERVAL 1 DAY)"; $p[] = $to; }
        if (isset($a['task_id'])) {
            $taskId = $this->number($a,'task_id',0,1,PHP_INT_MAX);
            $field = match($kind) { 'task'=>'r.id','worklog','plan','result_log'=>'r.task_id','note'=>'w.task_id',default=>null };
            if (!$field) throw new ExternalApiError('task_id applies to task/worklog/note/plan/result_log');
            $where[] = "$field=?"; $p[] = $taskId;
        }
        if (isset($a['parent_ref'])) {
            if ($kind !== 'attachment' || !preg_match('/^(task|result|report|mail|note):([1-9][0-9]*)$/', (string)$a['parent_ref'], $m)) throw new ExternalApiError('Invalid attachment parent_ref');
            $type = ['mail'=>'mail_message','note'=>'worklog_note'][$m[1]] ?? $m[1];
            $where[] = 'r.entity_type=? AND r.entity_id=?'; array_push($p,$type,(int)$m[2]);
        }
        if (array_key_exists('archived',$a)) {
            if (!in_array($kind,['task','person','tag','result'],true)) throw new ExternalApiError('archived applies to task/person/tag/result');
            $where[] = 'r.archived=?'; $p[] = $this->number($a,'archived',0,0,1);
        }
        $summary = match($kind) {
            'mail'=>"CASE WHEN a.status='ok' THEN COALESCE(a.summary,'') ELSE COALESCE(r.snippet,'') END",
            'conversation'=>"COALESCE(r.summary_text,'')", default=>$s['body']
        };
        if ($q !== '') {
            // Escape wildcard characters: query is literal text, not SQL LIKE syntax.
            $like = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$q) . '%';
            $searchBody = $kind === 'conversation' ? $summary : $s['body'];
            $where[] = "({$s['title']} LIKE ? ESCAPE '!' OR $summary LIKE ? ESCAPE '!' OR $searchBody LIKE ? ESCAPE '!')";
            array_push($p,$like,$like,$like);
        }
        $fields = $s['fields'] ? ',' . $s['fields'] : '';
        $sql = "SELECT r.id,{$s['title']} AS title,{$s['date']} AS date$fields,SUBSTRING(COALESCE($summary,''),1,160) AS excerpt,CHAR_LENGTH(COALESCE($summary,'')) AS excerpt_chars FROM {$s['from']} WHERE " . implode(' AND ',$where) . " ORDER BY $order LIMIT " . ($limit+1);
        $rows = $this->query($sql,$p); $more = count($rows)>$limit; $rows = array_slice($rows,0,$limit);
        $next = $rows ? (int)$rows[count($rows)-1]['id'] : null;
        $items = [];
        foreach ($rows as $r) { $r['excerpt_truncated'] = (int)$r['excerpt_chars']>160; unset($r['excerpt_chars']); $items[]=$this->row($kind,$r); }
        return ['kind'=>$kind,'items'=>$items,'has_more'=>$more,'next_before_id'=>$more && !$sample ? $next : null,'sample'=>$sample];
    }

    public function overview(array $a): array {
        $from = (string)($a['date_from'] ?? date('Y-m-d')); $to = (string)($a['date_to'] ?? $from);
        if ($from === '' || $to === '') throw new ExternalApiError('Overview dates cannot be empty');
        $this->dates(['date_from'=>$from,'date_to'=>$to]);
        if ((strtotime($to)-strtotime($from))/86400>366) throw new ExternalApiError('Overview range is at most 366 days; search supports longer history');
        $out = ['date_from'=>$from,'date_to'=>$to,'timezone'=>TIMEZONE,'available_kinds'=>$this->kinds()];
        $range = ['date_from'=>$from,'date_to'=>$to,'limit'=>4];
        if ($this->allowed('work')) {
            $out['active_tasks']=$this->search(['kind'=>'task','limit'=>6,'archived'=>0],true,"r.stage IN ('in_progress','stage_complete')",'r.priority DESC,r.id DESC');
            foreach (['worklog','note','plan','result_log'] as $kind) $out[$kind]=$this->search(['kind'=>$kind]+$range,true);
            $out['work_totals']=$this->query('SELECT COUNT(*) AS task_days,COUNT(DISTINCT w.task_id) AS tasks FROM work_logs w JOIN tasks t ON t.id=w.task_id WHERE t.user_id=? AND w.log_date BETWEEN ? AND ?',[$this->uid,$from,$to])[0];
        }
        if ($this->allowed('mail')) {
            $out['mail']=$this->search(['kind'=>'mail']+$range,true,"f.kind IN ('inbox','other','archive')",'((6-COALESCE(a.priority,5))*20+COALESCE(a.relevance,0)) DESC,r.id DESC');
            $out['mail_date_basis']='Unread mail belongs to today; read mail is pinned to read_at. sent_at remains the original sending date.';
        }
        if ($this->allowed('reports')) $out['report']=$this->search(['kind'=>'report','limit'=>3],true,'r.period_end>=' . $this->db->quote($from) . ' AND r.period_start<=' . $this->db->quote($to));
        if ($this->allowed('profile')) {
            $out['identity']=$this->search(['kind'=>'profile_document','limit'=>1],true,'1=1','r.is_primary DESC,r.id DESC');
            $out['profile_anchor']=$this->search(['kind'=>'profile_snapshot','limit'=>1],true,"r.kind IN ('base','stage')");
            $anchorId = (int)substr($out['profile_anchor']['items'][0]['ref'] ?? 'profile_snapshot:0',17);
            $out['profile_increment']=$this->search(['kind'=>'profile_snapshot','limit'=>1],true,"r.kind='incremental' AND r.id>$anchorId");
            $out['profile_rule']='Uploaded documents are authoritative; impressions are secondary. Excerpts are not complete profiles; read refs when personalising advice.';
        }
        $out['next']='Samples only. Use search(kind, date_from, date_to, before_id) for exhaustive review; read(refs) for evidence. No AI inference or mail synchronisation is triggered.';
        return $out;
    }

    /** Batch up to 8 refs; text pagination is explicit and Unicode character based. */
    public function read(array $a): array {
        $refs = $a['refs'] ?? [];
        if (!is_array($refs) || !$refs || count($refs)>8) throw new ExternalApiError('refs must contain 1–8 references');
        $offset=$this->number($a,'offset',0,0,100000000); $chars=$this->number($a,'max_chars',2000,100,8000);
        if (count($refs)*$chars>16000) throw new ExternalApiError('refs × max_chars must not exceed 16000');
        $part=(string)($a['part'] ?? 'body');
        if (!in_array($part,['body','analysis','summary'],true)) throw new ExternalApiError('part must be body, analysis or summary');
        $items=[];
        foreach (array_unique($refs) as $ref) {
            if (!is_string($ref) || !preg_match('/^([a-z_]+):([1-9][0-9]{0,9})$/',$ref,$m)) throw new ExternalApiError('Invalid ref');
            $kind=$m[1]; $s=$this->spec($kind); $body=$s['body'];
            if ($part==='analysis') {
                if ($kind!=='mail') throw new ExternalApiError('analysis part only supports mail');
                $body="CASE WHEN a.status='ok' THEN a.detailed_md ELSE '' END";
            } elseif ($part==='summary') {
                $body=match($kind) { 'mail'=>"CASE WHEN a.status='ok' THEN a.summary ELSE r.snippet END",'conversation'=>'r.summary_text',default=>$s['body'] };
            }
            $fields=$s['fields'] ? ','.$s['fields'] : '';
            if ($kind==='mail') $fields.=',r.to_json,r.cc_json';
            $rows=$this->query("SELECT r.id,{$s['title']} AS title,{$s['date']} AS date$fields,SUBSTRING(COALESCE($body,''),?,?) AS text,CHAR_LENGTH(COALESCE($body,'')) AS text_chars FROM {$s['from']} WHERE {$s['owner']}=? AND r.id=? AND {$s['where']}",[$offset+1,$chars,$this->uid,(int)$m[2]]);
            if (!$rows) { $items[]=['ref'=>$ref,'error'=>'not_found']; continue; }
            $r=$rows[0]; $r['part']=$part; $r['offset']=$offset; $r['text_chars']=(int)$r['text_chars'];
            $r['next_offset']=$offset+mb_strlen($r['text'])<$r['text_chars'] ? $offset+mb_strlen($r['text']) : null;
            $r['text_available']=$r['text_chars']>0;
            if ($kind==='mail') {
                $r['to']=json_decode($r['to_json']??'[]',true)?:[]; $r['cc']=json_decode($r['cc_json']??'[]',true)?:[]; unset($r['to_json'],$r['cc_json']);
            }
            $r=$this->row($kind,$r);
            if ($offset===0) $r['related']=$this->related($kind,(int)$m[2]);
            $items[]=$r;
        }
        return ['items'=>$items];
    }

    private function related(string $kind,int $id): array {
        $out=[];
        if ($kind==='task') {
            $out['tags']=$this->query("SELECT CONCAT('tag:',g.id) AS ref,g.name FROM tags g JOIN task_tags l ON l.tag_id=g.id WHERE l.task_id=? AND g.user_id=? LIMIT 20",[$id,$this->uid]);
            $out['results']=$this->query("SELECT CONCAT('result:',z.id) AS ref,z.name FROM results z JOIN task_results l ON l.result_id=z.id WHERE l.task_id=? AND z.user_id=? LIMIT 20",[$id,$this->uid]);
            if ($this->allowed('contacts')) $out['people']=$this->query("SELECT CONCAT('person:',p.id) AS ref,p.name FROM people p JOIN task_people l ON l.people_id=p.id WHERE l.task_id=? AND p.user_id=? LIMIT 20",[$id,$this->uid]);
            if ($this->allowed('mail')) $out['mail']=$this->query("SELECT CONCAT('mail:',m.id) AS ref,LEFT(m.subject,160) AS title,l.status,l.score FROM task_mail_links l JOIN mail_messages m ON m.id=l.message_id AND m.user_id=l.user_id WHERE l.task_id=? AND l.user_id=? AND m.is_deleted=0 AND l.status<>'rejected' ORDER BY l.score DESC LIMIT 20",[$id,$this->uid]);
            $out['history_query']=['kind'=>'note','task_id'=>$id];
            $out['lists_are_samples']=true;
        }
        if ($this->allowed('attachments') && in_array($kind,['task','result','report','mail','note'],true)) {
            $out['attachments']=$this->search(['kind'=>'attachment','parent_ref'=>"$kind:$id",'limit'=>10]);
        }
        return $out;
    }

    public function execute(string $action,array $args): array {
        $data=match($action) { 'overview'=>$this->overview($args),'search'=>$this->search($args),'read'=>$this->read($args),default=>throw new ExternalApiError('Unknown operation',404) };
        $version=substr(hash('sha256',$this->uid . ':' . json_encode([$this->scopes,$data],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)),0,24);
        if (($args['if_version']??'')===$version) return ['version'=>$version,'unchanged'=>true];
        return ['version'=>$version,'data'=>$data];
    }
}
