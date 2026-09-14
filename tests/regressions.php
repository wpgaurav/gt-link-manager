<?php
require_once __DIR__.'/reflection.php';
/** Security and settings regressions, only for a disposable fixture. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME,'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-analytics.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-controller.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
$checks=[];function regression($ok,$label,$details=null){global $checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$details];}
$db=new GTLM_DB();$settings=GTLM_Settings::get_instance();global $wpdb;
GTLM_Analytics::delete();
GTLM_Analytics::configure(['summary_days'=>60],false);
regression(!$settings->analytics_initialized()&&!get_option('gtlm_analytics',false)&&!wp_next_scheduled(GTLM_Analytics::CRON)&&!$wpdb->get_col("SHOW TABLES LIKE '%gtlm_analytics%'"),'Saving disabled settings creates no analytics footprint');
$r=GTLM_Analytics::configure(['summary_days'=>90],true);regression(!is_wp_error($r)&&$settings->advanced_analytics_enabled(),'Explicit checked setting enables analytics');
$generation=GTLM_Analytics::config()['generation'];
GTLM_Analytics::configure(['summary_days'=>60],true);
regression(GTLM_Analytics::config()['generation']===$generation&&GTLM_Analytics::config()['summary_days']===60,'Saving active preferences retains collection generation');
GTLM_Analytics::configure(['summary_days'=>30],false);GTLM_Analytics::configure(['summary_days'=>60],false);
regression(!$settings->advanced_analytics_enabled()&&GTLM_Analytics::status()['state']==='paused'&&GTLM_Analytics::config()['summary_days']===60,'Saving paused preferences does not resume collection');
GTLM_Analytics::delete();
$protected=['wp-login.php','wp-login.php/extra','wp-admin/admin.php','wp-json/wp/v2/users','xmlrpc.php','%77p-login.php','%2577p-login.php','x/../wp-login.php','wp-content/uploads/test','WP-LOGIN.PHP'];
foreach($protected as $path){regression(GTLM_DB::protected_path($path),'Protected path: '.$path);}
$site_filter=static fn($url)=>'http://gtlm.test/subsite/';add_filter('home_url',$site_filter);regression(GTLM_DB::protected_path('/subsite/wp-login.php'),'Subdirectory WordPress login is protected');remove_filter('home_url',$site_filter);
regression(!GTLM_DB::protected_path('guides/wp-login.php-example')&&!GTLM_DB::protected_path('go/tool'),'Ordinary routes remain available');
regression(!$db->insert_link(['name'=>'Blocked','slug'=>'wp-login.php','url'=>'https://example.invalid/','link_mode'=>'direct','redirect_type'=>307]),'Shared writes reject direct login takeover');
wp_set_current_user(1);do_action('rest_api_init');$request=new WP_REST_Request('POST','/gt-link-manager/v1/links');$request->set_body_params(['name'=>'Blocked','slug'=>'wp-login.php','url'=>'https://example.invalid/','link_mode'=>'direct','redirect_type'=>307]);
regression(rest_do_request($request)->get_status()===400,'REST rejects protected direct definition');
$rc=new ReflectionClass(GTLM_Redirect::class);$redirect=$rc->newInstanceWithoutConstructor();gtlm_test_access($rc->getProperty('db'))->setValue($redirect,$db);gtlm_test_access($rc->getProperty('settings'))->setValue($redirect,$settings);
$settings_before=$settings->all();$settings->update(array_merge($settings_before,['enable_advanced_redirects'=>1]));
$id=$db->insert_link(['name'=>'Catchall fixture','slug'=>'.*','url'=>'https://example.invalid/','regex_replacement'=>'https://example.invalid/','link_mode'=>'regex','redirect_type'=>307]);
regression((bool)$id,'Catchall regex fixture installed');
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/index.php';$_GET=[];
foreach(['/wp-login.php','/%77p-login.php','/wp-login.php/path','/wp-json/wp/v2/users'] as $path){$_SERVER['REQUEST_URI']=$path;$q=$wpdb->num_queries;$redirect->maybe_redirect();regression($q===$wpdb->num_queries,'Stored regex cannot intercept protected POST '.$path);}
$_SERVER['REQUEST_URI']='/custom-login';$_SERVER['SCRIPT_NAME']='/wp-login.php';$q=$wpdb->num_queries;$redirect->maybe_redirect();regression($q===$wpdb->num_queries,'Login script excluded with custom request path');
$_SERVER['SCRIPT_NAME']='/index.php';$_GET=['rest_route'=>'/wp/v2/users'];$q=$wpdb->num_queries;$redirect->maybe_redirect();regression($q===$wpdb->num_queries,'Plain permalink REST route excluded');
$db->delete_link($id);$settings->update($settings_before);$_GET=[];
$cells=['=1+1','+SUM(A1:A2)','@SUM(A1:A2)','-1+1',"\t=1+1","'literal","prefix\\\",=1+1",'ordinary, quoted "value"'];
$stream=fopen('php://temp','w+');fputcsv($stream,array_map([GTLM_CSV::class,'text'],$cells),',','"','');rewind($stream);$parsed=fgetcsv($stream,0,',','"','');fclose($stream);
regression(count($parsed)===count($cells)&&array_map([GTLM_CSV::class,'decode'],$parsed)===$cells,'CSV quotes, backslashes and formulas roundtrip without column breakout');
regression(count(array_filter($parsed,static fn($cell)=>(bool)preg_match('/^[\x00-\x20]*[=+@-]/',$cell)))===0,'Export text cells cannot start with spreadsheet formulas');
$dir=GTLM_Import_File::directory(true);regression(is_string($dir)&&!str_starts_with($dir,realpath(ABSPATH).'/')&&(fileperms($dir)&0077)===0,'Import directory is private and outside WordPress');
regression(GTLM_Import_File::path('../wp-config')===''&&GTLM_Import_File::path(str_repeat('a',32))==='','Unknown and traversal import tokens rejected');
regression(is_wp_error(GTLM_Import_File::stage(['tmp_name'=>__FILE__,'name'=>'test.csv','error'=>0])),'Non-uploaded local files rejected');
$token=bin2hex(random_bytes(16));$file=$dir.'/'.$token.'.csv';file_put_contents($file,'fixture');chmod($file,0600);touch($file,time()-3700);wp_schedule_single_event(time()+10,'gtlm_import_expire',[$token]);GTLM_Import_File::cleanup_expired();
regression(!file_exists($file)&&!wp_next_scheduled('gtlm_import_expire',[$token]),'Abandoned private import and expiry job cleaned together');
$token=bin2hex(random_bytes(16));$file=$dir.'/'.$token.'.csv';symlink(__FILE__,$file);regression(GTLM_Import_File::path($token)==='','Symlink import file rejected');unlink($file);
GTLM_Analytics::enable();GTLM_Analytics::pause();
wp_clear_scheduled_hook(GTLM_Analytics::CRON);define('DOING_CRON',true);gtlm_bootstrap();
regression((bool)wp_next_scheduled(GTLM_Analytics::CRON)&&!$settings->advanced_analytics_enabled(),'Control context restores missing maintenance without resuming collection');
GTLM_Analytics::delete();
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
