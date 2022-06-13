<?PHP
set_time_limit(0);
while(@ob_end_flush());
ob_implicit_flush(true);
date_default_timezone_set('Asia/Jakarta');
include_once 'config.php';

$hasil=array();
if(empty($_GET['id'])) exit();
$list_uid = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM progress WHERE id IN (".$list_uid.")");
$stmt->execute();
$result = $stmt->fetchAll();

$return = '';
foreach($result as $res){
	$stmt = $pdo->prepare("SELECT token FROM user WHERE email=?");
	$stmt->execute([$res['email']]);
	$datas = $stmt->fetch();
	if($datas)
		$hasil[]=array($datas['token'],$res['id'],$res['first_act'],$res['percentage']);
}
usort($hasil, function($a, $b) {return $a['3'] <=> $b['3'];});
getact($hasil);
function getact($datas){
	global $list_uid;
	?>
<!DOCTYPE HTML>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
p {
  text-align: center;
  font-size: 60px;
  margin-top: 0px;
}
</style>
</head>
<body><center><?PHP
	$sample = $datas[0];
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,'https://api.sekolah.mu/program_activity/v2/product_by_activity/'.$sample[2].'/activity?platform=kariermu');
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
	curl_setopt($ch, CURLOPT_HTTPHEADER,array('Authorization: '.$sample[0]));
	curl_setopt($ch, CURLOPT_TIMEOUT,30);
	curl_setopt($ch, CURLOPT_ENCODING,'gzip');
	curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$x = curl_exec($ch);
	curl_close($ch);
	$data = json_decode($x)->data;
	$stop = false;
	foreach($data->chapter_list as $chapters){
		foreach($chapters->chapters as $resource){
			foreach($resource->resource_detail as $res){
				//if($res->type!='webview'){
					if($res->is_complete) continue;
					echo "<h2>".$res->title."</h2> (Durasi ".$res->duration." Menit)\n";
					echo "<pre>";
					if($res->type=='quiz'){
						lihat_quiz($datas,$res->id)."\n";
					}else{
						$list_ret = getResponseByUrlsMultiThreads($datas,1,$res->id);
						foreach($list_ret as $key=>$original){
							if(str_contains($original,'Tidak Dapat Mempersingkat Durasi Video')) $stop = true;
							if(str_contains($original,'Permintaan anda sedang diproses')) $stop = true;
							echo "Akun ke ".($key+1)." | ".$res->id." => ".$original."\n";
						}
					}
					echo "</pre>";
					if($stop){
						if($res->type=='video') $detik = 19;else $detik = 2;
					}else $detik = 0;
					//echo "<pre>";print_r($res);echo "</pre>\n";
					echo '<p id="demo"></p><script>var detik = '.$detik.';var x = setInterval(function() {document.getElementById("demo").innerHTML = detik;if(detik<=0){clearInterval(x);window.location.href = "?id='.$list_uid.'";}detik--;}, 1000);</script>';
					exit();
				//}else exit("WEBVIEW");
			}
		}
	}
	echo '<p id="demo">SELESAI BOSKU</p>';
	echo '<script>var detik = 10;var x = setInterval(function() {if(detik<=0){clearInterval(x);window.location.href = "?id='.$list_uid.'";}detik--;}, 1000);</script>';
}
function getResponseByUrlsMultiThreads($datas, $mode, $param1=0, $threads = 10, $followLocation = true, $maxRedirects = 10){
	$curlOptions = [
		CURLOPT_HEADER => false,
		CURLOPT_NOBODY => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
		CURLOPT_ENCODING => '',
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_ENCODING => 'gzip',
		CURLOPT_POST => true
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
	foreach($datas as $key=>$data){
		switch($mode){
			case 1:
				$ch = curl_init('https://api.sekolah.mu/program_activity/activity/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{"program_user_id":'.$data[1].',"resource_id":'.$param1.',"is_finish":true}'),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = '{"program_user_id":'.$data[1].',"resource_id":'.$param1.',"is_finish":true}';
				break;
			case 2://into_quiz
				$ch = curl_init('https://api.sekolah.mu/program_activity/quiz/intro/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{"resource_id":'.$param1.',"group_id":0,"quiz_id":0,"content_id":0}'),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = '{"resource_id":'.$param1.',"group_id":0,"quiz_id":0,"content_id":0}';
				break;
			case 3://start_quiz
				$ch = curl_init('https://api.sekolah.mu/program_activity/quiz/start/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{"resource_id":'.$param1.',"group_id":0,"quiz_id":0,"content_id":0}'),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = '{"resource_id":'.$param1.',"group_id":0,"quiz_id":0,"content_id":0}';
				break;
			case 4://resume_quiz
				$ch = curl_init('https://api.sekolah.mu/program_activity/quiz/resume/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{"activity_id":'.$data[4].',"group_id":0,"quiz_id":0,"content_id":0}'),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = '{"activity_id":'.$data[4].',"group_id":0,"quiz_id":0,"content_id":0}';
				break;
			case 5://end_quiz
				$ch = curl_init('https://api.sekolah.mu/program_activity/quiz/end/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen('{"activity_id":'.$data[4].',"source":"web"}'),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = '{"activity_id":'.$data[4].',"source":"web"}';
				break;
			case 6://ans_quiz
				$ch = curl_init('https://api.sekolah.mu/program_activity/quiz/answer/?platform=kariermu');
				$curlOptions[CURLOPT_HTTPHEADER] = array('Authorization: '.$data[0],'Content-Length: '.strlen($data[5]),'Content-Type: application/json;charset=utf-8');
				$curlOptions[CURLOPT_POSTFIELDS] = $data[5];
				break;
			default:
		}
		curl_setopt_array($ch, $curlOptions);
		$chArray[$key] = $ch;
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



function lihat_quiz($datas,$resource_id){
	
	/* Intro Quiz */
	$list_ret = getResponseByUrlsMultiThreads($datas,2,$resource_id);

	$list_start = array();
	$list_resume = array();
	$list_end = array();

	foreach($list_ret as $key=>$value){
		$value = json_decode($value);
		$jumlah_soal=$value->data->quiz_master_data->question_per_batch;
		$list_trx = $value->data->quiz_transaction;
		if(count($list_trx)>0){
			//if($value->data->quiz_master_data->max_batch==0){
				foreach($list_trx as $id_trx=>$trx){
					$activity_id=$trx->id;
					$is_completed=$trx->is_completed;
					$is_passed=$trx->is_passed;
				}
				if(!$is_passed){
					if(!$is_completed){
						$list_resume[$key]=$datas[$key];
						$list_resume[$key][4]=$activity_id;
					}else{
						$list_start[$key]=$datas[$key];
					}
				}else{
					$list_start[$key]=$datas[$key];
				}
			//}
		}else{
			$list_start[$key]=$datas[$key];
		}
	}
	
	echo "Jumlah soal : ".$jumlah_soal."\n";

	/* Start Quiz */
	$list_ret = getResponseByUrlsMultiThreads($list_start,3,$resource_id);
	foreach($list_ret as $key=>$value){
		$value = json_decode($value);
		$list_resume[$key]=$datas[$key];
		$list_resume[$key][4]=$value->data->activity->id;
	}
	
	/* Resume Quiz */
	$list_ret = getResponseByUrlsMultiThreads($list_resume,4);
	foreach($list_ret as $key=>$value){
		$value = json_decode($value);
		$list_resume[$key]=$datas[$key];
		$list_resume[$key][4]=$value->data->activity->id;
	}

	/* Kerjakan Quiz */
	for($no_soal=1;$no_soal<=$jumlah_soal;$no_soal++){
		$list_jawab = array();
		foreach($list_ret as $key=>$soal){
			//if(strpos($value,'Quiz ini hanya dapat diambil 1 kali. atau kamu sudah pernah melihat kunci jawaban')!== false) break;
			$soal_json=json_decode($soal,true);
			$no_soal_asli = $soal_json['data']['activity_question']['position'];
			echo "Soal Nomor ".$no_soal_asli.". ".htmlspecialchars(substr($soal, 0, 150))."\n";
			$ans_soal=get_ans_from_soal($soal);
			echo "Jawab Nomor ".$no_soal_asli.". ".htmlspecialchars(substr($ans_soal, 0, 150))."\n";
			if($no_soal_asli<=$jumlah_soal){
				$list_jawab[$key]=$datas[$key];
				$list_jawab[$key][5]=$ans_soal;
			}
		}
		$list_ret = getResponseByUrlsMultiThreads($list_jawab,6);
	}
	
	/* Resume Quiz */
	$list_ret = getResponseByUrlsMultiThreads($list_resume,5);
	foreach($list_ret as $key=>$value){
		echo $key." | ".$resource_id." => ".$value."\n";
	}
}
function get_ans_from_soal($log){
	$x=json_decode($log,true);
	if($x['data']['activity_question']['quiz_question']['type']=='ESSAYFILE'){
		$z=(object)array(
			"activity_id"=>$x['data']['activity']['id'],
			"activity_question_id"=>$x['data']['activity_question']['id'],
			"question_position"=>$x['data']['activity_question']['position'],
			"type"=>$x['data']['activity_question']['quiz_question']['type'],
			"answer_essay"=> "ya",
			"answer_file"=> "",
			"is_next"=> true,
			"is_preview"=> false,
			"answer"=> array(),
			"is_prev"=> false,
			"group_id"=> 0,
			"quiz_id"=> 0,
			"content_id"=> 0,
			"source"=> "web"
		);
	}else if($x['data']['activity_question']['quiz_question']['type']=='ESSAY'){
		$z=(object)array(
			"activity_id"=>$x['data']['activity']['id'],
			"activity_question_id"=>$x['data']['activity_question']['id'],
			"question_position"=>$x['data']['activity_question']['position'],
			"type"=>$x['data']['activity_question']['quiz_question']['type'],
			"answer_essay"=> "ya",
			"answer_file"=> array("google.com"),
			"is_next"=> true,
			"is_preview"=> false,
			"answer"=> array(),
			"is_prev"=> false,
			"group_id"=> 0,
			"quiz_id"=> 0,
			"content_id"=> 0,
			"source"=> "web"
		);
	}else{
		$z=(object)array(
			"activity_id"=>$x['data']['activity']['id'],
			"activity_question_id"=>$x['data']['activity_question']['id'],
			"question_position"=>$x['data']['activity_question']['position'],
			"type"=>$x['data']['activity_question']['quiz_question']['type'],
			"answer_essay"=>"",
			"answer_file"=>"",
			"is_next"=>true,
			"is_preview"=>false,
			"answer"=>array(),
			"is_prev"=>false,
			"group_id"=>0,
			"quiz_id"=>0,
			"content_id"=>0,
			"source"=>"web"
		);
		$i=0;
		//if($x['data']['activity_question']['position']%5!=0){
			foreach($x['data']['activity_question']['quiz_question']['question_answer'] as $p=>$ans){
				if($ans['is_true']==1) $z->answer[$i++]=$x['data']['activity_question']['quiz_question']['question_answer'][$p];
			}
		//}else{
		//	$z->answer[$i++]=$x['data']['activity_question']['quiz_question']['question_answer'][rand(0,count($x['data']['activity_question']['quiz_question']['question_answer'])-1)];
		//}
	}
	$z=json_encode($z);
	return $z;
}