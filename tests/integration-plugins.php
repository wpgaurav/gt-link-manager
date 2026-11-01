<?php
require_once __DIR__.'/reflection.php';
/**
 * Records clicks through whichever real analytics plugins are active, then reads what
 * they stored. These adapters lean on other plugins' internals, so rerun this after
 * those plugins update. Plugins that are not active are skipped. Disposable fixture only.
 */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-integrations.php';
require_once GTLM_PATH.'includes/class-gtlm-integration-plugins.php';
$checks=[];$skipped=[];function ip_check($ok,$label,$detail=null){global $checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$p=$wpdb->prefix;$db=new GTLM_DB();wp_set_current_user(0);
$slug='ip-probe-'.wp_rand(1000,9999);
$id=$db->insert_link(['name'=>'Plugin Probe','slug'=>$slug,'url'=>'https://dest.example/page','redirect_type'=>302]);
$_SERVER=array_merge($_SERVER,['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/go/'.$slug,'HTTP_USER_AGENT'=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36','HTTP_REFERER'=>'https://news.example.com/story','REMOTE_ADDR'=>'8.8.8.8']);
$event=GTLM_Integrations::build_event($db->get_link_by_id($id),'https://dest.example/page',302,null,false);
$count=function(string $sql)use($wpdb){$wpdb->suppress_errors(true);$v=$wpdb->get_var($sql);return (int)$v;};
$available=fn($tool)=>GTLM_Integration_Plugins::available($tool);

$tool='independent_analytics';
if($available($tool)){
 GTLM_Integration_Plugins::register_hooks([$tool]);
 $vid='gtlm_link_'.$id;$before=$count("SELECT COUNT(*) FROM {$p}independent_analytics_views");
 GTLM_Integration_Plugins::record($tool,$event);GTLM_Integration_Plugins::record($tool,$event);
 ip_check($before+2===$count("SELECT COUNT(*) FROM {$p}independent_analytics_views"),'Independent Analytics records every click as a view');
 $row=$wpdb->get_row($wpdb->prepare("SELECT cached_title,cached_url,cached_type,cached_type_label FROM {$p}independent_analytics_resources WHERE virtual_page_id=%s",$vid),ARRAY_A);
 ip_check('Plugin Probe'===($row['cached_title']??'')&&'GT Link'===($row['cached_type_label']??'')&&str_ends_with((string)($row['cached_url']??''),'/'.$slug),'Independent Analytics names the page after the link',$row);
 $wpdb->delete("{$p}independent_analytics_resources",['virtual_page_id'=>$vid]);
}else{$skipped[]=$tool;}

$tool='koko_analytics';
if($available($tool)){
 $buffer=wp_upload_dir()['basedir'].'/koko-analytics/';$files=fn()=>implode('',array_map('file_get_contents',array_filter((array)glob($buffer.'buffer-*.csv'),'is_file')));
 GTLM_Integration_Plugins::record($tool,$event);
 ip_check(str_contains($files(),'/go/'.$slug),'Koko Analytics buffers a pageview for the short link path');
}else{$skipped[]=$tool;}

$tool='wp_statistics';
if($available($tool)){
 GTLM_Integration_Plugins::register_hooks([$tool]);
 $like='%'.$wpdb->esc_like($slug).'%';$before=$count($wpdb->prepare("SELECT COUNT(*) FROM {$p}statistics_events WHERE event_name='gtlm_link_click' AND event_data LIKE %s",$like));
 GTLM_Integration_Plugins::record($tool,$event);
 ip_check($before+1===$count($wpdb->prepare("SELECT COUNT(*) FROM {$p}statistics_events WHERE event_name='gtlm_link_click' AND event_data LIKE %s",$like)),'WP Statistics stores a gtlm_link_click custom event');
 $wpdb->query($wpdb->prepare("DELETE FROM {$p}statistics_events WHERE event_data LIKE %s",$like));
}else{$skipped[]=$tool;}

$tool='burst_statistics';
if($available($tool)){
 $was=burst_get_option('enable_custom_events');burst_update_option('enable_custom_events',1);
 // Burst attaches the event to the visitor's last statistic, so give this visitor one.
 $uid=md5('gtlm-probe');$_COOKIE['burst_uid']=$uid;
 $tracking=\Burst\burst_loader()->frontend->tracking;
 $stat=$tracking->get_last_user_statistic($uid);
 $resolve=new ReflectionMethod($tracking,'resolve_uid_id');$resolve->setAccessible(true);$uidId=(int)$resolve->invoke($tracking,$uid);
 $wpdb->insert("{$p}burst_statistics",['page_url'=>'/','page_id'=>0,'page_type'=>'page','time'=>time(),'uid_id'=>$uidId,'parameters'=>'','fragment'=>'']);$statId=(int)$wpdb->insert_id;$stat=$tracking->get_last_user_statistic($uid);
 $name='link_click_'.$slug;$q=$wpdb->prepare("SELECT COUNT(*) FROM {$p}burst_events e JOIN {$p}burst_event_names n ON n.ID=e.name_id WHERE n.name=%s",$name);
 if(!$stat){$skipped[]=$tool.' (could not create a Burst visitor)';}else{
  GTLM_Integration_Plugins::record($tool,$event);
  ip_check(1===$count($q),'Burst Statistics stores a custom event for a visitor with its cookie');
 }
 unset($_COOKIE['burst_uid']);$c=$count($q);GTLM_Integration_Plugins::record($tool,$event);
 ip_check($c===$count($q),'Burst Statistics is skipped for a visitor without its cookie');
 $wpdb->delete("{$p}burst_events",['statistic_id'=>$statId]);$wpdb->delete("{$p}burst_statistics",['ID'=>$statId]);
 burst_update_option('enable_custom_events',$was);
}else{$skipped[]=$tool;}

$tool='slimstat';
if($available($tool)){
 $q=$wpdb->prepare("SELECT COUNT(*) FROM {$p}slim_stats WHERE resource=%s",'/go/'.$slug);$before=$count($q);
 GTLM_Integration_Plugins::record($tool,$event);
 ip_check($before+1===$count($q),'SlimStat records a pageview of the short link path');
}else{$skipped[]=$tool;}

$tool='statify';
if($available($tool)){
 $like='/go/'.$wpdb->esc_like($slug).'%';$q=$wpdb->prepare("SELECT COUNT(*) FROM {$p}statify WHERE target LIKE %s",$like);$before=$count($q);
 GTLM_Integration_Plugins::record($tool,$event);
 ip_check($before+1===$count($q),'Statify counts a view of the short link path');
 $wpdb->query($wpdb->prepare("DELETE FROM {$p}statify WHERE target LIKE %s",$like));
}else{$skipped[]=$tool;}

$tool='matomo_plugin';
if($available($tool)&&(new \WpMatomo\Settings())->is_tracking_enabled()){
 // Matomo tracks over HTTP, so this needs a web server; the request is checked, not its result.
 $seen=null;add_filter('pre_http_request',$f=function($pre,$args,$url)use(&$seen){if(str_contains($url,'matomo')){$seen=[$url,$args];return ['headers'=>[],'body'=>'','response'=>['code'=>204,'message'=>''],'cookies'=>[],'filename'=>null];}return $pre;},10,3);
 GTLM_Integration_Plugins::record($tool,$event);remove_filter('pre_http_request',$f,10);
 $sentUrl=(string)($seen[0]??'');parse_str((string)wp_parse_url($sentUrl,PHP_URL_QUERY),$query);
 ip_check('Redirect'===($query['e_c']??'')&&'Link Click'===($query['e_a']??'')&&'Plugin Probe'===($query['e_n']??'')&&str_ends_with((string)($query['url']??''),'/go/'.$slug),'Matomo for WordPress sends an event hit for the short link',$query);
}else{$skipped[]=$tool;}

$db->delete_link($id);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'skipped'=>$skipped,'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
