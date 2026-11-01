<?php
require_once __DIR__.'/reflection.php';
/**
 * The circled ? help links: right page on each screen, safe markup, no link for a topic with no page.
 * Set GTLM_TEST_LIVE_DOCS=1 to also request every docs page and anchor. It needs network access and
 * fails for a page that isn't published yet, so it stays out of the default run. Disposable fixture only.
 */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/admin.php';
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-block-editor.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations-admin.php';
$_SERVER['HTTP_HOST']=$_SERVER['HTTP_HOST']??'localhost';
$checks=[];function hl_check($ok,$label,$detail=null){global $checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
$db=new GTLM_DB();$s=GTLM_Settings::get_instance();wp_set_current_user(1);$before=$s->all();set_current_screen('toplevel_page_gtlm-links');
$s->update(array_merge($before,['enable_advanced_redirects'=>1,'enable_geo_targeting'=>1]));
$base='https://gauravtiwari.org/docs/gt-link-manager/';
$pages=new GTLM_Admin_Pages($db,$s,new GTLM_Import($db,$s));
$render=function(callable $fn,array $get=[]){$_GET=$get;$_REQUEST=$get;ob_start();$fn();$html=ob_get_clean();$_GET=[];$_REQUEST=[];return $html;};
$linksIn=function(string $html){preg_match_all('#<a class="gtlm-help-link" href="([^"]+)"#',$html,$m);return array_map('html_entity_decode',$m[1]);};
$url=fn($topic)=>gtlm_help_url($topic);

// The helper.
$topics=gtlm_help_topics();
hl_check(count($topics)>0&&!array_filter(array_keys($topics),fn($t)=>!str_starts_with(gtlm_help_url($t),$base)),'Every topic resolves under the plugin docs root');
hl_check(''===gtlm_help_url('no_such_topic')&&''===gtlm_help_link('no_such_topic','x')&&''===gtlm_help_link('','x'),'A topic with no page has no link');
$link=gtlm_help_link('settings','A <b>label</b>');
hl_check(str_contains($link,'href="'.$base.'configuration-features/settings-configuration/"'),'The link points at the topic',$link);
hl_check(str_contains($link,'target="_blank"')&&str_contains($link,'rel="noopener noreferrer"'),'It opens in a new tab with a safe rel',$link);
hl_check(str_contains($link,'gtlm-icon--help-circle')&&str_contains($link,'aria-hidden="true"'),'It shows the circled ? as a decorative icon',$link);
hl_check(str_contains($link,'class="screen-reader-text"')&&str_contains($link,'(opens in a new tab)'),'Screen readers get a name for it',$link);
hl_check(!str_contains($link,'<b>')&&str_contains($link,'&lt;b&gt;'),'The label is escaped in both the title and the text',$link);

// Each screen links the page that explains it.
$expect=function(string $screen,string $html,array $want)use($linksIn,$url){$got=$linksIn($html);foreach($want as $topic){hl_check(in_array($url($topic),$got,true),$screen.' links '.$topic,$got);}};
$expect('All Links',$render(fn()=>$pages->render_links_page(),['page'=>'gtlm-links']),['links']);
$trash=$linksIn($render(fn()=>$pages->render_links_page(),['page'=>'gtlm-links','link_status'=>'trash']));
hl_check(in_array($url('trash'),$trash,true)&&!in_array($url('links'),$trash,true),'The Trash view links the trash section instead',$trash);
$new=$render(fn()=>$pages->render_edit_page(),['page'=>'gtlm-links-edit']);
$expect('Add New',$new,['link_new','link_mode','regex','redirect_type','rel','noindex','link_category','geo_rules']);
$id=$db->insert_link(['name'=>'Help probe','slug'=>'help-probe','url'=>'https://example.com/']);
$expect('Edit Link',$render(fn()=>$pages->render_edit_page(),['page'=>'gtlm-links-edit','link_id'=>(string)$id]),['link_edit']);
$db->delete_link($id);
$expect('Categories',$render(fn()=>$pages->render_categories_page(),['page'=>'gtlm-links-categories']),['categories']);
$settings=$render(fn()=>$pages->render_settings_page(),['page'=>'gtlm-links-settings']);
$expect('Settings',$settings,['settings','link_prefix','redirect_type','rel','noindex','click_counts','rest_api','geo_settings','geo_detection','geo_debug','advanced_redirects','trash_retention','uninstall','tools','diagnostics']);
$import=$render(fn()=>$pages->render_import_export_page(),['page'=>'gtlm-links-import-export']);
$expect('Import / Export',$import,['import_export','export','import_upload']);
$expect('AI agents',$render(fn()=>GTLM_Agent::page()),['ai_agents']);

// Integrations: the heading covers every tool, and a tool with its own page links it too.
$integrations=$render(fn()=>GTLM_Integrations_Admin::render());$got=$linksIn($integrations);
$expect('Integrations',$integrations,['integrations','integration_ga4','integration_plausible','integration_matomo','integration_simple_analytics','integration_fathom']);
hl_check(1===count(array_filter($got,fn($u)=>str_ends_with($u,'/analytics-integrations/'))),'Tools without a page add no duplicate hub links',$got);
hl_check(!array_filter(array_keys(GTLM_Integrations::tools()),fn($k)=>''===gtlm_help_url('integration_'.$k)&&''!==gtlm_help_link('integration_'.$k,'x')),'A tool with no page renders no link');

// Nothing outside the help links changes: existing third-party documentation stays.
hl_check(str_contains($integrations,'developers.google.com/analytics')&&str_contains($integrations,'novashare.io/docs/link-shortening'),'Vendor documentation links stay');

// The block editor gets the URL for its popover.
$editor=(new ReflectionClass(GTLM_Block_Editor::class))->newInstanceWithoutConstructor();gtlm_test_access(new ReflectionProperty(GTLM_Block_Editor::class,'settings'))->setValue($editor,$s);$editor->enqueue_assets();
$data=(string)wp_scripts()->get_data('gt-link-manager-editor','data');
hl_check(str_contains($data,'"helpUrl":"'.$url('block_editor').'"'),'The editor receives the inserter docs URL',$data);
$bundle=(string)file_get_contents(GTLM_PATH.'blocks/link-inserter/build/index.js');
hl_check(str_contains($bundle,'helpUrl'),'The committed editor build uses it');

$s->update($before);

// Optional: every page and anchor exists on the live docs site.
if('1'===getenv('GTLM_TEST_LIVE_DOCS')){
 $ctx=stream_context_create(['http'=>['timeout'=>30,'ignore_errors'=>true,'follow_location'=>0,'user_agent'=>'Mozilla/5.0 GT Link Manager help link check']]);$cache=[];
 foreach($topics as $topic=>$path){[$page,$anchor]=array_pad(explode('#',$path,2),2,'');
  if(!isset($cache[$page])){$body=@file_get_contents($base.$page,false,$ctx);$head=function_exists('http_get_last_response_headers')?(array)http_get_last_response_headers():[];$cache[$page]=[(string)($head[0]??''),(string)$body];}
  [$status,$body]=$cache[$page];
  hl_check(str_contains($status,' 200'),'Live: '.$page.' is published',$status);
  if(''!==$anchor){hl_check(str_contains($body,'id="'.$anchor.'"'),'Live: '.$page.' has #'.$anchor);}
 }
}

$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
