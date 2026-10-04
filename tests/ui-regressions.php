<?php
require_once __DIR__.'/reflection.php';
/** Quick Edit slug safety, lookup caching, front-end upgrades and list-table UI. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-admin.php';
require_once GTLM_PATH.'includes/class-gt-link-list-table.php';
$checks=[];function ui_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();wp_set_current_user(1);
$s->update(array_merge($s->all(),['enable_advanced_redirects'=>1]));

// Drive the real AJAX handler; wp_send_json() ends in wp_die(), turned into an exception here.
$admin=(new ReflectionClass(GTLM_Admin::class))->newInstanceWithoutConstructor();
foreach(['db'=>$db,'settings'=>$s] as $prop=>$value){$rp=new ReflectionProperty(GTLM_Admin::class,$prop);gtlm_test_access($rp)->setValue($admin,$value);}
add_filter('wp_doing_ajax','__return_true');add_filter('wp_die_ajax_handler',fn()=>function(){throw new RuntimeException('json-sent');});
function ui_quick_edit($admin,array $post){$_POST=array_merge(['nonce'=>wp_create_nonce('gtlm_quick_edit'),'redirect_type'=>'302'],$post);ob_start();try{$admin->ajax_quick_edit();}catch(RuntimeException $e){}$out=ob_get_clean();$_POST=[];return json_decode($out,true);}

$regex=$db->insert_link(['name'=>'Regex rule','slug'=>'^old/(.*)$','url'=>'https://example.com/old/$1','link_mode'=>'regex']);
$r=ui_quick_edit($admin,['link_id'=>$regex,'url'=>'https://example.com/new/$1','slug'=>'^old/(.*)$']);$row=$db->get_link_by_id($regex);
ui_check(!empty($r['success'])&&'^old/(.*)$'===$row['slug']&&'https://example.com/new/$1'===$row['url'],'Quick Edit keeps a regex pattern intact while changing its destination',[$r,$row['slug']]);
ui_check(''===($r['data']['branded_url']??null),'Regex rules report no branded URL to the row',$r);
$direct=$db->insert_link(['name'=>'Direct path','slug'=>'ui-regression/direct-path','url'=>'https://example.com/docs','link_mode'=>'direct']);
ui_check($direct>0,'Direct fixture link created',$wpdb->last_error);
ui_quick_edit($admin,['link_id'=>$direct,'url'=>'https://example.com/docs2','slug'=>'ui-regression/direct-path']);
ui_check('ui-regression/direct-path'===($db->get_link_by_id($direct)['slug']??null),'Quick Edit keeps a direct path intact');
$std=$db->insert_link(['name'=>'Standard','slug'=>'standard-link','url'=>'https://example.com/a']);
$r=ui_quick_edit($admin,['link_id'=>$std,'url'=>'https://example.com/b','slug'=>'Renamed Link']);
ui_check('renamed-link'===$db->get_link_by_id($std)['slug']&&home_url('/'.$s->prefix().'/renamed-link')===($r['data']['branded_url']??''),'Quick Edit still renames standard slugs and returns the new branded URL',$r);

// Lookup caching: a failed query must not be stored as "no such link".
$db->insert_link(['name'=>'Cached','slug'=>'cache-probe','url'=>'https://example.com/c']);wp_cache_delete('slug:cache-probe',GTLM_DB::CACHE_GROUP);
$old=$wpdb->suppress_errors(true);$broken=fn($sql)=>str_contains($sql,"slug = 'cache-probe'")?'SELECT broken FROM nowhere':$sql;add_filter('query',$broken);
$miss=$db->get_link_by_slug('cache-probe');remove_filter('query',$broken);$wpdb->suppress_errors($old);
ui_check(null===$miss&&false===wp_cache_get('slug:cache-probe',GTLM_DB::CACHE_GROUP),'A failed lookup is not cached');
ui_check('cache-probe'===($db->get_link_by_slug('cache-probe')['slug']??''),'The next lookup after a failure finds the link');
$db->get_link_by_slug('no-such-link-here');$found=false;$value=wp_cache_get('slug:no-such-link-here',GTLM_DB::CACHE_GROUP,false,$found);
ui_check($found&&null===$value,'A genuine miss is still cached');

// Upgrades run outside wp-admin, behind a short lock.
update_option('gtlm_db_version','1.0.0',true);add_option('gtlm_upgrade_lock',time(),'',false);GTLM_Activator::maybe_upgrade();
ui_check('1.0.0'===get_option('gtlm_db_version'),'A fresh upgrade lock makes concurrent requests skip the migration');
update_option('gtlm_upgrade_lock',time()-120,false);GTLM_Activator::maybe_upgrade();
ui_check(GTLM_VERSION===get_option('gtlm_db_version')&&false===get_option('gtlm_upgrade_lock'),'A stale lock is taken over and released after migrating');
update_option('gtlm_db_version','1.0.0',true);$cron_upgrade=!is_admin();GTLM_Activator::maybe_upgrade();
ui_check($cron_upgrade&&GTLM_VERSION===get_option('gtlm_db_version'),'Migration runs in a non-admin context');

// List table UI.
set_current_screen('toplevel_page_gtlm-links');$table=new GTLM_List_Table($db,[],$s->prefix(),'');$rc=new ReflectionClass($table);
ui_check('name'===gtlm_test_access($rc->getMethod('get_default_primary_column_name'))->invoke($table),'Name is the primary column, so phone layouts keep link names');
$geo=gtlm_test_access($rc->getMethod('column_geo'));$s->update(array_merge($s->all(),['enable_geo_targeting'=>0]));
$cell=$geo->invoke($table,['geo_mode'=>'targeted','geo_rules'=>GTLM_Geo::encode_rules(['rules'=>[['countries'=>['IN'],'url'=>'https://example.com/in','redirect_type'=>302]],'fallback'=>'default'])]);
ui_check(str_contains($cell,'(off)')&&str_contains($cell,'gtlm-icon--world'),'Geo rules flag that country routing is off',$cell);
$active=GTLM_List_Table::status_badge(true);$inactive=GTLM_List_Table::status_badge(false);
ui_check(str_contains($active,'gtlm-icon--circle-check')&&str_contains($active,'>Active<')&&str_contains($inactive,'gtlm-icon--player-pause'),'Status badges carry an icon and a text label');
$name=gtlm_test_access($rc->getMethod('column_name'))->invoke($table,$db->get_link_by_id($std));
ui_check(!str_contains($name,'gtlm-copy-url')&&str_contains($name,'data-link-mode="standard"'),'Row actions skip Copy URL while the Branded URL column is visible',$name);
ui_check(''===gtlm_icon('no-such-icon')&&str_contains(gtlm_icon('copy'),'aria-hidden="true"')&&str_contains(gtlm_icon('copy'),'focusable="false"'),'Icons are decorative and unknown names render nothing');

// Advanced redirects off: the edit form must carry a regex link's stored mode, not reset it.
$s->update(array_merge($s->all(),['enable_advanced_redirects'=>0]));$pages=new GTLM_Admin_Pages($db,$s,(new ReflectionClass(GTLM_Import::class))->newInstanceWithoutConstructor());
$_GET=['page'=>'gtlm-links-edit','link_id'=>$regex];ob_start();$pages->render_edit_page();$form=ob_get_clean();$_GET=[];
ui_check(str_contains($form,'name="link_mode" value="regex"')&&str_contains($form,'name="regex_replacement"')&&str_contains($form,'name="priority"')&&str_contains($form,'uses regex mode'),'Advanced redirects off keeps a regex link in regex mode on save',substr($form,0,400));
$_GET=['page'=>'gtlm-links-edit','link_id'=>$std];ob_start();$pages->render_edit_page();$form=ob_get_clean();$_GET=[];
ui_check(str_contains($form,'name="link_mode" value="standard"')&&!str_contains($form,'name="regex_replacement"'),'Standard links keep the plain hidden mode field');
$s->update(array_merge($s->all(),['enable_advanced_redirects'=>1]));

// Collector: scripts and region leaks.
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';require_once GTLM_PATH.'includes/class-gtlm-analytics-collector.php';
GTLM_Analytics::delete();GTLM_Analytics::enable(['country_source'=>'none']);$events=GTLM_Analytics_DB::events_table();$link=$db->get_link_by_id($std);
wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_REFERER']='https://fixture.example.org/post';
$_SERVER['HTTP_USER_AGENT']='';$empty=GTLM_Analytics_Collector::collect($link,302,null);
$_SERVER['HTTP_USER_AGENT']='curl/8.7.1';$curl=GTLM_Analytics_Collector::collect($link,302,null);
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1';$geo=GTLM_Analytics_Collector::collect($link,302,['matched'=>true,'country'=>'IN']);wp_set_current_user(1);
ui_check(!$empty&&!$curl,'Empty and curl user agents are not counted as clicks');
ui_check($geo&&'off'===$wpdb->get_var("SELECT geo FROM {$events} ORDER BY id DESC LIMIT 1"),'Geo rule outcome is not stored while countries are off');
GTLM_Analytics::delete();

remove_all_filters('wp_doing_ajax');remove_all_filters('wp_die_ajax_handler');
foreach([$regex,$direct,$std] as $id){$db->delete_link($id);}
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
