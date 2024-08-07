<?php
//Support for multiple files
/*
* Bitstorm - A small and fast Bittorrent tracker
* Copyright 2008 Peter Caprioli
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

 /*************************
 ** Configuration start **
 *************************/

//Enable debugging?
//This allows anyone to see the entire peer database by appending ?debug to the announce URL
define('__DEBUGGING_ON', false);

//What version are we at?
define('__VERSION', 2.0);

//How often should clients pull server for new clients? (Seconds)
define('__INTERVAL', 1800);

//What's the minimum interval a client may pull the server? (Seconds)
//Some bittorrent clients does not obey this
define('__INTERVAL_MIN', 300);

//How long should we wait for a client to re-announce after the last
//announce expires? (Seconds)
define('__CLIENT_TIMEOUT', 60);

//Skip sending the peer id if client does not want it?
//Hint: Should be set to true
define('__NO_PEER_ID', true);

//Should seeders not see each others?
//Hint: Should be set to true
define('__NO_SEED_P2P', true);

//Should we enable short announces?
//This allows NATed clients to get updates much faster, but it also
//takes more load on the server.
//(This is just an experimental feature which may be turned off)
define('__ENABLE_SHORT_ANNOUNCE', true);

//In case someone tries to access the tracker using a browser,
//redirect to this URL or file
define('__REDIR_BROWSER', 'https://github.com/ngosang/trackerslist');

 /***********************
 ** Configuration end **
 ***********************/

//Send response as text
header('Content-type: Text/Plain');
header('X-Tracker-Version: Bitstorm '.__VERSION.' by ck3r.org'); //Please give me some credit, If you *really* dont want to, comment this line out

//Bencoding function, returns a bencoded dictionary
//You may go ahead and enter custom keys in the dictionary in
//this function if you'd like.
function track($list, $interval=60, $min_ival=0) {
	if (is_string($list)) { //Did we get a string? Return an error to the client
		return 'd14:failure reason'.strlen($list).':'.$list.'e';
	}
	$p = ''; //Peer directory
	$c = $i = 0; //Complete and Incomplete clients
	foreach($list as $d) { //Runs for each client
		if ($d[7]) { //Are we seeding?
			$c++; //Seeding, add to complete list
			if (__NO_SEED_P2P && is_seed()) { //Seeds should not see each others
				continue;
			}
		} else {
			$i++; //Not seeding, add to incomplete list
		}
		//Do some bencoding
		
		$pid = '';
		
		if (!isset($_GET['no_peer_id']) && __NO_PEER_ID) { //Shall we include the peer id
			$pid = '7:peer id'.strlen($d[1]).':'.$d[1];
		}
		
		$p .= 'd2:ip'.strlen($d[0]).':'.$d[0].$pid.'4:porti'.$d[2].'ee';
	}
	//Add some other paramters in the dictionary and merge with peer list
	$r = 'd8:intervali'.$interval.'e12:min intervali'.$min_ival.'e8:completei'.$c.'e10:incompletei'.$i.'e5:peersl'.$p.'ee';
	return $r;
}

//Find out if we are seeding or not. Assume not if unknown.
function is_seed() {
	if (!isset($_GET['left'])) {
		return false;
	}
	if ($_GET['left'] == 0) {
		return true;
	}
	return false;
}

/*
* Yeah, this is the database engine. It's pretty bad, uses files to store peers.
* Should be easy to rewrite to use SQL instead.
*
* Yes, sometimes collisions may occur and screw the DB over. It might or might not
* recover by itself.
*/

//Save database to file
function db_save($data, $info_hash) {
	$log_dir = 'Torrents-Files';
	if (!is_dir($log_dir)) {
		mkdir($log_dir, 0777, true);
	}
	$log_file = $log_dir . '/' . bin2hex($info_hash) . '.torrents.files';
	$b = serialize($data);
	$h = @fopen($log_file, 'w');
	if (!$h) { return false; }
	if (!@flock($h, LOCK_EX)) { return false; }
	@fwrite($h, $b . PHP_EOL);
	@fclose($h);
	return true;
}

//Load database from file
function db_open($info_hash) {
	$log_dir = 'Torrents-Files';
	$log_file = $log_dir . '/' . bin2hex($info_hash) . '.torrents.files';
	$p = '';
	$m = '';
	$h = @fopen($log_file, 'r');
	if (!$h) { return false; }
	if (!@flock($h, LOCK_EX)) { return false; }
	while (!@feof($h)) {
		$p .= @fread($h, 512);
	}
	@fclose($h);
	return unserialize($p);
}

//Check if DB file exists, otherwise create it
function db_exists($info_hash, $create_empty=false) {
	$log_dir = 'Torrents-Files';
	$log_file = $log_dir . '/' . bin2hex($info_hash) . '.torrents.files';
	if (file_exists($log_file)) {
		return true;
	}
	if ($create_empty) {
		if (!db_save(array(), $info_hash)) {
			return false;
		}
		return true;
	}
	return false;
}

//Default announce time
$interval = __INTERVAL;

//Minimal announce time (does not apply to short announces)
$interval_min = __INTERVAL_MIN;

