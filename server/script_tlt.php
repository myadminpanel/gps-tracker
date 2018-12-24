#!/usr/bin/php -q
<?php
//waiting for system startup
//crontab: @reboot php -q /var/www/server/tracker.php
//sleep (180);

/**
 * Listens for requests and forks on each connection
 */
$tipoLog = "arquivo"; // tela //debug log, escreve na tela ou no arquivo de log.

$fh = null;
$remip = null;
$remport = null;

/* if ($tipoLog == "arquivo") {
  //Criando arquivo de log
  $fn = ROOT_URL."/sites/1/logs/" . "Log_". date("dmyhis") .".log";
  $fh = fopen($fn, 'w') or die ("Can not create file");
  $tempstr = "Log Inicio".chr(13).chr(10);
  fwrite($fh, $tempstr);
  } */

function abrirArquivoLog($imeiLog) {
    GLOBAL $fh;

    //$fn = ".".dirname(__FILE__)."/sites/1/logs/Log_". trim($imeiLog) .".log";
    $fn = "./var/www/sites/1/logs/Log_" . trim($imeiLog) . ".log";
    $fn = trim($fn);
    $fh = fopen($fn, 'a') or die("Can not create file");
    $tempstr = "Log Inicio" . chr(13) . chr(10);
    fwrite($fh, $tempstr);
}

function fecharArquivoLog() {
    GLOBAL $fh;
    if ($fh != null)
        fclose($fh);
}

function printLog($fh, $mensagem) {
    GLOBAL $tipoLog;
    GLOBAL $fh;

    if ($tipoLog == "arquivo") {
        //escreve no arquivo
        if ($fh != null)
            fwrite($fh, $mensagem . chr(13) . chr(10));
    } else {
        //escreve na tela
        echo $mensagem . "<br />";
    }
}

// IP Local
$ip = '172.31.27.135';
// Port
$port = 70772;
// Path to look for files with commands to send
$command_path = "./var/www/sites/1/";
$from_email = 'otagomes@hotmail.com';

$__server_listening = true;

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
declare(ticks = 1);
ini_set('sendmail_from', $from_email);

//printLog($fh, "become_daemon() in");
become_daemon();
//printLog($fh, "become_daemon() out");

/* nobody/nogroup, change to your host's uid/gid of the non-priv user 

 * * Comment by Andrew - I could not get this to work, i commented it out
  the code still works fine but mine does not run as a priv user anyway....
  uncommented for completeness
 */
//change_identity(65534, 65534);

/* handle signals */
pcntl_signal(SIGTERM, 'sig_handler');
pcntl_signal(SIGINT, 'sig_handler');
pcntl_signal(SIGCHLD, 'sig_handler');

//printLog($fh, "pcntl_signal ok");

/* change this to your own host / port */
//printLog($fh, "server_loop in");
server_loop($ip, $port);

//Finalizando arquivo
//fclose($fh);

/**
 * Change the identity to a non-priv user
 */
function change_identity($uid, $gid) {
    if (!posix_setgid($gid)) {
        print "Unable to setgid to " . $gid . "!\n";
        exit;
    }

    if (!posix_setuid($uid)) {
        print "Unable to setuid to " . $uid . "!\n";
        exit;
    }
}

/**
 * Creates a server socket and listens for incoming client connections
 * @param string $address The address to listen on
 * @param int $port The port to listen on
 */
function server_loop($address, $port) {
    GLOBAL $fh;
    GLOBAL $__server_listening;

    printLog($fh, "server_looping...");

    if (($sock = socket_create(AF_INET, SOCK_STREAM, 0)) < 0) {
        printLog($fh, "failed to create socket: " . socket_strerror($sock));
        exit();
    }

    if (($ret = socket_bind($sock, $address, $port)) < 0) {
        printLog($fh, "failed to bind socket: " . socket_strerror($ret));
        exit();
    }

    if (( $ret = socket_listen($sock, 0) ) < 0) {
        printLog($fh, "failed to listen to socket: " . socket_strerror($ret));
        exit();
    }

    socket_set_nonblock($sock);

    printLog($fh, "waiting for clients to connect...");

    while ($__server_listening) {
        $connection = @socket_accept($sock);
        if ($connection === false) {
            usleep(100);
        } elseif ($connection > 0) {
            handle_client($sock, $connection);
        } else {
            //printLog($fh, "error: ".socket_strerror($connection));
            die;
        }
    }
}

/**
 * Signal handler
 */
