<?php
declare(strict_types=1);

/**
 * Vision Specialists - Prescription In Your Frame Reminder System Builder
 *
 * Upload this file to:
 * /home/www/15-9-24/magento2/vsc-services/
 *
 * Run:
 * /usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame-reminder-builder.php
 *
 * It creates:
 * /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/
 */

$root = '/home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame';
$dirs = ['admin','config','cron','includes','logs','public','storage'];
foreach ($dirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path) && !mkdir($path, 02775, true) && !is_dir($path)) {
        fwrite(STDERR, "Unable to create $path\n");
        exit(1);
    }
}

$files = [];

$files['includes/common.php'] = <<<'PHP'
<?php
declare(strict_types=1);

const PIF_ROOT = __DIR__ . '/..';
const MAGENTO_ROOT = '/home/www/15-9-24/magento2';
const PIF_BASE_URL = 'https://www.visionspecialists.org/vsc-services/prescription-in-your-frame';
date_default_timezone_set('America/Los_Angeles');

function pif_load_config(array $paths): array {
    foreach ($paths as $path) {
        if (is_file($path)) {
            $v = require $path;
            if (is_array($v)) return $v;
        }
    }
    return [];
}

function pif_db(): mysqli {
    static $db;
    if ($db instanceof mysqli) return $db;
    $cfg = pif_load_config([
        PIF_ROOT . '/config/database.php',
        dirname(PIF_ROOT) . '/eyewear-repair/config/database.php',
    ]);
    if (!$cfg) {
        $env = require MAGENTO_ROOT . '/app/etc/env.php';
        $cfg = $env['db']['connection']['default'] ?? [];
    }
    $host = (string)($cfg['host'] ?? 'localhost');
    $user = (string)($cfg['username'] ?? $cfg['user'] ?? '');
    $pass = (string)($cfg['password'] ?? $cfg['pass'] ?? '');
    $name = (string)($cfg['database'] ?? $cfg['dbname'] ?? '');
    $port = (int)($cfg['port'] ?? 3306);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
    $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $db;
}

function pif_table_exists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0] > 0;
}

function pif_columns(mysqli $db, string $table): array {
    $out=[]; $res=$db->query("SHOW COLUMNS FROM `".$db->real_escape_string($table)."`");
    while($r=$res->fetch_assoc()) $out[(string)$r['Field']]=true;
    return $out;
}

function pif_pick(array $cols, array $candidates): ?string {
    foreach($candidates as $c) if(isset($cols[$c])) return $c;
    return null;
}

function pif_source_map(): array {
    static $map;
    if ($map) return $map;
    $db=pif_db();
    $tables=['rx_orders','prescription_orders','prescription_in_your_frame_orders','orders'];
    $table='';
    foreach($tables as $t) if(pif_table_exists($db,$t)){ $table=$t; break; }
    if($table==='') throw new RuntimeException('Could not find the prescription order table. Expected rx_orders or prescription_orders.');
    $c=pif_columns($db,$table);
    $map=[
        'table'=>$table,
        'id'=>pif_pick($c,['id','order_id','rx_order_id']),
        'order'=>pif_pick($c,['order_id','order_number','rx_order_number','reference_number','id']),
        'name'=>pif_pick($c,['name','customer_name','shipping_name','full_name','billing_name']),
        'email'=>pif_pick($c,['email','customer_email','billing_email']),
        'phone'=>pif_pick($c,['phone','telephone','customer_phone']),
        'created'=>pif_pick($c,['created_at','created','order_date','date_created']),
        'updated'=>pif_pick($c,['updated_at','modified_at','updated','created_at']),
        'paid'=>pif_pick($c,['payment_status','paid_status','status','is_paid','paypal_status']),
        'paid_at'=>pif_pick($c,['paid_at','payment_date','paid_date']),
        'payment_url'=>pif_pick($c,['payment_url','payment_link','paypal_link','invoice_url','pay_url']),
        'total'=>pif_pick($c,['total_price','total','grand_total','amount','invoice_total']),
        'notes'=>pif_pick($c,['notes','customer_notes','description','order_notes','frame_details']),
        'service'=>pif_pick($c,['service_type','lens_type','prescription_type','product_type']),
        'lens'=>pif_pick($c,['lens_option','lens_material','lens_type','lens']),
        'coating'=>pif_pick($c,['coating','coating_option','lens_coating']),
        'frame'=>pif_pick($c,['frame_name','frame_details','frame_brand','frame_model']),
        'label_url'=>pif_pick($c,['shipping_label_url','label_url','prepaid_label_url']),
        'shipment_id'=>pif_pick($c,['shipping_label_id','shipment_id','easypost_shipment_id']),
        'tracking'=>pif_pick($c,['shipping_tracking_code','tracking_code','tracking_number']),
    ];
    foreach(['id','order','email','created'] as $k) if(!$map[$k]) throw new RuntimeException("Required source column missing: $k");
    return $map;
}

