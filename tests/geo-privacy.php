<?php
require_once __DIR__.'/reflection.php';
/** The geo privacy notes against every analytics and sharing state, then against what the collector really saves. Disposable fixture only. */
if(!defined('GTLM_TEST_FIXTURE')||!GTLM_TEST_FIXTURE||!str_starts_with(DB_NAME,'gtlm_test_'))exit(2);
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations.php';
$checks=[];function gp_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global$wpdb;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();$pages=new GTLM_Admin_Pages($db,$s,new GTLM_Import($db,$s));

// Read the rendered screens, not the helpers, so a call site can't drift from them.
$render=function(string $method,string $pattern,array $args=[])use($pages){$m=gtlm_test_access(new ReflectionMethod($pages,$method));ob_start();$m->invoke($pages,...$args);$html=html_entity_decode((string)ob_get_clean(),ENT_QUOTES);return preg_match($pattern,$html,$found)?$found[1]:'';};
$notes=fn()=>['link editor'=>$render('render_geo_rules_field','#<p class="description">(Privacy: .*?)</p>#',['targeted','',302]),'settings'=>$render('render_geo_detection_status','#<p class="description">(Country detection reads .*?)</p>#')];
$analytics=function($gate,$option)use($s){$s->set_analytics_gate($gate);if(false===$option)delete_option('gtlm_analytics');else update_option('gtlm_analytics',$option,false);};
$sharing=function(array $tools,int $share)use($s){update_option(GTLM_Integrations::OPTION,['tools'=>$tools,'share_visitor_data'=>$share],false);$s->set_enabled_integrations(array_keys($tools));};

$analytics_states=[
 'never set up'=>[null,false,'none'],
 'forgotten, with a stale option'=>[null,['country_source'=>'auto'],'none'],
 'running, countries off'=>[true,['country_source'=>'none'],'off'],
 'paused, countries off'=>[false,['country_source'=>'none'],'off'],
 'running, option missing'=>[true,false,'off'],
 'running, option unreadable'=>[true,'garbage','off'],
 'running, no source saved'=>[true,[],'off'],
 'running, countries from auto'=>[true,['country_source'=>'auto'],'saved'],
 'running, countries from Cloudflare'=>[true,['country_source'=>'cloudflare'],'saved'],
 'running, countries from a custom header'=>[true,['country_source'=>'custom','country_header'=>'X-GEO-COUNTRY'],'saved'],
 'paused, countries on'=>[false,['country_source'=>'auto'],'saved'],
];
$plausible=['plausible'=>['enabled'=>1,'domain'=>'d.example']];
$sharing_states=[
 'no integrations'=>[[],0,false],
 'a service without sharing'=>[$plausible,0,false],
 'a browser tag with sharing on'=>[['ga4'=>['enabled'=>1,'mode'=>'browser','measurement_id'=>'G-ABCD1234']],1,false],
 'a local plugin with sharing on'=>[['koko_analytics'=>['enabled'=>1]],1,false],
 'a service with sharing on'=>[$plausible,1,true],
];
$says=[
 'none'=>["doesn't store or log the country"],
 'off'=>['Include countries is off','new clicks are recorded without the country'],
 'saved'=>['Include countries is on','each recorded click saves the country and whether a country rule matched'],
];
$denies=[
 'none'=>['Include countries','saves the country','Reports keep'],
 'off'=>["doesn't store",'saves the country','only for that one request'],
 'saved'=>["doesn't store",'without the country','only for that one request'],
];
foreach($analytics_states as $a_label=>[$gate,$option,$state]){
 foreach($sharing_states as $s_label=>[$tools,$share,$shared]){
  $analytics($gate,$option);$sharing($tools,$share);
  foreach($notes() as $where=>$note){
   $want=$says[$state];$avoid=array_merge($denies[$state],['never stores','never stored','no external service']);
   if('settings'===$where&&'none'!==$state)$want[]='Reports keep any countries already saved';
   if('settings'===$where&&'none'===$state&&!$shared)$want[]='only for that one request';
   if($shared){$want[]='shares';$avoid=array_merge($avoid,['reads no IP address',"never reads the visitor's IP",'only for that one request']);}
   else{$want[]='settings'===$where?"never reads the visitor's IP address":'reads no IP address';$avoid[]='shares';}
   $missing=array_filter($want,fn($t)=>!str_contains($note,$t));$false=array_filter($avoid,fn($t)=>str_contains($note,$t));
   gp_check(''!==$note&&!$missing&&!$false,"$where: $a_label, $s_label",compact('note','missing','false'));
  }
 }
}
gp_check(!class_exists('GTLM_Analytics',false)&&!class_exists('GTLM_Analytics_DB',false)&&!class_exists('GTLM_Analytics_Collector',false),'Rendering the notes in every state loads no analytics class');

// The claim each note makes has to match what the collector writes.
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';require_once GTLM_PATH.'includes/class-gtlm-analytics-collector.php';
$analytics(null,false);$sharing([],0);GTLM_Analytics::delete();
$id=$db->insert_link(['name'=>'Geo privacy fixture','slug'=>'geo-privacy-fixture','url'=>home_url('/target')]);$link=$db->get_link_by_id($id);
wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Chrome/130 Safari';$_SERVER['HTTP_CF_IPCOUNTRY']='IN';$_GET=[];
$latest=fn()=>$wpdb->get_row('SELECT country,geo FROM '.GTLM_Analytics_DB::events_table().' ORDER BY id DESC LIMIT 1',ARRAY_A);
$matched=['matched'=>true,'country'=>'IN'];

gp_check(!GTLM_Analytics_Collector::collect($link,302,$matched)&&!$wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'")&&str_contains($notes()['settings'],"doesn't store or log"),'Before setup nothing is written, and the note says so');
GTLM_Analytics::enable(['country_source'=>'cloudflare']);$written=GTLM_Analytics_Collector::collect($link,302,$matched);$row=$latest();
gp_check($written&&'IN'===$row['country']&&'matched'===$row['geo']&&str_contains($notes()['settings'],'each recorded click saves the country and whether a country rule matched'),'Countries on: the click keeps the country and the rule match, as the note says',$row);
GTLM_Analytics::pause();gp_check(!GTLM_Analytics_Collector::collect($link,302,$matched)&&str_contains($notes()['link editor'],'each recorded click saves the country'),'Paused: no new click is written, and the earlier click still holds its country');
GTLM_Analytics::delete();GTLM_Analytics::enable(['country_source'=>'none']);$written=GTLM_Analytics_Collector::collect($link,302,$matched);$row=$latest();
gp_check($written&&''===$row['country']&&'off'===$row['geo']&&str_contains($notes()['settings'],'new clicks are recorded without the country'),'Countries off: the click has no country and no rule match, as the note says',$row);
GTLM_Analytics::delete();gp_check(str_contains($notes()['link editor'],"doesn't store or log"),'Deleting analytics returns to the first note');

// Cleanup.
unset($_SERVER['HTTP_CF_IPCOUNTRY']);$db->delete_link($id);delete_option(GTLM_Integrations::OPTION);$s->set_enabled_integrations([]);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
