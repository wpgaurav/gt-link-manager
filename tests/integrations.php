<?php
require_once __DIR__.'/reflection.php';
/** Analytics integrations: config, delivery rules and the exact request each service gets. Disposable fixture only. */
if (!defined('GTLM_TEST_FIXTURE') || !GTLM_TEST_FIXTURE || !str_starts_with(DB_NAME, 'gtlm_test_')) exit(2);
require_once GTLM_PATH.'includes/class-gtlm-integrations.php';
require_once GTLM_PATH.'includes/class-gtlm-integration-services.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once GTLM_PATH.'includes/icons.php';
require_once GTLM_PATH.'includes/class-gtlm-integrations-admin.php';
$checks=[];function ig_check($ok,$label,$detail=null){global $checks;$checks[]=['ok'=>(bool)$ok,'check'=>$label,'details'=>$ok?null:$detail];}
$s=GTLM_Settings::get_instance();$db=new GTLM_DB();$before=$s->all();
wp_set_current_user(0);

// Off by default.
ig_check([]===$s->enabled_integrations()&&!get_option(GTLM_Integrations::OPTION),'Nothing is enabled on a fresh site');
$config=GTLM_Integrations::config();ig_check(0===$config['share_visitor_data']&&[]===$config['tools'],'Sharing visitor data defaults to off',$config);

