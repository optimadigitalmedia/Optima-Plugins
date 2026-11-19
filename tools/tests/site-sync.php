<?php
// Site Sync integration checks. Run against a disposable WordPress whose active theme
// is a throwaway test theme with a writable optima-sync.json, optima-core active:
//   php tools/tests/site-sync.php /path/to/wordpress
// It deletes every page and attachment on that site (and, when Gravity Forms is not
// installed, uses a stand-in for it). Never point it at a real site.
require rtrim($argv[1] ?? '', '/').'/wp-load.php';
if ('synctest' !== get_stylesheet()) { fwrite(STDERR, "Refusing: the active theme must be the throwaway 'synctest' theme.\n"); exit(1); }
wp_set_current_user(1);
$theme = get_stylesheet_directory();
$fail = 0;
function ok($cond, $msg){ global $fail; echo ($cond?'PASS ':'FAIL ').$msg."\n"; if(!$cond) $fail++; }
function states(){ $o=array(); foreach(Optima_Sync::plan()['rows'] as $r){ $o[$r['id']]=$r['state']; } return $o; }
function manifest($data){ file_put_contents(get_stylesheet_directory().'/optima-sync.json', json_encode($data, JSON_PRETTY_PRINT)); delete_option(Optima_Sync::SEEN); }
function bump($v){ $f=get_stylesheet_directory().'/style.css'; file_put_contents($f, "/*\nTheme Name: Sync Test\nVersion: $v\n*/\n"); wp_clean_themes_cache(); }

// Clean slate.
foreach (get_posts(array('post_type'=>array('page','attachment'),'numberposts'=>-1,'post_status'=>'any','fields'=>'ids')) as $id) wp_delete_post($id, true);
foreach (array(Optima_Sync::BASES, Optima_Sync::JOURNAL, Optima_Sync::LOCK, Optima_Sync::SEEN) as $o) delete_option($o);
delete_transient(Optima_Sync::SUMMARY);
update_option('blogname','Old Title');

$about   = wp_insert_post(array('post_type'=>'page','post_name'=>'about','post_title'=>'About','post_status'=>'publish','post_content'=>''));
$contact = wp_insert_post(array('post_type'=>'page','post_name'=>'contact','post_title'=>'Contact','post_status'=>'publish','post_content'=>'Old copy'));
update_post_meta($about, 'optima_hero_heading', 'A hand edit nobody knows about');
file_put_contents($theme.'/sync/pages/about.html', "<p>About copy v1</p>\n");
bump('1.0.0');
manifest(array('sync_version'=>1,
  'pages'=>array(
    array('slug'=>'about','source'=>'sync/pages/about.html'),
    array('slug'=>'contact','content'=>'Contact copy v1','previous'=>array('Old copy')),
    array('slug'=>'services','content'=>'Services copy'),
  ),
  'fields'=>array(
    array('slug'=>'about','field'=>'hero_heading','value'=>'Theme heading v1'),
    array('slug'=>'about','seo'=>'desc','value'=>'About description v1'),
    array('slug'=>'about','field'=>'hero_image','image'=>'sync/img/hero.png'),
  ),
  'options'=>array(array('option'=>'blogname','value'=>'New Title','previous'=>array('Old Title')), array('option'=>'siteurl','value'=>'http://evil')),
));

$s = states();
ok($s['page/about#content']==='update', 'empty page content is safe to fill');
ok($s['page/contact#content']==='update', 'a known earlier copy counts as untouched');
ok($s['page/services#content']==='missing', 'a page the site lacks is reported, not created');
ok($s['page/about#field:hero_heading']==='conflict', 'an unknown saved value is never treated as safe');
ok($s['page/about#field:hero_image']==='update', 'an empty image field is safe to fill');
ok(!isset($s['option#siteurl']), 'siteurl is refused');
ok(in_array('Option "siteurl" is not one Site Sync may set.', Optima_Sync::plan()['errors'], true), 'refused option is reported');

// A stale review is refused.
$r = Optima_Sync::apply('not-the-hash', 'safe');
ok(is_wp_error($r) && 'stale'===$r->get_error_code(), 'a stale review is refused');

// Automatic run after "theme update".
Optima_Sync::maybe_auto_run();
ok(get_post($about)->post_content === "<p>About copy v1</p>\n" || rtrim(get_post($about)->post_content)==='<p>About copy v1</p>', 'about content synced');
ok(get_post($contact)->post_content === 'Contact copy v1', 'contact content synced');
ok(get_option('blogname')==='New Title', 'site title synced');
ok(get_post_meta($about,'optima_hero_heading',true)==='A hand edit nobody knows about', 'the unknown hand edit was left alone');
$img = (int) get_post_meta($about,'optima_hero_image',true);
ok($img > 0 && 'attachment'===get_post_type($img), 'image imported into the media library');
$s = states();
ok($s['page/about#content']==='current' && $s['page/about#field:hero_image']==='current', 'synced rows read current');
ok(Optima_Sync::summary(true)['review']===3, 'review count is conflict + missing + refused option: '.Optima_Sync::summary(true)['review']);

