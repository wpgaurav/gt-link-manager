<?php
/** Integration checks. Run only in an isolated fixture defining GTLM_TEST_FIXTURE. */
if (!defined('GTLM_TEST_FIXTURE') || GTLM_TEST_FIXTURE !== true || !str_starts_with(DB_NAME, 'gtlm_test_')) {
 throw new RuntimeException('A disposable GTLM_TEST_FIXTURE database is required.');
}
$checks=[];
function verify($condition,$label,$details=null) { global $checks; $checks[]=['ok'=>(bool)$condition,'check'=>$label,'details'=>$condition?null:$details]; }
$db=new GTLM_DB();$settings=GTLM_Settings::get_instance();global $wpdb;
verify(!class_exists('GTLM_Analytics_Collector',false),'Disabled bootstrap does not load collector');
verify(!class_exists('GTLM_Analytics_DB',false),'Disabled bootstrap does not load analytics storage');
verify(!class_exists('GTLM_Admin',false),'Public bootstrap skips admin classes');
verify(!has_action('gtlm_analytics_maintenance'),'No analytics maintenance hook before opt-in');
verify(!get_option('gtlm_analytics',false),'No analytics option before opt-in');
verify(count($wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'")?:[])===0,'No analytics tables before opt-in');
add_filter('gtlm_settings',function($s){$s['enable_advanced_analytics']=1;return $s;});
verify(!$settings->advanced_analytics_enabled(),'Settings filters cannot manufacture analytics consent');
remove_all_filters('gtlm_settings');
$settings->update(array_merge($settings->all(),['enable_advanced_analytics'=>1]));
verify(!$settings->analytics_initialized(),'Ordinary settings save cannot initialize analytics');
$rc=new ReflectionClass(GTLM_Redirect::class);$redirect=$rc->newInstanceWithoutConstructor();$rc->getProperty('db')->setValue($redirect,$db);$rc->getProperty('settings')->setValue($redirect,$settings);$record=$rc->getMethod('record_click');
$id=$db->insert_link(['name'=>'Fixture','slug'=>'fixture','url'=>home_url('/target'),'redirect_type'=>302]);$link=$db->get_link_by_id($id);
$q=$wpdb->num_queries;$record->invoke($redirect,$link);verify($wpdb->num_queries===$q,'Disabled basic collector performs no queries');
$settings->update(array_merge($settings->all(),['enable_click_tracking'=>1]));
$wpdb->query($wpdb->prepare('UPDATE '.GTLM_DB::links_table().' SET updated_at=%s WHERE id=%d','2001-01-01 00:00:00',$id));
$record->invoke($redirect,$link);$row=$db->get_link_by_id($id);verify($row['total_clicks']===1 && $row['updated_at']==='2001-01-01 00:00:00','Atomic click preserves edit timestamp',$row);
$before=did_action('gtlm_click_recorded');$record->invoke($redirect,['id'=>999999]);verify(did_action('gtlm_click_recorded')===$before,'Missing row cannot emit successful click hook');
$db->reset_clicks($id);verify($db->get_link_by_id($id)['updated_at']==='2001-01-01 00:00:00','Counter reset preserves edit timestamp');
$stale=$db->get_link_by_id($id);$db->increment_clicks($id);$db->update_link($id,array_merge($stale,['name'=>'Edited']));verify($db->get_link_by_id($id)['total_clicks']===1,'Config edits preserve concurrent counter increments');
verify(GTLM_Redirect::valid_destination('https://a-valid-but-unresolvable-domain.invalid/path'),'Redirect validator requires no DNS');
verify(!GTLM_Redirect::valid_destination('javascript:alert(1)')&&!GTLM_Redirect::valid_destination('https://user:pass@example.com')&&!GTLM_Redirect::valid_destination("https://example.com\r\nX-Test: yes"),'Destination validator rejects schemes, credentials and control characters');
wp_set_current_user(1);
foreach(['direct'=>'docs/getting-started','regex'=>'^old/([0-9]+)$'] as $mode=>$slug){
 $req=new WP_REST_Request('POST','/gt-link-manager/v1/links');$req->set_body_params(['name'=>'REST '.$mode,'slug'=>$slug,'url'=>home_url('/target'),'link_mode'=>$mode,'regex_replacement'=>home_url('/new/$1'),'redirect_type'=>307]);$resp=rest_do_request($req);$data=$resp->get_data();verify($resp->get_status()===201&&($data['slug']??null)===$slug,'REST preserves '.$mode.' slug',$data);
 if(isset($data['id'])){$r=new WP_REST_Request('PATCH','/gt-link-manager/v1/links/'.$data['id']);$r->set_body_params(['slug'=>$slug,'name'=>'Patched','total_clicks'=>900]);$p=rest_do_request($r)->get_data();verify(($p['slug']??null)===$slug&&($p['redirect_type']??0)===307&&($p['total_clicks']??-1)===0,'PATCH uses existing mode and preserves omitted fields',$p);}
}
$r=new WP_REST_Request('POST','/gt-link-manager/v1/links');$r->set_body_params(['name'=>'Bad regex','slug'=>'[','url'=>home_url('/target'),'link_mode'=>'regex']);verify(rest_do_request($r)->get_status()===400,'REST rejects malformed regex');
require_once GTLM_PATH.'includes/class-gt-link-import.php';$import=new GTLM_Import($db,$settings);$convert=new ReflectionMethod(GTLM_Import::class,'row_to_link_data');
$mapped=$convert->invoke($import,['Direct','docs/test',home_url('/target'),'direct','',17,'0'],['name'=>0,'slug'=>1,'url'=>2,'link_mode'=>3,'regex_replacement'=>4,'priority'=>5,'is_active'=>6]);verify($mapped['slug']==='docs/test'&&$mapped['link_mode']==='direct'&&$mapped['priority']===17&&$mapped['is_active']===0,'CSV advanced fields round trip',$mapped);
$fallback=array_merge($mapped,['category_id'=>0]);$mapped=$convert->invoke($import,['Renamed','docs/test',home_url('/new-target')],['name'=>0,'slug'=>1,'url'=>2],$fallback);verify($mapped['link_mode']==='direct'&&$mapped['is_active']===0&&$mapped['priority']===17,'Legacy CSV overwrite preserves omitted advanced fields',$mapped);
$r=new WP_REST_Request('POST','/gt-link-manager/v1/analytics/enable');verify(rest_do_request($r)->get_status()===400,'REST requires explicit consent');verify(!get_option('gtlm_analytics',false),'Missing consent creates no config');
wp_set_current_user(0);verify(rest_do_request(new WP_REST_Request('GET','/gt-link-manager/v1/analytics/status'))->get_status()===401,'Anonymous analytics access denied');
$author=wp_insert_user(['user_login'=>'fixture-author','user_pass'=>wp_generate_password(),'user_email'=>'author@example.invalid','role'=>'author']);wp_set_current_user($author);verify(rest_do_request(new WP_REST_Request('GET','/gt-link-manager/v1/analytics/status'))->get_status()===403,'Link-editing permission does not grant analytics');
wp_set_current_user(1);$r=new WP_REST_Request('POST','/gt-link-manager/v1/analytics/enable');$r->set_body_params(['consent'=>true,'country_source'=>'cloudflare','campaigns'=>[['id'=>7,'utm_source'=>'newsletter','utm_medium'=>'email','utm_campaign'=>'launch']]]);$response=rest_do_request($r);verify($response->get_status()===200,'Explicit enable initializes analytics',$response->get_data());
if(!$settings->advanced_analytics_enabled()){echo json_encode($checks,JSON_PRETTY_PRINT);exit(1);}
verify((bool)wp_next_scheduled('gtlm_analytics_maintenance'),'Enable schedules maintenance');verify(count($wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'")?:[])===2,'Enable creates only two analytics tables');
verify(in_array($wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name='gtlm_analytics'"),['no','off','auto-off'],true),'Analytics configuration is not autoloaded');
require_once GTLM_PATH.'includes/class-gtlm-analytics-collector.php';$adb=new GTLM_Analytics_DB();
$settings->update(array_merge($settings->all(),['enable_click_tracking'=>0]));verify($settings->advanced_analytics_enabled(),'Basic settings save preserves explicit analytics gate');
wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (iPhone) AppleWebKit Safari';$_SERVER['HTTP_REFERER']='https://search.example.org/private/path?email=secret@example.org';$_SERVER['HTTP_CF_IPCOUNTRY']='IN';$_GET=['utm_source'=>'newsletter','utm_medium'=>'email','utm_campaign'=>'launch'];
$before=$db->get_link_by_id($id)['total_clicks'];$q=$wpdb->num_queries;verify(GTLM_Analytics_Collector::collect($link,302,null),'Analytics-only event stored');verify($wpdb->num_queries-$q<=2,'Collector uses at most config read plus one insert',$wpdb->num_queries-$q);verify($db->get_link_by_id($id)['total_clicks']===$before,'Analytics-only leaves legacy counts unchanged');
$event=$wpdb->get_row('SELECT * FROM '.GTLM_Analytics_DB::events_table().' ORDER BY id DESC LIMIT 1',ARRAY_A);verify($event['source']==='search.example.org'&&$event['country']==='IN'&&$event['device']==='mobile'&&$event['campaign']==='7','Event fields normalized',$event);verify(!str_contains(json_encode($event),'secret@')&&$event['page']==='https://search.example.org/private/path'&&!str_contains(json_encode($event),'Mozilla'),'Page URL excludes query data and raw user agent');
foreach(['HEAD','POST','OPTIONS'] as $method){$_SERVER['REQUEST_METHOD']=$method;verify(!GTLM_Analytics_Collector::collect($link,302,null),'Skip '.$method);}
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_USER_AGENT']='Googlebot';verify(!GTLM_Analytics_Collector::collect($link,302,null),'Skip recognized bots');$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Safari';$_SERVER['HTTP_SEC_PURPOSE']='prefetch';verify(!GTLM_Analytics_Collector::collect($link,302,null),'Skip prefetch');unset($_SERVER['HTTP_SEC_PURPOSE']);
add_filter('gtlm_analytics_should_record','__return_false');verify(!GTLM_Analytics_Collector::collect($link,302,null),'Consent integration can suppress collection');remove_filter('gtlm_analytics_should_record','__return_false');
wp_set_current_user(1);verify(!GTLM_Analytics_Collector::collect($link,302,null),'Skip signed-in link managers');wp_set_current_user(0);
$settings->update(array_merge($settings->all(),['enable_click_tracking'=>1]));$record->invoke($redirect,$link);verify(GTLM_Analytics_Collector::collect($link,302,null),'Both-on writes an event');verify($db->get_link_by_id($id)['total_clicks']===$before+1,'Both-on increments legacy total once');
$result=GTLM_Analytics::process();verify(!is_wp_error($result),'Maintenance succeeds',is_wp_error($result)?$result->get_error_message():$result);
$total=(int)$wpdb->get_var("SELECT SUM(clicks) FROM ".GTLM_Analytics_DB::hourly_table()." WHERE dimension='total'");verify($total===2,'Aggregation matches independent event total',$total);GTLM_Analytics::process();verify((int)$wpdb->get_var("SELECT SUM(clicks) FROM ".GTLM_Analytics_DB::hourly_table()." WHERE dimension='total'")===$total,'Repeated maintenance does not double count');
$groups=$wpdb->get_results('SELECT dimension,SUM(clicks) clicks FROM '.GTLM_Analytics_DB::hourly_table().' GROUP BY dimension',ARRAY_A);verify(count(array_filter($groups,fn($g)=>(int)$g['clicks']!==$total))===0,'All breakdowns reconcile with total',$groups);
$report=GTLM_Analytics_Controller::report([]);verify(!is_wp_error($report)&&$report['total']===2&&$report['previous']===null,'Summary reads aggregates and marks unavailable history',$report);
verify(is_wp_error(GTLM_Analytics_Controller::filters(['from'=>'2020-01-01']))&&is_wp_error(GTLM_Analytics_Controller::filters(['dimension'=>['source']]))&&is_wp_error(GTLM_Analytics_Controller::filters(['from'=>'2026-02-30'])),'Report filters reject unbounded, array and invalid-date inputs');
// Cross-batch source cardinality, including the already recorded referring host.
for($i=0;$i<105;$i++){$_SERVER['HTTP_REFERER']='https://s'.$i.'.example.org/path';GTLM_Analytics_Collector::collect($link,302,null);}
$adb->lock();$generation=GTLM_Analytics::config()['generation'];$adb->aggregate_batch($generation,gmdate('Y-m-d H:i:s',time()-86400),50);$adb->aggregate_batch($generation,gmdate('Y-m-d H:i:s',time()-86400),50);$adb->unlock();GTLM_Analytics::process();
$hosts=(int)$wpdb->get_var("SELECT COUNT(DISTINCT value) FROM ".GTLM_Analytics_DB::hourly_table()." WHERE dimension='source' AND value<>'_other' AND value<>''");verify($hosts===100,'Source cardinality cap persists across batches',$hosts);
// A lower auto-increment ID committed after the first worker pass must still count.
$event=array_intersect_key($event,array_flip(['link_id','occurred_at','generation','source','country','device','browser','os','campaign','status','mode','geo']));
$event['source']='late.example.org';$event['occurred_at']=gmdate('Y-m-d H:i:s');$event['generation']=GTLM_Analytics::config()['generation'];
$late=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);$late->begin_transaction();$columns=implode(',',array_keys($event));$sql=$wpdb->prepare('INSERT INTO '.GTLM_Analytics_DB::events_table().' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($event),'%s')).')',array_values($event));$late->query($sql);
$before=(int)$wpdb->get_var("SELECT SUM(clicks) FROM ".GTLM_Analytics_DB::hourly_table()." WHERE dimension='total'");$adb->append($event);GTLM_Analytics::process();$late->commit();$late->close();GTLM_Analytics::process();verify((int)$wpdb->get_var("SELECT SUM(clicks) FROM ".GTLM_Analytics_DB::hourly_table()." WHERE dimension='total'")===$before+2,'Late lower ID is processed without loss');
// Collector database failure must be silent and must not create a fallback record.
$old=$wpdb->suppress_errors(true);$fail=function($sql){return str_starts_with($sql,'INSERT INTO '.GTLM_Analytics_DB::events_table())?'INSERT INTO gtlm_intentionally_missing (id) VALUES (1)':$sql;};add_filter('query',$fail);verify(!GTLM_Analytics_Collector::collect($link,302,null),'Collector safely skips a failed database append');remove_filter('query',$fail);$wpdb->suppress_errors($old);
$paused=GTLM_Analytics::pause();verify(!$settings->advanced_analytics_enabled()&&!GTLM_Analytics_Collector::collect($link,302,null),'Pause stops collection');verify((bool)wp_next_scheduled('gtlm_analytics_maintenance'),'Pause retains retention maintenance');
$res=GTLM_Analytics::enable();verify(!is_wp_error($res),'Explicit resume succeeds',is_wp_error($res)?$res->get_error_message():null);$config=GTLM_Analytics::config();$config['lease_until']=time()-1;update_option('gtlm_analytics',$config,false);verify(!GTLM_Analytics_Collector::collect($link,302,null),'Expired worker lease stops collection');GTLM_Analytics::process();verify(GTLM_Analytics::status()['state']==='active','Successful maintenance renews an opted-in lease');
$before=$db->get_link_by_id($id)['total_clicks'];GTLM_Analytics::delete();verify(!$settings->analytics_initialized()&&!get_option('gtlm_analytics',false)&&!wp_next_scheduled('gtlm_analytics_maintenance'),'Delete removes gate, config and jobs');verify(count($wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'")?:[])===0,'Delete drops analytics tables');verify($db->get_link_by_id($id)['total_clicks']===$before,'Delete preserves basic counts');GTLM_Analytics::delete();verify(!$settings->analytics_initialized(),'Repeated delete is safe');
$wpdb->query("DELETE FROM {$wpdb->users} WHERE ID=".(int)$author);$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id=".(int)$author);
$result=['checks'=>$checks,'passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit(count($result['failed'])?1:0);