function pif_install_schema(): void {
    $db=pif_db();
    $db->query("CREATE TABLE IF NOT EXISTS vsc_pif_settings (`key` varchar(100) NOT NULL PRIMARY KEY, `value` text NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS vsc_pif_control (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, reminder_type enum('payment','label') NOT NULL, source_id varchar(100) NOT NULL, order_number varchar(100) NOT NULL, email varchar(255) NOT NULL, enabled tinyint(1) NOT NULL DEFAULT 1, opted_out tinyint(1) NOT NULL DEFAULT 0, stopped_reason varchar(255) NULL, sent_count int unsigned NOT NULL DEFAULT 0, last_sent_at datetime NULL, next_send_at datetime NULL, tracking_status varchar(80) NULL, tracking_checked_at datetime NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_type_source (reminder_type,source_id), KEY idx_due (reminder_type,enabled,opted_out,next_send_at), KEY idx_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS vsc_pif_log (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, reminder_type varchar(20) NOT NULL, source_id varchar(100) NULL, order_number varchar(100) NULL, email varchar(255) NULL, action varchar(40) NOT NULL, result varchar(30) NOT NULL, message text NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_created (created_at), KEY idx_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $defaults=['system_enabled'=>'0','payment_enabled'=>'0','label_enabled'=>'0','first_delay_hours'=>'24','repeat_hours'=>'24','current_days'=>'60','batch_limit'=>'25'];
    $stmt=$db->prepare("INSERT INTO vsc_pif_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)");
    foreach($defaults as $k=>$v){$stmt->bind_param('ss',$k,$v);$stmt->execute();}
}

function pif_setting(string $key,string $default=''): string {
    $stmt=pif_db()->prepare("SELECT `value` FROM vsc_pif_settings WHERE `key`=?");
    $stmt->bind_param('s',$key);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();
    return $r ? (string)$r['value'] : $default;
}
function pif_set_setting(string $key,string $value): void {
    $stmt=pif_db()->prepare("INSERT INTO vsc_pif_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $stmt->bind_param('ss',$key,$value);$stmt->execute();
}

function pif_q(string $name): string { return '`'.str_replace('`','',$name).'`'; }
function pif_val(array $row, ?string $col, $default=''){ return $col ? ($row[$col] ?? $default) : $default; }
function pif_valid_email(string $email): bool { return (bool)filter_var($email,FILTER_VALIDATE_EMAIL); }
function pif_paid(array $row,array $m): bool {
    if(!$m['paid']) return false;
    $v=strtolower(trim((string)pif_val($row,$m['paid'],'')));
    return in_array($v,['1','paid','complete','completed','success','successful','captured'],true);
}
function pif_label_stop_status(string $s): bool {
    return in_array(strtolower(trim($s)),['in_transit','out_for_delivery','delivered','available_for_pickup','return_to_sender','failure','cancelled'],true);
}

function pif_fetch_source(string $sourceId): ?array {
    $m=pif_source_map();$db=pif_db();
    $stmt=$db->prepare("SELECT * FROM ".pif_q($m['table'])." WHERE CAST(".pif_q($m['id'])." AS CHAR)=? LIMIT 1");
    $stmt->bind_param('s',$sourceId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();return $r?:null;
}

function pif_sync(string $type='all'): array {
    pif_install_schema();$db=pif_db();$m=pif_source_map();
    $days=max(1,(int)pif_setting('current_days','60'));
    $dateCol=$m['updated'] ?: $m['created'];
    $sql="SELECT * FROM ".pif_q($m['table'])." WHERE ".pif_q($m['email'])." IS NOT NULL AND ".pif_q($m['email'])."<>'' AND ".pif_q($dateCol)." >= DATE_SUB(NOW(),INTERVAL $days DAY) ORDER BY ".pif_q($dateCol)." DESC LIMIT 5000";
    $res=$db->query($sql);$n=0;
    $stmt=$db->prepare("INSERT INTO vsc_pif_control (reminder_type,source_id,order_number,email,next_send_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL ? HOUR)) ON DUPLICATE KEY UPDATE order_number=VALUES(order_number),email=VALUES(email)");
    $delay=max(1,(int)pif_setting('first_delay_hours','24'));
    while($row=$res->fetch_assoc()){
        $email=trim((string)pif_val($row,$m['email'],''));if(!pif_valid_email($email))continue;
        $sid=(string)pif_val($row,$m['id'],'');$ord=(string)pif_val($row,$m['order'],$sid);
        $types=$type==='all'?['payment','label']:[$type];
        foreach($types as $t){
            if($t==='payment' && pif_paid($row,$m)) continue;
            if($t==='payment' && !$m['payment_url']) continue;
            if($t==='label' && (!$m['label_url'] || trim((string)pif_val($row,$m['label_url'],''))==='')) continue;
            $stmt->bind_param('ssssi',$t,$sid,$ord,$email,$delay);$stmt->execute();$n++;
        }
    }
    return ['processed'=>$n,'table'=>$m['table']];
}

function pif_auth_config(): array {
    return pif_load_config([
        PIF_ROOT.'/config/admin_auth.php',
        dirname(PIF_ROOT).'/eyewear-repair/config/admin_auth.php',
        dirname(PIF_ROOT).'/abandoned-cart/config/admin_auth.php',
    ]);
}
function pif_start_session(): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    session_name('VSC_PIF_ADMIN');session_set_cookie_params(['lifetime'=>0,'path'=>'/vsc-services/prescription-in-your-frame/admin/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
}
function pif_csrf(): string { if(empty($_SESSION['pif_csrf']))$_SESSION['pif_csrf']=bin2hex(random_bytes(32));return $_SESSION['pif_csrf']; }
function pif_require_csrf(): void { if(!isset($_POST['csrf'])||!hash_equals(pif_csrf(),(string)$_POST['csrf']))throw new RuntimeException('Invalid security token.'); }

function pif_mail_config(): array {
    return pif_load_config([PIF_ROOT.'/config/zeptomail.php',dirname(PIF_ROOT).'/eyewear-repair/config/zeptomail.php',dirname(PIF_ROOT).'/abandoned-cart/config/zeptomail.php']);
}
function pif_easypost_config(): array {
    return pif_load_config([PIF_ROOT.'/config/easypost.php',dirname(PIF_ROOT).'/eyewear-repair/config/easypost.php']);
}

function pif_tracking_status(array $row,array $m): array {
    $shipment=trim((string)pif_val($row,$m['shipment_id'],''));$tracking=trim((string)pif_val($row,$m['tracking'],''));
    if($shipment===''&&$tracking==='')return ['ok'=>false,'status'=>'unknown','error'=>'No shipment or tracking reference'];
    $cfg=pif_easypost_config();$key=(string)($cfg['api_key']??$cfg['key']??'');if($key==='')return ['ok'=>false,'status'=>'unknown','error'=>'EasyPost key missing'];
    $url=$shipment!==''?'https://api.easypost.com/v2/shipments/'.rawurlencode($shipment):'https://api.easypost.com/v2/trackers?tracking_code='.rawurlencode($tracking);
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_USERPWD=>$key.':',CURLOPT_HTTPAUTH=>CURLAUTH_BASIC]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if(!is_string($body)||$code<200||$code>=300)return ['ok'=>false,'status'=>'unknown','error'=>$err?:"EasyPost HTTP $code"];
    $j=json_decode($body,true);$status=(string)($j['tracker']['status']??$j['trackers'][0]['status']??$j['status']??'unknown');return ['ok'=>true,'status'=>strtolower($status),'error'=>''];
}

function pif_escape(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function pif_unsub_token(string $type,string $sourceId,string $email): string {
    $secret=hash('sha256',MAGENTO_ROOT.'|pif-reminders|'.pif_db()->thread_id);return hash_hmac('sha256',$type.'|'.$sourceId.'|'.strtolower($email),$secret);
}
function pif_unsub_url(string $type,string $sourceId,string $email): string {
    return PIF_BASE_URL.'/public/unsubscribe.php?type='.rawurlencode($type).'&id='.rawurlencode($sourceId).'&email='.rawurlencode($email).'&token='.pif_unsub_token($type,$sourceId,$email);
}

function pif_payment_html(array $r,array $m,string $email): string {
    $name=pif_escape(trim((string)pif_val($r,$m['name'],'Customer'))?:'Customer');$order=pif_escape((string)pif_val($r,$m['order'],''));$url=pif_escape((string)pif_val($r,$m['payment_url'],''));$total=(float)pif_val($r,$m['total'],0);
    $details=[];foreach(['service'=>'Lens service','lens'=>'Lens option','coating'=>'Coating','frame'=>'Your frame'] as $k=>$label){$v=trim((string)pif_val($r,$m[$k],''));if($v!=='')$details[]='<tr><td style="padding:8px;border-bottom:1px solid #eee">'.pif_escape($label).'</td><td style="padding:8px;border-bottom:1px solid #eee">'.pif_escape($v).'</td></tr>';}
    $un=pif_escape(pif_unsub_url('payment',(string)pif_val($r,$m['id'],''),$email));
    return '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#172033"><img src="https://www.visionspecialists.org/media/logo/stores/1/vsc_logo.jpg" style="max-width:220px;display:block;margin:20px auto"><h2>Payment reminder for your prescription lens order</h2><p>Dear '.$name.',</p><p>This is a friendly reminder that payment for prescription lenses to be installed in your own frame, order <strong>#'.$order.'</strong>, is still pending.</p>'.($details?'<table style="width:100%;border-collapse:collapse;margin:20px 0">'.implode('',$details).'</table>':'').'<div style="background:#f7f9fb;padding:18px;border:1px solid #dde3ea;border-radius:8px"><small>Amount due</small><div style="font-size:28px;font-weight:bold">$'.number_format($total,2).'</div></div><p style="text-align:center;margin:24px"><a href="'.$url.'" style="background:#146c38;color:white;padding:14px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Pay Lens Order Now</a></p><p>Once payment is completed, reminders stop automatically.</p><p>Kind regards,<br><strong>Rossi</strong><br>Customer Care Executive<br>Vision Specialists<br>1-818-305-4023</p><p style="font-size:11px;text-align:center"><a href="'.$un.'">Unsubscribe / stop these reminders</a></p></div>';
}

function pif_label_html(array $r,array $m,string $email): string {
    $name=pif_escape(trim((string)pif_val($r,$m['name'],'Customer'))?:'Customer');$order=pif_escape((string)pif_val($r,$m['order'],''));$url=pif_escape((string)pif_val($r,$m['label_url'],''));$notes=trim((string)pif_val($r,$m['notes'],''));$tracking=trim((string)pif_val($r,$m['tracking'],''));$un=pif_escape(pif_unsub_url('label',(string)pif_val($r,$m['id'],''),$email));
    return '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#172033"><img src="https://www.visionspecialists.org/media/logo/stores/1/vsc_logo.jpg" style="max-width:220px;display:block;margin:20px auto"><h2>Please send us your frame for prescription lens installation</h2><p>Dear '.$name.',</p><p>As a courtesy, we provided a <strong>FREE prepaid shipping label</strong> so you can send your frame to us for your prescription lens order <strong>#'.$order.'</strong>.</p>'.($notes!==''?'<div style="margin:18px 0"><strong>Your Order Notes:</strong><div style="background:#f3f4f6;padding:10px;border-radius:5px;margin-top:6px">'.nl2br(pif_escape($notes)).'</div></div>':'').'<p>The prepaid label is attached to this email. You can also use the button below.</p><p style="text-align:center;margin:24px"><a href="'.$url.'" style="background:#146c38;color:white;padding:14px 24px;border-radius:6px;text-decoration:none;font-weight:bold">View or Download Your Shipping Label</a></p>'.($tracking!==''?'<p><strong>Tracking number:</strong> '.pif_escape($tracking).'</p>':'').'<p>Please pack your frame securely, attach the label, and request an acceptance scan when dropping off the package. Reminders stop automatically once EasyPost reports the package in transit.</p><p>Kind regards,<br><strong>Rossi</strong><br>Customer Care Executive<br>Vision Specialists<br>1-818-305-4023</p><p style="font-size:11px;text-align:center"><a href="'.$un.'">Unsubscribe / stop these reminders</a></p></div>';
}

function pif_prepare_attachment(string $url,string $order): array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'Vision Specialists Reminder']);$bin=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$ctype=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$err=curl_error($ch);curl_close($ch);
    if(!is_string($bin)||$bin===''||$code<200||$code>=300)throw new RuntimeException('Label attachment download failed: '.($err?:"HTTP $code"));
    if(stripos($ctype,'text/html')!==false)throw new RuntimeException('Label URL returned HTML instead of a label file.');
    $mime='application/pdf';$ext='pdf';if(stripos($ctype,'png')!==false){$mime='image/png';$ext='png';}elseif(stripos($ctype,'jpeg')!==false||stripos($ctype,'jpg')!==false){$mime='image/jpeg';$ext='jpg';}
    return ['data'=>$bin,'mime'=>$mime,'name'=>'FREE-Prepaid-Shipping-Label-'.preg_replace('/[^A-Za-z0-9_-]/','',$order).'.'.$ext];
}

function pif_send_mail(string $to,string $subject,string $html,?array $attachment=null): void {
    require_once MAGENTO_ROOT.'/vendor/autoload.php';
    $cfg=pif_mail_config();$mail=new PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Encoding='base64';$mail->Host=(string)($cfg['host']??'smtp.zeptomail.com');$mail->SMTPAuth=true;$mail->Username=(string)($cfg['username']??'emailapikey');$mail->Password=(string)($cfg['password']??'');$mail->SMTPSecure=(string)($cfg['encryption']??'tls');$mail->Port=(int)($cfg['port']??587);$mail->setFrom((string)($cfg['from_email']??'support@visionspecialists.org'),(string)($cfg['from_name']??'Vision Specialists'));$mail->addAddress($to);$mail->isHTML(true);$mail->Subject=$subject;$mail->Body=$html;$mail->AltBody=trim(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html)));
    if($attachment)$mail->addStringAttachment($attachment['data'],$attachment['name'],'base64',$attachment['mime']);
    $mail->send();
}

