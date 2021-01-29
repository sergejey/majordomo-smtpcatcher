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

function my_autoloader($class)
{
    $class = str_replace('ZBateson\\MailMimeParser\\', '', $class);
    $class = str_replace('\\', '/', $class);
    include DIR_MODULES . 'smtpcatcher/mime-parser/' . $class . '.php';
}

spl_autoload_register('my_autoloader');
require(DIR_MODULES . 'smtpcatcher/mime-parser/MailMimeParser.php');

$smtpcatcher_module = new smtpcatcher();
$smtpcatcher_module->getConfig();

$smtp_port = (int)$smtpcatcher_module->config['API_PORT'];
if (!$smtp_port) {
    $smtp_port = 2525;
}

function parseSMTPMail($data, $recipients)
{
    $mailParser = new \ZBateson\MailMimeParser\MailMimeParser();
    $message = $mailParser->parse($data);

    $to = trim($message->getHeaderValue('to'));
    if (!$to && $recipients[0]) {
        $to = trim($recipients[0]);
    }
    $from = trim($message->getHeaderValue('from'));
    $subject = trim($message->getHeaderValue('subject'));
    $text = $message->getTextContent();
    echo "\nTO: $to\nFROM: $from\nSUBJECT: $subject\nTEXT: $text\n";
    DebMes("\nTO: $to\nFROM: $from\nSUBJECT: $subject\nTEXT: $text", 'stmpcatcher');

    $mailto = SQLSelect("SELECT * FROM smtp_mails WHERE MAILTO LIKE '" . DBSafe($to) . "'");
    $total = count($mailto);
    if ($total == 0) {
        $rec = array();
        $rec['MAILTO'] = $to;
        $rec['TITLE'] = $rec['MAILTO'];
        $rec['ID'] = SQLInsert('smtp_mails', $rec);
        $mailto[] = $rec;
        $total = 1;
    }

    for ($i = 0; $i < $total; $i++) {
        $rec = $mailto[$i];
        $rec['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('smtp_mails', $rec);

        $params = array();
        $params['TO'] = $to;
        $params['FROM'] = $from;
        $params['SUBJECT'] = $subject;
        $params['TEXT'] = $text;

        if ($rec['ATTACHEMENT_DIR'] != '') {
            $attachments = $message->getAllAttachmentParts();
            if (count($attachments) > 0) {
                $atts = array();
                foreach ($attachments as $ind => $att) {
                    $filename = $att->getHeaderParameter(
                        'Content-Type',
                        'name',
                        $att->getHeaderParameter(
                            'Content-Disposition',
                            'filename',
                            '__unknown_file_name_' . $ind
                        )
                    );
                    DebMes("Attachement: " . $filename, 'stmpcatcher');
                    $atts[$ind] = $filename;
                    //$atts[$ind]['PATH']=$rec['ATTACHEMENT_DIR'].'/'.$filename;
                    echo "File: $filename \n";
                    $o = fopen($rec['ATTACHEMENT_DIR'] . '/' . $filename, 'w');
                    if ($o) {
                        stream_copy_to_stream($att->getContentResourceHandle(), $o);
                        fclose($o);
                    }
                }
                $params['ATTACHEMENTS'] = implode(',', $atts);
            }
        }

        if ($rec['LINKED_OBJECT'] && $rec['LINKED_METHOD']) {
            callMethodSafe($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD'], $params);
        }
        if ($rec['SCRIPT_ID']) {
            runScriptSafe($rec['SCRIPT_ID'], $params);
        }

    }

}

$config = array();
$config["PORT_NUMBER"] = $smtp_port;
$config["HOST_IP"] = "0.0.0.0";
$config["PROTOCOL"] = "tcp";
$config["SOCKET_TIMEOUT"] = 3600;
$config["TIME_ZONE"] = "Europe/Minsk";

define("PHP_CRLF", "\r\n");

$socket_counter = 0;

class Client
{
    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->run();
    }

    public function run()
    {
        $conn = $this->socket;
        if ($conn) {
            setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
            $socketid = mt_rand(1, 100000);
            $frommime = "";
            $recipients = array();
            $data = "";
            $getData = false;
            replyToClient($conn, '220 localhost smtpcatcher ready');
            while (($buffer = fgets($conn)) !== false) {
                if ($getData == false) phpwrite($buffer, $socketid);
                $rbuffer = $buffer;
                $buffer = strtolower(trim($buffer));
                if ($buffer == "quit") {
                    replyToClient($conn, '221 See you later aligator');
                    stream_socket_shutdown($conn, STREAM_SHUT_WR);
                    continue;
                }
                if ($buffer == ".") {
                    $getData = false;
                    replyToClient($conn, '250 Mail accepted');
                    continue;
                }
                if ($getData == true) {
                    if (substr($rbuffer, 0, 2) == '..' && strlen($rbuffer) > 2)
                        $rbuffer = substr($rbuffer, 1);
                    $data .= $rbuffer;
                }
                if ($buffer == "data") {
                    if (count($recipients) == 0) {
                        replyToClient($conn, '503 Need RCPT Command');
                        continue;
                    }
                    if (empty($frommime)) {
                        replyToClient($conn, '503 Need MAIL FROM Command');
                        continue;
                    }
                    //sendMessage($conn, "354");
                    replyToClient($conn, '354 End message with period');
                    $getData = true;
                    $data = "";
                    continue;
                }
                if (substr($buffer, 0, 4) == "ehlo" || substr($buffer, 0, 4) == "helo") {
                    replyToClient($conn, '250 Nice to meet you');
                    continue;
                }
                if ($buffer == 'rset') {
                    replyToClient($conn, '250 Okey dokey');
                    continue;
                }

                if ($buffer == 'auth plain') {
                    replyToClient($conn, '250 Okey dokey');
                    $buffer = fgets($conn); //skipping line
                    replyToClient($conn, '250 Okey dokey');
                    $buffer = fgets($conn); //skipping line
                    replyToClient($conn, '250 Okey dokey');
                    continue;
                }
                if ($buffer == 'auth login') {
                    replyToClient($conn, '334 Okey dokey');
                    $buffer = fgets($conn); //skipping line
                    replyToClient($conn, '334 Okey dokey');
                    $buffer = fgets($conn); //skipping line
                    replyToClient($conn, '235 Okey dokey');
                    continue;
                }

                if (preg_match_all('/^mail from:(\s|)(<(.*)>|.*)/', $buffer, $matches, PREG_SET_ORDER)) {
                    $address = (isset($matches[0][3]) ? $matches[0][3] : $matches[0][2]);
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === FALSE)
                        replyToClient($conn, '501 invalid mail address ' . $address);
                    else {
                        $frommime = $address;
                        replyToClient($conn, '250 Okey dokey');
                    }
                    continue;
                }
                if (preg_match_all('/^rcpt to:(\s|)(<(.*)>|.*)/', $buffer, $matches, PREG_SET_ORDER)) {
                    $address = (isset($matches[0][3]) ? $matches[0][3] : $matches[0][2]);
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === FALSE)
                        replyToClient($conn, '501 invalid mail address ' . $address);
                    else {
                        $recipients[] = $address;
                        replyToClient($conn, '250 Recipient accepted');
                    }
                    continue;
                }
                if ($getData == false) {
                    replyToClient($conn, '502 invalid command');
                    continue;
                }
            }


            if ($data != '') {
                parseSMTPMail($data, $recipients);
            } else {
//echo "Emtpy data\n";
            }
        }
    }
}

