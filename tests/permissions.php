<?php
require_once __DIR__.'/reflection.php';
/** Direct links, regex links and permanent delete need an administrator on every route. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/user.php';
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH.'includes/class-gt-link-admin.php';
require_once GTLM_PATH.'includes/class-gt-link-list-table.php';
require_once GTLM_PATH.'includes/class-gtlm-import-file.php';
$checks=[];function pm_check($ok,$label,$detail=null){global$checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
global $wpdb;$db=new GTLM_DB();$s=GTLM_Settings::get_instance();$table=GTLM_DB::links_table();$before=$s->all();
$s->update(array_merge($s->all(),['enable_advanced_redirects'=>1,'enable_click_tracking'=>1]));
$user=fn($role)=>wp_insert_user(['user_login'=>'pm-'.$role,'user_pass'=>wp_generate_password(),'user_email'=>'pm-'.$role.'@example.com','role'=>$role]);
$users=['subscriber'=>$user('subscriber'),'contributor'=>$user('contributor'),'author'=>$user('author'),'editor'=>$user('editor'),'administrator'=>$user('administrator')];
$as=fn($role)=>wp_set_current_user($users[$role]);
$call=function($object,$method,...$args){$m=gtlm_test_access(new ReflectionMethod($object,$method));return $m->invoke($m->isStatic()?null:$object,...$args);};
$rest=function($method,$route,$params=[]){$r=new WP_REST_Request($method,'/gt-link-manager/v1'.$route);foreach($params as $k=>$v)$r->set_param($k,$v);return rest_do_request($r);};
$code=fn($response)=>$response->get_status();
$link=fn($slug,$mode='standard',$extra=[])=>$db->insert_link(array_merge(['name'=>'PM '.$slug,'slug'=>$slug,'url'=>'https://example.com/'.md5($slug),'link_mode'=>$mode],$extra));
$row=fn($id)=>$db->get_link_by_id($id);
$trashed=fn($id)=>null!==($db->get_link_by_id($id)['trashed_at']??null);
$unchanged=function($id,$snapshot)use($db){$now=$db->get_link_by_id($id);foreach(['slug','url','name','link_mode','regex_replacement','is_active','redirect_type','rel','category_id','trashed_at','total_clicks'] as $k){if(($now[$k]??null)!==($snapshot[$k]??null))return $k;}return true;};
do_action('rest_api_init');
$as('administrator');$cat=$db->insert_category(['name'=>'PM cat','slug'=>'pm-cat']);$cat2=$db->insert_category(['name'=>'PM cat two','slug'=>'pm-cat-two']);

// REST, as each role that holds the general capability.
foreach(['contributor','author','editor'] as $role){$as($role);$t=$role.': ';
 $direct=$link("rest-direct-$role",'direct',['category_id'=>$cat,'regex_replacement'=>'']);$regex=$link("^rest-rx-$role/(.*)$",'regex',['regex_replacement'=>'https://example.com/$1']);$std=$link("rest-std-$role");
 $sd=$row($direct);$sr=$row($regex);
 foreach([['link_mode'=>'direct','slug'=>"rest-new-d-$role"],['link_mode'=>'regex','slug'=>"^rest-new-r-$role$"],['slug'=>"rest-new-s-$role",'regex_replacement'=>'https://example.com/$1']] as $body){
  $r=$rest('POST','/links',array_merge(['name'=>'PM refused','url'=>'https://example.com/x'],$body));pm_check(403===$code($r)&&'gtlm_admin_only'===($r->get_data()['code']??''),$t.'REST create refused: '.json_encode($body),$r->get_data());
  pm_check(null===$db->get_link_by_exact_slug($body['slug']),$t.'Nothing was created: '.$body['slug']);
 }
 $r=$rest('POST','/links',['name'=>'PM ok '.$role,'slug'=>"rest-ok-$role",'url'=>'https://example.com/ok']);pm_check(201===$code($r),$t.'REST create standard works',$r->get_data());
 $ss=$row($std);
 $r=$rest('PATCH','/links/'.$std,['link_mode'=>'regex','slug'=>'^x$']);pm_check(403===$code($r)&&true===$unchanged($std,$ss),$t.'REST cannot turn a standard link into a regex rule',$r->get_data());
 $r=$rest('PATCH','/links/'.$std,['regex_replacement'=>'https://example.com/$1']);pm_check(403===$code($r)&&true===$unchanged($std,$ss),$t.'REST cannot set a replacement on a standard link');
 $r=$rest('PATCH','/links/'.$std,['name'=>'PM renamed '.$role]);pm_check(200===$code($r)&&'PM renamed '.$role===$row($std)['name'],$t.'REST edits a standard link',$r->get_data());
 foreach([$direct=>$sd,$regex=>$sr] as $id=>$snap){
  $r=$rest('PATCH','/links/'.$id,['name'=>'PM hijacked']);pm_check(403===$code($r)&&true===$unchanged($id,$snap),$t.'REST cannot edit '.$snap['link_mode'],$r->get_data());
  $r=$rest('PATCH','/links/'.$id,['link_mode'=>'standard','slug'=>'pm-converted-'.$role]);pm_check(403===$code($r)&&true===$unchanged($id,$snap),$t.'REST cannot convert '.$snap['link_mode'].' to standard',$unchanged($id,$snap));
  $r=$rest('DELETE','/links/'.$id);pm_check(403===$code($r)&&true===$unchanged($id,$snap),$t.'REST cannot trash '.$snap['link_mode']);
  $r=$rest('DELETE','/links/'.$id,['force'=>true]);pm_check(403===$code($r)&&is_array($row($id)),$t.'REST cannot delete '.$snap['link_mode']);
  $r=$rest('PATCH','/links/'.$id.'/toggle-active',['is_active'=>false]);pm_check(403===$code($r)&&true===$unchanged($id,$snap),$t.'REST cannot toggle '.$snap['link_mode']);
 }
 $as('administrator');$db->trash_link($direct);$as($role);$r=$rest('PATCH','/links/'.$direct.'/restore');pm_check(403===$code($r)&&$trashed($direct),$t.'REST cannot restore a trashed direct link');$as('administrator');$db->restore_link($direct);$as($role);
 $r=$rest('DELETE','/links/'.$std,['force'=>true]);pm_check(403===$code($r)&&is_array($row($std)),$t.'REST cannot permanently delete a standard link');
 $r=$rest('DELETE','/links/'.$std);pm_check(200===$code($r)&&$trashed($std),$t.'REST can still trash a standard link',$r->get_data());
 $r=$rest('DELETE','/links/'.$std,['force'=>true]);pm_check(403===$code($r)&&is_array($row($std)),$t.'REST cannot permanently delete it from the Trash either');
 $r=$rest('PATCH','/links/'.$std.'/restore');pm_check(200===$code($r)&&!$trashed($std),$t.'REST can restore a standard link');
 $r=$rest('PATCH','/links/'.$std.'/toggle-active',['is_active'=>false]);pm_check(200===$code($r),$t.'REST can toggle a standard link');$db->toggle_active($std,true);
 $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
 foreach(['move','copy'] as $mode){$r=$rest('POST','/links/bulk-category',['link_ids'=>[$direct,$regex],'category_id'=>$cat2,'mode'=>$mode]);pm_check(403===$code($r),$t."Bulk $mode of only direct and regex is a 403",$r->get_data());}
 pm_check($count===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}")&&true===$unchanged($direct,$sd),$t.'Those bulk calls changed nothing');
 $r=$rest('POST','/links/bulk-category',['link_ids'=>[$std,$direct],'category_id'=>$cat2,'mode'=>'copy']);$d=$r->get_data();pm_check(200===$code($r)&&1===$d['copied']&&1===$d['failed']&&1===$d['restricted'],$t.'A mixed bulk copy copies only the standard link',$d);
 pm_check(null===$db->get_link_by_exact_slug("rest-direct-$role-2"),$t.'The direct link was not copied');
 $r=$rest('POST','/links/bulk-category',['link_ids'=>[$std,$direct],'category_id'=>$cat2,'mode'=>'move']);$d=$r->get_data();pm_check(1===$d['moved']&&1===$d['restricted']&&$cat2===(int)$row($std)['category_id']&&$cat===(int)$row($direct)['category_id'],$t.'A mixed bulk move skips the direct link',$d);

 // The agent runs on the same routes.
 pm_check(false===gtlm_agent('links.create',['link'=>['name'=>'PM agent','slug'=>"agent-d-$role",'url'=>'https://example.com/a','link_mode'=>'direct']])['ok'],$t.'Agent cannot create a direct link');
 foreach([['links.update',['id'=>$direct,'patch'=>['name'=>'PM agent hijack']]],['links.trash',['id'=>$direct]],['links.restore',['id'=>$direct]],['links.set_active',['id'=>$direct,'is_active'=>false]],['links.bulk_category',['link_ids'=>[$direct],'category_id'=>$cat2,'mode'=>'copy']]] as [$cmd,$input]){$a=gtlm_agent($cmd,$input);pm_check(false===$a['ok']&&str_contains((string)($a['error']??''),'administrators'),$t.'Agent refused: '.$cmd,$a);}
 $a=gtlm_agent('links.create',['link'=>['name'=>'PM agent ok','slug'=>"agent-s-$role",'url'=>'https://example.com/a']]);pm_check(true===$a['ok'],$t.'Agent creates a standard link',$a);
 pm_check(true===$unchanged($direct,$sd)||'category_id'===$unchanged($direct,$sd),$t.'The direct link survived every refused call',$unchanged($direct,$sd));
}

// An administrator keeps all of it.
$as('administrator');
$r=$rest('POST','/links',['name'=>'PM admin d','slug'=>'/admin-direct/','url'=>'https://example.com/d','link_mode'=>'direct']);$ad=(int)($r->get_data()['id']??0);pm_check(201===$code($r),'Admin: REST creates a direct link',$r->get_data());
$r=$rest('POST','/links',['name'=>'PM admin r','slug'=>'^admin-rx/(.*)$','url'=>'https://example.com/r','link_mode'=>'regex','regex_replacement'=>'https://example.com/$1']);$ar=(int)($r->get_data()['id']??0);pm_check(201===$code($r),'Admin: REST creates a regex rule',$r->get_data());
$r=$rest('PATCH','/links/'.$ad,['name'=>'PM admin edited']);pm_check(200===$code($r),'Admin: REST edits a direct link');
$r=$rest('POST','/links/bulk-category',['link_ids'=>[$ad],'category_id'=>$cat2,'mode'=>'copy']);pm_check(1===$r->get_data()['copied']&&0===$r->get_data()['restricted'],'Admin: bulk copy includes direct links',$r->get_data());
pm_check(200===$code($rest('DELETE','/links/'.$ad))&&200===$code($rest('PATCH','/links/'.$ad.'/restore'))&&200===$code($rest('PATCH','/links/'.$ad.'/toggle-active',['is_active'=>true])),'Admin: trash, restore and toggle a direct link');
$r=$rest('DELETE','/links/'.$ar,['force'=>true]);pm_check(200===$code($r)&&null===$row($ar),'Admin: permanently deletes through REST',$r->get_data());

// Filters: gtlm_capabilities can't open these, gtlm_restricted_capability can.
add_filter('gtlm_capabilities',$one=fn()=>'read');$as('subscriber');
pm_check(201===$code($rest('POST','/links',['name'=>'PM sub','slug'=>'pm-sub','url'=>'https://example.com/s'])),'A one-capability filter still opens standard links');
pm_check(403===$code($rest('POST','/links',['name'=>'PM sub d','slug'=>'pm-sub-d','url'=>'https://example.com/s','link_mode'=>'direct'])),'A one-capability filter does not open direct links');
pm_check(403===$code($rest('DELETE','/links/'.(int)$db->get_link_by_exact_slug('pm-sub')['id'],['force'=>true])),'A one-capability filter does not open permanent delete');
add_filter('gtlm_capabilities',$one2=fn()=>'manage_options',20);$as('administrator');pm_check(201===$code($rest('POST','/links',['name'=>'PM cap d','slug'=>'pm-cap-d','url'=>'https://example.com/s','link_mode'=>'direct'])),'A filter requiring manage_options leaves administrators working');remove_filter('gtlm_capabilities',$one);remove_filter('gtlm_capabilities',$one2,20);
add_filter('gtlm_restricted_capability',$grant=fn($cap,$ctx)=>'advanced_links'===$ctx?'edit_others_posts':$cap,10,2);
$as('editor');pm_check(201===$code($rest('POST','/links',['name'=>'PM ed d','slug'=>'pm-ed-d','url'=>'https://example.com/s','link_mode'=>'direct'])),'gtlm_restricted_capability delegates direct links to editors');
pm_check(403===$code($rest('DELETE','/links/'.(int)$db->get_link_by_exact_slug('pm-ed-d')['id'],['force'=>true])),'That delegation does not include permanent delete');
$as('contributor');pm_check(403===$code($rest('POST','/links',['name'=>'PM co d','slug'=>'pm-co-d','url'=>'https://example.com/s','link_mode'=>'direct'])),'That delegation stops at the capability it names');remove_filter('gtlm_restricted_capability',$grant,10);

// Admin screens.
$admin=(new ReflectionClass('GTLM_Admin'))->newInstanceWithoutConstructor();foreach(['db'=>$db,'settings'=>$s] as $prop=>$value){gtlm_test_access(new ReflectionProperty('GTLM_Admin',$prop))->setValue($admin,$value);}
add_filter('wp_redirect',$thrower=function($url){throw new RuntimeException($url);});
$run=function($fn){try{$fn();}catch(RuntimeException $e){parse_str((string)parse_url($e->getMessage(),PHP_URL_QUERY),$q);return $q;}return [];};
$save=function($post)use($admin,$call,$run){$_POST=array_merge(['gtlm_action'=>'save_link','name'=>'PM saved','url'=>'https://example.com/saved','_wpnonce'=>wp_create_nonce('gtlm_link_save')],$post);$_REQUEST=$_POST;$q=$run(fn()=>$call($admin,'handle_link_save_action'));$_POST=[];$_REQUEST=[];return $q['gtlm_notice']??'';};
$action=function($action,$id)use($admin,$call,$run){$_GET=['action'=>$action,'link'=>$id];$_REQUEST=['_wpnonce'=>wp_create_nonce('gtlm_'.$action.'_'.$id)];$q=$run(fn()=>$call($admin,'handle_link_actions'));$_GET=[];$_REQUEST=[];return $q['gtlm_notice']??'';};
add_filter('wp_doing_ajax','__return_true');add_filter('wp_die_ajax_handler',fn()=>function(){throw new DomainException('die');});
$quick=function($id)use($admin){$_POST=['link_id'=>$id,'url'=>'https://example.com/quick','redirect_type'=>302,'nonce'=>wp_create_nonce('gtlm_quick_edit')];ob_start();try{$admin->ajax_quick_edit();}catch(DomainException $e){}$out=json_decode((string)ob_get_clean(),true);$_POST=[];return $out;};
$pages=new GTLM_Admin_Pages($db,$s,new GTLM_Import($db,$s));set_current_screen('toplevel_page_gtlm-links');
$page=function($method,$get)use($pages){$_GET=$get;ob_start();$pages->$method();$html=(string)ob_get_clean();$_GET=[];return $html;};
$listTable=fn($view)=>new GTLM_List_Table($db,[],$s->link_base(),$view);

foreach(['contributor','editor'] as $role){$as($role);$t=$role.': ';
 $direct=$link("ui-direct-$role",'direct',['category_id'=>$cat,'total_clicks'=>5]);$regex=$link("^ui-rx-$role/(.*)$",'regex',['regex_replacement'=>'https://example.com/$1']);$std=$link("ui-std-$role",'standard',['category_id'=>$cat]);
 $wpdb->update($table,['total_clicks'=>5],['id'=>$direct]);$db->flush_cache_group();$sd=$row($direct);$sr=$row($regex);

 // Form saves.
 pm_check('admin_only_links'===$save(['link_mode'=>'regex','slug'=>"^new-$role$"])&&null===$db->get_link_by_exact_slug("^new-$role$"),$t.'Form cannot create a regex rule');
 pm_check('admin_only_links'===$save(['link_mode'=>'direct','slug'=>"new-d-$role"])&&null===$db->get_link_by_exact_slug("new-d-$role"),$t.'Form cannot create a direct link');
 $ss=$row($std);pm_check('admin_only_links'===$save(['link_id'=>$std,'link_mode'=>'direct','slug'=>"ui-std-$role"])&&true===$unchanged($std,$ss),$t.'Form cannot turn a standard link direct');
 pm_check('admin_only_links'===$save(['link_id'=>$std,'link_mode'=>'standard','slug'=>"ui-std-$role",'regex_replacement'=>'x'])&&true===$unchanged($std,$ss),$t.'Form cannot set a replacement');
 foreach([$direct=>$sd,$regex=>$sr] as $id=>$snap){
  pm_check('admin_only_links'===$save(['link_id'=>$id,'link_mode'=>$snap['link_mode'],'slug'=>$snap['slug'],'name'=>'PM hijacked'])&&true===$unchanged($id,$snap),$t.'Form cannot edit '.$snap['link_mode'],$unchanged($id,$snap));
  pm_check('admin_only_links'===$save(['link_id'=>$id,'link_mode'=>'standard','slug'=>'pm-conv-'.$role])&&true===$unchanged($id,$snap),$t.'Form cannot convert '.$snap['link_mode'].' to standard',$unchanged($id,$snap));
  pm_check('admin_only_links'===$save(['link_id'=>$id,'slug'=>'pm-conv2-'.$role])&&true===$unchanged($id,$snap),$t.'Form with no mode field cannot convert '.$snap['link_mode'],$unchanged($id,$snap));
 }
 $wpdb->update($table,['regex_replacement'=>'https://stale.example/$1'],['id'=>$std]);$db->flush_cache_group();
 pm_check('saved'===$save(['link_id'=>$std,'link_mode'=>'standard','slug'=>"ui-std-$role",'name'=>'PM form ok','category_id'=>$cat])&&'PM form ok'===$row($std)['name']&&'https://stale.example/$1'===$row($std)['regex_replacement'],$t.'Form edits a standard link and leaves its hidden replacement alone',$row($std));
 $wpdb->update($table,['regex_replacement'=>''],['id'=>$std]);$db->flush_cache_group();$ss=$row($std);

 // Row actions.
 foreach(['trash','deactivate','activate','reset_clicks','restore','permanent_delete'] as $act){$n=$action($act,$direct);pm_check(in_array($n,['admin_only_links','admin_only_delete'],true)&&true===$unchanged($direct,$sd),$t."Row action $act refused on a direct link",[$n,$unchanged($direct,$sd)]);}
 pm_check('admin_only_delete'===$action('permanent_delete',$std)&&is_array($row($std)),$t.'Row delete refused on a standard link');
 pm_check('trashed'===$action('trash',$std)&&$trashed($std),$t.'Row trash works on a standard link');pm_check('admin_only_delete'===$action('permanent_delete',$std)&&is_array($row($std)),$t.'Row delete refused from the Trash');pm_check('restored'===$action('restore',$std)&&!$trashed($std),$t.'Row restore works on a standard link');

 // Bulk.
 $r=$call($admin,'run_bulk_action','bulk_permanent_delete',[$std]);pm_check('admin_only_delete'===$r['notice']&&0===$r['count']&&is_array($row($std)),$t.'Bulk delete refused',$r);
 $r=$call($admin,'run_bulk_action','bulk_trash',[$std,$direct]);pm_check('bulk_admin_only'===$r['notice']&&1===$r['count']&&[$std]===$r['undo_ids']&&$trashed($std)&&true===$unchanged($direct,$sd),$t.'Bulk trash skips the direct link and says so',$r);$db->restore_link($std);
 foreach(['bulk_deactivate','bulk_301','bulk_rel_ugc'] as $act){$r=$call($admin,'run_bulk_action',$act,[$direct,$regex]);pm_check('bulk_admin_only'===$r['notice']&&0===$r['count']&&true===$unchanged($direct,$sd)&&true===$unchanged($regex,$sr),$t."$act changes nothing on direct or regex",$r);}
 $_REQUEST['bulk_category_id']=(string)$cat2;$r=$call($admin,'run_bulk_action','bulk_set_category',[$direct]);pm_check('bulk_admin_only'===$r['notice']&&true===$unchanged($direct,$sd),$t.'Set category skips a direct link',$r);unset($_REQUEST['bulk_category_id']);
 $r=$call($admin,'run_bulk_action','bulk_302',[$std]);pm_check('bulk_updated'===$r['notice']&&1===$r['count'],$t.'Bulk edits still work on standard links',$r);

 // Undo and empty trash.
 $_GET=['gtlm_do_undo'=>'trash','gtlm_undo_ids'=>(string)$direct,'gtlm_undo_nonce'=>wp_create_nonce('gtlm_undo_trash_'.$direct)];$q=$run(fn()=>$call($admin,'handle_undo_action'));$_GET=[];pm_check('undo_failed'===($q['gtlm_notice']??'')&&true===$unchanged($direct,$sd),$t.'Undo cannot trash a direct link',$q);
 $db->trash_link($std);$_GET=['gtlm_action'=>'empty_trash'];$_REQUEST=['_wpnonce'=>wp_create_nonce('gtlm_empty_trash')];$q=$run(fn()=>$call($admin,'handle_empty_trash_action'));$_GET=[];$_REQUEST=[];pm_check('admin_only_delete'===($q['gtlm_notice']??'')&&is_array($row($std)),$t.'Empty Trash refused',$q);$db->restore_link($std);

 // Quick edit.
 $q=$quick($direct);pm_check(false===($q['success']??null)&&str_contains((string)($q['data']['message']??''),'administrators')&&true===$unchanged($direct,$sd),$t.'Quick edit refused on a direct link',$q);
 $q=$quick($regex);pm_check(false===($q['success']??null)&&true===$unchanged($regex,$sr),$t.'Quick edit refused on a regex rule',$q);
 $q=$quick($std);pm_check(true===($q['success']??null),$t.'Quick edit works on a standard link',$q);

 // What the screens offer.
 $lt=$listTable('');$cb=fn($item)=>$call($lt,'column_cb',$item);$name=fn($item)=>$call($lt,'column_name',$item);
 pm_check(''===$cb($row($direct))&&str_contains($cb($row($std)),'type="checkbox"'),$t.'Only standard rows have a checkbox');
 $html=$name($row($direct));pm_check(str_contains($html,'Administrators only')&&!preg_match('/gtlm-quick-edit|action=trash|action=(de)?activate|action=reset_clicks|link_id=/',$html),$t.'A direct row offers no change actions',$html);
 $html=$name($row($std));pm_check(str_contains($html,'gtlm-quick-edit')&&str_contains($html,'action=trash'),$t.'A standard row keeps its actions',$html);
 $lt=$listTable('trash');$name=fn($item)=>$call($lt,'column_name',$item);$html=$name($row($std));pm_check(str_contains($html,'action=restore')&&!str_contains($html,'permanent_delete'),$t.'Trash rows offer restore but no delete',$html);
 $html=$name($row($direct));pm_check(!str_contains($html,'action=restore')&&!str_contains($html,'permanent_delete'),$t.'Trashed direct rows offer neither',$html);
 pm_check(!isset($call($lt,'get_bulk_actions')['bulk_permanent_delete'])&&isset($call($lt,'get_bulk_actions')['bulk_restore']),$t.'Bulk Delete Permanently is hidden');
 $db->trash_link($std);$html=$page('render_links_page',['page'=>'gtlm-links','link_status'=>'trash']);pm_check(!str_contains($html,'gtlm-empty-trash'),$t.'Empty Trash button is hidden');$db->restore_link($std);
 $html=$page('render_edit_page',['page'=>'gtlm-links-edit','link_id'=>$direct]);pm_check(!str_contains($html,'<form')&&str_contains($html,'Only administrators can change direct and regex links'),$t.'Editor shows a notice instead of a form for a direct link',$html);
 $html=$page('render_edit_page',['page'=>'gtlm-links-edit','link_id'=>$std]);pm_check(str_contains($html,'<form')&&!str_contains($html,'type="radio" name="link_mode"')&&!str_contains($html,'name="regex_replacement"')&&1===preg_match('/type="hidden" name="link_mode" value="standard"/',$html),$t.'Editor hides the mode choice',$html);
 $html=$page('render_edit_page',['page'=>'gtlm-links-edit']);pm_check(!str_contains($html,'name="regex_replacement"')&&!preg_match('/type="radio"[^>]*name="link_mode"/',$html),$t.'New link form hides the mode choice');
}

$as('administrator');
$lt=$listTable('trash');pm_check(isset($call($lt,'get_bulk_actions')['bulk_permanent_delete']),'Admin: Bulk Delete Permanently is offered');
$direct=(int)$db->get_link_by_exact_slug('ui-direct-editor')['id'];
pm_check(str_contains($call($listTable(''),'column_cb',$row($direct)),'checkbox')&&str_contains($call($listTable(''),'column_name',$row($direct)),'gtlm-quick-edit'),'Admin: direct rows have a checkbox and actions');
$html=$page('render_edit_page',['page'=>'gtlm-links-edit','link_id'=>$direct]);pm_check(str_contains($html,'<form')&&1===preg_match('/type="radio"[^>]*name="link_mode"/',$html),'Admin: editor shows the mode choice');
pm_check('saved'===$save(['link_id'=>$direct,'link_mode'=>'direct','slug'=>'ui-direct-editor','name'=>'PM admin form']),'Admin: form edits a direct link');
$db->trash_link($direct);$html=$page('render_links_page',['page'=>'gtlm-links','link_status'=>'trash']);pm_check(str_contains($html,'gtlm-empty-trash'),'Admin: Empty Trash button is offered');
pm_check('deleted'===$action('permanent_delete',$direct)&&null===$row($direct),'Admin: row delete works');
$bulk=$link('admin-bulk-del');$db->trash_link($bulk);$r=$call($admin,'run_bulk_action','bulk_permanent_delete',[$bulk]);pm_check('bulk_deleted'===$r['notice']&&null===$row($bulk),'Admin: bulk delete works',$r);
$empty=$link('admin-empty-del');$db->trash_link($empty);$_GET=['gtlm_action'=>'empty_trash'];$_REQUEST=['_wpnonce'=>wp_create_nonce('gtlm_empty_trash')];$q=$run(fn()=>$call($admin,'handle_empty_trash_action'));$_GET=[];$_REQUEST=[];pm_check('trash_emptied'===($q['gtlm_notice']??'')&&null===$row($empty),'Admin: Empty Trash works',$q);

// CSV import.
$import=new GTLM_Import($db,$s);
$csv=function($mode,$rows)use($import,$call,$run){$dir=GTLM_Import_File::directory(true);$token=bin2hex(random_bytes(16));$fh=fopen($dir.'/'.$token.'.csv','w');fputcsv($fh,['name','slug','url','link_mode','regex_replacement'],',','"','');foreach($rows as $r)fputcsv($fh,$r,',','"','');fclose($fh);
 $key='gtlm_import_preview_'.get_current_user_id();set_transient($key,['token'=>'tok','file_token'=>$token,'file_path'=>$dir.'/'.$token.'.csv','header'=>[],'rows'=>[],'preset'=>'generic','created_at'=>time()],HOUR_IN_SECONDS);
 $_POST=['preview_token'=>'tok','duplicate_mode'=>$mode,'map'=>['name'=>0,'slug'=>1,'url'=>2,'link_mode'=>3,'regex_replacement'=>4]];$q=$run(fn()=>$call($import,'import_csv'));$_POST=[];return $q;};
foreach(['contributor','editor'] as $role){$as($role);$t=$role.': ';
 $q=$csv('skip',[['PM csv s',"csv-s-$role",'https://example.com/c','standard',''],['PM csv d',"csv-d-$role",'https://example.com/c','direct',''],['PM csv r',"^csv-r-$role$",'https://example.com/c','regex',''],['PM csv x',"csv-x-$role",'https://example.com/c','standard','https://example.com/$1']]);
 pm_check('1'===($q['imported']??'')&&'3'===($q['skipped']??''),$t.'CSV imports the standard row and skips the rest',$q);pm_check(is_array($db->get_link_by_exact_slug("csv-s-$role"))&&null===$db->get_link_by_exact_slug("csv-d-$role")&&null===$db->get_link_by_exact_slug("^csv-r-$role$"),$t.'Only the standard row exists');
 $as('administrator');$od=$link("csv-over-d-$role",'direct');$os=$link("csv-over-s-$role");$as($role);$sd=$row($od);
 $q=$csv('overwrite',[['PM over',"csv-over-d-$role",'https://example.com/over','standard',''],['PM over',"csv-over-s-$role",'https://example.com/over','standard','']]);
 pm_check('1'===($q['updated']??'')&&'1'===($q['skipped']??'')&&true===$unchanged($od,$sd)&&'https://example.com/over'===$row($os)['url'],$t.'CSV overwrite skips a direct row and updates a standard one',[$q,$unchanged($od,$sd)]);
 $q=$csv('overwrite',[['PM over',"csv-over-s-$role",'https://example.com/over2','regex','']]);pm_check('1'===($q['skipped']??'')&&'standard'===$row($os)['link_mode'],$t.'CSV overwrite cannot change a link to regex',$q);
 $q=$csv('suffix',[['PM suffix',"csv-over-d-$role",'https://example.com/suffix','direct','']]);pm_check('1'===($q['skipped']??'')&&null===$db->get_link_by_exact_slug("csv-over-d-$role-2"),$t.'CSV auto-suffix cannot add a direct link',$q);
 $q=$csv('suffix',[['PM suffix',"csv-over-s-$role",'https://example.com/suffix','standard','']]);pm_check('1'===($q['imported']??'')&&is_array($db->get_link_by_exact_slug("csv-over-s-$role-2")),$t.'CSV auto-suffix still adds a standard link',$q);
}
$as('administrator');$q=$csv('skip',[['PM admin csv','csv-admin-d','https://example.com/c','direct',''],['PM admin csv r','^csv-admin-r$','https://example.com/c','regex','']]);pm_check('2'===($q['imported']??''),'Admin: CSV imports direct and regex rows',$q);

// No user: the retention job and DB layer aren't gated, so cron keeps working.
wp_set_current_user(0);$s->update(array_merge($s->all(),['trash_retention_days'=>1]));$purge=$link('cron-purge-direct','direct');$db->trash_link($purge);$wpdb->update($table,['trashed_at'=>wp_date('Y-m-d H:i:s',time()-3*DAY_IN_SECONDS)],['id'=>$purge]);$db->flush_cache_group();
gtlm_run_trash_purge();pm_check(null===$row($purge),'Retention cron purges with no user, direct links included');

// Cleanup.
remove_filter('wp_redirect',$thrower);remove_all_filters('wp_doing_ajax');remove_all_filters('wp_die_ajax_handler');
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE name LIKE %s",'PM %'));foreach([$cat,$cat2] as $c)$db->delete_category($c);$db->flush_cache_group();
foreach($users as $id)wp_delete_user($id);wp_set_current_user(1);$s->update($before);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