function pif_log(string $type,string $sid,string $order,string $email,string $action,string $result,string $message): void {
    $stmt=pif_db()->prepare("INSERT INTO vsc_pif_log(reminder_type,source_id,order_number,email,action,result,message) VALUES(?,?,?,?,?,?,?)");$stmt->bind_param('sssssss',$type,$sid,$order,$email,$action,$result,$message);$stmt->execute();
}

function pif_send_due(string $type,bool $dry=false,bool $force=false): array {
    pif_install_schema();pif_sync($type);$db=pif_db();$m=pif_source_map();
    if(!$force && (pif_setting('system_enabled','0')!=='1'||pif_setting($type.'_enabled','0')!=='1'))return ['sent'=>0,'skipped'=>0,'message'=>'System or reminder type disabled'];
    $limit=max(1,(int)pif_setting('batch_limit','25'));$sql="SELECT * FROM vsc_pif_control WHERE reminder_type=? AND enabled=1 AND opted_out=0 AND (next_send_at IS NULL OR next_send_at<=NOW()) ORDER BY next_send_at ASC LIMIT $limit";$stmt=$db->prepare($sql);$stmt->bind_param('s',$type);$stmt->execute();$res=$stmt->get_result();$sent=0;$skip=0;
    while($c=$res->fetch_assoc()){
        $sid=(string)$c['source_id'];$row=pif_fetch_source($sid);if(!$row){$skip++;continue;}$email=trim((string)pif_val($row,$m['email'],''));$order=(string)pif_val($row,$m['order'],$sid);
        try{
            if(!pif_valid_email($email))throw new RuntimeException('Invalid email');
            if($type==='payment'&&pif_paid($row,$m)){ $db->query("UPDATE vsc_pif_control SET enabled=0,stopped_reason='Paid' WHERE id=".(int)$c['id']);$skip++;continue; }
            $attachment=null;
            if($type==='label'){
                $track=pif_tracking_status($row,$m);$status=$track['status'];$up=$db->prepare("UPDATE vsc_pif_control SET tracking_status=?,tracking_checked_at=NOW() WHERE id=?");$id=(int)$c['id'];$up->bind_param('si',$status,$id);$up->execute();
                if(!$track['ok'])throw new RuntimeException('Tracking check failed; no email sent: '.$track['error']);
                if(pif_label_stop_status($status)){ $db->query("UPDATE vsc_pif_control SET enabled=0,stopped_reason='Tracking: ".$db->real_escape_string($status)."' WHERE id=".$id);$skip++;continue; }
                if(!in_array($status,['pre_transit','unknown'],true))throw new RuntimeException('Tracking status not eligible: '.$status);
                $attachment=pif_prepare_attachment((string)pif_val($row,$m['label_url'],''),$order);
            }
            $html=$type==='payment'?pif_payment_html($row,$m,$email):pif_label_html($row,$m,$email);$subject=$type==='payment'?'Payment reminder - prescription lenses for your frame':'Please send your frame - prepaid label attached';
            if(!$dry)pif_send_mail($email,$subject,$html,$attachment);
            if(!$dry){$hours=max(1,(int)pif_setting('repeat_hours','24'));$u=$db->prepare("UPDATE vsc_pif_control SET sent_count=sent_count+1,last_sent_at=NOW(),next_send_at=DATE_ADD(NOW(),INTERVAL ? HOUR) WHERE id=?");$id=(int)$c['id'];$u->bind_param('ii',$hours,$id);$u->execute();pif_log($type,$sid,$order,$email,'send','success','Email sent'.($attachment?' with attached label':''));}$sent++;
        }catch(Throwable $e){$skip++;pif_log($type,$sid,$order,$email,'send','error',$e->getMessage());}
    }
    return ['sent'=>$sent,'skipped'=>$skip,'message'=>$dry?'Dry run completed':'Run completed'];
}
PHP;

