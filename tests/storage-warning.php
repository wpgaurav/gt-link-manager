<?php
require_once __DIR__.'/reflection.php';
/** Uncapped collection, storage warning, delete-old data and the destination column. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-collector.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-controller.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-view.php';
require_once ABSPATH.'wp-admin/includes/template.php';
$checks=[];function storage_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_Analytics_DB();$hourly=GTLM_Analytics_DB::hourly_table();$events=GTLM_Analytics_DB::events_table();
function storage_render(array $get){$_GET=$get;ob_start();GTLM_Analytics_View::render();return ob_get_clean();}
function storage_click(array $link){wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Safari';$_SERVER['HTTP_REFERER']='https://fixture.example.org/post';$ok=GTLM_Analytics_Collector::collect($link,302,null);wp_set_current_user(1);return $ok;}
GTLM_Analytics::delete();wp_set_current_user(1);
$r=GTLM_Analytics::enable();storage_check(!is_wp_error($r)&&'active'===$r['state'],'Enable succeeds',$r);
$id=$db->insert_link(['name'=>'Storage fixture','slug'=>'storage-fixture','url'=>'https://example.com/landing?ref=gt','redirect_type'=>302]);$link=$db->get_link_by_id($id);

// 200 MB reported: previously the worker flipped to unhealthy at 100 MB and resume was refused.
$huge=fn($sql)=>str_contains($sql,'information_schema.TABLES')&&str_contains($sql,'DATA_LENGTH')?'SELECT 209715200':$sql;
add_filter('query',$huge);
$r=GTLM_Analytics::enable();storage_check(!is_wp_error($r)&&'active'===$r['state'],'Resume is not refused above 100 MB',$r);
storage_click($link);$r=GTLM_Analytics::process();$c=GTLM_Analytics::config();
storage_check(!is_wp_error($r)&&'active'===$c['state']&&$c['lease_until']>time(),'Maintenance above 100 MB keeps collection active',$c);
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$events}");storage_check(storage_click($link)&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$events}")===$before+1,'Clicks are still recorded above the warning level');
$st=GTLM_Analytics::status();storage_check($st['storage_warning']&&100*MB_IN_BYTES===$st['storage_warning_bytes'],'Status flags storage past the default 100 MB warning',$st);
$html=storage_render([]);storage_check(str_contains($html,'above your 100 MB warning level')&&str_contains($html,'#gtlm-delete-old')&&!str_contains($html,'have not updated in the last 15 minutes'),'Overview warns about storage, not stale reports');
remove_filter('query',$huge);

// Processing lag is reported, never used to stop collection.
$lag=fn($sql)=>str_starts_with($sql,"SELECT occurred_at FROM {$events} WHERE processed = 0")?"SELECT '2000-01-01 00:00:00'":$sql;
add_filter('query',$lag);GTLM_Analytics::process();remove_filter('query',$lag);$c=GTLM_Analytics::config();
storage_check('active'===$c['state']&&$c['lease_until']>time()&&$c['health']['lag']>=GTLM_Analytics::LAG_WARNING_SECONDS,'A processing backlog does not pause collection',$c);
storage_check(str_contains(storage_render([]),'Reports are catching up'),'Backlog notice renders');

// One failed run keeps the lease; persistent failure is left to the lease.
storage_click($link);$lease=GTLM_Analytics::config()['lease_until'];$old=$wpdb->suppress_errors(true);
$broken=fn($sql)=>str_starts_with($sql,'INSERT INTO '.$hourly)?'INVALID_AGGREGATION':$sql;add_filter('query',$broken);$r=GTLM_Analytics::process();remove_filter('query',$broken);$wpdb->suppress_errors($old);$c=GTLM_Analytics::config();
storage_check(is_wp_error($r)&&'active'===$c['state']&&$lease===$c['lease_until']&&'maintenance_failed'===$c['last_error']&&!empty($c['last_error_at']),'A failed update records the error without pausing',$c);
storage_check('active'===GTLM_Analytics::status()['state']&&str_contains(storage_render([]),'The last report update failed'),'Failed-update notice renders while collecting');
GTLM_Analytics::process();storage_check(!isset(GTLM_Analytics::config()['last_error']),'Next successful update clears the error');

// Destination column.
$html=storage_render([]);
storage_check(str_contains($html,'<th scope="col">Destination URL</th>')&&str_contains($html,'href="https://example.com/landing?ref=gt" class="gtlm-destination" title="https://example.com/landing?ref=gt" target="_blank"')&&str_contains($html,'>example.com/landing?ref=gt<'),'Top links shows the destination URL',$html);
$cell=gtlm_test_access(new ReflectionMethod(GTLM_Analytics_View::class,'destination_cell'));
$regex=$cell->invoke(null,'https://example.com/item/$1','regex');$script=$cell->invoke(null,'javascript:alert(1)','standard');
storage_check(!isset($regex['url'])&&'example.com/item/$1'===$regex['label']&&!isset($script['url']),'Regex and non-HTTP destinations are shown, not linked');

// Warning level setting.
storage_check(250===GTLM_Analytics::validate(['storage_warning_mb'=>'250'])['storage_warning_mb']&&100===GTLM_Analytics::validate([])['storage_warning_mb']&&300===GTLM_Analytics::validate(['storage_warning_mb'=>''],['storage_warning_mb'=>300])['storage_warning_mb'],'Warning level validates and defaults');
storage_check(is_wp_error(GTLM_Analytics::validate(['storage_warning_mb'=>'0']))&&is_wp_error(GTLM_Analytics::validate(['storage_warning_mb'=>'ten']))&&is_wp_error(GTLM_Analytics::validate(['storage_warning_mb'=>'-5'])),'Invalid warning levels are rejected');
GTLM_Analytics::configure(['storage_warning_mb'=>'50'],true);storage_check(50*MB_IN_BYTES===GTLM_Analytics::status()['storage_warning_bytes'],'Saved warning level drives the threshold');
add_filter('gtlm_analytics_storage_warning_bytes',fn()=>1);storage_check(GTLM_Analytics::status()['storage_warning'],'Warning threshold is filterable');remove_all_filters('gtlm_analytics_storage_warning_bytes');
$html=storage_render(['view'=>'settings']);
storage_check(str_contains($html,'name="storage_warning_mb"')&&str_contains($html,'value="50"')&&str_contains($html,'id="gtlm-delete-old"')&&str_contains($html,'name="older_than_days"')&&!str_contains($html,'Storage safeguards'),'Settings show the warning level and Delete old data');

// Delete old data: bounded, resumable, and honest about deleted history.
$stamp=gmdate('Y-m-d H:00:00',time()-200*DAY_IN_SECONDS);$values=[];for($i=0;$i<5005;$i++){$values[]=$wpdb->prepare('(%d,%s,%s,%s,%s,1)',$id,$stamp,'','source','old-'.$i);}
$wpdb->query("INSERT INTO {$hourly} (link_id,bucket_start,page_key,dimension,value,clicks) VALUES ".implode(',',$values));
$event=$wpdb->get_row("SELECT * FROM {$events} LIMIT 1",ARRAY_A);unset($event['id']);$event['occurred_at']=$stamp;$event['processed']=1;$wpdb->insert($events,$event);
$recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hourly} WHERE bucket_start >= %s",gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS)));
$r=GTLM_Analytics::delete_older_than(90,0.0);storage_check(!is_wp_error($r)&&5000===$r['deleted_rows']&&false===$r['complete'],'Deletion stops at its time budget and reports leftovers',$r);
$r=GTLM_Analytics::delete_older_than(90);storage_check(!is_wp_error($r)&&6===$r['deleted_rows']&&true===$r['complete'],'A second run finishes summaries and raw events',$r);
storage_check(0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hourly} WHERE bucket_start < %s",gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS)))&&$recent>0&&$recent===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hourly} WHERE bucket_start >= %s",gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS))),'Only data older than the cutoff is deleted');
storage_check(is_wp_error(GTLM_Analytics::delete_older_than(0)),'Zero days is rejected');
$c=GTLM_Analytics::config();$c['started_at']='2000-01-01 00:00:00';update_option('gtlm_analytics',$c,false);$today=current_datetime();
$r7=GTLM_Analytics_Controller::report(['from'=>$today->modify('-6 days')->format('Y-m-d'),'to'=>$today->format('Y-m-d')],false);
$r90=GTLM_Analytics_Controller::report(['from'=>$today->modify('-89 days')->format('Y-m-d'),'to'=>$today->format('Y-m-d')],false);
storage_check(null!==$r7['previous']&&null===$r90['previous'],'Deleted history is not compared as a complete period',[$r7['previous'],$r90['previous']]);
storage_check(str_contains(storage_render(['view'=>'settings','deleted_old'=>'6','old_complete'=>'1']),'Deleted 6 rows of old analytics data'),'Deletion result notice renders');

GTLM_Analytics::delete();$db->delete_link($id);$_GET=[];
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
