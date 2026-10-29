<?php
require_once __DIR__.'/reflection.php';
/** Prefix-free links, the shared branded-URL builder and the random-slug control. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-list-table.php';
$checks=[];function pf_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();wp_set_current_user(1);$before=$s->all();$prefix=$s->prefix();

// Resolve without exiting: the redirect hook reports what would be sent.
$rc=new ReflectionClass(GTLM_Redirect::class);$redirect=$rc->newInstanceWithoutConstructor();gtlm_test_access($rc->getProperty('db'))->setValue($redirect,$db);gtlm_test_access($rc->getProperty('settings'))->setValue($redirect,$s);gtlm_test_access($rc->getProperty('is_missing_link'))->setValue($redirect,false);
add_action('gtlm_before_redirect',function($link,$url){throw new RuntimeException('redirect:'.$link['slug'].'|'.$url);},10,2);
function pf_resolve($redirect,$path){$_SERVER['REQUEST_URI']=$path;$_SERVER['SCRIPT_NAME']='/index.php';try{$redirect->maybe_redirect();}catch(RuntimeException $e){return $e->getMessage();}return '';}

$std=$db->insert_link(['name'=>'Root probe','slug'=>'pf-root-probe','url'=>'https://example.com/root']);
$off=$db->insert_link(['name'=>'Root inactive','slug'=>'pf-root-off','url'=>'https://example.com/off','is_active'=>0]);
$page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Clash','post_name'=>'pf-root-probe']);
pf_check($std>0&&$off>0&&$page>0,'Fixture links and page created',$wpdb->last_error);

$s->update(array_merge($before,['prefix_free_links'=>0,'enable_advanced_redirects'=>0]));
pf_check(''===pf_resolve($redirect,'/pf-root-probe'),'With the setting off, a root path is not a short link');
pf_check(home_url('/'.$prefix.'/pf-root-probe')===$s->link_url('pf-root-probe'),'With the setting off, branded URLs keep the prefix');

$s->update(array_merge($before,['prefix_free_links'=>1,'enable_advanced_redirects'=>0]));
pf_check($s->prefix_free()&&''===$s->link_base(),'Prefix-free mode empties the link base');
pf_check(home_url('/pf-root-probe')===$s->link_url('pf-root-probe'),'Prefix-free branded URLs sit at the site root',$s->link_url('pf-root-probe'));
pf_check(home_url('/docs/a')===$s->link_url('docs/a','direct')&&''===$s->link_url('^x$','regex'),'Direct and regex URLs are unchanged');
pf_check(home_url('/')===$s->link_url(''),'The agent link base is the site root');
pf_check('redirect:pf-root-probe|https://example.com/root'===pf_resolve($redirect,'/pf-root-probe/'),'A root path resolves to its standard link',pf_resolve($redirect,'/pf-root-probe/'));
pf_check('redirect:pf-root-probe|https://example.com/root'===pf_resolve($redirect,'/'.$prefix.'/pf-root-probe'),'Links already shared with the prefix keep redirecting');
pf_check('redirect:pf-root-probe|https://example.com/root'===pf_resolve($redirect,'/PF-Root-Probe'),'Root lookups ignore letter case');
pf_check(''===pf_resolve($redirect,'/pf-root-off'),'An inactive link does not claim a root path');
pf_check(!gtlm_test_access($rc->getProperty('is_missing_link'))->getValue($redirect),'A root miss falls through to WordPress instead of a 404');
$q=$wpdb->num_queries;foreach(['/pf-root-probe/child','/favicon.ico','/'] as $path){pf_resolve($redirect,$path);}
pf_check($q===$wpdb->num_queries,'Nested, file-like and front-page paths never reach the database',$wpdb->num_queries-$q);
pf_check(['pf-root-probe']===$db->root_slug_conflicts(),'A link sharing a published page URL is reported',$db->root_slug_conflicts());
// Production pairs a unicode_520_ci links table with a unicode_ci wp_posts; a plain '=' join errors on that.
$links_table=GTLM_DB::links_table();$posts_coll=(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->posts));$links_coll=(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$links_table));
$other='utf8mb4_unicode_ci'===$posts_coll?'utf8mb4_unicode_520_ci':'utf8mb4_unicode_ci';$wpdb->query("ALTER TABLE {$links_table} CONVERT TO CHARACTER SET utf8mb4 COLLATE {$other}");wp_cache_flush();
pf_check(['pf-root-probe']===$db->root_slug_conflicts(),'Clashes are still found when the links table and wp_posts use different collations',[$posts_coll,$other,$db->root_slug_conflicts()]);
$wpdb->query("ALTER TABLE {$links_table} CONVERT TO CHARACTER SET utf8mb4 COLLATE ".esc_sql($links_coll?:$posts_coll));

$table=new GTLM_List_Table($db,[],$s->link_base(),'');$branded=gtlm_test_access((new ReflectionClass($table))->getMethod('column_branded_url'));$cell=$branded->invoke($table,$db->get_link_by_id($std));
pf_check(str_contains($cell,'<code>/pf-root-probe</code>')&&str_contains($cell,esc_url(home_url('/pf-root-probe'))),'The links list shows the root path',$cell);
wp_set_current_user(1);do_action('rest_api_init');$request=new WP_REST_Request('GET','/gt-link-manager/v1/links');$request->set_param('search','pf-root-probe');$response=rest_do_request($request);$urls=array_column((array)$response->get_data(),'url');
pf_check(in_array(home_url('/pf-root-probe'),$urls,true),'REST, and so the block editor, returns the root URL',$response->get_data());
pf_check(home_url('/pf-root-probe')===(gtlm_agent('links.get',['id'=>$std])['link']['url']??''),'Agents get the root URL');

$pages=new GTLM_Admin_Pages($db,$s,(new ReflectionClass(GTLM_Import::class))->newInstanceWithoutConstructor());
ob_start();$pages->render_settings_page();$html=ob_get_clean();
pf_check(str_contains($html,'name="prefix_free_links" value="1"')&&str_contains($html,'checked=\'checked\'')&&str_contains($html,'<code>pf-root-probe</code>'),'Settings show the control and the clashing link',substr($html,0,200));
$_GET=['page'=>'gtlm-links-edit'];ob_start();$pages->render_edit_page();$form=ob_get_clean();$_GET=[];
pf_check(str_contains($form,'id="gtlm-random-slug"')&&str_contains($form,'name="slug" id="slug"'),'The link form has a Random slug button beside the slug',substr($form,0,200));

$s->update($before);wp_delete_post($page,true);foreach([$std,$off] as $id){$db->delete_link($id);}
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