$files['cron/install.php'] = <<<'PHP'
<?php
require __DIR__.'/../includes/common.php';
try { pif_install_schema(); $r=pif_sync('all'); echo "Prescription In Your Frame reminder system installed.\n"; echo "Source table: {$r['table']}\n"; echo "Records processed: {$r['processed']}\n"; echo "All systems remain DISABLED.\n"; } catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
PHP;

$files['cron/payment_reminder_cron.php'] = <<<'PHP'
<?php
require __DIR__.'/../includes/common.php';
$dry=in_array('--dry-run',$argv??[],true);$force=in_array('--force',$argv??[],true);$r=pif_send_due('payment',$dry,$force);echo json_encode($r,JSON_PRETTY_PRINT).PHP_EOL;
PHP;
$files['cron/label_reminder_cron.php'] = <<<'PHP'
<?php
require __DIR__.'/../includes/common.php';
$dry=in_array('--dry-run',$argv??[],true);$force=in_array('--force',$argv??[],true);$r=pif_send_due('label',$dry,$force);echo json_encode($r,JSON_PRETTY_PRINT).PHP_EOL;
PHP;

$files['public/unsubscribe.php'] = <<<'PHP'
<?php
require __DIR__.'/../includes/common.php';
pif_install_schema();$type=(string)($_GET['type']??'');$id=(string)($_GET['id']??'');$email=(string)($_GET['email']??'');$token=(string)($_GET['token']??'');$ok=in_array($type,['payment','label'],true)&&$id!==''&&pif_valid_email($email)&&hash_equals(pif_unsub_token($type,$id,$email),$token);if($ok){$s=pif_db()->prepare("UPDATE vsc_pif_control SET opted_out=1,enabled=0,stopped_reason='Customer unsubscribed' WHERE reminder_type=? AND source_id=? AND LOWER(email)=LOWER(?)");$s->bind_param('sss',$type,$id,$email);$s->execute();}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Reminder preferences</title><style>body{font-family:Arial;background:#f5f7fa;padding:40px}.box{max-width:620px;margin:auto;background:#fff;padding:30px;border-radius:12px}</style></head><body><div class="box"><h2><?= $ok?'Reminders stopped':'Invalid or expired link' ?></h2><p><?= $ok?'No further reminders will be sent for this order.':'The unsubscribe link could not be validated.' ?></p></div></body></html>
PHP;

$files['admin/index.php'] = <<<'PHP'
<?php
require __DIR__.'/../includes/common.php';pif_install_schema();pif_start_session();$msg='';$err='';
if(isset($_GET['logout'])){session_destroy();header('Location: ./');exit;}
if(empty($_SESSION['pif_logged_in'])){
    if($_SERVER['REQUEST_METHOD']==='POST'){$a=pif_auth_config();$u=(string)($_POST['username']??'');$p=(string)($_POST['password']??'');$ok=hash_equals((string)($a['username']??'joy'),$u)&&isset($a['password_hash'])&&password_verify($p,(string)$a['password_hash']);if($ok){$_SESSION['pif_logged_in']=1;header('Location: ./');exit;}$err='Invalid username or password.';}
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Prescription Reminder Login</title><style>body{font-family:Arial;background:#f2f5f8;padding:50px}.card{max-width:420px;margin:auto;background:#fff;padding:28px;border-radius:12px}input,button{width:100%;padding:12px;margin:8px 0;box-sizing:border-box}button{background:#146c38;color:#fff;border:0;border-radius:6px;font-weight:bold}.e{color:#b91c1c}</style></head><body><div class="card"><h2>Prescription Reminder Admin</h2><?php if($err):?><p class="e"><?=pif_escape($err)?></p><?php endif?><form method="post"><input name="username" placeholder="Username" required><input type="password" name="password" placeholder="Password" required><button>Sign in</button></form></div></body></html><?php exit;
}
$tab=(string)($_GET['tab']??'overview');
try{
if($_SERVER['REQUEST_METHOD']==='POST'){pif_require_csrf();$act=(string)($_POST['action']??'');
 if($act==='save_settings'){foreach(['system_enabled','payment_enabled','label_enabled'] as $k)pif_set_setting($k,isset($_POST[$k])?'1':'0');foreach(['first_delay_hours','repeat_hours','current_days','batch_limit'] as $k)pif_set_setting($k,(string)max(1,(int)($_POST[$k]??1)));$msg='Settings saved.';}
 elseif($act==='sync'){$r=pif_sync('all');$msg='Sync completed: '.$r['processed'].' records processed.';}
 elseif(in_array($act,['enable','disable'],true)){foreach((array)($_POST['ids']??[]) as $id){$v=$act==='enable'?1:0;$s=pif_db()->prepare("UPDATE vsc_pif_control SET enabled=?,stopped_reason=? WHERE id=?");$reason=$v?'':'Disabled by admin';$ii=(int)$id;$s->bind_param('isi',$v,$reason,$ii);$s->execute();}$msg='Selected records updated.';}
 elseif($act==='test'){ $type=(string)($_POST['type']??'payment');$to=trim((string)($_POST['email']??''));if(!pif_valid_email($to))throw new RuntimeException('Enter a valid test email.');pif_sync($type);$s=pif_db()->prepare("SELECT source_id FROM vsc_pif_control WHERE reminder_type=? ORDER BY id DESC LIMIT 1");$s->bind_param('s',$type);$s->execute();$x=$s->get_result()->fetch_assoc();if(!$x)throw new RuntimeException('No eligible source record found for this test.');$m=pif_source_map();$r=pif_fetch_source((string)$x['source_id']);$attachment=null;if($type==='label')$attachment=pif_prepare_attachment((string)pif_val($r,$m['label_url'],''),(string)pif_val($r,$m['order'],''));$html=$type==='payment'?pif_payment_html($r,$m,$to):pif_label_html($r,$m,$to);pif_send_mail($to,$type==='payment'?'Payment reminder - prescription lenses for your frame':'Please send your frame - prepaid label attached',$html,$attachment);$msg='Test email sent'.($attachment?' with label attachment':'').'.'; }
}
}catch(Throwable $e){$err=$e->getMessage();}
$counts=[];foreach(['payment','label'] as $t){$s=pif_db()->prepare("SELECT COUNT(*) c FROM vsc_pif_control WHERE reminder_type=?");$s->bind_param('s',$t);$s->execute();$counts[$t]=(int)$s->get_result()->fetch_assoc()['c'];}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Prescription In Your Frame Reminder Admin</title><style>body{margin:0;font-family:Arial;background:#f4f6f8;color:#111827}.top{background:#111827;color:white;padding:18px 28px;display:flex;justify-content:space-between}.wrap{max-width:1450px;margin:24px auto;padding:0 20px}.tabs a{display:inline-block;padding:11px 15px;background:#fff;border:1px solid #d8dee7;text-decoration:none;color:#111827;margin:0 5px 10px 0;border-radius:7px}.tabs a.on{background:#111827;color:#fff}.card{background:#fff;border:1px solid #dfe4ea;border-radius:10px;padding:20px;margin:15px 0}.ok{background:#dcfce7;padding:12px}.bad{background:#fee2e2;padding:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.stat{background:#effaf3;padding:18px;border-radius:8px}.btn{padding:10px 14px;border:0;border-radius:6px;font-weight:bold;cursor:pointer}.green{background:#15803d;color:white}.red{background:#b91c1c;color:white}.blue{background:#1d4ed8;color:white}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}input,select{padding:9px;border:1px solid #cbd5e1;border-radius:5px}label{display:block;margin:10px 0}</style></head><body><div class="top"><strong>Prescription In Your Frame Reminder Admin</strong><a style="color:white" href="?logout=1">Log out</a></div><div class="wrap"><div class="tabs"><?php foreach(['overview'=>'Overview','payment'=>'Payment reminders','label'=>'Label reminders','test'=>'Test email','logs'=>'Email logs','settings'=>'Settings'] as $k=>$v):?><a class="<?=$tab===$k?'on':''?>" href="?tab=<?=$k?>"><?=$v?></a><?php endforeach?></div><?php if($msg):?><div class="ok"><?=pif_escape($msg)?></div><?php endif?><?php if($err):?><div class="bad"><?=pif_escape($err)?></div><?php endif?>
<?php if($tab==='overview'):?><div class="grid"><div class="stat"><b>System</b><br><?=pif_setting('system_enabled','0')==='1'?'ENABLED':'DISABLED'?></div><div class="stat"><b>Payment records</b><br><?=$counts['payment']?></div><div class="stat"><b>Label records</b><br><?=$counts['label']?></div><div class="stat"><b>Source table</b><br><?=pif_escape(pif_source_map()['table'])?></div></div><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=pif_csrf()?>"><input type="hidden" name="action" value="sync"><button class="btn blue">Sync current records now</button></form></div>
<?php elseif(in_array($tab,['payment','label'],true)):$t=$tab;$s=pif_db()->prepare("SELECT * FROM vsc_pif_control WHERE reminder_type=? ORDER BY id DESC LIMIT 500");$s->bind_param('s',$t);$s->execute();$res=$s->get_result();?><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=pif_csrf()?>"><button name="action" value="enable" class="btn green">Enable selected</button> <button name="action" value="disable" class="btn red">Disable selected</button><table><tr><th></th><th>Order</th><th>Email</th><th>Sent</th><th>Last sent</th><th>Next send</th><th>Tracking</th><th>State</th></tr><?php while($r=$res->fetch_assoc()):?><tr><td><input type="checkbox" name="ids[]" value="<?=(int)$r['id']?>"></td><td><?=pif_escape($r['order_number'])?></td><td><?=pif_escape($r['email'])?></td><td><?=(int)$r['sent_count']?></td><td><?=pif_escape((string)$r['last_sent_at'])?></td><td><?=pif_escape((string)$r['next_send_at'])?></td><td><?=pif_escape((string)$r['tracking_status'])?></td><td><?=((int)$r['enabled']===1&&(int)$r['opted_out']===0)?'Active':pif_escape((string)$r['stopped_reason'])?></td></tr><?php endwhile?></table></form></div>
<?php elseif($tab==='test'):?><div class="card"><h2>Send customer-style test email</h2><form method="post"><input type="hidden" name="csrf" value="<?=pif_csrf()?>"><input type="hidden" name="action" value="test"><label>Template <select name="type"><option value="payment">Payment reminder</option><option value="label">Shipping-label reminder</option></select></label><label>Recipient email <input type="email" name="email" required></label><button class="btn blue">Send test email</button></form></div>
<?php elseif($tab==='logs'):$res=pif_db()->query("SELECT * FROM vsc_pif_log ORDER BY id DESC LIMIT 500");?><div class="card"><table><tr><th>Date</th><th>Type</th><th>Order</th><th>Email</th><th>Result</th><th>Message</th></tr><?php while($r=$res->fetch_assoc()):?><tr><td><?=pif_escape($r['created_at'])?></td><td><?=pif_escape($r['reminder_type'])?></td><td><?=pif_escape((string)$r['order_number'])?></td><td><?=pif_escape((string)$r['email'])?></td><td><?=pif_escape($r['result'])?></td><td><?=pif_escape((string)$r['message'])?></td></tr><?php endwhile?></table></div>
<?php else:?><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=pif_csrf()?>"><input type="hidden" name="action" value="save_settings"><label><input type="checkbox" name="system_enabled" <?=pif_setting('system_enabled')==='1'?'checked':''?>> Entire system enabled</label><label><input type="checkbox" name="payment_enabled" <?=pif_setting('payment_enabled')==='1'?'checked':''?>> Payment reminders enabled</label><label><input type="checkbox" name="label_enabled" <?=pif_setting('label_enabled')==='1'?'checked':''?>> Label reminders enabled</label><label>First reminder hours <input type="number" name="first_delay_hours" value="<?=pif_escape(pif_setting('first_delay_hours','24'))?>"></label><label>Repeat every hours <input type="number" name="repeat_hours" value="<?=pif_escape(pif_setting('repeat_hours','24'))?>"></label><label>Current data days <input type="number" name="current_days" value="<?=pif_escape(pif_setting('current_days','60'))?>"></label><label>Maximum per cron run <input type="number" name="batch_limit" value="<?=pif_escape(pif_setting('batch_limit','25'))?>"></label><button class="btn green">Save settings</button></form></div><?php endif?></div></body></html>
PHP;

$files['.htaccess'] = "Options -Indexes\n";
$files['config/.htaccess'] = "Require all denied\n";
$files['cron/.htaccess'] = "Require all denied\n";
$files['includes/.htaccess'] = "Require all denied\n";
$files['logs/.htaccess'] = "Require all denied\n";
$files['storage/.htaccess'] = "Require all denied\n";
$files['public/.htaccess'] = "Options -Indexes\n";
$files['logs/.keep'] = '';
$files['storage/.keep'] = '';
$files['README.txt'] = <<<'TXT'
VISION SPECIALISTS - PRESCRIPTION IN YOUR FRAME REMINDERS

Install path:
/home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/

Admin URL:
https://www.visionspecialists.org/vsc-services/prescription-in-your-frame/admin/

Uses the same admin_auth.php, database.php, zeptomail.php and easypost.php from the sibling eyewear-repair system when present.

Install:
/usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/cron/install.php

Dry runs:
/usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/cron/payment_reminder_cron.php --dry-run --force
/usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/cron/label_reminder_cron.php --dry-run --force

Keep these commented until approval:
#*/30 * * * * /usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/cron/payment_reminder_cron.php >> /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/logs/payment_cron.log 2>&1
#*/30 * * * * /usr/bin/php /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/cron/label_reminder_cron.php >> /home/www/15-9-24/magento2/vsc-services/prescription-in-your-frame/logs/label_cron.log 2>&1
TXT;

foreach ($files as $rel => $content) {
    $path = $root . '/' . $rel;
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 02775, true);
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        fwrite(STDERR, "Failed writing $path\n");
        exit(1);
    }
}

@chmod($root, 02775);
foreach ($dirs as $dir) @chmod($root . '/' . $dir, 02775);
foreach (array_keys($files) as $rel) @chmod($root . '/' . $rel, str_ends_with($rel,'.php') ? 0664 : 0664);

echo "Created Prescription In Your Frame reminder system at:\n$root\n\n";
echo "Next run:\n/usr/bin/php $root/cron/install.php\n";
echo "The system starts DISABLED.\n";
