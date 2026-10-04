<?php
require_once __DIR__.'/reflection.php';
/** Minute summaries past the individual-record window become daily totals. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-controller.php';
$checks=[];function roll_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_Analytics_DB();$links=new GTLM_DB();$hourly=GTLM_Analytics_DB::hourly_table();$events=GTLM_Analytics_DB::events_table();
$old_tz=get_option('timezone_string');$old_offset=get_option('gmt_offset');
$a=$links->insert_link(['name'=>'Rollup A','slug'=>'rollup-a','url'=>'https://example.com/a']);
$b=$links->insert_link(['name'=>'Rollup B','slug'=>'rollup-b','url'=>'https://example.com/b']);
$page='https://source.example.org/post/';$pk=hash('sha256',$page);

/** Sum of clicks per (link, local day, page cohort, dimension, value) in the active WordPress timezone. */
function roll_snapshot(): array {global $wpdb,$hourly;$out=[];$tz=wp_timezone();foreach($wpdb->get_results("SELECT link_id,bucket_start,page_key,dimension,value,clicks FROM {$hourly}",ARRAY_A) as $r){$day=(new DateTimeImmutable($r['bucket_start'],new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');$k=implode('|',[$r['link_id'],$day,$r['page_key'],$r['dimension'],$r['value']]);$out[$k]=($out[$k]??0)+(int)$r['clicks'];}ksort($out);return $out;}
function roll_insert(int $link,string $utc,string $page_key,string $dimension,string $value,int $clicks): void {global $wpdb,$hourly;$wpdb->query($wpdb->prepare("INSERT INTO {$hourly} (link_id,bucket_start,page_key,dimension,value,clicks) VALUES (%d,%s,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE clicks=clicks+VALUES(clicks)",$link,$utc,$page_key,$dimension,$value,$clicks));}
/** Minute rows for one click: totals, a few dimensions, and the page cohort. */
function roll_click(int $link,string $utc,string $page,string $pk,string $country): void {foreach(['total'=>'','source'=>'source.example.org','page'=>$page,'country'=>$country,'device'=>'mobile'] as $d=>$v){roll_insert($link,$utc,'',$d,$v,1);}foreach(['total'=>'','country'=>$country,'device'=>'mobile'] as $d=>$v){roll_insert($link,$utc,$pk,$d,$v,1);}}

foreach([['UTC',null],['Asia/Kolkata',null],['America/New_York','2026-03-08']] as [$zone,$dst]){
 GTLM_Analytics::delete();update_option('timezone_string',$zone);update_option('gmt_offset',0);
 $r=GTLM_Analytics::enable(['summary_days'=>0,'event_days'=>7]);roll_check(!is_wp_error($r),"[$zone] analytics enabled",$r);
 $tz=wp_timezone();$utc=new DateTimeZone('UTC');
 $local=fn(string $when)=>(new DateTimeImmutable($when,$tz))->setTimezone($utc)->format('Y-m-d H:i:s');
 // Old days: several clicks per day for two links, including one exactly at local midnight and one at 23:59.
 $days=$dst?['2026-03-07','2026-03-08','2026-03-09']:[gmdate('Y-m-d',time()-40*DAY_IN_SECONDS),gmdate('Y-m-d',time()-39*DAY_IN_SECONDS),gmdate('Y-m-d',time()-20*DAY_IN_SECONDS)];
 foreach($days as $i=>$d){foreach(['00:00','03:17','12:45','23:59'] as $j=>$t){roll_click(0===($j%2)?$a:$b,$local("$d $t"),$page,$pk,['US','IN','DE'][$i]);}}
 // A recent day inside the individual-record window keeps its minutes.
 $recent=$local((new DateTimeImmutable('today',$tz))->modify('-2 days')->format('Y-m-d').' 10:10');roll_click($a,$recent,$page,$pk,'GB');roll_click($a,gmdate('Y-m-d H:i:00',strtotime($recent.' UTC')+60),$page,$pk,'GB');
 $before=roll_snapshot();$rows_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$hourly}");
 $from=(new DateTimeImmutable('today',$tz))->modify('-89 days')->format('Y-m-d');$to=(new DateTimeImmutable('today',$tz))->format('Y-m-d');
 $report_before=GTLM_Analytics_Controller::report(['from'=>$from,'to'=>$to],false);
 GTLM_Analytics::process();GTLM_Analytics::process();
 $after=roll_snapshot();$config=GTLM_Analytics::config();
 roll_check($before===$after,"[$zone] every local-day total is unchanged",array_diff_assoc($before,$after)+array_diff_assoc($after,$before));
 roll_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$hourly}")<$rows_before,"[$zone] rolled days use fewer rows");
 foreach($days as $d){$start=$local("$d 00:00");$end=(new DateTimeImmutable("$d 00:00",$tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
  $stamps=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT bucket_start FROM {$hourly} WHERE bucket_start>=%s AND bucket_start<%s",$start,$end));
  roll_check([$start]===$stamps,"[$zone] $d is one bucket at local midnight",$stamps);}
 roll_check(2===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT bucket_start) FROM {$hourly} WHERE bucket_start>=%s",$local((new DateTimeImmutable('today',$tz))->modify('-3 days')->format('Y-m-d').' 00:00'))),"[$zone] days inside the record window keep minute buckets");
 roll_check(!empty($config['rolled_until'])&&$config['rolled_until']<=$db->event_cutoff(7)&&$config['rolled_until']>$local(end($days).' 00:00'),"[$zone] progress is recorded up to the window",$config['rolled_until']??null);
 // Idempotent: rolling an already rolled day again changes nothing.
 $first=$local($days[0].' 00:00');$next=(new DateTimeImmutable($days[0].' 00:00',$tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
 $db->rollup_range($first,$next);GTLM_Analytics::process();roll_check(roll_snapshot()===$after,"[$zone] re-rolling is a no-op");
 $report_after=GTLM_Analytics_Controller::report(['from'=>$from,'to'=>$to],false);
 roll_check($report_before['total']===$report_after['total']&&array_column($report_before['trend'],'clicks','day')==array_column($report_after['trend'],'clicks','day'),"[$zone] 90-day totals and daily trend are unchanged",[$report_before['total'],$report_after['total']]);
 // Only a range that reaches into rolled days loses hourly detail.
 $hour_to=min($to,(new DateTimeImmutable($days[0],$tz))->modify('+60 days')->format('Y-m-d'));
 $hourly_old=GTLM_Analytics_Controller::report(['from'=>$days[0],'to'=>$hour_to,'granularity'=>'hour'],false);
 $hourly_new=GTLM_Analytics_Controller::report(['from'=>(new DateTimeImmutable('today',$tz))->modify('-3 days')->format('Y-m-d'),'to'=>$to,'granularity'=>'hour'],false);
 roll_check(true===$hourly_old['hourly_limited']&&'day'===$hourly_old['filters']['granularity']&&false===$hourly_new['hourly_limited']&&'hour'===$hourly_new['filters']['granularity'],"[$zone] hourly trends reaching rolled days are grouped by day");
}

// A remaining raw click holds the rollup back: its day keeps minute rows until the click expires.
GTLM_Analytics::delete();update_option('timezone_string','UTC');GTLM_Analytics::enable(['summary_days'=>0,'event_days'=>7]);
$old_day=gmdate('Y-m-d',time()-30*DAY_IN_SECONDS);roll_click($a,"$old_day 08:00:00",$page,$pk,'US');roll_click($a,"$old_day 09:30:00",$page,$pk,'US');
$wpdb->insert($events,['link_id'=>$a,'occurred_at'=>"$old_day 08:00:00",'generation'=>GTLM_Analytics::config()['generation'],'processed'=>1,'page_processed'=>1,'source'=>'','page'=>'','country'=>'','device'=>'mobile','browser'=>'safari','os'=>'ios','campaign'=>0,'status'=>302,'mode'=>'standard','geo'=>'off']);
$rc=new ReflectionMethod(GTLM_Analytics::class,'rollup');$config=gtlm_test_access($rc)->invoke(null,$db,GTLM_Analytics::config(),microtime(true)+2.0);
roll_check(2===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT bucket_start) FROM {$hourly} WHERE bucket_start>=%s AND bucket_start<%s","$old_day 00:00:00",gmdate('Y-m-d',time()-29*DAY_IN_SECONDS).' 00:00:00'))&&empty($config['rolled_until']),'A day with remaining raw clicks is not rolled up',$config['rolled_until']??null);

GTLM_Analytics::delete();update_option('timezone_string',$old_tz);update_option('gmt_offset',$old_offset);$links->delete_link($a);$links->delete_link($b);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