// Running again does nothing new.
Optima_Sync::maybe_auto_run();
ok(Optima_Sync::apply('', 'safe', array(), 'auto')['written']===0, 'a second run writes nothing');

// Image re-import is reused, not duplicated.
// Hand edit in WordPress, then a theme update that does not touch that field.
wp_update_post(array('ID'=>$contact,'post_content'=>'Contact edited in WP'));
file_put_contents($theme.'/sync/pages/about.html', "<p>About copy v2</p>\n");
bump('1.0.1');
$s = states();
ok($s['page/contact#content']==='edited', 'a WordPress edit is flagged as edited');
ok($s['page/about#content']==='update', 'an untouched page with new theme copy is an update');
Optima_Sync::maybe_auto_run();
ok(rtrim(get_post($about)->post_content)==='<p>About copy v2</p>', 'auto run applied the theme update');
ok(get_post($contact)->post_content==='Contact edited in WP', 'auto run kept the WordPress edit');

// Theme changes a field that was also edited in WordPress: conflict, untouched.
manifest(array_merge(json_decode(file_get_contents($theme.'/optima-sync.json'),true), array()));
$m = json_decode(file_get_contents($theme.'/optima-sync.json'),true);
$m['pages'][1]['content']='Contact copy v2'; manifest($m); bump('1.0.2');
ok(states()['page/contact#content']==='conflict', 'both changed is a conflict');
Optima_Sync::maybe_auto_run();
ok(get_post($contact)->post_content==='Contact edited in WP', 'a conflict is never auto-overwritten');

// Keep the WordPress copy, then the theme moves again: still waits for a person.
$plan = Optima_Sync::plan();
$r = Optima_Sync::apply($plan['hash'], 'keep', array('page/contact#content'));
ok(!is_wp_error($r) && states()['page/contact#content']==='current', 'keeping the WordPress copy clears the flag');
$m['pages'][1]['content']='Contact copy v3'; manifest($m); bump('1.0.3');
ok(states()['page/contact#content']==='conflict', 'a kept copy is not overwritten when the theme moves on');

// Take the theme copy on purpose.
$plan = Optima_Sync::plan();
$r = Optima_Sync::apply($plan['hash'], 'take', array('page/contact#content'));
ok(!is_wp_error($r) && get_post($contact)->post_content==='Contact copy v3', 'take writes the theme copy');
ok(states()['page/contact#content']==='current', 'and reads current afterwards');

// Undo puts back what the last sync replaced.
$r = Optima_Sync::undo();
ok(!is_wp_error($r) && 1===$r['restored'] && get_post($contact)->post_content==='Contact edited in WP', 'undo restores the previous copy');

// A field edited between review and write stops the sync and changes nothing more.
$m['pages'][0]['content']='About v3 inline'; unset($m['pages'][0]['source']); manifest($m); bump('1.0.4');
$plan = Optima_Sync::plan();
add_filter('optima_sync_applied', '__return_null');
// Simulate a racing edit: change the page after planning but before apply re-plans by hooking the lock.
wp_update_post(array('ID'=>$about,'post_content'=>'Racing edit'));
$r = Optima_Sync::apply($plan['hash'], 'safe');
ok(is_wp_error($r) && 'stale'===$r->get_error_code(), 'an edit after review makes the review stale');
ok(get_post($about)->post_content==='Racing edit', 'and nothing was written');

// Adoption: a page that already matches gets a base, so the next theme copy is a safe update.
$faq = wp_insert_post(array('post_type'=>'page','post_name'=>'faq','post_title'=>'FAQ','post_status'=>'publish','post_content'=>'FAQ copy v1'));
$m['pages'][] = array('slug'=>'faq','content'=>'FAQ copy v1'); manifest($m); bump('1.0.5');
ok(states()['page/faq#content']==='current', 'a page that already matches reads current');
Optima_Sync::maybe_auto_run();
$m['pages'][count($m['pages'])-1]['content']='FAQ copy v2'; manifest($m); bump('1.0.6');
ok(states()['page/faq#content']==='update', 'after adoption, new theme copy on a matching page is a safe update');
Optima_Sync::maybe_auto_run();
ok(get_post($faq)->post_content==='FAQ copy v2', 'and the automatic run applies it');

