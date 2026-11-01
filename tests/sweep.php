<?php
require_once __DIR__.'/reflection.php';
/** Regressions from the 1.12.0 bug sweep. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-admin.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations-admin.php';
require_once GTLM_PATH.'includes/class-gtlm-analytics-collector.php';
require_once GTLM_PATH.'includes/class-gtlm-import-file.php';
require_once GTLM_PATH.'includes/class-gt-link-deactivator.php';
$checks=[];function sw_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$detail];}
global $wpdb,$wp_rewrite;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();wp_set_current_user(1);$before=$s->all();$table=GTLM_DB::links_table();
$link=fn($slug,$mode='standard',$extra=[])=>$db->insert_link(array_merge(['name'=>'Sweep '.$slug,'slug'=>$slug,'url'=>'https://example.com/t','link_mode'=>$mode],$extra));
$up=fn($id,$fields)=>$db->update_link($id,array_merge($db->get_link_by_id($id),$fields));
$call=function($object,$method,...$args){$m=gtlm_test_access(new ReflectionMethod($object,$method));return $m->invoke($m->isStatic()?null:$object,...$args);};
$rest=function($method,$route,$params=[]){$r=new WP_REST_Request($method,'/gt-link-manager/v1'.$route);foreach($params as $k=>$v)$r->set_param($k,$v);return rest_do_request($r);};
do_action('rest_api_init');

// Encoded request paths keep their bytes before the lookup.
$redirect=(new ReflectionClass('GTLM_Redirect'))->newInstanceWithoutConstructor();foreach(['db'=>$db,'settings'=>$s] as $prop=>$value){gtlm_test_access(new ReflectionProperty('GTLM_Redirect',$prop))->setValue($redirect,$value);}
$uri=function($u)use($redirect,$call){$_SERVER['REQUEST_URI']=wp_slash($u);return [$call($redirect,'extract_slug_from_request'),$call($redirect,'extract_path_from_request')];};
$hindi=$link('हिन्दी');$stored=$db->get_link_by_id($hindi)['slug'];
[$got]=$uri('/go/'.rawurlencode('हिन्दी'));sw_check($got===$stored&&is_array($db->get_link_by_slug($got)),'A non-Latin slug resolves from its encoded URL',$got);
[,$path]=$uri('/foo%20bar');sw_check('foo bar'===$path,'A direct path keeps an encoded space',$path);
[,$path]=$uri('/old-page/?a=1');sw_check('old-page'===$path,'The query string and outer slashes are not part of a path',$path);
$s->update(array_merge($s->all(),['prefix_free_links'=>1]));$_SERVER['REQUEST_URI']='/'.rawurlencode('हिन्दी');sw_check($stored===$call($redirect,'extract_root_slug_from_request'),'A non-Latin slug also resolves prefix-free');$s->update(array_merge($s->all(),['prefix_free_links'=>0]));

// Slugs by mode.
sw_check('old-page'===GTLM_DB::clean_slug('/old-page/','direct')&&'a/b'===GTLM_DB::clean_slug('/a/b/','direct'),'A direct slug loses its outer slashes only');
$d=$link('/sweep-old/','direct');sw_check('sweep-old'===$db->get_link_by_id($d)['slug'],'A direct path saved with slashes is stored so it can match');
$found=$db->get_direct_link_by_path('sweep-old');sw_check(is_array($found)&&(int)$found['id']===$d,'That direct path resolves');
$r=$rest('POST','/links',['name'=>'Sweep slashed','slug'=>'/sweep-rest/','url'=>'https://example.com/t','link_mode'=>'direct']);sw_check('sweep-rest'===($r->get_data()['slug']??''),'REST stores a slashed direct path the same way',$r->get_data());
sw_check(''===$db->unique_slug('^a(.*)$','regex'),'A regex rule never takes a suffix');
$sub=$link('sweep/sub','direct');sw_check('sweep/sub-2'===$db->unique_slug('sweep/sub','direct'),'A direct path keeps its slashes when suffixed');
$link('sweep-base');sw_check('sweep-base-2'===$db->unique_slug('sweep-base'),'A standard slug still takes a suffix');

// Bulk copy.
$cat=$db->insert_category(['name'=>'Sweep cat','slug'=>'sweep-cat']);$rx=$link('^sweep-rx/(.*)$','regex',['regex_replacement'=>'https://example.com/$1']);
$r=$rest('POST','/links/bulk-category',['link_ids'=>[$sub,$rx],'category_id'=>$cat,'mode'=>'copy']);$bulk=$r->get_data();
$copy=$db->get_link_by_exact_slug('sweep/sub-2');sw_check(is_array($copy)&&'direct'===$copy['link_mode'],'Bulk copy keeps a direct path as a path',$bulk);
sw_check(null===$db->get_link_by_exact_slug('sweep-rx'),'Bulk copy does not mangle a regex');sw_check(1===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug LIKE %s",'^sweep-rx%')),'Bulk copy leaves one regex rule and reports it failed',$bulk);
if($copy)$db->delete_link((int)$copy['id']);

// Regex values survive saving, and $10 is not $1 then 0.
$pat='^n/(?<name>[a-z]+)%20x$';$n=$link($pat,'regex',['regex_replacement'=>'https://example.com/<$1>?a=%20']);$row=$db->get_link_by_id($n);
sw_check($pat===$row['slug'],'A regex pattern keeps < and %xx',$row['slug']);sw_check('https://example.com/<$1>?a=%20'===$row['regex_replacement'],'A regex replacement keeps < and %xx',$row['regex_replacement']);
sw_check('ok'===GTLM_DB::clean_pattern(" o\x01k\n"),'Control characters still leave a pattern');
$up($n,['is_active'=>0]);
$ten=$link('^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)$','regex',['url'=>'https://example.com/$10/$1']);
$hit=$call($redirect,'match_regex_rules','abcdefghij');sw_check('https://example.com/j/a'===($hit['target_url']??''),'$10 is group ten',$hit['target_url']??$hit);$up($ten,['is_active'=>0]);

// Slug cache.
$key=fn($slug)=>'slug:'.sanitize_title($slug);$dc=$link('sweep-direct-cache','direct');wp_cache_set($key('sweep-direct-cache'),['stale'=>1],GTLM_DB::CACHE_GROUP);wp_cache_set('direct:sweep-direct-cache',['stale'=>1],GTLM_DB::CACHE_GROUP);
$up($dc,['notes'=>'changed']);sw_check(false===wp_cache_get($key('sweep-direct-cache'),GTLM_DB::CACHE_GROUP)&&false===wp_cache_get('direct:sweep-direct-cache',GTLM_DB::CACHE_GROUP),'Saving a direct link clears both of its cache keys');

// Destinations.
foreach(['https://example.com:8443/a','https://my_host.example.com/a','https://xn--bcher-kva.example/','https://b%C3%BCcher.example/','https://bücher.example/','https://example.com.'] as $u)sw_check(GTLM_Redirect::valid_destination($u),'Allowed destination '.$u);
foreach(['ftp://example.com/','https://user:pw@example.com/','https://10.0.0.1/','https://[::1]/',"https://example.com/\nx",'https://exa mple.com/'] as $u)sw_check(!GTLM_Redirect::valid_destination($u),'Refused destination '.$u);

// Geo rules survive switching geolocation off.
$rules=['version'=>1,'rules'=>[['countries'=>['US'],'url'=>'https://example.com/us','redirect_type'=>302]],'fallback'=>'default'];$g=$link('sweep-geo','standard',['geo_mode'=>'targeted','geo_rules'=>$rules,'redirect_type'=>302]);
$up($g,['geo_mode'=>'off']);$row=$db->get_link_by_id($g);sw_check('off'===$row['geo_mode']&&'https://example.com/us'===(json_decode((string)$row['geo_rules'],true)['rules'][0]['url']??''),'Turning geolocation off keeps the rules',$row['geo_rules']);

// Category PATCH keeps omitted fields; counts ignore the Trash.
$c2=$db->insert_category(['name'=>'Sweep two','slug'=>'sweep-two','description'=>'Keep me','parent_id'=>$cat]);
$r=$rest('PATCH','/categories/'.$c2,['name'=>'Sweep renamed']);$row=$db->get_category($c2);sw_check(200===$r->get_status()&&'Sweep renamed'===$row['name']&&'Keep me'===$row['description']&&'sweep-two'===$row['slug']&&$cat===(int)$row['parent_id'],'A category PATCH keeps omitted fields',$row);
$cnt=fn($id)=>(int)$db->get_category($id)['count'];$cl=$link('sweep-counted','standard',['category_id'=>$c2]);sw_check(1===$cnt($c2),'Adding a link counts it');
$db->trash_link($cl);sw_check(0===$cnt($c2),'Trashing a link uncounts it');$db->restore_link($cl);sw_check(1===$cnt($c2),'Restoring a link counts it');
$up($cl,['category_id'=>$cat]);sw_check(0===$cnt($c2),'Moving a link uncounts it from the old category');
$wpdb->update(GTLM_DB::categories_table(),['count'=>99],['id'=>$c2]);$db->recount_categories();sw_check(0===$cnt($c2),'The upgrade recount corrects a stored count');

// REST slugs and shapes.
$r=$rest('PATCH','/links/'.$cl,['slug'=>'!!!']);sw_check(400===$r->get_status(),'REST cannot blank a slug',$r->get_data());sw_check('sweep-counted'===$db->get_link_by_id($cl)['slug'],'The slug is unchanged afterwards');
$r=$rest('POST','/links',['name'=>'???','url'=>'https://example.com/t']);sw_check(400===$r->get_status(),'REST refuses a link with nothing to make a slug from',$r->get_data());
$r=$rest('PUT','/links/'.$cl.'/toggle-active',['is_active'=>false]);$t=$r->get_data();$list=$rest('GET','/links/'.$cl)->get_data();sw_check(array_keys($t)===array_keys($list),'Toggle and get agree on fields',array_diff(array_keys($list),array_keys($t)));
$db->trash_link($cl);$t=$rest('PUT','/links/'.$cl.'/restore')->get_data();sw_check(array_keys($t)===array_keys($list),'Restore and get agree on fields');
sw_check(404===$rest('PUT','/links/999999/restore')->get_status()&&404===$rest('PUT','/links/999999/toggle-active',['is_active'=>true])->get_status(),'A missing link is a 404');
$db->toggle_active($cl,true);

// Trash retention compares site time.
update_option('timezone_string','');update_option('gmt_offset',-8);$old=$link('sweep-purge');$db->trash_link($old);$wpdb->update($table,['trashed_at'=>wp_date('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS+2*HOUR_IN_SECONDS)],['id'=>$old]);
$db->purge_trash_older_than(30);sw_check(is_array($db->get_link_by_id($old)),'A link two hours short of the window is kept west of UTC');
$wpdb->update($table,['trashed_at'=>wp_date('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS-2*HOUR_IN_SECONDS)],['id'=>$old]);$db->purge_trash_older_than(30);sw_check(null===$db->get_link_by_id($old),'A link two hours past the window is purged');update_option('gmt_offset',0);

// Legacy rel, referrers, geo token.
$lr=$link('sweep-legacy-rel');$wpdb->update($table,['rel'=>'nofollow, sponsored'],['id'=>$lr]);$ids=array_map('intval',array_column($db->list_links(['rel'=>'sponsored'],1,50,'id','DESC'),'id'));sw_check(in_array($lr,$ids,true),'The rel filter finds a comma-space row');
$_SERVER['HTTP_REFERER']=wp_slash("https://example.com/it's");sw_check("https://example.com/it's"===$call('GTLM_Analytics_Collector','server_value','HTTP_REFERER',2048),'A referrer with a quote is unslashed');unset($_SERVER['HTTP_REFERER']);
$tok=GTLM_Geo::issue_probe_token();sw_check(!GTLM_Geo::consume_probe_token('wrong'),'A wrong token fails');sw_check(GTLM_Geo::consume_probe_token($tok),'A wrong guess does not cancel the check');sw_check(!GTLM_Geo::consume_probe_token($tok),'The right token still works once');

// Bulk category and the validation redirect.
$admin=(new ReflectionClass('GTLM_Admin'))->newInstanceWithoutConstructor();foreach(['db'=>$db,'settings'=>$s] as $prop=>$value){gtlm_test_access(new ReflectionProperty('GTLM_Admin',$prop))->setValue($admin,$value);}
$bc=$link('sweep-bulk-cat','standard',['category_id'=>$cat]);unset($_REQUEST['bulk_category_id']);$res=$call($admin,'run_bulk_action','bulk_set_category',[$bc]);sw_check('bulk_category_missing'===$res['notice']&&$cat===(int)$db->get_link_by_id($bc)['category_id'],'Set category with nothing chosen changes nothing',$res);
$_REQUEST['bulk_category_id']=(string)$c2;$call($admin,'run_bulk_action','bulk_set_category',[$bc]);sw_check($c2===(int)$db->get_link_by_id($bc)['category_id'],'Set category moves the link');
$_REQUEST['bulk_category_id']='-1';$call($admin,'run_bulk_action','bulk_set_category',[$bc]);sw_check(0===(int)$db->get_link_by_id($bc)['category_id'],'No category clears it on purpose');unset($_REQUEST['bulk_category_id']);
add_filter('wp_redirect',$thrower=function($url){throw new RuntimeException($url);});$_POST=['gtlm_action'=>'save_link','slug'=>'x','name'=>'','url'=>'','link_id'=>(string)$bc,'link_mode'=>'standard','_wpnonce'=>wp_create_nonce('gtlm_link_save')];$_REQUEST=$_POST;$to='';
try{$call($admin,'handle_link_save_action');}catch(RuntimeException $e){$to=$e->getMessage();}sw_check(str_contains($to,'link_id='.$bc),'A validation error returns to the link being edited',$to);$_POST=[];$_REQUEST=[];remove_filter('wp_redirect',$thrower);

// Privacy copy and the beta flag.
$pages=new GTLM_Admin_Pages($db,$s,new GTLM_Import($db,$s));sw_check(!$call($pages,'shares_visitor_data'),'Nothing enabled means nothing shared');
$cfg=function($tools,$share){update_option(GTLM_Integrations::OPTION,['tools'=>$tools,'share_visitor_data'=>$share],false);GTLM_Settings::get_instance()->set_enabled_integrations(array_keys($tools));};
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']],1);sw_check($call($pages,'shares_visitor_data'),'A service with sharing on means shared');
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']],0);sw_check(!$call($pages,'shares_visitor_data'),'A service with sharing off means not shared');
$cfg(['ga4'=>['enabled'=>1,'mode'=>'browser','measurement_id'=>'G-ABCD1234']],1);sw_check(!$call($pages,'shares_visitor_data'),'A browser tag sends nothing from the server');
sw_check(!empty(GTLM_Integrations::tools()['plausible']['beta']),'Plausible is flagged beta');$betas=array_keys(array_filter(GTLM_Integrations::tools(),fn($t)=>!empty($t['beta'])));sw_check(['plausible']===$betas,'Plausible is the only beta tool',$betas);
ob_start();GTLM_Integrations_Admin::render();$html=(string)ob_get_clean();sw_check(1===preg_match('/Plausible Analytics<\/span><span class="gtlm-integration-status is-beta">Beta</',$html),'The Integrations screen shows the Beta badge');sw_check(1===substr_count($html,'is-beta'),'Only one tool shows it');
$cfg([],0);delete_option(GTLM_Integrations::OPTION);

// Inserter query, diagnostics sample, dead code.
$bad=$link('sweep-inactive','standard',['is_active'=>0]);$ids=array_column($rest('GET','/links',['status'=>'active','per_page'=>100,'search'=>'sweep-inactive'])->get_data(),'id');sw_check(!in_array($bad,array_map('intval',$ids),true),'status=active hides inactive links from the inserter');
sw_check(!method_exists($db,'search_links'),'search_links is gone');

// Deactivation removes the rule.
$wp_rewrite->set_permalink_structure('/%postname%/');$redirect->register_rewrite_rules();$wp_rewrite->flush_rules(false);$rule='^go/([^/]+)/?$';sw_check(isset(get_option('rewrite_rules')[$rule]),'The rule is present while active');
GTLM_Deactivator::deactivate();sw_check(!isset(get_option('rewrite_rules')[$rule]),'Deactivating removes the rule');$redirect->register_rewrite_rules();$wp_rewrite->set_permalink_structure('');$wp_rewrite->flush_rules(false);

// Uninstall removes staged imports.
$dir=GTLM_Import_File::directory(true);file_put_contents($dir.'/sweep.csv','a,b');wp_schedule_single_event(time()+3600,'gtlm_import_expire',['sweep']);GTLM_Import_File::delete_all();sw_check(!file_exists($dir.'/sweep.csv')&&!wp_next_scheduled('gtlm_import_expire',['sweep']),'Uninstall clears staged CSVs and their job');

// A buffer that cannot be removed must not spin the fallback flush.
ob_start(null,0,0);$call($redirect,'finish_response',true);sw_check(1<=ob_get_level(),'The flush loop stops at a fixed buffer');

// Cleanup.
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE name LIKE %s",'Sweep %'));$wpdb->query("DELETE FROM {$table} WHERE name IN ('???')");foreach([$c2,$cat] as $c)$db->delete_category($c);$db->flush_cache_group();$s->update($before);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
