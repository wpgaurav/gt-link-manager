<?php
require_once __DIR__.'/reflection.php';
/** Chart geometry checks on a disposable fixture only. */
if(!defined('GTLM_TEST_FIXTURE')||!GTLM_TEST_FIXTURE||!str_starts_with(DB_NAME,'gtlm_test_'))exit(2);
require_once GTLM_PATH.'includes/class-gtlm-analytics-view.php';
$method=gtlm_test_access((new ReflectionClass(GTLM_Analytics_View::class))->getMethod('chart_data'));$checks=[];function chart_check($ok,$label){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label];}
$one=$method->invoke(null,[['day'=>'2026-09-13 10:00:00 +05:30','clicks'=>3]],'hour');chart_check((float)explode(',',$one['points'][0])[0]===450.0,'One recorded bucket is centered');
$rows=[['day'=>'2026-09-13 10:00:00 +05:30','clicks'=>3],['day'=>'2026-09-13 11:00:00 +05:30','clicks'=>5],['day'=>'2026-09-13 15:00:00 +05:30','clicks'=>2]];$chart=$method->invoke(null,$rows,'hour');$xs=array_map(fn($p)=>(float)explode(',',$p)[0],$chart['points']);chart_check($xs[0]===24.0&&$xs[2]===876.0,'Activity spans the plot instead of clustering at the selected range edge');chart_check(abs(($xs[1]-$xs[0])/($xs[2]-$xs[0])-0.2)<0.001,'Time gaps retain their correct relative spacing');
$before=get_option('timezone_string');update_option('timezone_string','Asia/Kathmandu');$chart=$method->invoke(null,$rows,'hour');chart_check(str_contains($chart['first_label'],'10:15 +05:45'),'Axis labels follow the WordPress timezone');update_option('timezone_string',$before);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
