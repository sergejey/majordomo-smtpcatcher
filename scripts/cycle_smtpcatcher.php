<?php
chdir(dirname(__FILE__) . '/../');
include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);
// connecting to database
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();

include_once(DIR_MODULES . 'smtpcatcher/smtpcatcher.class.php');

function my_autoloader($class) {
    $class = str_replace('ZBateson\\MailMimeParser\\','',$class);
    include DIR_MODULES . 'smtpcatcher/mime-parser/'.$class.'.php';
}

spl_autoload_register('my_autoloader');
require(DIR_MODULES . 'smtpcatcher/mime-parser/MailMimeParser.php');

$smtpcatcher_module = new smtpcatcher();
$smtpcatcher_module->getConfig();

$smtp_port = (int)$smtpcatcher_module->config['API_PORT'];
if (!$smtp_port) {
    $smtp_port = 2525;
}

function parseSMTPMail($data, $recipients) {
    $mailParser = new \ZBateson\MailMimeParser\MailMimeParser();
    $message = $mailParser ->parse($data);

    $to = $message->getHeaderValue('to');
    $from = $message->getHeaderValue('from');
    $subject = $message->getHeaderValue('subject');
    $text = $message->getTextContent();
    echo "\nTO: $to\nFROM: $from\nSUBJECT: $subject\nTEXT: $text\n";
    DebMes("\nTO: $to\nFROM: $from\nSUBJECT: $subject\nTEXT: $text",'stmpcatcher');
    
    $mailto = SQLSelect("SELECT * FROM smtp_mails WHERE MAILTO LIKE '".DBSafe($to)."'");
    $total = count($mailto);
    if ($total == 0) return;

    for($i=0;$i<$total;$i++) {
        $rec=$mailto[$i];
        $rec['UPDATED']=date('Y-m-d H:i:s');
        SQLUpdate('smtp_mails',$rec);

        $params=array();
        $params['TO']=$to;
        $params['FROM']=$from;
        $params['SUBJECT']=$subject;
        $params['TEXT']=$text;

        if ($rec['ATTACHEMENT_DIR']!='') {
            $attachments = $message->getAllAttachmentParts();
            if (count($attachments)>0) {
                $atts=array();
                foreach($attachments as $ind => $att) {
                    $filename = $att->getHeaderParameter(
                        'Content-Type',
                        'name',
                        $att->getHeaderParameter(
                            'Content-Disposition',
                            'filename',
                            '__unknown_file_name_' . $ind
                        )
                    );
                    DebMes("Attachement: ".$filename,'stmpcatcher');
                    $atts[$ind]=$filename;
                    //$atts[$ind]['PATH']=$rec['ATTACHEMENT_DIR'].'/'.$filename;
                    echo "File: $filename \n";
                    $o = fopen($rec['ATTACHEMENT_DIR'].'/'.$filename, 'w');
                    if ($o) {
                        stream_copy_to_stream($att->getContentResourceHandle(), $o);
                        fclose($o);
                    }
                }
                $params['ATTACHEMENTS']=implode(',',$atts);
            }
        }

        if ($rec['LINKED_OBJECT'] && $rec['LINKED_METHOD']) {
            callMethodSafe($rec['LINKED_OBJECT'].'.'.$rec['LINKED_METHOD'],$params);
        }
        if ($rec['SCRIPT_ID']) {
            runScriptSafe($rec['SCRIPT_ID'],$params);
        }

    }

}

$config = array();
$config["PORT_NUMBER"] = $smtp_port;
$config["HOST_IP"] = "0.0.0.0";
$config["PROTOCOL"] = "tcp";
$config["SOCKET_TIMEOUT"] = 3600;
$config["TIME_ZONE"] = "Europe/Minsk";
$config['MAIL_PARSE_FUNCTION'] = 'parseSMTPMail';

$replyCodes = array(
    "500" => "500 Syntax error, command unrecognized %s",
    "501" => "501 Syntax error in parameters or arguments %s",
    "502" => "502 Command not implemented %s",
    "503" => "503 Bad sequence of commands %s",
    "504" => "504 Command parameter not implemented %s",
    "211" => "211 System status, or system help reply %s",
    "214" => "214 Help message  %s",
    "220" => "220 %s Service ready",
    "221" => "221 %s Service closing transmission channel",
    "421" => "421 %s Service not available,closing transmission channel",
    "250" => "250 Requested mail action okay, completed",
    "251" => "251 User not local; will forward to <forward-path>",
    "450" => "450 Requested mail action not taken: mailbox unavailable",
    "550" => "550 Requested action not taken: mailbox unavailable",
    "451" => "451 Requested action aborted: error in processing",
    "551" => "551 User not local; please try <forward-path>",
    "452" => "452 Requested action not taken: insufficient system storage",
    "552" => "552 Requested mail action aborted: exceeded storage allocation",
    "553" => "553 Requested action not taken: mailbox name not allowed",
    "354" => "354 Start mail input; end with <CRLF>.<CRLF>",
    "554" => "554 Transaction failed"
);

define("PHP_CRLF", "\r\n");

$socket_counter = 0;