function replyToClient($conn, $msg)
{
    phpwrite('>' . $msg);
    fwrite($conn, $msg . PHP_CRLF);
}

function phpwrite($message, $socketid = 0, $priority = LOG_ALERT)
{
    //print date("c") . ":PID->" . getmypid() . ":SID:$socketid:" . rtrim($message) . PHP_EOL;
}

ini_set("default_socket_timeout", $config["SOCKET_TIMEOUT"]);
$socket = stream_socket_server($config["PROTOCOL"] . "://" . $config["HOST_IP"] . ":" . $config["PORT_NUMBER"], $errno, $errstr);
//stream_set_blocking($socket,false);

if (!$socket) {
    phpwrite("$errstr ($errno)");
} else {
    phpwrite("Welcome Simple phpsmptserver");

    $checked_time = 0;

    while (1) {

        if ((time()-$checked_time)>10) {
            setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
            $checked_time = time();

        }
        phpwrite("Wating for connection...");

        while ($conn = @stream_socket_accept($socket, 10)) {
            if ((time()-$checked_time)>10) {
                setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
                $checked_time = time();
            }
            if (isRebootRequired() || IsSet($_GET['onetime'])) {
                $db->Disconnect();
                exit;
            }
            $conns[] = new Client($conn);
        }

        //echo "Timed out\n";
        if (isRebootRequired() || IsSet($_GET['onetime'])) {
            $db->Disconnect();
            exit;
        }
    }
    //fclose($socket);
}


DebMes("Unexpected close of cycle: " . basename(__FILE__));
