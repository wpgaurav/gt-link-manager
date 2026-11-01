<?php
require_once __DIR__.'/reflection.php';
/** Novashare's documented novashare_short_link_url contract. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
$checks=[];function ns_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();wp_set_current_user(1);$before=$s->all();$table=GTLM_DB::links_table();
$count=fn()=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE slug LIKE 'ns-%'");
$slug=fn($url,$len=8)=>'ns-'.substr(md5($url),0,$len);
$short=fn($long,$network='facebook')=>apply_filters('novashare_short_link_url','DEFAULT',$long,$network);

ns_check(0===$s->defaults()['novashare_short_links']&&!$s->novashare_enabled(),'The integration is off by default');
ns_check(!has_filter('novashare_short_link_url'),'Nothing hooks Novashare until the setting is on');
ns_check('DEFAULT'===$short('https://example.com/'),'With the setting off, the filter returns its default');
$s->update(array_merge($before,['default_redirect_type'=>302,'default_rel'=>['nofollow','sponsored'],'default_noindex'=>1,'prefix_free_links'=>0]));$s->set_novashare_enabled(true);
ns_check($s->novashare_enabled(),'The setting persists through its setter');
$s->update(array_merge($s->all(),['novashare_short_links'=>0]));
ns_check($s->novashare_enabled(),'Saving the main settings form leaves Novashare on');

require_once GTLM_PATH.'includes/class-gtlm-novashare.php';GTLM_Novashare::init($db,$s);

global $wp_rewrite;$wp_rewrite->set_permalink_structure('/%postname%/');$wp_rewrite->flush_rules(false);
$post=wp_insert_post(['post_status'=>'publish','post_title'=>'Share &amp; "probe"','post_name'=>'ns-share-probe']);
$draft=wp_insert_post(['post_status'=>'draft','post_title'=>'Draft','post_name'=>'ns-draft-probe']);
$long=get_permalink($post);ns_check(str_ends_with($long,'/ns-share-probe/')&&$post===url_to_postid($long),'Fixture post resolves from its permalink',$long);

// Creating a link.
$got=$short($long);$key=$slug($long);$link=$db->get_link_by_exact_slug($key);
ns_check($s->link_url($key)===$got,'A published post gets a branded link',$got);
ns_check(is_array($link)&&$long===$link['url'],'The link points at the shared URL',$link);
ns_check(302===(int)$link['redirect_type']&&'nofollow,sponsored'===$link['rel']&&1===(int)$link['noindex']&&1===(int)$link['is_active'],'The link uses the configured defaults',$link);
ns_check('Share & "probe"'===html_entity_decode((string)$link['name'],ENT_QUOTES)&&str_contains((string)$link['notes'],'Novashare'),'The link is named after the post and noted as generated',$link);
$cat=$db->get_category((int)$link['category_id']);ns_check('novashare'===($cat['slug']??'')&&1===(int)$cat['count'],'The link lands in a Novashare category',$cat);

// One link per URL.
$rows=$count();
ns_check($got===$short($long,'twitter')&&$got===$short($long,'facebook'),'Other networks sharing the same URL reuse the link');
ns_check($rows===$count(),'Repeat calls add no rows',$count());
$utm=$long.'?utm_source=x&utm_medium=social';$got2=$short($utm,'twitter');$link2=$db->get_link_by_exact_slug($slug(esc_url_raw($utm)));
ns_check($got2!==$got&&is_array($link2)&&str_contains($link2['url'],'utm_source=x'),'A UTM-tagged URL gets its own link',$got2);
ns_check((int)$link['category_id']===(int)$link2['category_id'],'Later links reuse the Novashare category',$link2);

// Nothing else becomes a link.
$rows=$count();
foreach(['an external URL'=>'https://evil.example/phish','a non-UTM query string'=>$long.'?ref=abc','a relative URL'=>'/ns-share-probe/','a script URL'=>'javascript:alert(1)','an empty URL'=>'','a draft'=>get_permalink($draft),'a missing post'=>home_url('/ns-no-such-post/')] as $label=>$bad){ns_check('DEFAULT'===$short($bad),'Refuses '.$label,$short($bad));}
ns_check('DEFAULT'===$short(['not','a','string'])&&'DEFAULT'===$short(null),'Non-string input returns the default');
ns_check($rows===$count(),'Refused URLs create no rows',$count());

// Post types: url_to_postid() covers any public type, and ?p= answers for any type at all.
register_post_type('ns_public',['public'=>true,'rewrite'=>['slug'=>'ns-public']]);register_post_type('ns_hidden',['public'=>false]);$wp_rewrite->flush_rules(false);
$cpt=wp_insert_post(['post_type'=>'ns_public','post_status'=>'publish','post_title'=>'Public type','post_name'=>'ns-cpt-probe']);$hidden=wp_insert_post(['post_type'=>'ns_hidden','post_status'=>'publish','post_title'=>'Hidden type']);
$cptUrl=get_permalink($cpt);$gotCpt=$short($cptUrl);ns_check($cpt===url_to_postid($cptUrl)&&$s->link_url($slug($cptUrl))===$gotCpt,'A published item of another public post type gets a link',[$cptUrl,$gotCpt]);
$rows=$count();$hiddenUrl=home_url('/?p='.$hidden);ns_check($hidden===url_to_postid($hiddenUrl)&&'DEFAULT'===$short($hiddenUrl)&&$rows===$count(),'A non-public post type is refused even though ?p= resolves it',$hiddenUrl);

// What the readme says about UTM values: each new one is a new row.
$rows=$count();$short($long.'?utm_term=first');$short($long.'?utm_term=second');ns_check($rows+2===$count(),'Each new UTM value makes its own link',$count());

// Developer control.
add_filter('gtlm_novashare_should_shorten','__return_false');
ns_check('DEFAULT'===$short($long.'?utm_source=vetoed'),'gtlm_novashare_should_shorten can veto a share');
remove_filter('gtlm_novashare_should_shorten','__return_false');
$seen=null;$allow=function($should,$url,$network)use(&$seen){$seen=[$should,$network];return true;};add_filter('gtlm_novashare_should_shorten',$allow,10,3);
$external='https://partner.example/page';$got3=$short($external,'reddit');remove_filter('gtlm_novashare_should_shorten',$allow,10);
$link3=$db->get_link_by_exact_slug($slug($external));
ns_check($s->link_url($slug($external))===$got3&&is_array($link3)&&$external===$link3['name'],'The filter can allow an external URL, named after the URL',$link3);
ns_check([false,'reddit']===$seen,'The filter receives the default decision and the network',$seen);
$allow2=fn()=>true;add_filter('gtlm_novashare_should_shorten',$allow2);ns_check('DEFAULT'===$short('javascript:alert(1)'),'The filter cannot admit a non-web URL');remove_filter('gtlm_novashare_should_shorten',$allow2);

// The owner's decisions stick.
$paused=$long.'?utm_source=paused';$short($paused);$pausedLink=$db->get_link_by_exact_slug($slug(esc_url_raw($paused)));$db->toggle_active((int)$pausedLink['id'],false);
ns_check('DEFAULT'===$short($paused)&&0===(int)$db->get_link_by_id((int)$pausedLink['id'])['is_active'],'A paused link is not revived');
$trashed=$long.'?utm_source=trashed';$short($trashed);$trashLink=$db->get_link_by_exact_slug($slug(esc_url_raw($trashed)));$db->trash_link((int)$trashLink['id']);
ns_check('DEFAULT'===$short($trashed)&&null!==$db->get_link_by_id((int)$trashLink['id'])['trashed_at'],'A trashed link stays in the trash');

// Slug taken by something else.
$clash=$long.'?utm_source=clash';$hand=$db->insert_link(['name'=>'Hand made','slug'=>$slug(esc_url_raw($clash)),'url'=>'https://example.com/hand']);
$got4=$short($clash);ns_check($s->link_url($slug(esc_url_raw($clash),16))===$got4,'A slug held by another destination moves to a longer slug',$got4);
ns_check('https://example.com/hand'===$db->get_link_by_id($hand)['url'],'The existing link is left untouched');

// Lost race: a concurrent first share inserts between this request's lookup and its insert.
$raceUrl=esc_url_raw($long.'?utm_source=race');$raceSlug=$slug($raceUrl);
$racer=function($sql)use(&$racer,$wpdb,$table,$raceSlug,$raceUrl){if(str_starts_with($sql,'INSERT')&&str_contains($sql,$raceSlug)){remove_filter('query',$racer);$wpdb->insert($table,['name'=>'Winner','slug'=>$raceSlug,'url'=>$raceUrl]);}return $sql;};
add_filter('query',$racer);$wpdb->suppress_errors(true);$got5=$short($raceUrl);$wpdb->suppress_errors(false);remove_filter('query',$racer);
ns_check($s->link_url($raceSlug)===$got5&&'Winner'===$db->get_link_by_exact_slug($raceSlug)['name'],'A concurrent first share resolves to the winning row',$got5);

// Prefix-free mode and the redirect itself.
$s->update(array_merge($s->all(),['prefix_free_links'=>1]));
ns_check(home_url('/'.$key)===$short($long),'Prefix-free sites get root URLs',$short($long));
$s->update(array_merge($s->all(),['prefix_free_links'=>0]));
$rc=new ReflectionClass(GTLM_Redirect::class);$redirect=$rc->newInstanceWithoutConstructor();gtlm_test_access($rc->getProperty('db'))->setValue($redirect,$db);gtlm_test_access($rc->getProperty('settings'))->setValue($redirect,$s);gtlm_test_access($rc->getProperty('is_missing_link'))->setValue($redirect,false);
add_action('gtlm_before_redirect',function($l,$u){throw new RuntimeException('redirect:'.$u);},10,2);
$_SERVER['REQUEST_URI']=wp_parse_url($got,PHP_URL_PATH);$_SERVER['SCRIPT_NAME']='/index.php';try{$redirect->maybe_redirect();$res='';}catch(RuntimeException $e){$res=$e->getMessage();}
ns_check('redirect:'.$long===$res,'The generated link redirects to the shared URL',$res);

// Screens.
require_once GTLM_PATH.'includes/class-gtlm-integrations-admin.php';
$_GET=['page'=>'gtlm-links-settings'];$pages=new GTLM_Admin_Pages($db,$s,new GTLM_Import($db,$s));ob_start();$pages->render_settings_page();$html=ob_get_clean();$_GET=[];
ns_check(!str_contains($html,'novashare_short_links')&&!str_contains($html,'Novashare'),'The Settings screen no longer shows Novashare');
ob_start();GTLM_Integrations_Admin::render();$html=ob_get_clean();
ns_check(str_contains($html,'name="gtlm_integrations[novashare_short_links]" value="1"')&&str_contains($html,'novashare.io/docs/link-shortening/#custom')&&str_contains($html,'choose Custom'),'The Integrations screen shows the control and the Novashare steps',substr($html,0,200));
ns_check(1===preg_match('/id="gtlm-integration-novashare" open>.*?is-on/s',$html)&&1===preg_match('/novashare_short_links\]" value="1"\s+checked/',$html),'The Integrations screen shows Novashare as on',substr($html,(int)strpos($html,'gtlm-integration-novashare'),400));
GTLM_Integrations_Admin::apply_form([]);ns_check(!$s->novashare_enabled(),'Unticking it on the Integrations screen turns Novashare off');
ob_start();GTLM_Integrations_Admin::render();$html=ob_get_clean();ns_check(0===preg_match('/id="gtlm-integration-novashare" open/',$html),'Novashare reads as off once turned off');
GTLM_Integrations_Admin::apply_form(['novashare_short_links'=>'1']);ns_check($s->novashare_enabled(),'Ticking it on the Integrations screen turns Novashare on');

// Cleanup.
$wpdb->query("DELETE FROM {$table} WHERE slug LIKE 'ns-%'");$db->delete_link($hand);$db->delete_category((int)$link['category_id']);$db->flush_cache_group();
wp_delete_post($post,true);wp_delete_post($draft,true);wp_delete_post($cpt,true);wp_delete_post($hidden,true);$wp_rewrite->set_permalink_structure('');$wp_rewrite->flush_rules(false);$s->update($before);$s->set_novashare_enabled(false);delete_option(GTLM_Integrations::OPTION);$s->set_enabled_integrations([]);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