// Capture outgoing requests rather than sending them.
$sent=[];$respond=null;
add_filter('pre_http_request',function($pre,$args,$url)use(&$sent,&$respond){$sent[]=['url'=>$url,'args'=>$args];return $respond?$respond($url,$args):['headers'=>[],'body'=>'{"status":1}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];},10,3);
$browser='Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
$visit=function(array $server=[])use($browser){$_SERVER=array_merge($_SERVER,['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/go/probe?utm_source=x','HTTP_USER_AGENT'=>$browser,'HTTP_REFERER'=>'https://news.example.com/story?secret=1#frag','REMOTE_ADDR'=>'8.8.8.8'],$server);foreach(['HTTP_X_FORWARDED_FOR','HTTP_CF_CONNECTING_IP','HTTP_SEC_PURPOSE'] as $k){if(!isset($server[$k]))unset($_SERVER[$k]);}};
$cat=$db->insert_category(['name'=>'Partners','slug'=>'ig-partners']);
$linkId=$db->insert_link(['name'=>'Probe Link','slug'=>'ig-probe','url'=>'https://dest.example/page?aff=1','redirect_type'=>302,'category_id'=>$cat]);
$link=$db->get_link_by_id($linkId);
$cfg=function(array $tools,int $share=0){$r=GTLM_Integrations::sanitize_config(['share_visitor_data'=>$share,'tools'=>$tools],['share_visitor_data'=>0,'tools'=>[]]);GTLM_Integrations::save($r['config']);return $r;};
$fire=function(?array $geo=null)use(&$sent,$link){$sent=[];GTLM_Integrations::dispatch($link,'https://dest.example/page?aff=1',302,$geo);return $sent;};
$body=fn($r)=>json_decode((string)$r['args']['body'],true);

// Google Analytics 4.
$cfg(['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'g-abcd1234','api_secret'=>'sec ret','region'=>'global']]);
$visit();$_COOKIE=[];$r=$fire()[0]??null;$b=$r?$body($r):[];
ig_check($r&&str_starts_with($r['url'],'https://www.google-analytics.com/mp/collect?')&&str_contains($r['url'],'measurement_id=G-ABCD1234')&&str_contains($r['url'],'api_secret=sec%20ret'),'GA4 posts to Measurement Protocol with an encoded secret',$r['url']??null);
$p=$b['events'][0]['params']??[];
ig_check('link_click'===($b['events'][0]['name']??'')&&'Probe Link'===$p['link_name']&&'ig-probe'===$p['link_slug']&&'dest.example'===$p['link_domain']&&'Partners'===$p['link_category']&&302===$p['redirect_type']&&true===$p['outbound'],'GA4 carries the link details in dimensions it already reports',$p);
ig_check('https://news.example.com/story'===$p['page_referrer'],'GA4 referrer has no query string or fragment',$p);
ig_check(1===$p['engagement_time_msec']&&ctype_digit($p['session_id'])&&preg_match('/^\d+\.\d+$/',$b['client_id']),'GA4 gets a session and a well-formed client ID without cookies',$b);
ig_check(!isset($b['ip_override'],$b['user_location']),'GA4 gets no IP or location unless sharing is on',$b);
ig_check($browser===($b['user_agent']??''),'GA4 gets the browser user agent',$b);
foreach(['link_name','link_slug','link_url','link_domain','link_category','page_location','page_referrer'] as $k){if(mb_strlen((string)($p[$k]??''))>100)ig_check(false,'GA4 parameter over 100 characters: '.$k);}
$long=$link;$long['name']=str_repeat('n',300);$sent=[];GTLM_Integrations::dispatch($long,'https://dest.example/'.str_repeat('p',300),302,null);$lp=$body($sent[0])['events'][0]['params'];
ig_check(100===mb_strlen($lp['link_name'])&&100===mb_strlen($lp['link_url']),'GA4 values are clipped to its 100 character limit',$lp);
$_COOKIE=['_ga'=>'GA1.1.111222333.1700000000','_ga_ABCD1234'=>'GS2.1.s1790000000$o3$g1$t1790000100'];
$b=$body($fire()[0]);ig_check('111222333.1700000000'===$b['client_id']&&'1790000000'===$b['events'][0]['params']['session_id'],'GA4 joins the visitor\'s existing user and session (GS2 cookie)',$b);
$_COOKIE=['_ga'=>'GA1.2.5.6','_ga_ABCD1234'=>'GS1.1.1790000001.4.1.1790000100.0.0.0'];
$b=$body($fire()[0]);ig_check('5.6'===$b['client_id']&&'1790000001'===$b['events'][0]['params']['session_id'],'GA4 also reads the older GS1 session cookie',$b);
$_COOKIE=['_ga'=>'not-a-ga-cookie"><script>','_ga_ABCD1234'=>'garbage'];
$b=$body($fire()[0]);ig_check(preg_match('/^\d+\.\d+$/',$b['client_id'])&&ctype_digit($b['events'][0]['params']['session_id']),'A malformed GA cookie is ignored',$b);
$_COOKIE=[];
$cfg(['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'s','region'=>'eu']]);
ig_check(str_starts_with($fire()[0]['url'],'https://region1.google-analytics.com/mp/collect'),'GA4 EU region uses the EU endpoint');
$cfg(['ga4'=>['enabled'=>1,'mode'=>'browser']]);
ig_check([]===$fire(),'GA4 browser mode sends nothing from the server');
ig_check(in_array('ga4_browser',$s->enabled_integrations(),true),'GA4 browser mode is flagged for the click listener',$s->enabled_integrations());

// Every other service.
$svc=function(string $tool,array $settings)use($cfg,$fire,$visit){$cfg([$tool=>array_merge(['enabled'=>1],$settings)]);$visit();return $fire()[0]??null;};
$r=$svc('plausible',['domain'=>'site.example']);$b=$body($r);
ig_check('https://plausible.io/api/event'===$r['url']&&'Link Click'===$b['name']&&'site.example'===$b['domain']&&str_ends_with($b['url'],'/go/probe')&&'https://news.example.com/story'===$b['referrer']&&'ig-probe'===$b['props']['link_slug'],'Plausible gets a Link Click event',$b);
ig_check($browser===$r['args']['headers']['User-Agent']&&!isset($r['args']['headers']['X-Forwarded-For']),'Plausible gets the browser user agent and no IP by default',$r['args']['headers']);
foreach($b['props'] as $k=>$v){if(!is_scalar($v))ig_check(false,'Plausible prop is not scalar: '.$k);}
$r=$svc('plausible',['domain'=>'site.example','base_url'=>'https://stats.example/']);ig_check('https://stats.example/api/event'===$r['url'],'Plausible self-hosted URL is used',$r['url']);
$r=$svc('matomo',['base_url'=>'https://m.example/matomo/','site_id'=>'7','token'=>'tok']);$b=$r['args']['body'];
ig_check('https://m.example/matomo/matomo.php'===$r['url']&&7===$b['idsite']&&1===$b['rec']&&'Redirect'===$b['e_c']&&'Link Click'===$b['e_a']&&'Probe Link'===$b['e_n']&&16===strlen($b['_id'])&&ctype_xdigit($b['_id'])&&$browser===$b['ua'],'Matomo gets an event hit',$b);
ig_check(!isset($b['token_auth'],$b['cip']),'Matomo token stays home while sharing is off',$b);
$r=$svc('umami',['website_id'=>'abc-123']);$b=$body($r);
ig_check('https://gateway.umami.is/api/send'===$r['url']&&'event'===$b['type']&&'abc-123'===$b['payload']['website']&&'link_click'===$b['payload']['name']&&'/go/probe'===$b['payload']['url']&&$browser===$r['args']['headers']['User-Agent'],'Umami gets an event with the browser user agent',$b);
$r=$svc('simple_analytics',['hostname'=>'site.example']);$b=$body($r);
ig_check('https://queue.simpleanalyticscdn.com/events'===$r['url']&&'event'===$b['type']&&'site.example'===$b['hostname']&&'link_click'===$b['event']&&$browser===$b['ua']&&'/go/probe'===$b['path'],'Simple Analytics gets an event with the browser user agent',$b);
$r=$svc('posthog',['api_key'=>'phc_x','region'=>'eu']);$b=$body($r);
ig_check('https://eu.i.posthog.com/i/v0/e/'===$r['url']&&'phc_x'===$b['api_key']&&'link_click'===$b['event']&&''!==$b['distinct_id']&&false===$b['properties']['$process_person_profile']&&true===$b['properties']['$geoip_disable'],'PostHog gets an anonymous event that will not geolocate this server',$b);
$r=$svc('mixpanel',['token'=>'mp','region'=>'in']);$b=$body($r)[0];
ig_check(str_starts_with($r['url'],'https://api-in.mixpanel.com/track?ip=0')&&'link_click'===$b['event']&&'mp'===$b['properties']['token']&&!empty($b['properties']['$insert_id'])&&!isset($b['properties']['ip']),'Mixpanel gets an event that will not geolocate this server',$r);
$r=$svc('amplitude',['api_key'=>'amp','region'=>'eu']);$b=$body($r);$e=$b['events'][0];
ig_check('https://api.eu.amplitude.com/2/httpapi'===$r['url']&&'amp'===$b['api_key']&&'link_click'===$e['event_type']&&strlen($e['device_id'])>=5&&$e['time']>1e12&&!empty($e['insert_id']),'Amplitude gets a deduplicated event with a timestamp in milliseconds',$b);
$r=$svc('rybbit',['site_id'=>'4','api_key'=>'rk']);$b=$body($r);
ig_check('https://app.rybbit.io/api/track'===$r['url']&&'Bearer rk'===$r['args']['headers']['Authorization']&&'custom_event'===$b['type']&&'link_click'===$b['event_name']&&is_string($b['properties'])&&'ig-probe'===json_decode($b['properties'],true)['link_slug'],'Rybbit gets a keyed custom event whose properties are a JSON string',$b);
$r=$svc('clicky',['site_id'=>'55','sitekey_admin'=>'k+y']);
ig_check(str_starts_with($r['url'],'https://in.getclicky.com/in.php?')&&str_contains($r['url'],'site_id=55')&&str_contains($r['url'],'sitekey_admin=k%2By')&&str_contains($r['url'],'type=click')&&str_contains($r['url'],'href=%23short-link%2Fig-probe')&&!str_contains($r['url'],'ip_address'),'Clicky gets an encoded click action with no IP by default',$r['url']);

// Sharing visitor data, only when the owner opts in.
$all=['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'s'],'plausible'=>['enabled'=>1,'domain'=>'d.example'],'matomo'=>['enabled'=>1,'base_url'=>'https://m.example','site_id'=>'1','token'=>'t'],'posthog'=>['enabled'=>1,'api_key'=>'k'],'mixpanel'=>['enabled'=>1,'token'=>'k'],'amplitude'=>['enabled'=>1,'api_key'=>'k'],'rybbit'=>['enabled'=>1,'site_id'=>'1','api_key'=>'k'],'clicky'=>['enabled'=>1,'site_id'=>'1','sitekey_admin'=>'k']];
$cfg($all,1);$visit(['HTTP_CF_CONNECTING_IP'=>'9.9.9.9','HTTP_X_FORWARDED_FOR'=>'10.0.0.1, 1.1.1.1']);
$reqs=$fire(['country'=>'IN','url'=>'x','redirect_type'=>302,'matched'=>true]);
$by=[];foreach($reqs as $q){$by[(string)wp_parse_url($q['url'],PHP_URL_HOST)]=$q;}
$g=$body($by['www.google-analytics.com']);ig_check('9.9.9.9'===$g['ip_override']&&'IN'===$g['user_location']['country_id'],'Sharing on: GA4 gets IP and country',$g);
ig_check('9.9.9.9'===$by['plausible.io']['args']['headers']['X-Forwarded-For'],'Sharing on: Plausible gets the IP header');
$m=$by['m.example']['args']['body'];ig_check('t'===$m['token_auth']&&'9.9.9.9'===$m['cip']&&'in'===$m['country'],'Sharing on: Matomo gets a token, IP and lowercase country',$m);
ig_check('9.9.9.9'===$body($by['us.i.posthog.com'])['properties']['$ip']&&!isset($body($by['us.i.posthog.com'])['properties']['$geoip_disable']),'Sharing on: PostHog gets the IP');
ig_check('9.9.9.9'===$body($by['api.mixpanel.com'])[0]['properties']['ip'],'Sharing on: Mixpanel gets the IP');
$a=$body($by['api2.amplitude.com'])['events'][0];ig_check('9.9.9.9'===$a['ip']&&'IN'===$a['country'],'Sharing on: Amplitude gets IP and country',$a);
ig_check('9.9.9.9'===$body($by['app.rybbit.io'])['ip_address'],'Sharing on: Rybbit gets the IP');
ig_check(str_contains($by['in.getclicky.com']['url'],'ip_address=9.9.9.9'),'Sharing on: Clicky gets the IP');
$id1=$body($by['us.i.posthog.com'])['distinct_id'];$id2=$body(array_values(array_filter($fire(),fn($q)=>str_contains($q['url'],'posthog')))[0])['distinct_id'];
ig_check($id1===$id2&&32===strlen($id1),'Sharing on: the same visitor keeps one ID within a day',[$id1,$id2]);
$visit(['REMOTE_ADDR'=>'10.0.0.5','HTTP_X_FORWARDED_FOR'=>'192.168.1.1']);$reqs=$fire();$g=$body($reqs[0]);
ig_check(!isset($g['ip_override']),'A private or reserved address is never shared',$g);
add_filter('gtlm_integration_visitor_ip',$ipf=fn()=>'4.4.4.4');$g=$body($fire()[0]);remove_filter('gtlm_integration_visitor_ip',$ipf);
ig_check('4.4.4.4'===$g['ip_override'],'gtlm_integration_visitor_ip can supply the address',$g);
$cfg($all,0);$visit(['HTTP_CF_CONNECTING_IP'=>'9.9.9.9']);$reqs=$fire(['country'=>'IN','url'=>'x','redirect_type'=>302,'matched'=>true]);
$raw=wp_json_encode($reqs);ig_check(!str_contains($raw,'9.9.9.9')&&!str_contains($raw,'8.8.8.8')&&!str_contains($raw,'"IN"'),'Sharing off: no IP or country reaches any service');
$ids=array_map(fn($q)=>$body($q)['distinct_id'],array_values(array_filter(array_merge($fire(),$fire()),fn($q)=>str_contains($q['url'],'posthog'))));
ig_check(2===count($ids)&&$ids[0]!==$ids[1],'Sharing off: each click gets a new random ID',$ids);

// Which clicks count.
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']]);
$visit(['REQUEST_METHOD'=>'HEAD']);ig_check([]===$fire(),'HEAD requests are not sent');
$visit(['HTTP_USER_AGENT'=>'Mozilla/5.0 (compatible; Googlebot/2.1)']);ig_check([]===$fire(),'Bots are not sent');
$visit(['HTTP_USER_AGENT'=>'']);ig_check([]===$fire(),'An empty user agent is not sent');
$visit(['HTTP_SEC_PURPOSE'=>'prefetch']);ig_check([]===$fire(),'Prefetches are not sent');
$visit();wp_set_current_user(1);ig_check([]===$fire(),'Logged-in link managers are not sent');wp_set_current_user(0);
ig_check(1===count($fire()),'A real visit is sent');
add_filter('gtlm_integrations_should_send','__return_false');ig_check([]===$fire(),'gtlm_integrations_should_send can require consent');remove_filter('gtlm_integrations_should_send','__return_false');
add_filter('gtlm_integration_event',$ef=function($e){$e['link_slug']='changed';return $e;});ig_check('changed'===$body($fire()[0])['props']['link_slug'],'gtlm_integration_event can change the event');remove_filter('gtlm_integration_event',$ef);
add_filter('gtlm_integration_request','__return_null');ig_check([]===$fire(),'gtlm_integration_request can cancel a request');remove_filter('gtlm_integration_request','__return_null');
$seen=[];add_action('gtlm_integration_sent',$sa=function($tool,$res)use(&$seen){$seen[]=$tool;},10,2);$fire();remove_action('gtlm_integration_sent',$sa,10);ig_check(['plausible']===$seen,'gtlm_integration_sent reports delivery',$seen);
$rx=$link;$rx['link_mode']='regex';$rx['slug']='^old/(.*)$';$visit(['REQUEST_URI'=>'/old/page']);$sent=[];GTLM_Integrations::dispatch($rx,'https://dest.example/',302,null);
ig_check('old/page'===$body($sent[0])['props']['link_slug'],'A regex link reports its path, not its pattern',$body($sent[0]));
$visit();

// One failure must not stop the rest.
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example'],'umami'=>['enabled'=>1,'website_id'=>'w'],'simple_analytics'=>['enabled'=>1,'hostname'=>'h.example']]);
$respond=function($url){if(str_contains($url,'plausible'))return new WP_Error('down','Service down');if(str_contains($url,'umami'))throw new RuntimeException('boom');return null;};
$respond2=$respond;$respond=fn($url,$args)=>$respond2($url)??['headers'=>[],'body'=>'','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
$thrown=false;try{$hosts=array_map(fn($q)=>wp_parse_url($q['url'],PHP_URL_HOST),$fire());}catch(Throwable $e){$thrown=true;$hosts=[];}
$respond=null;ig_check(!$thrown&&['plausible.io','gateway.umami.is','queue.simpleanalyticscdn.com']===$hosts,'A down or throwing service does not stop the others',$hosts);

// Saving.
$r=GTLM_Integrations::sanitize_config(['tools'=>['umami'=>['enabled'=>1]]],['share_visitor_data'=>0,'tools'=>[]]);
ig_check(0===$r['config']['tools']['umami']['enabled']&&str_contains($r['errors'][0],'Website ID'),'A tool missing a required field is saved switched off, with a reason',$r);
$r=GTLM_Integrations::sanitize_config(['tools'=>['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'UA-1234','api_secret'=>'s']]],['share_visitor_data'=>0,'tools'=>[]]);
ig_check(0===$r['config']['tools']['ga4']['enabled']&&''===$r['config']['tools']['ga4']['measurement_id'],'An invalid measurement ID is rejected',$r);
foreach(['with credentials'=>'https://u:p@m.example','with a query'=>'https://m.example/?x=1','as a script'=>'javascript:alert(1)','not a URL'=>'not a url','as a file'=>'file:///etc/passwd'] as $label=>$url){$r=GTLM_Integrations::sanitize_config(['tools'=>['matomo'=>['enabled'=>1,'base_url'=>$url,'site_id'=>'1']]],['share_visitor_data'=>0,'tools'=>[]]);ig_check(0===$r['config']['tools']['matomo']['enabled'],'A service URL '.$label.' is rejected',$r['config']['tools']['matomo']);}
$prior=['share_visitor_data'=>0,'tools'=>['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'keepme']]];
$r=GTLM_Integrations::sanitize_config(['tools'=>['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'']]],$prior);
ig_check('keepme'===$r['config']['tools']['ga4']['api_secret']&&1===$r['config']['tools']['ga4']['enabled'],'A blank secret keeps the saved one',$r);
$r=GTLM_Integrations::sanitize_config(['tools'=>['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'newone']]],$prior);ig_check('newone'===$r['config']['tools']['ga4']['api_secret'],'A typed secret replaces the saved one');
$r=GTLM_Integrations::sanitize_config(['tools'=>['ga4'=>['forget'=>1,'enabled'=>1]]],$prior);ig_check(!isset($r['config']['tools']['ga4']),'Forget removes a tool\'s saved settings',$r);
$r=GTLM_Integrations::sanitize_config(['tools'=>['ga4'=>['enabled'=>1,'mode'=>'evil','measurement_id'=>'G-ABCD1234','api_secret'=>'s','region'=>'evil']]],['share_visitor_data'=>0,'tools'=>[]]);ig_check('server'===$r['config']['tools']['ga4']['mode']&&'global'===$r['config']['tools']['ga4']['region'],'An unknown select value falls back to the first option',$r);
$r=GTLM_Integrations::sanitize_config(['tools'=>['koko_analytics'=>['enabled'=>1],'nonsense'=>['enabled'=>1]]],['share_visitor_data'=>0,'tools'=>[]]);
ig_check(!isset($r['config']['tools']['nonsense']),'Unknown tools are dropped',$r);
if(!function_exists('koko_analytics_track_pageview'))ig_check(0===$r['config']['tools']['koko_analytics']['enabled']&&str_contains(implode(' ',$r['errors']),'not active'),'A plugin tool stays off while its plugin is inactive',$r);
$r=GTLM_Integrations::sanitize_config(['tools'=>['gtm'=>['enabled'=>1,'data_layer'=>'x;alert(1)']]],['share_visitor_data'=>0,'tools'=>[]]);ig_check(''===$r['config']['tools']['gtm']['data_layer'],'A data layer name must be a plain variable name',$r);

// Other settings forms leave the enabled list alone.
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']]);
$s->update(array_merge($s->all(),['base_prefix'=>'go']));ig_check(['plausible']===$s->enabled_integrations(),'Saving the main settings keeps integrations on',$s->enabled_integrations());

// Test button.
$cfg(['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'s']]);$sent=[];
$t=GTLM_Integrations::send_test('ga4');ig_check($t['ok']&&str_contains($sent[0]['url'],'/debug/mp/collect')&&str_contains($t['message'],'validated'),'The GA4 test uses Google\'s validator, which records nothing',[$t,$sent[0]['url']??'']);
$respond=fn()=>['headers'=>[],'body'=>'{"validationMessages":[{"description":"Bad param"}]}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];$t=GTLM_Integrations::send_test('ga4');ig_check(!$t['ok']&&str_contains($t['message'],'Bad param'),'A GA4 validation error reaches the screen',$t);
$cfg(['umami'=>['enabled'=>1,'website_id'=>'w']]);$respond=fn()=>['headers'=>[],'body'=>'{"error":"Invalid website ID"}','response'=>['code'=>400,'message'=>'Bad'],'cookies'=>[],'filename'=>null];$t=GTLM_Integrations::send_test('umami');ig_check(!$t['ok']&&str_contains($t['message'],'400')&&str_contains($t['message'],'Invalid website ID'),'A service error reaches the screen',$t);
$cfg(['mixpanel'=>['enabled'=>1,'token'=>'m']]);$respond=fn()=>['headers'=>[],'body'=>'{"status":0,"error":"token"}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];$t=GTLM_Integrations::send_test('mixpanel');ig_check(!$t['ok'],'Mixpanel\'s status 0 reads as a failure despite HTTP 200',$t);
$respond=fn()=>new WP_Error('x','Timed out');$t=GTLM_Integrations::send_test('mixpanel');ig_check(!$t['ok']&&str_contains($t['message'],'Timed out')&&!str_contains($t['message'],'token'),'A connection error reaches the screen',$t);$respond=null;
$accepted=fn(array $headers)=>fn()=>['headers'=>$headers,'body'=>'ok','response'=>['code'=>202,'message'=>'Accepted'],'cookies'=>[],'filename'=>null];
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']]);$respond=$accepted(['x-plausible-dropped'=>'1']);$t=GTLM_Integrations::send_test('plausible');ig_check(!$t['ok']&&str_contains($t['message'],'dropped')&&str_contains($t['message'],'turn on IP address and country')&&str_contains($t['message'],'registered'),'A dropped Plausible event fails the test and names the setting when sharing is off',$t);
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']],1);$t=GTLM_Integrations::send_test('plausible');ig_check(!$t['ok']&&str_contains($t['message'],'dropped')&&!str_contains($t['message'],'turn on IP address and country'),'A dropped Plausible event fails the test without suggesting a setting that is already on',$t);
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']]);$respond=$accepted([]);$t=GTLM_Integrations::send_test('plausible');ig_check($t['ok'],'A Plausible 202 with no drop header still passes',$t);
$respond=$accepted(['x-plausible-dropped'=>'0']);$t=GTLM_Integrations::send_test('plausible');ig_check($t['ok'],'A Plausible drop count of 0 still passes',$t);
$respond=fn()=>['headers'=>['x-plausible-dropped'=>'1'],'body'=>'{"errors":{"name":["can\'t be blank"]}}','response'=>['code'=>400,'message'=>'Bad'],'cookies'=>[],'filename'=>null];$t=GTLM_Integrations::send_test('plausible');ig_check(!$t['ok']&&str_contains($t['message'],'400')&&str_contains($t['message'],'blank'),'A rejected Plausible event shows the service error, not the drop advice',$t);
$cfg(['umami'=>['enabled'=>1,'website_id'=>'w']]);$respond=$accepted(['x-plausible-dropped'=>'1']);$t=GTLM_Integrations::send_test('umami');ig_check($t['ok'],'The drop header only counts for Plausible',$t);$respond=null;
ig_check(str_contains(GTLM_Integrations::tools()['plausible']['help'],'IP address and country'),'The Plausible help text warns about server addresses');
$cfg(['mixpanel'=>['enabled'=>1,'token'=>'m']]);
$t=GTLM_Integrations::send_test('umami');ig_check(!$t['ok'],'A tool that is off cannot be tested',$t);
$t=GTLM_Integrations::send_test('fathom');ig_check(!$t['ok'],'A browser tool has no server test',$t);

// Bootstrap and the click listener.
GTLM_Integrations::save(['share_visitor_data'=>0,'tools'=>[]]);ig_check([]===$s->enabled_integrations(),'Turning everything off empties the enabled list');
global $wp_scripts;$wp_scripts=new WP_Scripts();wp_set_current_user(0);
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']]);GTLM_Integrations::enqueue_bridge();ig_check(!wp_script_is('gtlm-integrations','enqueued'),'A server-only setup adds no script');
$cfg(['fathom'=>['enabled'=>1],'gtm'=>['enabled'=>1,'data_layer'=>'myLayer']]);GTLM_Integrations::enqueue_bridge();
$inline=implode('',(array)$wp_scripts->get_data('gtlm-integrations','before'));
ig_check(wp_script_is('gtlm-integrations','enqueued')&&str_contains($inline,'"myLayer"')&&str_contains($inline,'"gtm"')&&str_contains($inline,'"fathom"')&&!str_contains($inline,'secret'),'A browser tool loads the listener with its config',$inline);
$wp_scripts=new WP_Scripts();wp_set_current_user(1);GTLM_Integrations::enqueue_bridge();ig_check(!wp_script_is('gtlm-integrations','enqueued'),'Logged-in link managers do not load the listener');wp_set_current_user(0);
$wp_scripts=new WP_Scripts();add_filter('gtlm_integrations_load_bridge','__return_false');GTLM_Integrations::enqueue_bridge();remove_filter('gtlm_integrations_load_bridge','__return_false');ig_check(!wp_script_is('gtlm-integrations','enqueued'),'gtlm_integrations_load_bridge can stop the listener');
$js=(string)file_get_contents(GTLM_PATH.'assets/js/integrations.js');ig_check(!str_contains($js,'preventDefault')&&!str_contains($js,'cookie')&&!str_contains($js,'localStorage'),'The listener never blocks a click or stores anything');

// Settings screen.
wp_set_current_user(1);
$cfg(['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'TOPSECRETVALUE'],'matomo'=>['enabled'=>1,'base_url'=>'https://m.example','site_id'=>'3','token'=>'MATOMOSECRET']]);
ob_start();GTLM_Integrations_Admin::render();$html=ob_get_clean();
ig_check(!str_contains($html,'TOPSECRETVALUE')&&!str_contains($html,'MATOMOSECRET'),'Saved secrets are never sent to the browser');
ig_check(str_contains($html,'G-ABCD1234')&&str_contains($html,'name="gtlm_integrations[tools][ga4][enabled]"')&&str_contains($html,'gtlm_test_tool')&&str_contains($html,'_wpnonce'),'The screen shows saved values, the toggles, the test button and a nonce');
$cfg(['ga4'=>['enabled'=>1,'mode'=>'server','measurement_id'=>'G-ABCD1234','api_secret'=>'x']]);
if(!class_exists('\\IAWP\\Views\\View')){$raw=GTLM_Integrations::config();$raw['tools']['independent_analytics']=['enabled'=>1];GTLM_Integrations::save($raw);ob_start();GTLM_Integrations_Admin::render();$h=ob_get_clean();$pos=strpos($h,'gtlm-integration-independent-analytics');ig_check(str_contains(substr($h,$pos,400),'Plugin not active'),'A saved tool whose plugin was deactivated does not read as on',substr($h,$pos,400));}
foreach(['Independent Analytics','Koko Analytics','WP Statistics','Burst Statistics','SlimStat','Statify','Matomo','Google Analytics 4','Plausible','Umami','Simple Analytics','PostHog','Mixpanel','Amplitude','Rybbit','Clicky','Google Tag Manager','Fathom','Microsoft Clarity'] as $name){if(!str_contains($html,$name))ig_check(false,'The screen is missing '.$name);}

// Privacy text has to match what the site does.
require_once GTLM_PATH.'includes/class-gt-link-admin.php';
require_once GTLM_PATH.'includes/class-gt-link-import.php';
require_once GTLM_PATH.'includes/class-gt-link-admin-pages.php';
require_once ABSPATH.'wp-admin/includes/misc.php';require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';require_once ABSPATH.'wp-admin/includes/screen.php';require_once ABSPATH.'wp-admin/includes/class-wp-privacy-policy-content.php';
$GLOBALS['current_screen']=WP_Screen::get('dashboard');$GLOBALS['wp_actions']['admin_init']=1;
$policy=function()use($s){$ref=new ReflectionClass('GTLM_Admin');$admin=$ref->newInstanceWithoutConstructor();$prop=$ref->getProperty('settings');$prop->setAccessible(true);$prop->setValue($admin,$s);$store=new ReflectionProperty('WP_Privacy_Policy_Content','policy_content');$store->setAccessible(true);$store->setValue(null,[]);$admin->register_privacy_content();$found=$store->getValue();return $found?(string)end($found)['policy_text']:'';};
GTLM_Integrations::save(['share_visitor_data'=>0,'tools'=>[]]);$t=$policy();
ig_check(str_contains($t,'add tracking scripts')&&!str_contains($t,'Analytics integrations')&&str_contains($t,'never stores or transmits the country'),'With nothing on, the policy keeps its no-tracking statements');
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']],0);$t=$policy();
ig_check(str_contains($t,'Analytics integrations')&&str_contains($t,'Plausible Analytics')&&str_contains($t,'are not included')&&str_contains($t,'never stores or transmits the country'),'A service is named, with its data and no IP sharing',$t);
$cfg(['plausible'=>['enabled'=>1,'domain'=>'d.example']],1);$t=$policy();
ig_check(str_contains($t,'also shares the visitor')&&!str_contains($t,'never stores or transmits the country')&&!str_contains($t,'never reads or processes'),'With sharing on, the policy stops promising the IP and country stay here',$t);
$cfg(['fathom'=>['enabled'=>1]],0);$t=$policy();
ig_check(str_contains($t,'Fathom Analytics')&&str_contains($t,'small script')&&!str_contains($t,'add tracking scripts'),'A browser tool removes the no-tracking-scripts statement',$t);

// Uninstall.
ig_check(str_contains((string)file_get_contents(GTLM_PATH.'uninstall.php'),"delete_option( 'gtlm_integrations' )"),'Uninstall cleanup removes saved credentials');

// Cleanup.
unset($GLOBALS['current_screen'],$GLOBALS['wp_actions']['admin_init']);wp_set_current_user(0);$_COOKIE=[];
delete_option(GTLM_Integrations::OPTION);$s->set_enabled_integrations([]);$db->delete_link($linkId);$db->delete_category($cat);$s->update($before);
$out=['passed'=>count(array_filter($checks,fn($c)=>$c['ok'])),'checks'=>$checks,'failed'=>array_values(array_filter($checks,fn($c)=>!$c['ok']))];echo json_encode($out,JSON_PRETTY_PRINT);exit($out['failed']?1:0);
