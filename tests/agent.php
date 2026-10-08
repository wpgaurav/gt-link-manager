<?php
/** gtlm_agent() and the Site Agent skill registration. Run only in an isolated fixture defining GTLM_TEST_FIXTURE. */
if (!defined('GTLM_TEST_FIXTURE') || GTLM_TEST_FIXTURE !== true || !str_starts_with(DB_NAME, 'gtlm_test_')) {
 throw new RuntimeException('A disposable GTLM_TEST_FIXTURE database is required.');
}
$checks=[];
function agent_check($condition,$label,$details=null) { global $checks; $checks[]=['ok'=>(bool)$condition,'check'=>$label,'details'=>$condition?null:$details]; }
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$editor=wp_insert_user(['user_login'=>'gtlm-agent-editor-'.wp_rand(),'user_pass'=>wp_generate_password(),'role'=>'editor']);
$subscriber=wp_insert_user(['user_login'=>'gtlm-agent-sub-'.wp_rand(),'user_pass'=>wp_generate_password(),'role'=>'subscriber']);
$prefix=trim(GTLM_Settings::get_instance()->prefix(),'/');
wp_set_current_user($admin);
$ids=[];$cats=[];
try {
 $context=gtlm_agent('context');
 agent_check($context['ok']&&$context['api_version']===1&&$context['plugin_version']===GTLM_VERSION&&isset($context['defaults']['redirect_type'],$context['counts']['active']),'context reports version, defaults and counts',$context);
 agent_check(gtlm_agent('nope')['ok']===false&&gtlm_agent('links.get')['ok']===false,'Unknown commands and missing ids return ok false');

 $created=gtlm_agent('links.create',['link'=>['name'=>'Agent hosting','slug'=>'agent-hosting','url'=>'https://hosting.example/?ref=agent','redirect_type'=>302,'rel'=>['nofollow','sponsored'],'notes'=>"Quote's \"test\" & \$var"]]);
 $ids[]=$id=(int)($created['link']['id']??0);
 agent_check($created['ok']&&$id&&$created['link']['slug']==='agent-hosting'&&(int)$created['link']['redirect_type']===302,'links.create saves a link through REST',$created);
 agent_check(gtlm_agent('links.get',['id'=>$id])['link']['notes']==="Quote's \"test\" & \$var",'Quotes and dollar signs survive the JSON round trip');
 $duplicate=gtlm_agent('links.create',['link'=>['name'=>'Again','slug'=>'agent-hosting','url'=>'https://other.example/']]);
 agent_check($duplicate['ok']===false&&$duplicate['error']!=='','A duplicate slug is refused with the REST message',$duplicate);
 agent_check(gtlm_agent('links.create',['link'=>['name'=>'No url']])['ok']===false,'A link without a destination is refused');
 agent_check(gtlm_agent('links.get',['slug'=>$prefix.'/agent-hosting'])['link']['id']==$id&&gtlm_agent('links.get',['slug'=>'agent-hosting'])['link']['id']==$id,'links.get finds a link by slug with or without the prefix');
 $geo=gtlm_agent('links.create',['link'=>['name'=>'Agent geo','slug'=>'agent-geo','url'=>'https://store.example.com/','redirect_type'=>302,'geo_mode'=>'targeted','geo_rules'=>['rules'=>[['countries'=>['IN'],'url'=>'https://store.example.in/','redirect_type'=>302]],'fallback'=>'default']]]);
 $ids[]=(int)($geo['link']['id']??0);
 agent_check($geo['ok']&&$geo['link']['geo_mode']==='targeted'&&!empty($geo['link']['geo_rules']),'Nested country rules reach REST intact',$geo);

 $updated=gtlm_agent('links.update',['slug'=>'agent-hosting','patch'=>['url'=>'https://hosting.example/new']]);
 agent_check($updated['ok']&&$updated['link']['target_url']==='https://hosting.example/new'&&(int)$updated['link']['redirect_type']===302,'links.update patches one field and keeps the rest',$updated);
 agent_check(gtlm_agent('links.update',['id'=>$id])['ok']===false,'An empty patch is refused');
 agent_check(gtlm_agent('links.set_active',['id'=>$id,'is_active'=>false])['link']['is_active']==0&&gtlm_agent('links.set_active',['id'=>$id,'is_active'=>true])['link']['is_active']==1,'links.set_active turns a redirect off and on');
 $trash=gtlm_agent('links.trash',['id'=>$id]);
 agent_check($trash['ok']&&(new GTLM_DB())->get_link_by_id($id)!==null,'links.trash moves to the trash without deleting',$trash);
 agent_check(gtlm_agent('links.restore',['id'=>$id])['ok'],'links.restore brings it back');

 $cat=gtlm_agent('categories.create',['category'=>['name'=>'Agent hosting partners']]);
 $cats[]=$cat_id=(int)($cat['category']['id']??0);
 agent_check($cat['ok']&&$cat_id,'categories.create',$cat);
 agent_check(in_array($cat_id,array_map('intval',array_column(gtlm_agent('categories.list',[])['categories'],'id')),true),'categories.list includes it');
 $moved=gtlm_agent('links.bulk_category',['link_ids'=>[$id],'category_id'=>$cat_id,'mode'=>'move']);
 agent_check($moved['ok']&&(int)gtlm_agent('links.get',['id'=>$id])['link']['category_id']===$cat_id,'links.bulk_category moves links',$moved);
 $listed=gtlm_agent('links.list',['search'=>'Agent','per_page'=>10]);
 agent_check($listed['ok']&&$listed['total']>=2,'links.list searches with a total',$listed);
 agent_check(gtlm_agent('analytics.status')['ok'],'An administrator reads analytics status');

 wp_set_current_user($editor);
 agent_check(gtlm_agent('links.list')['ok']&&gtlm_agent('analytics.status')['ok']===false,'An editor manages links but not analytics');
 wp_set_current_user($subscriber);
 agent_check(gtlm_agent('links.list')['ok']===false&&gtlm_agent('context')['ok']===false,'A subscriber is refused');
 wp_set_current_user($admin);

 $skills=apply_filters('site_agent_skills',[]);
 agent_check(isset($skills['gt-link-manager'])&&is_file($skills['gt-link-manager']['directory'].'/SKILL.md')&&$skills['gt-link-manager']['version']===GTLM_VERSION,'The skill registers with Site Agent',$skills);
 if (class_exists('SiteAgent\\Skills') && method_exists('SiteAgent\\Skills','registered')) {
  $read=SiteAgent\Skills::read(['skill'=>'gt-link-manager']);
  agent_check(is_array($read)&&str_contains($read['content'],"return gtlm_agent('links.list'"),'Site Agent serves the skill',$read);
 }
} finally {
 $db=new GTLM_DB();
 foreach(array_filter($ids) as $link){$db->delete_link($link);}
 foreach(array_filter($cats) as $cat){$db->delete_category($cat);}
 require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($editor);wp_delete_user($subscriber);
}
$result=['checks'=>$checks,'passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit(count($result['failed'])?1:0);
