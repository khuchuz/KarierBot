<?PHP
date_default_timezone_set('Asia/Jakarta');
header("Content-type: application/json");

include_once 'config.php';

$hasil=array();
$final=array();
$mentah=array();


/* Get Progress from Database*/
$stmt_pr = $pdo->prepare("SELECT * FROM progress");
$stmt_pr->execute();
$fetchAllProgress = $stmt_pr->fetchAll();

foreach($fetchAllProgress as $datas){
	if($datas['percentage']==100)
		$final[$datas[0]]=array(
			'id'=>$datas['id'],
			'name'=>$datas['name'],
			'email'=>$datas['email'],
			'pelatihan'=>$datas['judul'],
			'progress'=>$datas['percentage']
		);
	else{
		$mentah[$datas['id']]=$datas;
	}
}

/* Get User from Database Where mentah*/
$stmt_us = $pdo->prepare("SELECT * FROM user");
$stmt_us->execute();
$fetchAllUser = $stmt_us->fetchAll();

foreach($mentah as $key=>$data){
	foreach($fetchAllUser as $user){
		if($user['email']==$data['email'])
			$hasil[$key]=array($user['token'],$user['id']);
	}
}

/* Get Progress from Server*/
$list_ret = getResponseByUrlsMultiThreads($hasil,2);
foreach($list_ret as $key=>$val){
	$data = json_decode($val)->data;
	$final[$key] = array(
		'id'=>$key,
		'name'=>$mentah[$key]['name'],
		'email'=>$mentah[$key]['email'],
		'pelatihan'=>$data[0]->name,
		'progress'=>$data[0]->percentage_progress);
}
usort($final, function($a, $b) {return $b['id'] <=> $a['id'];});
usort($final, function($a, $b) {return $a['progress'] <=> $b['progress'];});
echo json_encode($final);

function getResponseByUrlsMultiThreads($datas, $mode=1, $threads = 100, $followLocation = true, $maxRedirects = 10){
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
		if($mode==2){
			$ch = curl_init('https://api.sekolah.mu/program_activity/enrolled/'.$data[1].'/1/12?platform=kariermu');
			$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0]);
			$curlOptions[CURLOPT_POST] = false;
		}else{
			$ch = curl_init('https://api.sekolah.mu/v2/auth/me-v2?platform=kariermu');
			$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{}'),'Content-Type: application/json;charset=utf-8');
			$curlOptions[CURLOPT_POST] = true;
			$curlOptions[CURLOPT_POSTFIELDS] = '{}';
		}
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
?>