/*
* This is a pretty smart feature not present in other tracker software.
* If you expect to have many NATed clients, add short as a GET parameter,
* and clients will pull much more often.
*
* This can be done automatically, simply try to open a TCP connection to
* the client and assume it is NATed if not successful.
*/
if (isset($_GET['short']) && __ENABLE_SHORT_ANNOUNCE) {
	$interval = 120;
	$interval_min = 30;
}

//Did we get any parameters at all?
//Client is  probably a web browser, do a redirect
if (empty($_GET)) {
	header('Location: '.__REDIR_BROWSER);
	die();
}

// Inputs validation and sanitization
function valdata($g, $must_be_20_chars=false) {
	if (!isset($_GET[$g])) {
		die(track('Missing one or more arguments'));
	}
	if (!is_string($_GET[$g])) {
		die(track('Invalid types on one or more arguments'));
	}
	if ($must_be_20_chars && strlen($_GET[$g]) != 20) {
		die(track('Invalid length on '.$g.' argument'));
	}
	if (strlen($_GET[$g]) > 128) { //128 chars should really be enough
		die(track('Argument '.$g.' is too large to handle'));
	}
}

// Inputs that are needed, do not continue without these
valdata('peer_id', true);
valdata('port');
valdata('info_hash', true);

//Use the tracker key extension. Makes it much harder to steal a session.
if (!isset($_GET['key'])) {
	$_GET['key'] = '';
}
valdata('key');

//Do we have a valid client port?
if (!ctype_digit($_GET['port']) || $_GET['port'] < 1 || $_GET['port'] > 65535) {
	die(track('Invalid client port'));
}

//Make sure we've got a user agent to avoid errors
//Used for debugging
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
	$_SERVER['HTTP_USER_AGENT'] = ''; //Must always be set
}

//When should we remove the client?
$expire = time()+$interval;

$info_hash = bin2hex(stripslashes($_GET["info_hash"]));
$peer_id = bin2hex(stripslashes($_GET["peer_id"]));

//Array key, unique for each client and torrent
$sum = sha1($_GET["peer_id"].$_GET["info_hash"]);

//CreateParece que o sistema cortou parte do meu código enquanto estava sendo atualizado. Vou continuar e completar a lógica para garantir que tudo esteja correto.

// Verifique se o banco de dados existe, senão, crie
db_exists($info_hash, true) or die(track('Unable to create database'));
$d = db_open($info_hash);

// Deseja depurar? (Não deve ser usado por padrão)
if (isset($_GET['debug']) && __DEBUGGING_ON) {
    echo 'Connected peers:'.count($d)."\n\n";
    print_r($d);
    die();
}

// O banco de dados falhou?
if ($d === false) {
    die(track('Database failure'));
}

// Este cliente já se registrou antes? Verifique se ele usa a mesma chave
if (isset($d[$sum])) {
    if ($d[$sum][6] !== $_GET['key']) {
        sleep(3); // Anti brute force
        die(track('Access denied, authentication failed'));
    }
}

// Adicione/atualize o cliente em nossa lista global de clientes, com algumas informações
$d[$sum] = array(
    $_SERVER['REMOTE_ADDR'], // IP do cliente
    $peer_id,                // ID do peer
    $_GET['port'],           // Porta do peer
    $expire,                 // Tempo de expiração
    $info_hash,              // Hash de informação do arquivo
    $_SERVER['HTTP_USER_AGENT'], // Agente do usuário (cliente BitTorrent)
    $_GET['key'],            // Chave do cliente (se houver)
    is_seed()                // Indicador de se o cliente é um seed ou leecher
);

// Não há motivo para salvar o user agent, a menos que estejamos depurando
if (!__DEBUGGING_ON) {
    unset($d[$sum][5]);
} elseif (!empty($_GET)) { // Estamos depurando, adicione os parâmetros GET ao banco de dados
    $d[$sum]['get_parm'] = $_GET;
}

// O cliente parou o torrent?
// Não nos importamos com outros eventos
if (isset($_GET['event']) && $_GET['event'] === 'stopped') {
    unset($d[$sum]);
    db_save($d, $info_hash);
    die(track(array())); // A RFC diz que está OK retornar o que quisermos quando o cliente para de baixar,
                         // no entanto, alguns clientes reclamarão sobre o tracker não funcionar, por isso retornamos
                         // uma lista de peers bencode vazia
}

// Verifique se algum cliente expirou
foreach ($d as $k => $data) {
    if (time() > $data[3] + __CLIENT_TIMEOUT) { // Dê ao cliente algum tempo extra antes de expirar
        unset($d[$k]); // Cliente se foi, remova-o
    }
}

// Salve a lista de clientes
db_save($d, $info_hash);

// Compare info_hash com o resto de nossos clientes e remova qualquer um que não tenha o torrent correto
foreach ($d as $id => $info) {
    if ($info[4] !== $info_hash) {
        unset($d[$id]);
    }
}

// Remova a si mesmo da lista, não há razão para nos termos no dicionário de clientes
unset($d[$sum]);

// Adicione alguns segundos a mais no tempo de intervalo para balancear a carga
$interval += rand(0, 10);

// Bencode o dicionário e envie de volta
die(track($d, $interval, $interval_min));
?>
