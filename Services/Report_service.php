<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class Report_service
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db = null, private ?Audit_service $audit = null)
    {
        $this->db = $db ?? db_connect('default');
        $this->audit ??= new Audit_service();
    }

    public function generate(array $filters): array
    {
        $range = $this->range($filters);
        $instanceId = $this->instanceId($filters['instance_id'] ?? null);
        $conversationsTable = $this->db->prefixTable('chat_conversations');
        $messagesTable = $this->db->prefixTable('chat_messages');
        $instancesTable = $this->db->prefixTable('chat_instances');
        $usersTable = $this->db->prefixTable('users');
        $aiLogsTable = $this->db->prefixTable('chat_ai_logs');
        $conversationTagsTable = $this->db->prefixTable('chat_conversation_tags');
        $tagsTable = $this->db->prefixTable('chat_tags');

        $conversationBase = $this->db->table($conversationsTable)
            ->where($conversationsTable . '.deleted', 0)
            ->where($conversationsTable . '.created_at >=', $range['from'])
            ->where($conversationsTable . '.created_at <=', $range['to']);
        $messageBase = $this->db->table($messagesTable)
            ->where($messagesTable . '.deleted', 0)
            ->where($messagesTable . '.is_internal_note', 0)
            ->where($messagesTable . '.created_at >=', $range['from'])
            ->where($messagesTable . '.created_at <=', $range['to']);
        if ($instanceId) {
            $conversationBase->where($conversationsTable . '.instance_id', $instanceId);
            $messageBase->where($messagesTable . '.instance_id', $instanceId);
        }

        $conversationTotal = (clone $conversationBase)->countAllResults();
        $messagesIn = (clone $messageBase)->where($messagesTable . '.direction', 'incoming')->countAllResults();
        $messagesOut = (clone $messageBase)->where($messagesTable . '.direction', 'outgoing')->countAllResults();
        $failed = (clone $messageBase)->where($messagesTable . '.status', 'failed')->countAllResults();
        $resolved = (clone $conversationBase)->where($conversationsTable . '.status', 'resolved')->countAllResults();
        $responded = (clone $conversationBase)->where($conversationsTable . '.first_response_at IS NOT NULL', null, false)->countAllResults();
        $unread = (clone $conversationBase)->select('COALESCE(SUM(' . $conversationsTable . '.unread_count),0) total', false)->get()->getRowArray();
        $first = (clone $conversationBase)->select('COALESCE(AVG(' . $conversationsTable . '.first_response_seconds),0) average', false)->where($conversationsTable . '.first_response_seconds IS NOT NULL', null, false)->get()->getRowArray();
        $resolution = (clone $conversationBase)->select('COALESCE(AVG(TIMESTAMPDIFF(SECOND,' . $conversationsTable . '.created_at,' . $conversationsTable . '.resolved_at)),0) average', false)->where($conversationsTable . '.resolved_at IS NOT NULL', null, false)->get()->getRowArray();
        $aiResolvedBuilder = clone $conversationBase;
        $aiResolved = $aiResolvedBuilder->where($conversationsTable . '.status', 'resolved')
            ->where($conversationsTable . '.last_bot_message_at IS NOT NULL', null, false)
            ->groupStart()
            ->where($conversationsTable . '.last_human_message_at IS NULL', null, false)
            ->orWhere($conversationsTable . '.last_bot_message_at > ' . $conversationsTable . '.last_human_message_at', null, false)
            ->groupEnd()->countAllResults();

        $volumeRows = (clone $conversationBase)
            ->select('DATE(' . $conversationsTable . '.created_at) day, COUNT(' . $conversationsTable . '.id) total', false)
            ->groupBy('DATE(' . $conversationsTable . '.created_at)')->orderBy('day', 'ASC')->get()->getResultArray();
        $volumeMap = []; foreach ($volumeRows as $row) $volumeMap[(string)$row['day']] = (int)$row['total'];
        $labels=[];$volume=[];$volumeObjects=[];$cursor=(new DateTimeImmutable($range['from'].' UTC'))->setTimezone($range['timezone']);$end=(new DateTimeImmutable($range['to'].' UTC'))->setTimezone($range['timezone']);
        while($cursor->format('Y-m-d')<=$end->format('Y-m-d')){ $utcDay=$cursor->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');$value=$volumeMap[$utcDay]??0;$labels[]=$cursor->format('d/m');$volume[]=$value;$volumeObjects[]=['label'=>$cursor->format('d/m'),'value'=>$value];$cursor=$cursor->modify('+1 day'); if(count($labels)>366)break; }

        $channelBuilder = $this->db->table($conversationsTable)
            ->select($instancesTable . '.id, ' . $instancesTable . '.name, COUNT(' . $conversationsTable . '.id) count', false)
            ->join($instancesTable, $instancesTable . '.id=' . $conversationsTable . '.instance_id AND ' . $instancesTable . '.deleted=0', 'left')
            ->where($conversationsTable . '.deleted', 0)
            ->where($conversationsTable . '.created_at >=', $range['from'])
            ->where($conversationsTable . '.created_at <=', $range['to']);
        if ($instanceId) $channelBuilder->where($conversationsTable . '.instance_id', $instanceId);
        $channelRows = $channelBuilder->groupBy($conversationsTable . '.instance_id')->orderBy('count', 'DESC')->get()->getResultArray();
        $channels = [];
        foreach ($channelRows as $row) {
            $count = (int) $row['count'];
            $channels[] = ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['name'] ?? 'Instancia'), 'count' => $count, 'value' => $conversationTotal > 0 ? round($count * 100 / $conversationTotal, 1) . '%' : '0%'];
        }

        $agentBuilder = $this->db->table($conversationsTable)
            ->select($conversationsTable . ".assignee_id, CONCAT(COALESCE(" . $usersTable . ".first_name,''),' ',COALESCE(" . $usersTable . ".last_name,'')) name, COUNT(" . $conversationsTable . '.id) conversations, SUM(CASE WHEN ' . $conversationsTable . ".status='resolved' THEN 1 ELSE 0 END) resolved, COALESCE(AVG(" . $conversationsTable . '.first_response_seconds),0) first_response', false)
            ->join($usersTable, $usersTable . '.id=' . $conversationsTable . '.assignee_id AND ' . $usersTable . '.deleted=0', 'left')
            ->where($conversationsTable . '.deleted', 0)
            ->where($conversationsTable . '.assignee_id IS NOT NULL', null, false)
            ->where($conversationsTable . '.created_at >=', $range['from'])
            ->where($conversationsTable . '.created_at <=', $range['to']);
        if ($instanceId) $agentBuilder->where($conversationsTable . '.instance_id', $instanceId);
        $agentRows = $agentBuilder->groupBy($conversationsTable . '.assignee_id')->orderBy('conversations', 'DESC')->get()->getResultArray();
        $agents = [];
        foreach ($agentRows as $row) {
            $conversations = (int) $row['conversations'];
            $agents[] = ['id' => (int) $row['assignee_id'], 'name' => trim((string) $row['name']) ?: 'Atendente', 'team' => 'Atendimento', 'conversations' => $conversations, 'resolved' => (int) $row['resolved'], 'first_response' => $this->duration((int) $row['first_response']), 'reply_rate' => $conversations > 0 ? round(((int) $row['resolved']) * 100 / $conversations, 1) . '%' : '0%'];
        }

        $aiBuilder = $this->db->table($aiLogsTable)->select("SUM(CASE WHEN event_name IN ('message.generated','message.sent') AND status='success' THEN 1 ELSE 0 END) responses, SUM(CASE WHEN event_name='state.change' AND request_payload LIKE '%handoff%' THEN 1 ELSE 0 END) handoffs, SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) errors", false)->where($aiLogsTable . '.deleted', 0)->where($aiLogsTable . '.created_at >=', $range['from'])->where($aiLogsTable . '.created_at <=', $range['to']);
        if ($instanceId) $aiBuilder->where($aiLogsTable . '.instance_id', $instanceId);
        $aiRow = $aiBuilder->get()->getRowArray() ?: [];
        $qualifiedBuilder = $this->db->table($conversationsTable)
            ->select('COUNT(DISTINCT ' . $conversationsTable . '.id) total', false)
            ->join($conversationTagsTable, $conversationTagsTable . '.conversation_id=' . $conversationsTable . '.id AND ' . $conversationTagsTable . '.deleted=0', 'left')
            ->join($tagsTable, $tagsTable . '.id=' . $conversationTagsTable . '.tag_id AND ' . $tagsTable . '.deleted=0', 'left')
            ->where($conversationsTable . '.deleted', 0)
            ->where($conversationsTable . '.created_at >=', $range['from'])
            ->where($conversationsTable . '.created_at <=', $range['to'])
            ->whereIn($tagsTable . '.normalized_name', ['qualificado', 'qualified', 'matriculado']);
        if ($instanceId) $qualifiedBuilder->where($conversationsTable . '.instance_id', $instanceId);
        $qualifiedRow = $qualifiedBuilder->get()->getRowArray();

        $avgFirst=(int)($first['average']??0);$avgResolution=(int)($resolution['average']??0);$resolutionRate=$conversationTotal?round($resolved*100/$conversationTotal,1):0;$aiRate=$resolved?round($aiResolved*100/$resolved,1):0;
        return [
            'summary'=>['conversations'=>$conversationTotal,'messages_in'=>$messagesIn,'messages_out'=>$messagesOut,'avg_first_response_seconds'=>$avgFirst,'resolution_rate'=>$resolutionRate,'ai_resolution_rate'=>$aiRate,'unread'=>(int)($unread['total']??0),'failed_messages'=>$failed,'received'=>$conversationTotal,'first_response'=>$this->duration($avgFirst),'resolution_time'=>$this->duration($avgResolution),'reply_rate'=>($conversationTotal?round($responded*100/$conversationTotal,1):0).'%','channel_total'=>$conversationTotal],
            'received'=>$conversationTotal,'first_response'=>$this->duration($avgFirst),'resolution_time'=>$this->duration($avgResolution),'reply_rate'=>($conversationTotal?round($responded*100/$conversationTotal,1):0).'%',
            'volume'=>$volumeObjects,'volume_values'=>$volume,'volume_series'=>$volumeObjects,'labels'=>$labels,'channels'=>$channels,'agents'=>$agents,
            'ai'=>['responses'=>(int)($aiRow['responses']??0),'handoffs'=>(int)($aiRow['handoffs']??0),'errors'=>(int)($aiRow['errors']??0),'response_time'=>'—','resolution_rate'=>$aiRate],
            'funnel'=>['received'=>$conversationTotal,'replied'=>$responded,'qualified'=>(int)($qualifiedRow['total']??0),'resolved'=>$resolved],
            'period'=>['from'=>(new DateTimeImmutable($range['from'].' UTC'))->setTimezone($range['timezone'])->format(DATE_ATOM),'to'=>(new DateTimeImmutable($range['to'].' UTC'))->setTimezone($range['timezone'])->format(DATE_ATOM),'timezone'=>$range['timezone']->getName()],
        ];
    }

    public function csv(array $filters,int $actorId):string
    {
        $data=$this->generate($filters);$h=fopen('php://temp','w+');fputcsv($h,['section','metric','label','value']);foreach($data['summary']as$key=>$value)fputcsv($h,['summary',$key,'',$this->safe($value)]);foreach($data['volume_series']as$row)fputcsv($h,['volume','conversations',$this->safe($row['label']),$this->safe($row['value'])]);foreach($data['channels']as$row)fputcsv($h,['channel','conversations',$this->safe($row['name']),$this->safe($row['count'])]);foreach($data['agents']as$row)fputcsv($h,['agent','conversations',$this->safe($row['name']),$this->safe($row['conversations'])]);rewind($h);$csv=stream_get_contents($h)?:'';fclose($h);$this->audit->record($actorId,'report.exported','report',null,$this->instanceId($filters['instance_id']??null),[],['period'=>$filters['period']??'7d']);return"\xEF\xBB\xBF".$csv;
    }

    private function range(array $filters):array
    {
        $timezoneName=trim((string)($filters['timezone']??'America/Sao_Paulo'));try{$tz=new DateTimeZone($timezoneName);}catch(\Throwable $e){throw new InvalidArgumentException('Fuso horario invalido.');}$now=new DateTimeImmutable('now',$tz);$period=strtolower(trim((string)($filters['period']??'7d')));
        if($period==='custom'){$fromInput=trim((string)($filters['from']??''));$toInput=trim((string)($filters['to']??''));if($fromInput===''||$toInput==='')throw new InvalidArgumentException('Informe inicio e fim do periodo personalizado.');$from=new DateTimeImmutable($fromInput,$tz);$to=new DateTimeImmutable($toInput,$tz);}elseif($period==='24h'){$to=$now;$from=$now->modify('-24 hours');}elseif($period==='30d'){$to=$now;$from=$now->modify('-29 days')->setTime(0,0);}elseif($period==='month'){$to=$now;$from=$now->modify('first day of this month')->setTime(0,0);}else{$to=$now;$from=$now->modify('-6 days')->setTime(0,0);}
        if($from>$to||$to->getTimestamp()-$from->getTimestamp()>366*86400)throw new InvalidArgumentException('Periodo de relatorio invalido.');$utc=new DateTimeZone('UTC');return['from'=>$from->setTimezone($utc)->format('Y-m-d H:i:s'),'to'=>$to->setTimezone($utc)->format('Y-m-d H:i:s'),'timezone'=>$tz];
    }
    private function instanceId($value):?int{$value=trim((string)$value);return$value!==''&&$value!=='all'&&(int)$value>0?(int)$value:null;}
    private function duration(int $seconds):string{if($seconds<=0)return'—';if($seconds<60)return$seconds.'s';if($seconds<3600)return round($seconds/60,1).' min';return round($seconds/3600,1).' h';}
    private function safe($value):string{$value=(string)$value;return preg_match('/^[=+\-@]/',$value)?"'".$value:$value;}
}