// A source file missing from a theme release never blanks a page.
file_put_contents($theme.'/sync/pages/faq.html', 'FAQ copy v2');
$m['pages'][count($m['pages'])-1] = array('slug'=>'faq','source'=>'sync/pages/faq.html'); manifest($m); bump('1.0.7');
Optima_Sync::maybe_auto_run();
unlink($theme.'/sync/pages/faq.html'); bump('1.0.8');
ok(!isset(states()['page/faq#content']), 'a row whose file is missing is left out');
ok(in_array('File missing from the theme: sync/pages/faq.html', Optima_Sync::plan()['errors'], true), 'and reported');
Optima_Sync::maybe_auto_run();
$r = Optima_Sync::apply(Optima_Sync::plan()['hash'], 'take', array('page/faq#content'));
ok(get_post($faq)->post_content==='FAQ copy v2', 'neither the automatic run nor "Use the theme copy" blanked it');

// 1.5: one key inside an array option, a list item matched by slug, a key inside array meta, an author bio.
add_filter('optima_sync_option_keys', function($k){ $k[]='synctest_cards'; return $k; });
update_option('optima_business', array('name'=>'Typed by hand','phone'=>'(555) 000-0000'));
update_option('synctest_cards', array('people'=>array(array('slug'=>'alex','title'=>'Alex title'), array('slug'=>'jordan','title'=>'Old Jordan title','pick'=>'Kept pick'))));
$team = wp_insert_post(array('post_type'=>'page','post_name'=>'team-jordan','post_title'=>'Jordan','post_status'=>'publish','post_content'=>'x'));
update_post_meta($team, '_synctest_team', array('role'=>'Co-Founder','bio'=>'Hand bio','email'=>'jordan@example.com'));
if ($old = get_user_by('email','jordan-sync@example.com')) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($old->ID); }
$uid = wp_insert_user(array('user_login'=>'synctest_sean','user_email'=>'jordan-sync@example.com','user_pass'=>wp_generate_password()));
update_user_meta($uid, 'description', 'Old author bio');
$m = array('sync_version'=>1,
  'fields'=>array(
    array('slug'=>'team-jordan','meta'=>'_synctest_team','key'=>'role','value'=>'Co-Founder, Strategy & Technology','previous'=>array('Co-Founder')),
    array('slug'=>'team-jordan','meta'=>'_synctest_team','key'=>'bio','value'=>'Theme bio'),
  ),
  'options'=>array(
    array('option'=>'optima_business','key'=>'name','value'=>'Recon'),
    array('option'=>'optima_business','key'=>'email','value'=>'team@example.com'),
    array('option'=>'synctest_cards','key'=>'people.jordan.title','value'=>'New Jordan title','previous'=>array('Old Jordan title')),
    array('option'=>'optima_business'),
    array('option'=>'synctest_other','key'=>'x','value'=>'nope'),
  ),
  'users'=>array(
    array('email'=>'jordan-sync@example.com','field'=>'description','value'=>'New author bio','previous'=>array('Old author bio')),
    array('email'=>'nobody@example.com','field'=>'description','value'=>'Nobody'),
    array('email'=>'jordan-sync@example.com','field'=>'user_pass','value'=>'nope'),
  ),
);
manifest($m); bump('1.5.0');
$s = states();
ok($s['option#optima_business.name']==='conflict', 'a hand-typed business name is not overwritten');
ok($s['option#optima_business.email']==='update', 'an empty business field is safe to fill');
ok($s['option#synctest_cards.people.jordan.title']==='update', 'a list item is found by its slug');
ok($s['page/team-jordan#meta:_synctest_team.role']==='update', 'a known earlier value inside array meta is safe');
ok($s['page/team-jordan#meta:_synctest_team.bio']==='conflict', 'an unknown value inside array meta waits for a person');
ok($s['user/jordan-sync@example.com#description']==='update', 'an earlier author bio is safe to replace');
ok($s['user/nobody@example.com#description']==='missing', 'an unknown author is reported missing');
$errs = Optima_Sync::plan()['errors'];
ok(in_array('Option "optima_business" is not one Site Sync may set.', $errs, true), 'a whole array option is refused');
ok(in_array('Option "synctest_other.x" is not one Site Sync may set.', $errs, true), 'an option nobody allowed is refused');
ok((bool) preg_grep('/needs an email and a field/', $errs), 'a user field outside the list is refused');
Optima_Sync::maybe_auto_run();
$biz = get_option('optima_business'); $cards = get_option('synctest_cards'); $tm = get_post_meta($team,'_synctest_team',true);
ok($biz['email']==='team@example.com' && $biz['name']==='Typed by hand' && $biz['phone']==='(555) 000-0000', 'business: filled the empty key, kept the rest');
ok($cards['people'][1]['title']==='New Jordan title' && $cards['people'][1]['pick']==='Kept pick' && $cards['people'][0]['title']==='Alex title', 'cards: only that person\'s title changed');
ok($tm['role']==='Co-Founder, Strategy & Technology' && $tm['bio']==='Hand bio' && $tm['email']==='jordan@example.com', 'team meta: only the role changed');
ok(get_user_meta($uid,'description',true)==='New author bio', 'author bio synced');
$r = Optima_Sync::undo();
$biz = get_option('optima_business'); $cards = get_option('synctest_cards'); $tm = get_post_meta($team,'_synctest_team',true);
ok(!is_wp_error($r) && 4===$r['restored'] && ''===($biz['email']??''), 'undo puts back the business field');
ok($cards['people'][1]['title']==='Old Jordan title' && $tm['role']==='Co-Founder' && get_user_meta($uid,'description',true)==='Old author bio', 'undo puts back card, team role and author bio');
require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($uid); wp_delete_post($team, true); delete_option('optima_business'); delete_option('synctest_cards');