function sig_handler($sig) {
    switch ($sig) {
        case SIGTERM:
        case SIGINT:
            //exit();
            break;

        case SIGCHLD:
            pcntl_waitpid(-1, $status);
            break;
    }
}

$firstInteraction = false;

/**
 * Handle a new client connection
 */
function handle_client($ssock, $csock) {
    GLOBAL $__server_listening;
    GLOBAL $fh;
    GLOBAL $firstInteraction;
    GLOBAL $remip;
    GLOBAL $remport;

    $pid = pcntl_fork();

    if ($pid == -1) {
        /* fork failed */
        //printLog($fh, "fork failure!");
        die;
    } elseif ($pid == 0) {
        /* child process */
        $__server_listening = false;
        socket_getpeername($csock, $remip, $remport);

        //printLog($fh, date("d-m-y h:i:sa") . " Connection from $remip:$remport");

        $firstInteraction = true;

        socket_close($ssock);
        interact($csock);
        socket_close($csock);

        printLog($fh, date("d-m-y h:i:sa") . " Connection to $remip:$remport closed");

        fecharArquivoLog();
    } else {
        socket_close($csock);
    }
}

function interact($socket) {
    GLOBAL $fh;
    GLOBAL $command_path;
    GLOBAL $firstInteraction;
    GLOBAL $remip;
    GLOBAL $remport;

    $loopcount = 0;
    $conn_imei = "";
    /* TALK TO YOUR CLIENT */
    $rec = "";

    $conexao = mysql_connect("cloudservice.cgejdsdl842e.sa-east-1.rds.amazonaws.com", "gpstracker", "d1$1793689");
    mysql_select_db('tracker', $conexao);

    # Read the socket but don't wait for data..
    while (@socket_recv($socket, $rec, 2048, 0x40) !== 0) {

        # Some pacing to ensure we don't split any incoming data.
        sleep(1);

        # Timeout the socket if it's not talking...
        # Prevents duplicate connections, confusing the send commands
        $loopcount++;
        if ($loopcount > 120)
            return;

        #remove any whitespace from ends of string.
        $rec = trim($rec);


        if ($rec != "") {
            if ((preg_match("/TLT/i", $rec)) OR (preg_match("/V500/i", $rec))) {
                $loopcount = 0;
                $partsx = explode('#', $rec);
                $imei = mysql_real_escape_string($partsx[1]);
                $status = mysql_real_escape_string($partsx[4]);
                $quantidadecoordenadas = mysql_real_escape_string($partsx[5]);
                mysql_set_charset('utf8', $conexao);
                switch ($status) {
                    case "SOS":
                        $body = "SOS! Alerta emitido";
                        mysql_query("INSERT INTO message (imei, message) VALUES ('$imei', '$body')", $conexao);
                        printLog($fh, date("d-m-Y H:i:s") . " Alerta: " . $body);
                        break;
                    case "DEF":
                        $body = "Bateria principal desligada";
                        mysql_query("INSERT INTO message (imei, message) VALUES ('$imei', '$body')", $conexao);
                        printLog($fh, date("d-m-Y H:i:s") . " Alerta: " . $body);
                        break;
                    case "LP":
                        $body = "Bateria Interna Fraca";
                        mysql_query("INSERT INTO message (imei, message) VALUES ('$imei', '$body')", $conexao);
                        printLog($fh, date("d-m-Y H:i:s") . " Alerta: " . $body);
                        break;
                    case "TOWED":
                        $body = "Rebocado";
                        mysql_query("INSERT INTO message (imei, message) VALUES ('$imei', '$body')", $conexao);
                        printLog($fh, date("d-m-Y H:i:s") . " Alerta: " . $body);
                        break;
                }
                //
                $conn_imei = $imei;
                abrirArquivoLog($conn_imei);
                printLog($fh, date("d-m-Y H:i:s") . " CONEXAO DE $remip:$remport");
                printLog($fh, date("d-m-Y H:i:s") . " RECEBEU : $rec");
            }
            // SE TIVER A STRING GPRMC
            if (preg_match("/GPRMC/i", $rec)) {
                $explode = explode("GPRMC", $rec);
                //echo "Quantidade >> " . $quantidadecoordenadas . "\n";
                for ($i = 1; $i <= $quantidadecoordenadas; $i++) {
                    //echo "Linha " . $i . " >> " . $explode[$i] . "\n";
                    $loopcount = 0;
                    $linha = $explode[$i];
                    $parts = explode(',', $linha);
                    $gpsSignalIndicator = mysql_real_escape_string($parts[2]);
                    if ((count($parts) > 1) and ($gpsSignalIndicator == 'A')) {
                        // HORA GMT
                        $hora = mysql_real_escape_string($parts[1]);
                        $hora = substr($hora, 0, 2) . ":" . substr($hora, 2, 2) . ":" . substr($hora, 4, 2);
                        // DATA
                        $data = mysql_real_escape_string($parts[9]);
                        $data = "20" . substr($data, 4, 2) . "-" . substr($data, 2, 2) . "-" . substr($data, 0, 2);
                        // DATETIME
                        $datetime = $data . " " . $hora;
                        //
                        $latitudeDecimalDegrees = mysql_real_escape_string($parts[3]);
                        $latitudeHemisphere = mysql_real_escape_string($parts[4]);
                        $longitudeDecimalDegrees = mysql_real_escape_string($parts[5]);
                        $longitudeHemisphere = mysql_real_escape_string($parts[6]);
                        $velocidadekn = mysql_real_escape_string($parts[7]);
                        $velocidadekm = $velocidadekn * 1.852;
                        $velocidadekm = str_replace(',', '.', $velocidadekm);
                        $angulo = mysql_real_escape_string($parts[8]);
                        if ($angulo == "") {
                            $angulo = 0;
                        }
                        strlen($latitudeDecimalDegrees) == 9 && $latitudeDecimalDegrees = '0' . $latitudeDecimalDegrees;
                        $g = substr($latitudeDecimalDegrees, 0, 3);
                        $d = substr($latitudeDecimalDegrees, 3);
                        $strLatitudeDecimalDegrees = $g + ($d / 60);
                        $latitudeHemisphere == "S" && $strLatitudeDecimalDegrees = $strLatitudeDecimalDegrees * -1;

                        strlen($longitudeDecimalDegrees) == 9 && $longitudeDecimalDegrees = '0' . $longitudeDecimalDegrees;
                        $g = substr($longitudeDecimalDegrees, 0, 3);
                        $d = substr($longitudeDecimalDegrees, 3);
                        $strLongitudeDecimalDegrees = $g + ($d / 60);
                        $longitudeHemisphere == "W" && $strLongitudeDecimalDegrees = $strLongitudeDecimalDegrees * -1;

                        $latitude = $strLatitudeDecimalDegrees;
                        $longitude = $strLongitudeDecimalDegrees;
                        $lat_point = $latitude;
                        $lng_point = $longitude;

                        if (($velocidadekn < 1) and ($velocidadekn > 0)) {
                            $velocidadekn = 0;
                        }
                        $speed = $velocidadekn;

                        # GRAVANDO DADOS NAS TABELAS
                        mysql_query("UPDATE bem set date = date, status_sinal = 'R' WHERE imei = '$imei'", $conexao);
                        mysql_query("INSERT INTO gprmc (date, imei, satelliteFixStatus, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere, speed, infotext, gpsSignalIndicator) VALUES (now(), '$imei', 'A', '$latitudeDecimalDegrees', '$latitudeHemisphere', '$longitudeDecimalDegrees', '$longitudeHemisphere', '$speed', '$status', 'F')", $conexao);

                        # VERIFICA CERCA VIRTUAL
                        $consulta = mysql_query("SELECT * FROM geo_fence WHERE imei = '$imei'", $conexao);
                        while ($data = mysql_fetch_assoc($consulta)) {
                            $idCerca = $data['id'];
                            $imeiCerca = $data['imei'];
                            $nomeCerca = $data['nome'];
                            $coordenadasCerca = $data['coordenadas'];
                            $resultCerca = $data['tipo'];
                            $tipoEnvio = $data['tipoEnvio'];
                            $exp = explode("|", $coordenadasCerca);

                            if (( count($exp) ) < 5) {
                                $strExp = explode(",", $exp[0]);
                                $strExp1 = explode(",", $exp[2]);
                            } else {
                                $int = (count($exp)) / 2;
                                $strExp = explode(",", $exp[0]);
                                $strExp1 = explode(",", $exp[$int]);
                            }

                            $lat_vertice_1 = $strExp[0];
                            $lng_vertice_1 = $strExp[1];
                            $lat_vertice_2 = $strExp1[0];
                            $lng_vertice_2 = $strExp1[1];

                            if ($lat_vertice_1 < $lat_point Or $lat_point < $lat_vertice_2 And $lng_point < $lng_vertice_1 Or $lng_vertice_2 < $lng_point) {
                                $result = '0';
                                $situacao = 'fora';
                            } else {
                                $result = '1';
                                $situacao = 'dentro';
                            }

                            if ($result <> $resultCerca And round($speed * 1.852, 0) > 0) {
                                mysql_query("INSERT INTO message (imei, message) VALUES ('$imei', 'Cerca " . $nomeCerca . " Violada!')", $conexao);

                                if ($tipoEnvio == 0) {

                                    # Convert the GPS coordinates to a human readable address
                                    $tempstr = "http://maps.google.com/maps/geo?q=$lat_point,$lng_point&oe=utf-8&sensor=true&key=ABQIAAAAFd56B-wCWVpooPPO7LR3ihTz-K-sFZ2BISbybur6B4OYOOGbdRShvXwdlYvbnwC38zgCx2up86CqEg&output=csv"; //output = csv, xml, kml, json
                                    $rev_geo_str = file_get_contents($tempstr);
                                    $rev_geo_str = preg_replace("/\"/", "", $rev_geo_str);
                                    $rev_geo = explode(',', $rev_geo_str);
                                    $logradouro = $rev_geo[2] . "," . $rev_geo[3];

                                    require "lib/class.phpmailer.php";

                                    $consulta1 = mysql_query("SELECT a.*, b.* FROM cliente a INNER JOIN bem b ON (a.id = b.cliente) WHERE b.imei = '$imei'", $conexao);
                                    while ($data = mysql_fetch_assoc($consulta1)) {

                                        $emailDestino = $data['email'];
                                        $nameBem = $data['name'];
                                        $mensagem = "O veiculo " . $nameBem . ", esta " . $situacao . " do perimetro " . $nomeCerca . ", as " . date("H:i:s") . " do dia " . date("d/m/Y") . ", no local " . $logradouro . " e trafegando a " . round($speed * 1.852, 0) . " km/h.";

                                        $msg = "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
                                        $msg .= "<html>";
                                        $msg .= "<head></head>";
                                        $msg .= "<body style=\"background-color:#fff;\" >";
                                        $msg .= "<p><strong>Alerta de Violacao de Perimetro:</strong><br /><br />";
                                        $msg .= $mensagem . "<br /><br />";
                                        $msg .= "Equipe BarukSat<br />";
                                        $msg .= "(85)88462069<br />";
                                        $msg .= "<a href=\"http://www.systemtracker.com.br\">www.systemtracker.com.br</a></p>";
                                        $msg .= "</body>";
                                        $msg .= "</html>";

                                        $mail = new PHPMailer();
                                        $mail->Mailer = "smtp";
                                        $mail->IsHTML(true);
                                        $mail->CharSet = "utf-8";
                                        $mail->SMTPSecure = "tls";
                                        $mail->Host = "smtp.gmail.com";
                                        $mail->Port = "587";
                                        $mail->SMTPAuth = "true";
                                        $mail->Username = "paccelli.rocha";
                                        $mail->Password = "sua_senha";
                                        $mail->From = "josenilsontrindade@gmail.com";
                                        $mail->FromName = "BarukSat";
                                        $mail->AddAddress($emailDestino);
                                        $mail->AddReplyTo($mail->From, $mail->FromName);
                                        $mail->Subject = "BarukSat - Alerta de Violacao de Perimetro";
                                        $mail->Body = $msg;

                                        if (!$mail->Send()) {
                                            echo "Erro de envio: " . $mail->ErrorInfo;
                                        } else {
                                            echo "Mensagem enviada com sucesso!";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // DADOS INCORRETOS PARA COLETA
            }
        }
        $rec = "";
    } //while
}

//fim interact

/**
 * Become a daemon by forking and closing the parent
 */
function become_daemon() {
    GLOBAL $fh;

    //printLog($fh, "pcntl_fork() in");
    $pid = pcntl_fork();
    //printLog($fh, "pcntl_fork() out");

    if ($pid == -1) {
        /* fork failed */
        //printLog($fh, "fork failure!");
        exit();
    } elseif ($pid) {
        //printLog($fh, "pid: " . $pid);
        /* close the parent */
        exit();
    } else {
        /* child becomes our daemon */
        posix_setsid();
        chdir('/');
        umask(0);
        return posix_getpid();
    }

    //printLog($fh, "become_daemon() fim");
}
?>
