<?PHP
set_time_limit(0);
while(@ob_end_flush());
ob_implicit_flush(true);
date_default_timezone_set('Asia/Jakarta');
echo "<pre>";
include_once 'config.php';

/* Local table $user
data: [
	0: token,
	1: uid,
	2: name,
	3: email,
]
*/
$user=array();
/* Local table $hasil
data: [
	0: token,
	1: user_id,
	2: name,
	3: email,
	4: program_user_id,
	5: percentage_progress,
	6: program_id,
	7: judul,
	8: first_act,
]
*/
$hasil=array();

/* Get User 'token' & 'status' */
$stmt_us = $pdo->prepare("SELECT token,status FROM user");
$stmt_us->execute();
$fetchAllUser = $stmt_us->fetchAll();
foreach($fetchAllUser as $data){
	if(!empty($data['token']) && $data['status']==0)
		$user[][0]=$data['token'];
}

/* Get Progress from Database*/
$stmt_pr = $pdo->prepare("SELECT id,percentage FROM progress");
$stmt_pr->execute();
$fetchAllProgress = $stmt_pr->fetchAll();
foreach($fetchAllProgress as $pr){
	$progress[$pr['id']] = $pr['percentage'];
}

/*Get User details from server*/
$list_ret = getResponseByUrlsMultiThreads($user);
foreach($list_ret as $key=>$data){
	$data = json_decode($data)->data;
	$user[$key][1] = $data->id;
	$user[$key][2] = $data->name;
	$user[$key][3] = $data->email;
}

$idpel=array();$token='';

/*Get User Pelatihan from server*/
$list_ret = getResponseByUrlsMultiThreads($user,1);
foreach($list_ret as $key=>$data){
	$data = json_decode($data);
	if(isset($data->data) && $data->status==200){
		foreach($data->data as $datas){
			$program_user_id = $datas->program_user_id;
			$program_id = $datas->id;

			/*Copy User ke Hasil*/
			$hasil[$program_user_id] = $user[$key];
			
			$hasil[$program_user_id][4] = $program_user_id;
			$hasil[$program_user_id][5] = $datas->percentage_progress;
			$hasil[$program_user_id][6] = $program_id;
			$hasil[$program_user_id][7] = $datas->name;

			/*Save temp data*/
			$idpel[$program_id] = $program_id;$token=$user[$key][0];
		}
	}
}

/* Sort By 'program_id' */
usort($hasil, function($a, $b) {return $a['6'] <=> $b['6'];});

/*Get Pelatihan 'first_activity' from server*/
$first_activity = get_first_activity($token,$idpel);
foreach($hasil as $key=>$data){
	$hasil[$key][8] = $first_activity[$data[6]];
	if(isset($progress[$data[4]])){
		if($data[5]>$progress[$data[4]]){
			$pdo->prepare("UPDATE progress SET name=?,percentage=? WHERE id=?")->execute([
				$data[2],
				$data[5],
				$data[4]
			]);
		}
	}else{
		$pdo->prepare("INSERT INTO progress (id,name,email,judul,first_act,percentage) VALUES (?,?,?,?,?,?)")->execute([
			$data[4],
			$data[2],
			$data[3],
			$data[7],
			$hasil[$key][8],
			$data[5]
		]);
	}
}
echo count($hasil)." data progress dari ".count($user)." user telah di refresh";

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
		if($mode==1){
			$ch = curl_init('https://api.sekolah.mu/program_activity/enrolled/'.$data[1].'/1/12?platform=kariermu');
			$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0]);
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

function get_first_activity($token,$idpel){
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,'https://api.sekolah.mu/program/first-activity-multi-program/?platform=kariermu');
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: '.$token,'Content-Length: '.strlen('{"program_id_list":['.implode(',',$idpel).']}'),'Content-Type: application/json;charset=utf-8'));
	curl_setopt($ch, CURLOPT_TIMEOUT,30);
	curl_setopt($ch, CURLOPT_ENCODING,'gzip');
	curl_setopt($ch, CURLOPT_POST,true);
	curl_setopt($ch, CURLOPT_POSTFIELDS,'{"program_id_list":['.implode(',',$idpel).']}');
	curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$x = curl_exec($ch);
	curl_close($ch);
	$firstact = json_decode($x);
	$return = array();
	if(isset($firstact->data) && $firstact->status==200){
		foreach($firstact->data as $data){
			$return[$data->program_id] = $data->first_activity_slug;
		}
	}
	return $return;
}
?>