// 1.6.1: a page the site lacks is created from its "create" block, only when a person ticks it.
foreach (get_posts(array('post_type'=>'page','numberposts'=>-1,'post_status'=>'any','fields'=>'ids')) as $id) wp_delete_post($id, true);
foreach (array(Optima_Sync::BASES, Optima_Sync::JOURNAL) as $o) delete_option($o);
function rows(){ $o=array(); foreach(Optima_Sync::plan()['rows'] as $r){ $o[$r['id']]=$r; } return $o; }
function hashnow(){ return Optima_Sync::plan()['hash']; }
file_put_contents($theme.'/tpl-landing.php', "<?php\n/* Template Name: Landing */\n");
file_put_contents($theme.'/sync/pages/services.html', "<p>Services copy</p>\n");
$old = wp_insert_post(array('post_type'=>'page','post_name'=>'old-offer','post_title'=>'Old offer','post_status'=>'publish','post_content'=>'Old'));
wp_trash_post($old);
$admin = get_userdata(1);
$m = array('sync_version'=>1,
  'pages'=>array(
    array('slug'=>'services','title'=>'Services','source'=>'sync/pages/services.html','create'=>array('status'=>'publish','template'=>'tpl-landing.php','menu_order'=>3,'author'=>$admin->user_login)),
    array('slug'=>'services/injury','title'=>'Injury','content'=>'Injury copy','create'=>array('author'=>'nobody@example.com')),
    array('slug'=>'old-offer','content'=>'Offer copy','create'=>array('status'=>'publish')),
    array('slug'=>'bad-template','content'=>'x','create'=>array('template'=>'nope.php')),
    array('slug'=>'orphan','content'=>'x','create'=>array('parent'=>'no-such-parent')),
    array('slug'=>'bad-status','content'=>'x','create'=>array('status'=>'private')),
  ),
  'fields'=>array(array('slug'=>'services','seo'=>'desc','value'=>'Services description')),
);
manifest($m); bump('1.6.1');
$s = states(); $r = rows();
ok($s['page/services#create']==='missing' && empty($r['page/services#create']['blocked']), 'a page with a create block reads missing and can be created');
ok(!empty($r['page/services#content']['covered']) && !empty($r['page/services#seo:desc']['covered']), 'its copy and field rows wait for the page instead of counting twice');
ok(empty($r['page/services/injury#create']['blocked']), 'a child whose parent the theme also creates is not blocked');
ok(false !== strpos((string) $r['page/services/injury#create']['author_note'], 'nobody@example.com'), 'an unknown author falls back, with a note');
ok((int) $r['page/old-offer#create']['trashed'] === $old, 'a trashed page with the same slug is found');
ok(false !== strpos((string) ($r['page/bad-template#create']['blocked'] ?? ''), 'no page template'), 'a template the theme lacks blocks creation');
ok(false !== strpos((string) ($r['page/orphan#create']['blocked'] ?? ''), 'parent page'), 'a parent nobody can create blocks creation');
ok((bool) preg_grep('/status "private"/', Optima_Sync::plan()['errors']), 'a status other than publish or draft is refused');
ok(7 === Optima_Sync::summary(true)['review'], 'review count: five new-page rows, the page with no create block, one manifest error (covered field rows not counted): '.Optima_Sync::summary(true)['review']);
Optima_Sync::maybe_auto_run();
ok(!get_page_by_path('services'), 'the automatic run never creates a page');
$res = Optima_Sync::apply(hashnow(), 'create', array('page/services/injury#create'));
ok(is_wp_error($res) && 'parent'===$res->get_error_code() && !get_page_by_path('services/injury'), 'a child without its parent ticked is refused, nothing created');
$res = Optima_Sync::apply(hashnow(), 'create', array('page/old-offer#create'));
ok(!is_wp_error($res) && 0===$res['written'] && 'trash'===get_post_status($old) && !get_page_by_path('old-offer'), 'a slug in the trash is never created a second time');
$res = Optima_Sync::apply(hashnow(), 'create', array('page/services/injury#create','page/services#create'));
$svc = get_page_by_path('services'); $inj = get_page_by_path('services/injury');
ok(!is_wp_error($res) && 2===$res['written'] && $svc && $inj, 'create makes the ticked pages, parent first');
ok('publish'===$svc->post_status && 'Services'===$svc->post_title && '<p>Services copy</p>'===rtrim($svc->post_content), 'the new page has its status, title and copy');
ok('tpl-landing.php'===get_page_template_slug($svc) && 3===(int)$svc->menu_order && 1===(int)$svc->post_author, 'and its template, menu order and author');
ok('draft'===$inj->post_status && (int)$inj->post_parent===$svc->ID && 1===(int)$inj->post_author, 'a draft stays a draft, under its parent, credited to the person running it');
ok('Services description'===get_post_meta($svc->ID, OPTIMA_SEO_META_DESC, true), 'its SEO description filled in the same step');
$s = states();
ok('current'===$s['page/services#create'] && 'current'===$s['page/services#content'] && 'current'===$s['page/services#title'] && 'current'===$s['page/services#seo:desc'], 'every row of the new page reads current');
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && 2===$res['restored'] && 'trash'===get_post_status($svc->ID) && 'trash'===get_post_status($inj->ID), 'undo moves the created pages to the trash');
$r = rows();
ok('missing'===$r['page/services#create']['state'] && (int)$r['page/services#create']['trashed']===$svc->ID, 'after undo the page is offered for restore, not re-creation');
$res = Optima_Sync::apply(hashnow(), 'restore', array('page/services#create'));
ok(!is_wp_error($res) && 1===$res['written'] && 'publish'===get_post_status($svc->ID) && 'services'===get_post($svc->ID)->post_name, 'restore brings it back with the status and address it had');
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && 'trash'===get_post_status($svc->ID), 'undoing a restore puts it back in the trash');
wp_delete_post($svc->ID, true); wp_delete_post($inj->ID, true);
$res = Optima_Sync::apply(hashnow(), 'create', array('page/services#create'));
$svc = get_page_by_path('services');
wp_update_post(array('ID'=>$svc->ID, 'post_content'=>'Edited after creation'));
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && in_array('services: new page', $res['skipped'], true) && 'publish'===get_post_status($svc->ID), 'a created page edited since is never trashed by undo');
ok('Services description'===get_post_meta($svc->ID, OPTIMA_SEO_META_DESC, true), 'and its fields are left whole');
wp_delete_post($svc->ID, true);