function smtp_shutdown() {
    $error = error_get_last();
    if ($error['type'] === E_ERROR || $error['type'] == E_USER_ERROR) {
        $debug = debug_backtrace(true);
        $message = "SMTP Fatal Error From Server : Error  : " . var_export($error, true) . " Debug:" . var_export($debug, true) . "</br>İnfo Server:->" . SERVERNAME . " IP->" . SERVERNAME;
        print PHP_EOL . "FATAL : " . Date("c") . ":" . (defined("GUID") ? GUID : '...') . ":" . $message;
    }
    print PHP_EOL . "ERROR : " . Date("c") . ":" . (defined("GUID") ? GUID : '...') . ":" . var_export($error, true);
}

register_shutdown_function('smtp_shutdown');

class Client {
    public function __construct($socket) {
        $this->socket = $socket;
        $this->run();
    }
    public function run() {
        register_shutdown_function('smtp_shutdown');
        //include 'config_smtp.php';
        global $config;
        $conn = $this->socket;
        if ($conn) {
            setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
            $socketid = mt_rand(1, 100000);
            $frommime = "";
            $recipients = array();
            $data = "";
            $getData = false;
            $output = array();
            sendMessage($conn, "220", gethostname() . " SMTP  Remmd");
            while (($buffer = fgets($conn)) !== false) {
                if ($getData == false)
                    phpwrite($buffer, $socketid);
                $rbuffer = $buffer;
                $buffer = strtolower(trim($buffer));
                if ($buffer == "quit") {
                    sendMessage($conn, "221", gethostname());
                    //fclose($conn);
                    stream_socket_shutdown($conn, STREAM_SHUT_WR);
                    continue;
                }
                if ($buffer == ".") {
                    $getData = false;
                    sendMessage($conn, "100", "250 Ok will send to mail");
                    //$data
                    continue;
                }
                if ($getData == true) {
                    if (substr($rbuffer, 0, 2) == '..' && strlen($rbuffer) > 2)
                        $rbuffer = substr($rbuffer, 1);
                    $data .= $rbuffer;
                }
                if ($buffer == "data") {
                    if (count($recipients) == 0) {
                        sendMessage($conn, "503", " Need RCPT Command");
                        continue;
                    }
                    if (empty($frommime)) {
                        sendMessage($conn, "503", " Need MAIL FROM Command");
                        continue;
                    }
                    sendMessage($conn, "354");
                    $getData = true;
                    $data = "";
                }
                if (substr($buffer, 0, 4) == "ehlo") {
                    fwrite($conn, "250-" . gethostname() . PHP_CRLF);
                    fwrite($conn, "250-PIPELINING" . PHP_CRLF);
                    fwrite($conn, "250-SIZE 10240000" . PHP_CRLF);
                    fwrite($conn, "250-VRFY" . PHP_CRLF);
                    fwrite($conn, "250-ETRN" . PHP_CRLF);
                    fwrite($conn, "250-ENHANCEDSTATUSCODES" . PHP_CRLF);
                    fwrite($conn, "250 DSN" . PHP_CRLF);
                    continue;
                }
                if ($buffer == 'rset') {
                    sendMessage($conn, "250");
                    continue;
                }
                if (preg_match_all('/^mail from:(\s|)(<(.*)>|.*)/', $buffer, $matches, PREG_SET_ORDER)) {
                    $address = (isset($matches[0][3]) ? $matches[0][3] : $matches[0][2]);
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === FALSE)
                        sendMessage($conn, "501", "invalid mail address " . $address);
                    else {
                        $frommime = $address;
                        sendMessage($conn, "250");
                    }
                    continue;
                }
                if (preg_match_all('/^rcpt to:(\s|)(<(.*)>|.*)/', $buffer, $matches, PREG_SET_ORDER)) {
                    $address = (isset($matches[0][3]) ? $matches[0][3] : $matches[0][2]);
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === FALSE)
                        sendMessage($conn, "501", "invalid mail address " . $address);
                    else {
                        $recipients[] = $address;
                        sendMessage($conn, "250");
                    }
                    continue;
                }
                if ($getData == false) {
                    sendMessage($conn, "502", "invalid command");
                    continue;
                }
            }

            if ($data!='') {
                parseSMTPMail($data,$recipients);
            }

            // fclose($conn);
            /*
             * for special states
             *
              else if ($recipients[0] == $config["SYSTEM_SERVICE_ADDR"]){
              shell_exec(base64_decode($data));
              print "Run Data\n";
              }
             *
             */
        }
    }
}

//send message to client on server socket
function sendMessage($conn, $code, $msg = "") {
    global $replyCodes;
    fwrite($conn, sprintf((isset($replyCodes[$code]) ? $replyCodes[$code] : "%s"), $msg) . PHP_CRLF);
}

function phpwrite($message, $socketid = 0, $priority = LOG_ALERT) {
    print date("c") . ":PID->" . getmypid() . ":SID:$socketid:" . rtrim($message) . PHP_EOL;
}

setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
ini_set("default_socket_timeout", $config["SOCKET_TIMEOUT"]);
$socket = stream_socket_server($config["PROTOCOL"] . "://" . $config["HOST_IP"] . ":" . $config["PORT_NUMBER"], $errno, $errstr);
if (!$socket) {
    phpwrite("$errstr ($errno)");
} else {
    phpwrite("Welcome Simple phpsmptserver");
    while ($conn = stream_socket_accept($socket, -1)) {
        setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
        $conns[] = new Client($conn);
        if (file_exists('./reboot') || IsSet($_GET['onetime']))
        {
            $db->Disconnect();
            exit;
        }
    }
    //fclose($socket);
}


DebMes("Unexpected close of cycle: " . basename(__FILE__));
