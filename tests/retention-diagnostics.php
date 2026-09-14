<?php
/** Retention boundaries and diagnostics in a disposable WordPress fixture. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME,'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-controller.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-view.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-admin.php';
require_once ABSPATH.'wp-admin/includes/template.php';
$checks=[];function retention_check($ok,$label){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label];}
GTLM_Analytics::delete();global$wpdb;$db=new GTLM_Analytics_DB();$settings=GTLM_Settings::get_instance();wp_set_current_user(1);
$owned=$wpdb->prefix.'gtlm_storage_fixture';$foreign=$wpdb->prefix.'gtlmXstorage_fixture';
$wpdb->query("CREATE TABLE {$owned} (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$wpdb->query("CREATE TABLE {$foreign} (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$expected=(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(DATA_LENGTH+INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)',GTLM_DB::links_table(),GTLM_DB::categories_table(),$owned));
retention_check($db->storage_bytes()===$expected&&$expected>0,'Storage includes every owned table and excludes lookalike prefixes');
$wpdb->query("DROP TABLE {$owned}");$wpdb->query("DROP TABLE {$foreign}");
$view=new GTLM_Admin_Pages($db,$settings,new GTLM_Import($db,$settings));ob_start();$view->render_settings_page();$html=ob_get_clean();
retention_check(str_contains($html,'Database size (all GT Link Manager tables)')&&str_contains($html,'Advanced Analytics')&&str_contains($html,'Advanced redirects'),'Diagnostics shows size and both feature switches without a saved diagnostic');
retention_check(!$settings->analytics_initialized()&&!get_option('gtlm_analytics',false)&&!wp_next_scheduled(GTLM_Analytics::CRON)&&!$wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'"),'Reading settings diagnostics creates no analytics footprint');
foreach([31,60,61,365,36500,PHP_INT_MAX] as $days){$v=GTLM_Analytics::validate(['event_days'=>$days]);retention_check(!is_wp_error($v)&&$v['event_days']===$days,'Long retention is accepted: '.$days);}
$invalid=true;foreach([0,-1,'2.5',[],true,'lots'] as $days){$invalid=$invalid&&is_wp_error(GTLM_Analytics::validate(['event_days'=>$days]));}retention_check($invalid,'Retention still requires positive whole days');
$id=$db->insert_link(['name'=>'Retention fixture','slug'=>'retention-fixture','url'=>home_url('/target')]);GTLM_Analytics::enable(['event_days'=>90,'summary_days'=>0]);$gen=GTLM_Analytics::config()['generation'];
function retention_event($age){global$db,$id,$gen;$db->append(['link_id'=>$id,'occurred_at'=>gmdate('Y-m-d H:i:s',time()-$age*DAY_IN_SECONDS),'generation'=>$gen,'source'=>'example.org','page'=>'https://example.org/article/','country'=>'','device'=>'desktop','browser'=>'chrome','os'=>'linux','campaign'=>0,'status'=>302,'mode'=>'standard','geo'=>'off']);}
retention_event(70);retention_event(95);GTLM_Analytics::process();$events=GTLM_Analytics_DB::events_table();
retention_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$events} WHERE link_id={$id}")===1,'90-day retention keeps a 70-day record and expires a 95-day record');
GTLM_Analytics::configure(['event_days'=>PHP_INT_MAX],true);retention_event(400);$r=GTLM_Analytics::process();
retention_check(!is_wp_error($r)&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$events} WHERE link_id={$id}")===2,'Very large retention neither overflows nor deletes retained records');
$expected=(int)$wpdb->get_var($wpdb->prepare('SELECT SUM(DATA_LENGTH+INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)',GTLM_DB::links_table(),GTLM_DB::categories_table(),GTLM_Analytics_DB::events_table(),GTLM_Analytics_DB::hourly_table()));
retention_check($db->storage_bytes()===$expected,'Storage includes both analytics tables after opt-in');
foreach([60,61] as $days){GTLM_Analytics::configure(['event_days'=>$days],true);$_GET=['view'=>'settings'];ob_start();GTLM_Analytics_View::render();$html=ob_get_clean();preg_match('/<input id="gtlm-events"[^>]*>/', $html,$input);preg_match('/<p id="gtlm-events-warning"[^>]*>/', $html,$warning);retention_check(!str_contains($input[0]??'',' max=')&&str_contains($input[0]??'','aria-describedby=')&&(str_contains($warning[0]??'',' hidden')===($days===60)),'Uncapped field and warning threshold render correctly: '.$days);}
$settings->update(array_merge($settings->all(),['enable_advanced_redirects'=>1]));ob_start();$view->render_settings_page();$html=ob_get_clean();preg_match('/<table class="gtlm-diagnostics-table gtlm-diagnostics-current">(.*?)<\/table>/s',$html,$table);retention_check(substr_count($table[1]??'','>Enabled<')===2,'Diagnostics reflects current enabled states');
$head=false;$mock=static function($pre,$args)use(&$head){$head=$args['method']==='HEAD';return ['headers'=>['location'=>'https://example.org/'],'body'=>'','response'=>['code'=>302,'message'=>'Found'],'cookies'=>[]];};add_filter('pre_http_request',$mock,10,2);$ref=new ReflectionClass(GTLM_Admin::class);$admin=$ref->newInstanceWithoutConstructor();$ref->getProperty('db')->setValue($admin,$db);$ref->getProperty('settings')->setValue($admin,$settings);$method=$ref->getMethod('run_diagnostics');$method->setAccessible(true);$method->invoke($admin);remove_filter('pre_http_request',$mock,10);retention_check($head,'Runtime diagnostics uses HEAD rather than adding a tracked GET');
$db->delete_link($id);GTLM_Analytics::delete();$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