// 1.6.1: Gravity Forms from the theme bundle. A stand-in for GFAPI when Gravity Forms is not installed;
// like the real one, it adds keys of its own when it saves, so a form never equals its export byte for byte.
if (!class_exists('GFAPI')) {
  class GFAPI {
    static function all(){ $f=get_option('synctest_gf',array()); return is_array($f)?$f:array(); }
    static function put($f){ update_option('synctest_gf',$f,false); }
    static function get_form($id){ $a=self::all(); return $a[$id] ?? false; }
    static function get_forms($active=true,$trash=false){ return array_values(array_filter(self::all(), function($f) use($trash){ return $trash || empty($f['is_trash']); })); }
    static function add_form($form){ $a=self::all(); $id=$a?max(array_keys($a))+1:1; $form['id']=$id; $form['is_active']='1'; $form['is_trash']='0'; $form['date_created']=gmdate('Y-m-d H:i:s'); foreach($form['fields'] as &$fl){ $fl['formId']=$id; $fl['visibility']=$fl['visibility']??'visible'; } unset($fl); $a[$id]=$form; self::put($a); return $id; }
    static function update_form($form,$id){ $a=self::all(); if(!isset($a[$id])) return new WP_Error('not_found','Form not found'); $form['id']=$id; $form['is_active']=$a[$id]['is_active']; $form['is_trash']=$a[$id]['is_trash']; $form['date_created']=$a[$id]['date_created']; foreach($form['fields'] as &$fl){ $fl['formId']=$id; $fl['visibility']=$fl['visibility']??'visible'; } unset($fl); $a[$id]=$form; self::put($a); return true; }
    static function update_form_property($id,$k,$v){ $a=self::all(); $a[$id][$k]=$v; self::put($a); return true; }
  }
  delete_option('synctest_gf');
}
@mkdir($theme.'/sync/forms', 0777, true);
function export_form($fields, $button='Send'){ file_put_contents(get_stylesheet_directory().'/sync/forms/contact.json', json_encode(array('0'=>array('id'=>7,'title'=>'Contact form export','fields'=>$fields,'button'=>array('type'=>'text','text'=>$button)),'version'=>'2.8.0'))); }
function gf_by_title($t){ foreach (GFAPI::get_forms(null,false) as $f) if ($f['title']===$t) return $f; return null; }
$f1 = array(array('id'=>1,'type'=>'text','label'=>'Name','isRequired'=>true), array('id'=>2,'type'=>'email','label'=>'Email'));
export_form($f1);
manifest(array('sync_version'=>1, 'forms'=>array(
  array('file'=>'sync/forms/contact.json','title'=>'Contact'),
  array('file'=>'sync/forms/none.json','title'=>'Nowhere'),
  array('title'=>'No file'),
))); bump('1.6.2');
add_filter('optima_sync_forms_ready', '__return_false');
ok('unavailable'===states()['form/contact'], 'without Gravity Forms a form row says so');
$res = Optima_Sync::apply(hashnow(), 'create', array('form/contact'));
ok(!is_wp_error($res) && 0===$res['written'] && null===gf_by_title('Contact'), 'and nothing is done to it');
remove_filter('optima_sync_forms_ready', '__return_false');
$errs = Optima_Sync::plan()['errors'];
ok(in_array('File missing from the theme: sync/forms/none.json', $errs, true) && (bool) preg_grep('/A forms entry needs a "file"/', $errs), 'a missing export file and an entry without a file are reported');
ok('missing'===states()['form/contact'], 'a form the site lacks reads missing');
Optima_Sync::maybe_auto_run();
ok(null===gf_by_title('Contact'), 'the automatic run never adds a form');
$res = Optima_Sync::apply(hashnow(), 'create', array('form/contact'));
$form = gf_by_title('Contact');
ok(!is_wp_error($res) && 1===$res['written'] && $form && 'Contact'===$form['title'] && 2===count($form['fields']), 'create adds the form with the manifest title, exactly');
$fid = (int) $form['id'];
ok('current'===states()['form/contact'], 'and it reads current');
$f2 = $f1; $f2[] = array('id'=>3,'type'=>'phone','label'=>'Phone'); export_form($f2); bump('1.6.3');
ok('update'===states()['form/contact'], 'a theme change to an untouched form is a safe update');
Optima_Sync::maybe_auto_run();
$form = GFAPI::get_form($fid);
ok(3===count($form['fields']) && $fid===(int)$form['id'] && 1===count(GFAPI::get_forms(null,false)), 'the automatic run updates it in place, same id, no second form');
$a = GFAPI::all(); $a[$fid]['fields'][0]['label'] = 'Full name (edited in WordPress)'; GFAPI::put($a); Optima_Sync::forget_summary();
ok('edited'===states()['form/contact'], 'a form edited in WordPress is flagged as edited');
$f3 = $f2; $f3[2]['label'] = 'Phone number'; export_form($f3); bump('1.6.4');
ok('conflict'===states()['form/contact'], 'both changed is a conflict');
Optima_Sync::maybe_auto_run();
ok('Full name (edited in WordPress)'===GFAPI::get_form($fid)['fields'][0]['label'], 'a conflict is never overwritten on its own');
$res = Optima_Sync::apply(hashnow(), 'take', array('form/contact'));
$form = GFAPI::get_form($fid);
ok(!is_wp_error($res) && 'Name'===$form['fields'][0]['label'] && 'Phone number'===$form['fields'][2]['label'] && $fid===(int)$form['id'], '"Use the theme copy" updates the form and keeps its id');
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && 1===$res['restored'] && 'Full name (edited in WordPress)'===GFAPI::get_form($fid)['fields'][0]['label'], 'undo puts back the form as it was');
$res = Optima_Sync::apply(hashnow(), 'keep', array('form/contact'));
ok('current'===states()['form/contact'], 'keeping the WordPress form clears the flag');
delete_option('synctest_gf'); delete_option(Optima_Sync::BASES); Optima_Sync::forget_summary();
GFAPI::add_form(array('title'=>'Contact','fields'=>$f3,'button'=>array('type'=>'text','text'=>'Send')));
ok('current'===states()['form/contact'], 'a form once imported by hand from the same file is adopted, not flagged');
GFAPI::add_form(array('title'=>'Contact','fields'=>$f1));
$r = rows();
ok('conflict'===$r['form/contact']['state'] && false !== strpos((string) ($r['form/contact']['blocked'] ?? ''), 'Two forms'), 'two forms with the same title wait for a person');
$res = Optima_Sync::apply(hashnow(), 'take', array('form/contact'));
ok(!is_wp_error($res) && 0===$res['written'], 'and are never written');
delete_option('synctest_gf'); delete_option(Optima_Sync::BASES);
$res = Optima_Sync::apply(hashnow(), 'create', array('form/contact'));
$fid = (int) gf_by_title('Contact')['id'];
$res = Optima_Sync::undo();
$form = GFAPI::get_form($fid);
ok(!is_wp_error($res) && 1===$res['restored'] && !empty($form['is_trash']) && empty($form['is_active']), 'undoing an added form switches it off and trashes it');
ok('missing'===states()['form/contact'], 'and it can be added again');
delete_option('synctest_gf');

