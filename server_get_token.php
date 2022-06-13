<?PHP
set_time_limit(0);
while(@ob_end_flush());
ob_implicit_flush(true);
date_default_timezone_set('Asia/Jakarta');
echo "<pre>";
include_once 'config.php';

/*Get User from database*/
$stmt = $pdo->prepare("SELECT * FROM user");
$stmt->execute();
$fetchAll = $stmt->fetchAll();
$result = $fetchAll;

/* Local table $hasil
data: [
	0: email,
	1: password,
	2: status,
	3: token,
	4: id,
	5: name
]
*/
$hasil=array();
foreach($result as $key=>$line){
	$hasil[]=array($line['email'],$line['password']);
	$result[$line['email']]=$line;
	unset($result[$key]);
}

/*Get User details from server*/
$list_ret = getResponseByUrlsMultiThreads($hasil);
foreach($list_ret as $key=>$data){
	$data = json_decode($data);
	$hasil[$key][2] = $data->status;
	if(isset($data->token) && $data->status==200){
		$hasil[$key][3] = $data->token->token;
		$hasil[$key][4] = $data->data->id;
		$hasil[$key][5] = $data->data->name;
	}else{
		$hasil_rusak[$key]=$hasil[$key];
		unset($hasil[$key]);
	}
}

/* Update User with $hasil*/
foreach($hasil as $line2){
	if(isset($result[$line2[0]])){
		if($result[$line2[0]]['id']!=$line2[4]    && !empty($line2[4])) $result[$line2[0]]['id']=$line2[4];
		if($result[$line2[0]]['name']!=$line2[5]  && !empty($line2[5])) $result[$line2[0]]['name']=$line2[5];
		if($result[$line2[0]]['token']!=$line2[3] && !empty($line2[3])){
			$pdo->prepare("UPDATE user SET id=?,name=?,status=?,token=? WHERE email=?")->execute([
				$result[$line2[0]]['id'],
				$result[$line2[0]]['name'],
				0,
				$line2[3],
				$line2[0]
			]);
		}
		unset($result[$line2[0]]);
	}
}

/* Update User with $hasil_rusak*/
foreach($hasil_rusak as $line2){
	if(isset($result[$line2[0]])){
		$pdo->prepare("UPDATE user SET status=? WHERE email=?")->execute([
			$result[$line2[0]]['status']+1,
			$line2[0]
		]);
		unset($result[$line2[0]]);
	}
}

echo count($hasil)." data bagus dan ".count($hasil_rusak)." data rusak dari total ".count($fetchAll)." data telah di refresh";

function getResponseByUrlsMultiThreads($datas, $mode=0, $threads = 25, $followLocation = true, $maxRedirects = 10){
	$curlOptions = [
		CURLOPT_HEADER => false,
		CURLOPT_NOBODY => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
		CURLOPT_ENCODING => '',
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_ENCODING => 'gzip'
	];
	if($followLocation){
		$curlOptions[CURLOPT_FOLLOWLOCATION] = true;
		$curlOptions[CURLOPT_MAXREDIRS] = $maxRedirects;
	}
	$mh = curl_multi_init();
	$chArray = [];
	$executeMethod = function ($mh, $chArray, &$result, &$running, &$currentThread){
		while (curl_multi_exec($mh, $running) === CURLM_CALL_MULTI_PERFORM);
		curl_multi_select($mh);
		while ($done = curl_multi_info_read($mh)) {
			foreach ($chArray as $key => $ch) {
				if($ch == $done['handle']){
					$result[$key] = curl_multi_getcontent($ch);
				}
			}
			curl_multi_remove_handle($mh, $done['handle']);
			curl_close($done['handle']);
			$currentThread--;
		}
	};

	$result = [];
	$running = [];
	$currentThread = 0;

	$datas = !is_array($datas) ? [$datas] : $datas;
	foreach($datas as $keys=>$data){
		$ch = curl_init('https://api.sekolah.mu/user/login/?source=web-kariermu&platform=kariermu&source=a2FyaWVyLm11LXdlYg==');
		$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: Sekolahmu0App0Key0Secret!!!','Content-Length: '.strlen('{"email":"'.$data[0].'","password":"'.$data[1].'","from_webview":true,"source":"web-kariermu"}'),'Content-Type: application/json;charset=utf-8');
		$curlOptions[CURLOPT_POST] = true;
		$curlOptions[CURLOPT_POSTFIELDS] = '{"email":"'.$data[0].'","password":"'.$data[1].'","from_webview":true,"source":"web-kariermu"}';
		curl_setopt_array($ch, $curlOptions);
		$chArray[$keys] = $ch;
		curl_multi_add_handle($mh, $ch);
		$currentThread++;
		if ($currentThread >= $threads) {
			while ($currentThread >= $threads) {
				$executeMethod($mh, $chArray, $result, $running, $currentThread);
			}
		}
	}
	do {
		$executeMethod($mh, $chArray, $result, $running, $currentThread);
	} while($running > 0);
	curl_multi_close($mh);
	return $result;
}