// 1.6.1: status keeping and the retire list. Neither ever runs on its own.
foreach (get_posts(array('post_type'=>'page','numberposts'=>-1,'post_status'=>array('publish','draft','pending','private','future','trash'),'fields'=>'ids')) as $id) wp_delete_post($id, true);
foreach (array(Optima_Sync::BASES, Optima_Sync::JOURNAL) as $o) delete_option($o);
function pg($slug, $status='publish', $parent=0){ return wp_insert_post(array('post_type'=>'page','post_name'=>$slug,'post_title'=>ucfirst($slug),'post_status'=>$status,'post_parent'=>$parent,'post_content'=>'x')); }
$soon = pg('coming-soon'); $launch = pg('launch', 'draft'); $members = pg('members', 'private');
$front = pg('home'); update_option('show_on_front','page'); update_option('page_on_front',$front);
$ind = pg('industries'); $good = pg('truck-drivers', 'publish', $ind);
$oldind = pg('industries-x'); $junk = pg('truck-drivers', 'publish', $oldind); wp_trash_post($oldind); // WordPress renames the parent: industries-x__trashed
$junkpath = get_page_uri($junk);
$oldp = pg('old-parent'); $keeper = pg('keeper', 'publish', $oldp);
$dupp = pg('dup-parent'); $dupc = pg('dup-child', 'publish', $dupp);
$m = array('sync_version'=>1,
  'pages'=>array(
    array('slug'=>'coming-soon','status'=>'draft'),
    array('slug'=>'launch','status'=>'publish'),
    array('slug'=>'members','status'=>'publish'),
    array('slug'=>'new-page','status'=>'publish','content'=>'New','create'=>true),
    array('slug'=>'clash','status'=>'draft','content'=>'x','create'=>array('status'=>'publish')),
    array('slug'=>'bad','status'=>'private'),
  ),
  'retire'=>array(
    array('path'=>$junkpath,'reason'=>'Duplicate of /industries/truck-drivers/','redirect'=>'/industries/truck-drivers/'),
    array('path'=>'home','reason'=>'x'),
    array('path'=>'launch','reason'=>'x'),
    array('path'=>'no-such-page','reason'=>'Gone already'),
    array('path'=>'old-parent','reason'=>'x'),
    array('path'=>'dup-parent','reason'=>'x'),
    array('path'=>'dup-parent/dup-child','reason'=>'x'),
    array('reason'=>'no path'),
  ),
);
manifest($m); bump('1.6.3');
$s = states(); $r = rows(); $errs = Optima_Sync::plan()['errors'];
ok('industries-x__trashed/truck-drivers' === $junkpath, 'the stand-in junk page sits under a trashed parent: '.$junkpath);
ok('published_early'===$s['page/coming-soon#status'], 'the theme says draft and the page is published: published early');
ok('ready_to_publish'===$s['page/launch#status'], 'the theme says publish and the page is a draft: ready to publish');
ok('current'===$s['page/members#status'], 'a private page was set that way on purpose and reads current');
ok('missing'===$s['page/new-page#status'] && !empty($r['page/new-page#status']['covered']) && 'publish'===$r['page/new-page#create']['status'], 'a page not on the site leaves its status to its create block, which takes the entry status');
ok((bool) preg_grep('/clash has status "draft" but its "create" block says "publish"/', $errs), 'an entry status and a create status that disagree are refused');
ok((bool) preg_grep('/The "status" for bad must be/', $errs), 'a status other than publish or draft is refused');
ok('retire'===$s['retire/page/'.$junkpath] && empty($r['retire/page/'.$junkpath]['blocked']), 'a retired page still on the site is listed: match');
ok('current'===$s['retire/page/no-such-page'], 'a retired page that is not on the site needs nothing: miss');
ok(false !== strpos((string) ($r['retire/page/home']['blocked'] ?? ''), 'front page'), 'the front page is refused');
ok(!isset($s['retire/page/launch']) && (bool) preg_grep('/lists \/launch\/ as a live page/', $errs), 'a page the manifest lists as live is refused');
ok(false !== strpos((string) ($r['retire/page/old-parent']['blocked'] ?? ''), 'does not retire'), 'a page with a live page under it is refused');
ok(empty($r['retire/page/dup-parent']['blocked']), 'a parent whose pages the theme also retires is allowed');
ok((bool) preg_grep('/A retire entry needs a "path"/', $errs), 'a retire entry with no path is reported');
$review = Optima_Sync::summary(true)['review'];
ok(13 === $review, 'review count: early, ready, five retire rows, the new page, the clash page with no create block, four manifest errors (covered status rows not counted): '.$review);
Optima_Sync::maybe_auto_run();
ok('publish'===get_post_status($soon) && 'draft'===get_post_status($launch) && 'publish'===get_post_status($junk), 'the automatic run never changes a status or trashes a page');
$res = Optima_Sync::apply('', 'safe', array(), 'auto');
ok('publish'===get_post_status($soon) && 'publish'===get_post_status($junk), 'nor does a safe run');

// Status: set back to draft and publish, then undo.
$res = Optima_Sync::apply(hashnow(), 'status', array('page/coming-soon#status','page/launch#status'));
ok(!is_wp_error($res) && 2===$res['written'] && 'draft'===get_post_status($soon) && 'publish'===get_post_status($launch), 'set back to draft and publish, as ticked');
$s = states();
ok('current'===$s['page/coming-soon#status'] && 'current'===$s['page/launch#status'], 'and both read current');
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && 2===$res['restored'] && 'publish'===get_post_status($soon) && 'draft'===get_post_status($launch), 'undo puts both statuses back');
$res = Optima_Sync::apply(hashnow(), 'status', array('page/coming-soon#status'));
wp_update_post(array('ID'=>$soon,'post_status'=>'pending'));
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && in_array('coming-soon: status', $res['skipped'], true) && 'pending'===get_post_status($soon), 'undo leaves a status someone changed since');
wp_update_post(array('ID'=>$soon,'post_status'=>'publish'));
$res = Optima_Sync::apply(hashnow(), 'keep', array('page/coming-soon#status'));
ok(!is_wp_error($res) && 'current'===states()['page/coming-soon#status'] && 'publish'===get_post_status($soon), '"Keep it as it is" clears the flag and changes nothing');
$m['pages'][0]['status']='publish'; manifest($m); bump('1.6.4');
$m['pages'][0]['status']='draft'; manifest($m); bump('1.6.5');
ok('current'===states()['page/coming-soon#status'], 'a kept status stays quiet while neither side moves');
wp_update_post(array('ID'=>$soon,'post_status'=>'draft')); wp_update_post(array('ID'=>$soon,'post_status'=>'publish'));
delete_option(Optima_Sync::BASES);
ok('published_early'===states()['page/coming-soon#status'], 'without the keep it is flagged again');

// Retire: children with the parent, refused rows, the 301, undo.
$res = Optima_Sync::apply(hashnow(), 'retire', array('retire/page/dup-parent'));
ok(is_wp_error($res) && 'children'===$res->get_error_code() && 'publish'===get_post_status($dupp), 'a parent without its retired pages ticked is refused, nothing trashed');
$res = Optima_Sync::apply(hashnow(), 'retire', array('retire/page/home'));
ok(!is_wp_error($res) && 0===$res['written'] && 'publish'===get_post_status($front), 'the front page is never trashed, even when ticked');
$redirects = class_exists('Optima_Redirects');
$id = 'retire/page/'.$junkpath;
ok($redirects ? !empty(rows()[$id]['redirect_ok']) : false !== strpos((string) rows()[$id]['redirect_note'], 'not active'), $redirects ? 'with Optima Redirects active the 301 is offered' : 'without Optima Redirects the screen says the 301 is not added here');
$res = Optima_Sync::apply(hashnow(), 'retire', array($id, 'redirect:'.$id, 'retire/page/dup-parent', 'retire/page/dup-parent/dup-child'));
ok(!is_wp_error($res) && 3===$res['written'] && 'trash'===get_post_status($junk) && 'trash'===get_post_status($dupp) && 'trash'===get_post_status($dupc), 'move to the trash: the junk page and a parent with its page, deepest first');
ok('publish'===get_post_status($good) && null !== get_post($junk), 'the live page is untouched and nothing was deleted');
if ($redirects) {
  $red = Optima_Redirects_Store::find(Optima_Redirects::normalize_source('/'.$junkpath.'/'));
  ok($red && '/industries/truck-drivers/'===$red->target && 301===(int)$red->type, 'the 301 from the old address was added');
}
$s = states();
ok('current'===$s[$id] && 'current'===$s['retire/page/dup-parent'], 'trashed pages read current');
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && 3===$res['restored'] && 'publish'===get_post_status($junk) && 'publish'===get_post_status($dupc), 'undo brings them back from the trash with the status they had');
ok(get_page_by_path($junkpath) && $junk===(int) get_page_by_path($junkpath)->ID, 'at the address they had');
if ($redirects) ok(null === Optima_Redirects_Store::find(Optima_Redirects::normalize_source('/'.$junkpath.'/')), 'and the 301 Site Sync added is gone');
$res = Optima_Sync::apply(hashnow(), 'retire', array($id));
wp_delete_post($junk, true);
$res = Optima_Sync::undo();
ok(!is_wp_error($res) && in_array('/'.$junkpath.'/: retired by the theme', $res['skipped'], true), 'undo names a retired page someone emptied from the trash since');
update_option('show_on_front','posts'); delete_option('page_on_front');

// No manifest: status only.
unlink($theme.'/optima-sync.json');
ok(Optima_Sync::plan()['manifest']===false && Optima_Sync::summary(true)['review']===0, 'no manifest means status only, nothing to review');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
